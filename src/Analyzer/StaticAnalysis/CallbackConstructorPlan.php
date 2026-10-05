<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Metadata\TypeMetadata;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\MixedType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind;
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\Analyzer\TypeComparator;
use PhpParser\Node;

/** Describe finite physical initialization without executing arbitrary constructors. */
final class CallbackConstructorPlan
{
    /**
     * The interpreter must bind every argument against parameterMetadata, reject
     * selected reference escapes, retain actual field values and validate every
     * assignment against the field type. Parent arguments retain ordinary flow.
     *
     * @return array<string, mixed>|null
     */
    public static function plan(Codebase $codebase, TypeComparator $types, DirectCallbackReferenceEffects $index, string $class): ?array
    {
        $source = $index->classSource($codebase, $class);
        if ($source === null || ! $source['node'] instanceof Node\Stmt\Class_ || ! $source['node']->isFinal()) { return null; }
        $node = $source['node'];
        $metadata = $source['metadata'];
        $file = $source['entry']['file'];
        if ($metadata->usedTraits !== [] || $metadata->flags->contains(MetadataFlags::READONLY) !== $node->isReadonly()
            || $node->isAbstract() || $codebase->getMethod($class, '__destruct') !== null) { return null; }
        $parent = $node->extends?->toString();
        if ($parent !== null && ! in_array(strtolower($parent), ['exception', 'runtimeexception'], true)) { return null; }

        $constructor = $codebase->getMethod($class, '__construct');
        $method = $constructor === null ? null : $index->method($codebase, $types, $class, '__construct');
        if ($constructor !== null && ($method === null || strcasecmp($method['owner'], $class) !== 0
            || ! $method['node']->isPublic() || $method['node']->isStatic() || $method['node']->returnType !== null
            || $method['metadata']->returnType !== null && (string) $method['metadata']->returnType->type !== 'void')) { return null; }
        if ($constructor === null && ($node->getMethod('__construct') !== null || $parent !== null)) { return null; }

        $parameters = $method['node']->params ?? [];
        $parameterMetadata = $method['metadata']->parameters ?? [];
        if (count($parameters) > 32 || count($node->getProperties()) > 32 || count($method['node']->stmts ?? []) > 32) { return null; }
        $formals = [];
        foreach ($parameters as $position => $parameter) {
            if (! $parameter->var instanceof Node\Expr\Variable || ! is_string($parameter->var->name) || $parameter->variadic
                || $parameter->hooks !== [] || $parameter->byRef && ($parameter->isPromoted()
                    || ! $parameter->type instanceof Node\Identifier || strtolower($parameter->type->name) !== 'array')
                || $parameterMetadata[$position]->flags->contains(MetadataFlags::PROMOTED_PROPERTY) !== $parameter->isPromoted()) { return null; }
            $formals[$parameter->var->name] = true;
        }

        $fields = [];
        foreach ($node->getProperties() as $property) {
            if (count($property->props) !== 1 || $property->isStatic() || $property->isAbstract() || $property->hooks !== []
                || $property->isPublicSet() || $property->isProtectedSet() || $property->isPrivateSet()
                || self::documented($property)) { return null; }
            $item = $property->props[0];
            $name = $item->name->name;
            $field = self::field($codebase, $types, $index, $class, $property, $item->name, $property->type, $file,
                false, $node->isReadonly() || $property->isReadonly(), $item->default, null);
            if ($field === null || isset($fields[$name])) { return null; }
            $fields[$name] = $field;
        }
        $promotions = [];
        foreach ($parameters as $position => $parameter) {
            if (! $parameter->isPromoted()) { continue; }
            if ($parameter->isPublicSet() || $parameter->isProtectedSet() || $parameter->isPrivateSet()) { return null; }
            $name = $parameter->var->name;
            $field = self::field($codebase, $types, $index, $class, $parameter, $parameter->var, $parameter->type, $file,
                true, $node->isReadonly() || $parameter->isReadonly(), null, $parameterMetadata[$position]->type,
                $parameterMetadata[$position]->defaultType);
            if ($field === null || isset($fields[$name])) { return null; }
            $fields[$name] = $field;
            $promotions[] = ['field' => $name, 'parameter' => $name];
        }

        $steps = [];
        $assigned = array_fill_keys(array_column($promotions, 'field'), true);
        $parentCalled = false;
        foreach ($method['node']->stmts ?? [] as $statement) {
            if ($statement instanceof Node\Stmt\Nop) { continue; }
            if (! $statement instanceof Node\Stmt\Expression) { return null; }
            $expression = $statement->expr;
            if ($expression instanceof Node\Expr\Assign && $expression->var instanceof Node\Expr\PropertyFetch
                && $expression->var->var instanceof Node\Expr\Variable && $expression->var->var->name === 'this'
                && $expression->var->name instanceof Node\Identifier) {
                $name = $expression->var->name->name;
                if (! isset($fields[$name]) || isset($assigned[$name]) || $fields[$name]['readonly'] && $fields[$name]['hasDefault']
                    || ! self::value($expression->expr, $formals, $codebase, $index)) { return null; }
                $assigned[$name] = true;
                $steps[] = ['kind' => 'assign', 'field' => $name, 'value' => $expression->expr];
                continue;
            }
            if ($expression instanceof Node\Expr\StaticCall && $expression->class instanceof Node\Name
                && strcasecmp($expression->class->toString(), 'parent') === 0 && $expression->name instanceof Node\Identifier
                && strcasecmp($expression->name->name, '__construct') === 0 && $parent !== null && ! $parentCalled) {
                $native = $index->construction($codebase, $types, $parent);
                if ($native === null || count($expression->args) > 3) { return null; }
                foreach ($expression->args as $argument) {
                    if (! $argument instanceof Node\Arg || $argument->byRef || $argument->unpack
                        || $argument->name !== null && ! in_array($argument->name->name, ['message', 'code', 'previous'], true)
                        || ! self::value($argument->value, $formals, $codebase, $index)) { return null; }
                }
                $parentCalled = true;
                $steps[] = ['kind' => 'parent-exception', 'call' => $expression, 'construction' => $native];
                continue;
            }
            return null;
        }
        if ($parent !== null && ! $parentCalled) { return null; }

        return ['kind' => 'source', 'class' => $class, 'source' => $source, 'constructor' => $method,
            'parameters' => $parameters, 'parameterMetadata' => $parameterMetadata, 'fields' => $fields,
            'promotions' => $promotions, 'steps' => $steps];
    }

