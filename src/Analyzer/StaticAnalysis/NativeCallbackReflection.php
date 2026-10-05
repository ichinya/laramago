<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Metadata\ClassLikeKind;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeKind;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\AnyObjectType;
use Mago\Sdk\Analyzer\Type\ClassLikeStringKind;
use Mago\Sdk\Analyzer\Type\ClassLikeStringType;
use Mago\Sdk\Analyzer\Type\ClassLikeStringVariant;
use Mago\Sdk\Analyzer\Type\GenericParameterType;
use Mago\Sdk\Analyzer\Type\GenericParent;
use Mago\Sdk\Analyzer\Type\GenericParentKind;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Analyzer\Type\Variance;
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\Analyzer\TypeComparator;
use PhpParser\Node;
use PhpParser\NodeFinder;

/** Verify one native reflection selector and its physical, non-hook write contract. */
final class NativeCallbackReflection
{
    private const NATIVE_FILE = 'mago_prelude_extensions/reflection.php';

    /**
     * This is a declaration certificate, not a reflective operation. The caller
     * must bind the exact selector, retain its identity, check the actual setValue
     * receiver/value and capability escapes, and consume it before the selected
     * boolean origin. Recheck snapshots after all dependent operations.
     *
     * @return array<string, mixed>|null
     */
    public static function verify(Codebase $codebase, TypeComparator $types, string $root, string $class, string $property): ?array
    {
        if ($class === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $property) !== 1) { return null; }
        $native = self::native($codebase, $types);
        $target = $codebase->getClass($class);
        $field = $codebase->getDeclaringProperty($class, '$'.$property);
        if ($native === null || $target === null || $target->kind !== ClassLikeKind::Class_ || $target->hasIncompleteHierarchy()
            || $target->flags->contains(MetadataFlags::BUILTIN) || $target->templates !== [] || $target->mixins !== []
            || $field === null || $field->name !== '$'.$property || $field->hooks !== [] || $field->type === null
            || $field->flags->contains(MetadataFlags::STATIC) || $field->flags->contains(MetadataFlags::READONLY)
            || $field->flags->contains(MetadataFlags::VIRTUAL_PROPERTY) || $field->flags->contains(MetadataFlags::PROMOTED_PROPERTY)
            || $field->flags->contains(MetadataFlags::ASYMMETRIC_PROPERTY) || $field->flags->contains(MetadataFlags::WRITEONLY)) { return null; }

        $source = new PhpSource($root);
        $snapshots = $classes = [];
        $pending = [$class, ...$codebase->getClassAncestors($class)];
        $visited = [];
        while ($pending !== []) {
            if (count($visited) + count($pending) > 128) { return null; }
            $name = array_pop($pending);
            $key = strtolower($name);
            if (isset($visited[$key])) { continue; }
            $visited[$key] = true;
            $metadata = $codebase->getClassLike($name);
            if ($metadata === null || $metadata->hasIncompleteHierarchy() || strcasecmp($metadata->name, $name) !== 0) { return null; }
            if ($metadata->flags->contains(MetadataFlags::BUILTIN)) {
                if ($metadata->flags->contains(MetadataFlags::USER_DEFINED)) { return null; }
                continue;
            }
            $declaration = self::declaration($source, $metadata, $snapshots);
            if ($declaration === null) { return null; }
            $classes[$key] = ['node' => $declaration, 'metadata' => $metadata];
            $pending = [...$pending, ...$metadata->directParentInterfaces, ...$metadata->usedTraits];
            if ($metadata->directParentClass !== null) { $pending[] = $metadata->directParentClass; }
        }
        $owner = $classes[strtolower($class)] ?? null;
        if ($owner === null || ! $owner['node'] instanceof Node\Stmt\Class_) { return null; }