    /** @return array<string, mixed>|null */
    private static function field(Codebase $codebase, TypeComparator $types, DirectCallbackReferenceEffects $index, string $class, Node\Stmt\Property|Node\Param $node,
        Node $name, ?Node $syntax, string $file, bool $promoted, bool $readonly, ?Node\Expr $default, ?TypeMetadata $parameterType,
        ?TypeMetadata $parameterDefault = null): ?array
    {
        $rawName = $name instanceof Node\Expr\Variable ? $name->name : ($name instanceof Node\Identifier ? $name->name : null);
        if (! is_string($rawName)) { return null; }
        $property = $codebase->getDeclaringProperty($class, '$'.$rawName);
        $declared = self::declared($syntax, $class);
        $visibility = $node->isPrivate() ? Visibility::Private : ($node->isProtected() ? Visibility::Protected : Visibility::Public);
        // Native metadata gives every ordinary readonly field protected(set),
        // including a field whose read visibility is private.
        $writeVisibility = $readonly ? Visibility::Protected : $visibility;
        if ($property === null || $property->name !== '$'.$rawName || $declared === null || $property->declaredType === null
            || $property->type === null || $property->hooks !== []
            || $property->flags->contains(MetadataFlags::STATIC) || $property->flags->contains(MetadataFlags::VIRTUAL_PROPERTY)
            || $property->flags->contains(MetadataFlags::ASYMMETRIC_PROPERTY) !== $readonly || $property->flags->contains(MetadataFlags::WRITEONLY)
            || $property->flags->contains(MetadataFlags::PROMOTED_PROPERTY) !== $promoted
            || $property->flags->contains(MetadataFlags::HAS_DEFAULT) !== ($promoted ? $node->default !== null : $default !== null)
            || $property->flags->contains(MetadataFlags::READONLY) !== $readonly
            || $property->readVisibility !== $visibility || $property->writeVisibility !== $writeVisibility
            || $property->location === null && $promoted
            || $property->location !== null && ! DirectCallbackReferenceEffects::located($property->location, $node, $file)
            || ! DirectCallbackReferenceEffects::located($property->nameLocation, $name, $file)
            || ! DirectCallbackReferenceEffects::located($property->declaredType->location, $syntax, $file)
            || $property->declaredType->fromDocblock || ! $types->equals($declared, $property->declaredType->type)
            || $property->writeType !== null && ! $types->equals($property->type->type, $property->writeType->type)) { return null; }
        if ($parameterType !== null) {
            if (! $types->equals($property->type->type, $parameterType->type) || $property->type->fromDocblock !== $parameterType->fromDocblock
                || RefreshedModelProperties::path($property->type->location->file ?? '') !== RefreshedModelProperties::path($parameterType->location->file ?? '')
                || $property->type->location->span->start !== $parameterType->location->span->start
                || $property->type->location->span->end !== $parameterType->location->span->end) { return null; }
        } elseif ($property->type->fromDocblock || ! $types->equals($property->type->type, $declared)
            || ! DirectCallbackReferenceEffects::located($property->type->location, $syntax, $file)) { return null; }
        if (! $types->isContainedBy($property->type->type, $declared)
            || ! self::supportedType($property->type->type, $codebase, $index,
                $syntax instanceof Node\Name && strcasecmp($syntax->toString(), 'Closure') === 0)) { return null; }
        if ($promoted) {
            if ($node->default === null) {
                if ($property->defaultType !== null || $parameterDefault !== null) { return null; }
            } elseif ($property->defaultType === null || $parameterDefault === null
                || $property->defaultType->fromDocblock || ! $types->equals($property->defaultType->type, $parameterDefault->type)
                || RefreshedModelProperties::path($property->defaultType->location->file ?? '') !== RefreshedModelProperties::path($parameterDefault->location->file ?? '')
                || $property->defaultType->location->span->start !== $parameterDefault->location->span->start
                || $property->defaultType->location->span->end !== $parameterDefault->location->span->end) { return null; }
        } elseif ($default === null) {
            if ($property->defaultType !== null) { return null; }
        } else {
            $literal = self::literal($default);
            if ($literal === null || $property->defaultType === null || $property->defaultType->fromDocblock
                || ! DirectCallbackReferenceEffects::located($property->defaultType->location, $default, $file)
                || ! $types->equals($property->defaultType->type, $literal)
                || ! $types->isContainedBy($literal, $property->type->type)) { return null; }
        }
        return ['node' => $node, 'nameNode' => $name, 'metadata' => $property, 'type' => $property->type->type,
            'writeType' => $property->writeType?->type ?? $property->type->type,
            'default' => $default, 'hasDefault' => $default !== null, 'promoted' => $promoted, 'readonly' => $readonly];
    }

    private static function declared(?Node $syntax, string $class): ?Type
    {
        if ($syntax instanceof Node\Identifier && strtolower($syntax->name) === 'array') {
            return Type::array(Type::fromAtomic(new ScalarType(ScalarTypeKind::ArrayKey)), Type::mixed());
        }
        if ($syntax instanceof Node\NullableType) {
            $inner = self::declared($syntax->type, $class);
            return $inner === null ? null : Type::union($inner, Type::null());
        }
        if ($syntax instanceof Node\Identifier && in_array(strtolower($syntax->name), ['string', 'int', 'float', 'bool', 'true', 'false'], true)
            || $syntax instanceof Node\Name && strcasecmp($syntax->toString(), 'Closure') === 0) {
            return DirectCallbackReferenceEffects::syntaxType($syntax, $class);
        }
        return null;
    }

    private static function supportedType(Type $type, Codebase $codebase, DirectCallbackReferenceEffects $index,
        bool $closure = false, int $depth = 0, bool $dataElement = false): bool
    {
        if ($depth > 8 || count($type->atomicTypes) > 32) { return false; }
        if ($closure) { return DirectCallbackReferenceEffects::genericCallable($type, true); }
        foreach ($type->atomicTypes as $atom) {
            if ($atom instanceof ScalarType || $atom instanceof SimpleAtomicType && $atom->kind === SimpleAtomicTypeKind::Null
                || $atom instanceof MixedType) { continue; }
            if ($atom instanceof ListType) {
                if (count($atom->knownElements ?? []) > 64) { return false; }
                if (! self::supportedType($atom->elementType, $codebase, $index, false, $depth + 1, true)) { return false; }
                foreach ($atom->knownElements ?? [] as $element) { if (! self::supportedType($element->type, $codebase, $index, false, $depth + 1, true)) { return false; } }
                continue;
            }
            if ($atom instanceof KeyedArrayType) {
                if (count($atom->knownItems ?? []) > 64) { return false; }
                if ($atom->keyType !== null && ! self::supportedType($atom->keyType, $codebase, $index, false, $depth + 1)
                    || $atom->valueType !== null && ! self::supportedType($atom->valueType, $codebase, $index, false, $depth + 1, true)) { return false; }
                foreach ($atom->knownItems ?? [] as $item) { if (! self::supportedType($item->type, $codebase, $index, false, $depth + 1, true)) { return false; } }
                continue;
            }
            if ($atom instanceof NamedObjectType && $dataElement && ($atom->parameters ?? []) === [] && ($atom->variances ?? []) === []
                && ($atom->intersections ?? []) === [] && ! $atom->static && ! $atom->isThis && ! $atom->remappedParameters) {
                // Only analyzed data elements are admitted. The interpreter must
                // retain each actual object and check native element containment.
                $source = $index->classSource($codebase, $atom->name);
                if ($source === null || ! $source['node'] instanceof Node\Stmt\Class_
                    || $source['metadata']->hasIncompleteHierarchy()) { return false; }
                continue;
            }
            return false;
        }
        return true;
    }