        // The initial contract admits the selector's own physical declaration.
        // Inherited and trait-provided fields require a separate owner proof.
        $declaration = $item = null;
        foreach ($owner['node']->getProperties() as $candidate) {
            foreach ($candidate->props as $candidateItem) {
                if ($candidateItem->name->name !== $property) { continue; }
                if ($declaration !== null || count($candidate->props) !== 1) { return null; }
                $declaration = $candidate; $item = $candidateItem;
            }
        }
        if ($declaration === null || $item === null || $declaration->isStatic() || $declaration->isReadonly()
            || $owner['node']->isReadonly() || $declaration->isAbstract() || $declaration->hooks !== []
            || $declaration->isPublicSet() || $declaration->isProtectedSet() || $declaration->isPrivateSet()) { return null; }
        $file = $target->location->file;
        $visibility = $declaration->isPrivate() ? Visibility::Private : ($declaration->isProtected() ? Visibility::Protected : Visibility::Public);
        if ($file === null || $field->readVisibility !== $visibility || $field->writeVisibility !== $visibility
            || $field->location !== null && ! DirectCallbackReferenceEffects::located($field->location, $declaration, $file)
            || ! DirectCallbackReferenceEffects::located($field->nameLocation, $item->name, $file)
            || $field->flags->contains(MetadataFlags::HAS_DEFAULT) !== ($item->default !== null)) { return null; }
        $declared = self::syntax($declaration->type);
        if ($declaration->type === null) {
            if ($field->declaredType !== null) { return null; }
        } elseif ($declared === null || $field->declaredType === null || $field->declaredType->fromDocblock
            || ! DirectCallbackReferenceEffects::located($field->declaredType->location, $declaration->type, $file)
            || ! $types->equals($declared, $field->declaredType->type)) { return null; }
        $documented = self::documented($declaration);
        if ($documented === false) { return null; }
        if ($documented !== null) {
            if (! $field->type->fromDocblock || ! $types->equals($documented['type'], $field->type->type)
                || RefreshedModelProperties::path($field->type->location->file ?? '') !== RefreshedModelProperties::path($file)
                || $field->type->location->span->start !== $documented['start'] || $field->type->location->span->end !== $documented['end']) { return null; }
        } elseif ($declared === null || $field->type->fromDocblock || ! $types->equals($declared, $field->type->type)
            || ! DirectCallbackReferenceEffects::located($field->type->location, $declaration->type, $file)) { return null; }
        if ($declared !== null && ! $types->isContainedBy($field->type->type, $declared)
            || $field->writeType !== null && ! $types->equals($field->writeType->type, $field->type->type)) { return null; }
        $default = $item->default === null ? null : self::literal($item->default);
        if ($item->default === null) {
            if ($field->defaultType !== null) { return null; }
        } elseif ($default === null || $field->defaultType === null || $field->defaultType->fromDocblock
            || ! DirectCallbackReferenceEffects::located($field->defaultType->location, $item->default, $file)
            || ! $types->equals($default, $field->defaultType->type) || ! $types->isContainedBy($default, $field->type->type)) { return null; }
        if (! self::current(['snapshots' => $snapshots])) { return null; }
        $receiver = Type::namedObject($class);
        $classString = Type::fromAtomic(new ScalarType(ScalarTypeKind::ClassLikeString,
            new ClassLikeStringType(ClassLikeStringVariant::Literal, ClassLikeStringKind::Class_, $class)));
        return $native + ['class' => $class, 'property' => $property, 'target' => $target, 'field' => $field,
            'declaration' => $declaration, 'item' => $item, 'readType' => $field->type->type,
            'writeType' => $field->writeType?->type ?? $field->type->type, 'default' => $item->default,
            'receiverType' => $receiver, 'classStringType' => $classString,
            'reflectionType' => Type::namedObject('ReflectionProperty', $receiver),
            'sourceClasses' => $classes, 'snapshots' => $snapshots];
    }

    public static function current(array $plan): bool
    {
        foreach ($plan['snapshots'] ?? [] as $snapshot) { if (! DirectCallbackReferenceEffects::current($snapshot)) { return false; } }
        return ($plan['snapshots'] ?? []) !== [];
    }

    /** @return array<string, mixed>|null */
    private static function native(Codebase $codebase, TypeComparator $types): ?array
    {
        $class = $codebase->getClass('ReflectionProperty');
        $constructor = $codebase->getMethod('ReflectionProperty', '__construct');
        $setter = $codebase->getMethod('ReflectionProperty', 'setValue');
        if ($class === null || $class->kind !== ClassLikeKind::Class_ || strcasecmp($class->name, 'ReflectionProperty') !== 0
            || strcasecmp($class->originalName, 'ReflectionProperty') !== 0 || $class->hasIncompleteHierarchy()
            || ! $class->flags->contains(MetadataFlags::BUILTIN) || $class->flags->contains(MetadataFlags::USER_DEFINED)
            || $class->flags->contains(MetadataFlags::FINAL) || $class->flags->contains(MetadataFlags::ABSTRACT) || $class->flags->contains(MetadataFlags::READONLY)
            || $class->location->file !== self::NATIVE_FILE || $class->nameLocation?->file !== self::NATIVE_FILE
            || $class->directParentClass !== null || $class->parentClasses !== [] || $class->usedTraits !== [] || $class->mixins !== []
            || ! self::names($class->directParentInterfaces, ['Reflector']) || ! self::names($class->parentInterfaces, ['Reflector', 'Stringable'])
            || count($class->templates) !== 1) { return null; }
        $template = $class->templates[0];
        if ($template->name !== 'T' || ! self::parent($template->definingEntity) || ! $types->equals($template->constraint, Type::object())
            || $template->default === null || ! $types->equals($template->default, Type::object())
            || $template->variance !== Variance::Invariant || ! $template->readonly) { return null; }
        foreach (['Reflector', 'Stringable'] as $name) {
            $ancestor = $codebase->getClassLike($name);
            if ($ancestor === null || $ancestor->kind !== ClassLikeKind::Interface || strcasecmp($ancestor->name, $name) !== 0
                || $ancestor->hasIncompleteHierarchy() || ! $ancestor->flags->contains(MetadataFlags::BUILTIN)
                || $ancestor->flags->contains(MetadataFlags::USER_DEFINED) || $ancestor->templates !== [] || $ancestor->mixins !== []) { return null; }
        }
        if (! self::method($constructor, '__construct', true) || ! self::method($setter, 'setValue', false)
            || $constructor->returnType !== null || $constructor->declaredReturnType !== null
            || $setter->declaredReturnType === null || $setter->returnType === null
            || $setter->declaredReturnType->fromDocblock || $setter->returnType->fromDocblock
            || ! $types->equals($setter->declaredReturnType->type, Type::void()) || ! $types->equals($setter->returnType->type, Type::void())) { return null; }
        $specifications = [[$constructor, ['$class', '$property']], [$setter, ['$objectOrValue', '$value']]];
        foreach ($specifications as [$method, $names]) {
            foreach ($method->parameters as $position => $parameter) {
                if ($parameter->name !== $names[$position] || ! $parameter->flags->contains(MetadataFlags::BUILTIN)
                    || $parameter->flags->contains(MetadataFlags::USER_DEFINED) || $parameter->flags->contains(MetadataFlags::BY_REFERENCE)
                    || $parameter->flags->contains(MetadataFlags::VARIADIC) || $parameter->flags->contains(MetadataFlags::HAS_DEFAULT)
                    || $parameter->defaultType !== null || $parameter->outType !== null || $parameter->closureThisType !== null
                    || $parameter->declaredType === null || $parameter->type === null || $parameter->declaredType->fromDocblock
                    || $parameter->attributes !== []
                    || $parameter->location->file !== self::NATIVE_FILE || $parameter->nameLocation->file !== self::NATIVE_FILE
                    || $parameter->declaredType->location->file !== self::NATIVE_FILE || $parameter->type->location->file !== self::NATIVE_FILE) { return null; }
                if ($method === $constructor && $position === 0) {
                    if (! $types->equals($parameter->declaredType->type, Type::union(Type::string(), Type::object()))
                        || ! $parameter->type->fromDocblock || ! self::genericSelector($parameter->type->type, $types)) { return null; }
                } else {
                    $expected = $method === $constructor ? Type::string() : Type::mixed();
                    if ($parameter->type->fromDocblock || ! $types->equals($parameter->declaredType->type, $expected)
                        || ! $types->equals($parameter->type->type, $expected)) { return null; }
                }
            }
        }
        return ['nativeClass' => $class, 'constructor' => $constructor, 'setValue' => $setter];
    }

    private static function method(?FunctionLikeMetadata $method, string $name, bool $constructor): bool
    {
        return $method !== null && $method->kind === FunctionLikeKind::Method
            && $method->identifier->kind === \Mago\Sdk\Analyzer\Type\FunctionLikeKind::Method
            && strcasecmp($method->identifier->class ?? '', 'ReflectionProperty') === 0
            && strcasecmp($method->identifier->name, $name) === 0 && strcasecmp($method->name, $name) === 0
            && strcasecmp($method->originalName, $name) === 0 && $method->constructor === $constructor
            && $method->flags->contains(MetadataFlags::BUILTIN) && ! $method->flags->contains(MetadataFlags::USER_DEFINED)
            && ! $method->flags->contains(MetadataFlags::BY_REFERENCE) && ! $method->static && ! $method->abstract && ! $method->final
            && $method->visibility === Visibility::Public && count($method->parameters) === 2 && $method->templates === []
            && $method->globalsAccessed === [] && $method->whereConstraints === [] && $method->assertions === []
            && $method->ifTrueAssertions === [] && $method->ifFalseAssertions === [] && ! $method->assertionsInferred
            && $method->location->file === self::NATIVE_FILE && $method->nameLocation?->file === self::NATIVE_FILE;
    }

    private static function genericSelector(Type $type, TypeComparator $types): bool
    {
        if (count($type->atomicTypes) !== 2) { return false; }
        $string = $object = false;
        foreach ($type->atomicTypes as $atom) {
            if ($atom instanceof ScalarType && $atom->kind === ScalarTypeKind::ClassLikeString && $atom->refinement instanceof ClassLikeStringType) {
                $value = $atom->refinement;
                if ($string || $value->variant !== ClassLikeStringVariant::Generic || $value->kind !== ClassLikeStringKind::Class_
                    || $value->literal !== null || $value->parameterName !== 'T' || $value->definingEntity === null
                    || ! self::parent($value->definingEntity) || ! $value->constraint instanceof AnyObjectType) { return false; }
                $string = true;
            } elseif ($atom instanceof GenericParameterType) {
                if ($object || $atom->name !== 'T' || ! self::parent($atom->definingEntity) || $atom->intersections !== null
                    || ! $types->equals($atom->constraint, Type::object())) { return false; }
                $object = true;
            } else { return false; }
        }
        return $string && $object;
    }

    private static function parent(GenericParent $parent): bool
    {
        return $parent->kind === GenericParentKind::ClassLike && strcasecmp($parent->name, 'ReflectionProperty') === 0 && $parent->member === null;
    }

    private static function declaration(PhpSource $source, \Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata $metadata, array &$snapshots): ?Node\Stmt\ClassLike
    {
        $file = $metadata->location->file;
        if ($file === null || $metadata->templates !== [] || $metadata->mixins !== []) { return null; }
        $disk = $source->path($file);
        $size = @filesize($disk);
        if ($size === false || $size > 2_000_000 || count($snapshots) >= 32 && ! isset($snapshots[RefreshedModelProperties::path($disk)])) { return null; }
        $nodes = $source->read($file);
        $hash = $source->contentHash($file);
        if ($nodes === null || $hash === null) { return null; }
        $found = null;
        foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Stmt\ClassLike::class) as $node) {
            if ($node->name === null || $node->namespacedName === null || strcasecmp($node->namespacedName->toString(), $metadata->name) !== 0) { continue; }
            if ($found !== null || ! DirectCallbackReferenceEffects::located($metadata->location, $node, $file)
                || ! DirectCallbackReferenceEffects::located($metadata->nameLocation, $node->name, $file)) { return null; }
            $kind = $node instanceof Node\Stmt\Class_ ? ClassLikeKind::Class_ : ($node instanceof Node\Stmt\Trait_ ? ClassLikeKind::Trait
                : ($node instanceof Node\Stmt\Interface_ ? ClassLikeKind::Interface : ClassLikeKind::Enum));
            if ($metadata->kind !== $kind || $node instanceof Node\Stmt\Enum_) { return null; }
            if ($node instanceof Node\Stmt\Class_ && ($metadata->flags->contains(MetadataFlags::FINAL) !== $node->isFinal()
                || $metadata->flags->contains(MetadataFlags::ABSTRACT) !== $node->isAbstract()
                || $metadata->flags->contains(MetadataFlags::READONLY) !== $node->isReadonly()
                || strcasecmp($metadata->directParentClass ?? '', $node->extends?->toString() ?? '') !== 0)) { return null; }
            $interfaces = $node instanceof Node\Stmt\Class_ ? $node->implements : ($node instanceof Node\Stmt\Interface_ ? $node->extends : []);
            if (! self::names(array_map(static fn (Node\Name $name): string => $name->toString(), $interfaces), $metadata->directParentInterfaces)) { return null; }
            $traits = [];
            foreach ($node->stmts as $statement) {
                if (! $statement instanceof Node\Stmt\TraitUse) { continue; }
                if ($statement->adaptations !== []) { return null; }
                foreach ($statement->traits as $trait) { $traits[] = $trait->toString(); }
            }
            if (! self::names($traits, $metadata->usedTraits)) { return null; }
            $found = $node;
        }
        if ($found === null) { return null; }
        $snapshots[RefreshedModelProperties::path($disk)] = ['file' => $file, 'diskFile' => $disk, 'hash' => $hash];
        return $found;
    }

    private static function syntax(?Node $node): ?Type
    {
        if ($node instanceof Node\NullableType) {
            $inner = self::syntax($node->type);
            return $inner === null ? null : Type::union($inner, Type::null());
        }
        if (! $node instanceof Node\Identifier) { return null; }
        return match (strtolower($node->name)) {
            'array' => Type::array(Type::fromAtomic(new ScalarType(ScalarTypeKind::ArrayKey)), Type::mixed()),
            'string' => Type::string(), 'int' => Type::int(), 'float' => Type::float(), 'bool' => Type::bool(),
            'true' => Type::true(), 'false' => Type::false(), default => null,
        };
    }

    /** @return array{type: Type, start: int, end: int}|false|null */
    private static function documented(Node\Stmt\Property $property): array|false|null
    {
        $found = null;
        foreach ($property->getComments() as $comment) {
            if (preg_match('/@(?:phpstan-|psalm-)(?:var|type)\b/', $comment->getText()) === 1) { return false; }
            if (preg_match_all('/@var[ \t]+([^\r\n]*?)(?=[ \t]*\*\/|\r?\n)/', $comment->getText(), $matches, PREG_OFFSET_CAPTURE) === 0) { continue; }
            foreach ($matches[1] as [$raw, $offset]) {
                if ($found !== null) { return false; }
                $expression = trim($raw);
                $type = DiagnosticArrayTypes::parse($expression);
                if ($type === null || in_array($expression, ['mixed', 'never', 'null'], true)) { return false; }
                $start = $comment->getStartFilePos() + $offset + strlen($raw) - strlen(ltrim($raw));
                $found = ['type' => $type, 'start' => $start, 'end' => $start + strlen($expression)];
            }
        }
        return $found;
    }

    private static function literal(Node\Expr $node, int $depth = 0): ?Type
    {
        if ($depth > 8) { return null; }
        $value = PhpSource::value($node);
        if (is_bool($value)) { return $value ? Type::true() : Type::false(); }
        if (is_int($value)) { return Type::literalInt($value); }
        if (is_string($value)) { return Type::literalString($value); }
        if ($value === null) { return Type::null(); }
        if (! $node instanceof Node\Expr\Array_ || count($node->items) > 64) { return null; }
        if ($node->items === []) { return Type::fromAtomic(new ListType(Type::never(), [], 0, false)); }
        $items = []; $next = 0;
        foreach ($node->items as $item) {
            if ($item === null || $item->byRef || $item->unpack) { return null; }
            $key = $item->key === null ? $next : PhpSource::value($item->key);
            $type = self::literal($item->value, $depth + 1);
            if ((! is_int($key) && ! is_string($key)) || $type === null) { return null; }
            $key = array_key_first([$key => true]);
            $items[$key] = new \Mago\Sdk\Analyzer\Type\ArrayItem(new \Mago\Sdk\Analyzer\Type\ArrayKey(
                is_int($key) ? \Mago\Sdk\Analyzer\Type\ArrayKeyKind::Integer : \Mago\Sdk\Analyzer\Type\ArrayKeyKind::String, $key,
            ), false, $type);
            if (is_int($key) && $key >= $next) { $next = $key + 1; }
        }
        return Type::fromAtomic(new KeyedArrayType(array_values($items), null, null, $items !== []));
    }

    private static function names(array $left, array $right): bool
    {
        $left = array_map('strtolower', $left); $right = array_map('strtolower', $right);
        sort($left); sort($right);
        return $left === $right;
    }
}