    private static function value(Node\Expr $node, array $formals, Codebase $codebase, DirectCallbackReferenceEffects $index, int $depth = 0): bool
    {
        if ($depth > 8) { return false; }
        if ($node instanceof Node\Expr\Variable) { return is_string($node->name) && isset($formals[$node->name]); }
        if ($node instanceof Node\Scalar\String_ || $node instanceof Node\Scalar\Int_ || $node instanceof Node\Scalar\Float_) { return true; }
        if ($node instanceof Node\Expr\ConstFetch) { return in_array(strtolower($node->name->toString()), ['true', 'false', 'null'], true); }
        if ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) { return DirectCallbackReferenceEffects::closedScope($node); }
        if ($node instanceof Node\Expr\Array_) {
            if (count($node->items) > 64) { return false; }
            foreach ($node->items as $item) {
                if ($item === null || $item->byRef || $item->unpack || $item->key !== null && self::literal($item->key) === null
                    || ! self::value($item->value, $formals, $codebase, $index, $depth + 1)) { return false; }
            }
            return true;
        }
        if ($node instanceof Node\Expr\BinaryOp\Concat) {
            return self::value($node->left, $formals, $codebase, $index, $depth + 1)
                && self::value($node->right, $formals, $codebase, $index, $depth + 1);
        }
        if ($node instanceof Node\Scalar\InterpolatedString) {
            foreach ($node->parts as $part) {
                if (! $part instanceof Node\InterpolatedStringPart && ! self::value($part, $formals, $codebase, $index, $depth + 1)) { return false; }
            }
            return true;
        }
        if ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name && strcasecmp($node->class->toString(), 'Closure') === 0
            && $node->name instanceof Node\Identifier && strcasecmp($node->name->name, 'fromCallable') === 0
            && count($node->args) === 1 && $index->nativeClosure($codebase)) {
            $argument = $node->args[0];
            return $argument instanceof Node\Arg && ! $argument->byRef && ! $argument->unpack
                && ($argument->name === null || $argument->name->name === 'callback')
                && $argument->value instanceof Node\Expr\Variable && is_string($argument->value->name) && isset($formals[$argument->value->name]);
        }
        return false;
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
        $items = [];
        $next = 0;
        foreach ($node->items as $item) {
            if ($item === null || $item->byRef || $item->unpack) { return null; }
            $key = $item->key === null ? $next : PhpSource::value($item->key);
            if (! is_int($key) && ! is_string($key)) { return null; }
            // PHP normalizes decimal string keys to integers before indexing.
            $normalized = array_key_first([$key => true]);
            $type = self::literal($item->value, $depth + 1);
            if ($type === null) { return null; }
            $items[$normalized] = new \Mago\Sdk\Analyzer\Type\ArrayItem(new \Mago\Sdk\Analyzer\Type\ArrayKey(
                is_int($normalized) ? \Mago\Sdk\Analyzer\Type\ArrayKeyKind::Integer : \Mago\Sdk\Analyzer\Type\ArrayKeyKind::String, $normalized,
            ), false, $type);
            if (is_int($normalized) && $normalized >= $next) { $next = $normalized + 1; }
        }
        return Type::fromAtomic(new KeyedArrayType(array_values($items), null, null, $items !== []));
    }

    private static function documented(Node $node): bool
    {
        foreach ($node->getComments() as $comment) {
            if (preg_match('/@(?:phpstan-|psalm-)?var\b/', $comment->getText()) === 1) { return true; }
        }
        return false;
    }
}
