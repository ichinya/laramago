<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\CodebaseScanContext;
use Mago\Sdk\Analyzer\CodebaseScanHook;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\Metadata\ClassLikeKind;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeKind;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\TypeComparator;
use Mago\Sdk\Analyzer\Type\CallableType;
use Mago\Sdk\Analyzer\Type\GenericParameterType;
use Mago\Sdk\Analyzer\Type\GenericParentKind;
use Mago\Sdk\Analyzer\Type\IterableType;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\SourceLocation;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** Certify an unchanged mapper formal from every element of its fresh literal input. */
final class FiniteMapArgumentProofs implements InitializationHook, CodebaseScanHook
{
    private array $files = [];
    private array $symbols = [];
    private bool $complete = false;
    private bool $failed = false;
    private bool $started = false;
    private int $bytes = 0;

    // Included PHP declarations use the current SDK's source classification bit without USER_DEFINED.
    private const INCLUDED_SOURCE = 1 << 6;

    public function __construct(private readonly string $root = '.') {}

    public function initialize(InitializationContext $context): void
    {
        $this->files = $this->symbols = [];
        $this->complete = $this->failed = $this->started = false;
        $this->bytes = 0;
    }

    public function getTargets(): array { return ['**']; }

    public function scan(CodebaseScanContext $context): void
    {
        if ($context->firstBatch) {
            $this->files = $this->symbols = [];
            $this->failed = false;
            $this->started = true;
            $this->bytes = 0;
        }
        $this->complete = false;
        try { $context->cancellation->throwIfCancelled(); }
        catch (\Throwable $interrupted) {
            $this->failed = true;
            $this->started = false;
            $this->files = $this->symbols = [];
            throw $interrupted;
        }
        if (! $this->started || $this->failed) {
            $this->failed = true;
            $this->files = $this->symbols = [];
            return;
        }
        foreach ($context->files as $file) {
            try { $context->cancellation->throwIfCancelled(); }
            catch (\Throwable $interrupted) {
                $this->failed = true;
                $this->started = false;
                $this->files = $this->symbols = [];
                throw $interrupted;
            }
            $path = $this->path($file->path);
            $this->bytes += strlen($file->contents);
            if ($this->failed || isset($this->files[$path]) || count($this->files) >= 100_000
                || strlen($file->contents) > 2_000_000 || $this->bytes > 67_108_864) {
                $this->failed = true;
                break;
            }
            $entry = ['file' => $file->path, 'diskFile' => $this->disk($file->path), 'hash' => hash('sha256', $file->contents), 'proofs' => []];
            $this->files[$path] = $entry;
            try {
                $nodes = (new NodeTraverser(new NameResolver))->traverse((new ParserFactory)->createForNewestSupportedVersion()->parse($file->contents) ?? []);
            } catch (\PhpParser\Error) { $this->failed = true; break; }
            foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Stmt\ClassLike::class) as $class) {
                if ($class->name === null || $class->namespacedName === null) { continue; }
                $symbol = strtolower($class->namespacedName->toString());
                if (isset($this->symbols[$symbol])) { $this->failed = true; break 2; }
                $this->symbols[$symbol] = true;
            }
            if ((new NodeFinder)->findFirst($nodes, static fn (Node $node): bool => $node instanceof Node\DeclareItem
                && strtolower($node->key->name) === 'ticks') !== null) { continue; }
            $blocked = [];
            foreach ((new NodeFinder)->find($nodes, static fn (Node $node): bool => self::strongDocs($node)) as $node) {
                $blocked[] = [$node->getStartFilePos(), $node->getEndFilePos() + 1];
            }
            foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Expr\FuncCall::class) as $call) {
                $proof = self::candidate($call);
                if ($proof === null) { continue; }
                foreach ($blocked as [$start, $end]) {
                    if ($start <= $proof['callback']->getStartFilePos() && $end >= $proof['callback']->getEndFilePos() + 1
                        || $start <= $proof['array']->getStartFilePos() && $end >= $proof['array']->getEndFilePos() + 1) { continue 2; }
                }
                $entry['proofs'][] = $proof + ['contents' => $file->contents] + array_diff_key($entry, ['proofs' => true]);
            }
            $this->files[$path] = $entry;
        }
        if ($this->failed) { $this->files = $this->symbols = []; }
        $this->complete = $context->lastBatch && ! $this->failed;
        $this->started = ! $context->lastBatch && ! $this->failed;
    }

    public function proofs(string $file, string $contents): array
    {
        $entry = $this->complete ? ($this->files[$this->path($file)] ?? null) : null;
        return $entry !== null && hash('sha256', $contents) === $entry['hash'] ? $entry['proofs'] : [];
    }

    /** The selected argument is evaluated first, before any unrelated constructor arguments. */
    public function certificate(Codebase $codebase, TypeComparator $types, array $proof, string $file): ?array
    {
        if (! $this->complete || $this->path($file) !== $this->path($proof['file']) || ! self::current($proof) || ! self::nativeMap($codebase, $types)) { return null; }
        if ($proof['namespaceFallback'] !== null && $codebase->getFunction($proof['namespaceFallback']) !== null) { return null; }
        $identifier = self::callbackIdentifier($proof, $file);
        $callback = $codebase->getFunctionLike($identifier);
        if ($callback === null || ! $callback->identifier->equals($identifier) || ! $this->callback($callback, $proof, $types)) { return null; }
        $constructor = $codebase->getDeclaringMethod($proof['class'], '__construct');
        $class = $codebase->getClass($proof['class']);
        if ($constructor === null || $class === null || $class->kind !== ClassLikeKind::Class_ || $class->hasIncompleteHierarchy()
            || strcasecmp($class->name, $proof['class']) !== 0 || strcasecmp($class->originalName, $proof['class']) !== 0
            || ! self::sourceMetadata($class->flags)
            || $class->flags->contains(MetadataFlags::ABSTRACT)
            || $class->templates !== [] || $class->mixins !== [] || $class->usedTraits !== [] || $class->directParentClass !== null
            || $constructor->identifier->class === null || strcasecmp($constructor->identifier->class, $proof['class']) !== 0) { return null; }
        $declaration = $this->declaration($constructor->location->file, $proof['class']);
        if ($declaration === null) { return null; }
        [$syntax, $entry] = $declaration;
        $method = $syntax->getMethod('__construct');
        if ($method === null || ! $syntax->isFinal() || ! $class->flags->contains(MetadataFlags::FINAL) || $syntax->extends !== null
            || (new NodeFinder)->findInstanceOf($syntax->stmts, Node\Stmt\TraitUse::class) !== []
            || $class->flags->contains(MetadataFlags::READONLY) !== $syntax->isReadonly()
            || ! $this->located($class->location, $syntax, $entry['file']) || ! $this->located($class->nameLocation, $syntax->name, $entry['file'])
            || array_map('strtolower', $class->directParentInterfaces) !== array_map(static fn (Node\Name $name): string => strtolower($name->toString()), $syntax->implements)
            || ! $this->constructor($constructor, $method, $entry, $types) || ! self::binding($proof['new']->args, $constructor)) { return null; }
        $parameter = $constructor->parameters[0] ?? null;
        $sourceParameter = $method->params[0] ?? null;
        if ($parameter === null || $sourceParameter === null || $proof['argument']->name !== null && $proof['argument']->name->name !== $sourceParameter->var->name
            || $parameter->type === null || ! $parameter->type->fromDocblock || ! self::clean($parameter->type->type)
            || ! $types->equals($parameter->type->type, Type::nonEmptyString())
            || ! self::documented($method, $parameter->name, 'non-empty-string')
            || $parameter->type->location === null || $this->path($parameter->type->location->file ?? '') !== $this->path($entry['file'])
            || ! self::docAnchor($parameter->type->location, $method, $entry['contents'], 'non-empty-string')) { return null; }
        $input = null;
        foreach ($proof['values'] as $value) {
            $literal = Type::literalString($value);
            if (! $types->isContainedBy($literal, $callback->parameters[0]->type->type)
                || ! $types->isContainedBy($literal, $parameter->type->type)) { return null; }
            $input = $input === null ? $literal : Type::union($input, $literal);
        }
        if ($input === null || ! $types->isContainedBy($input, $parameter->type->type) || ! self::current($proof) || ! self::current($entry)) { return null; }
        return ['input' => $input, 'actual' => $callback->parameters[0]->type->type, 'expected' => $parameter->type->type,
            'consumer' => $constructor->identifier->class.'::'.$constructor->originalName, 'position' => 1,
            'actualText' => 'string', 'expectedText' => 'non-empty-string'];
    }

    private static function candidate(Node\Expr\FuncCall $call): ?array
    {
        if (! $call->name instanceof Node\Name || $call->isFirstClassCallable() || count($call->args) !== 2) { return null; }
        $name = $call->name->toString();
        if (strcasecmp($name, 'array_map') !== 0) { return null; }
        $namespaced = $call->name->getAttribute('namespacedName');
        $fallback = $namespaced instanceof Node\Name && strcasecmp($namespaced->toString(), 'array_map') !== 0 ? $namespaced->toString() : null;
        foreach ($call->args as $position => $argument) {
            if (! $argument instanceof Node\Arg || $argument->byRef || $argument->unpack
                || $argument->name !== null && $argument->name->name !== ['callback', 'array'][$position]) { return null; }
        }
        $callback = $call->args[0]->value;
        $array = $call->args[1]->value;
        if (! ($callback instanceof Node\Expr\ArrowFunction || $callback instanceof Node\Expr\Closure)
            || ! $callback->static || $callback->byRef || count($callback->params) !== 1 || $callback->attrGroups !== []
            || $callback instanceof Node\Expr\Closure && $callback->uses !== []
            || ! $array instanceof Node\Expr\Array_ || $array->items === [] || count($array->items) > 64) { return null; }
        $parameter = $callback->params[0];
        if (! $parameter->type instanceof Node\Identifier || strtolower($parameter->type->name) !== 'string'
            || ! is_string($parameter->var->name) || $parameter->byRef || $parameter->variadic || $parameter->default !== null
            || $parameter->attrGroups !== [] || $parameter->flags !== 0) { return null; }
        $values = [];
        foreach ($array->items as $item) {
            if ($item === null || $item->key !== null || $item->byRef || $item->unpack || ! $item->value instanceof Node\Scalar\String_
                || $item->value->value === '') { return null; }
            $values[] = $item->value->value;
        }
        $new = $callback instanceof Node\Expr\ArrowFunction ? $callback->expr
            : (count($callback->stmts) === 1 && $callback->stmts[0] instanceof Node\Stmt\Return_ ? $callback->stmts[0]->expr : null);
        if (! $new instanceof Node\Expr\New_ || ! $new->class instanceof Node\Name || $new->args === []
            || in_array(strtolower($new->class->toString()), ['self', 'static', 'parent'], true)) { return null; }
        $argument = $new->args[0];
        if (! $argument instanceof Node\Arg || $argument->byRef || $argument->unpack || ! $argument->value instanceof Node\Expr\Variable
            || $argument->value->name !== $parameter->var->name) { return null; }
        foreach ($new->args as $item) { if (! $item instanceof Node\Arg || $item->byRef || $item->unpack) { return null; } }
        if (! $callback->returnType instanceof Node\Name
            || strcasecmp($callback->returnType->toString(), $new->class->toString()) !== 0) { return null; }
        return ['map' => $call, 'callback' => $callback, 'array' => $array, 'values' => $values, 'new' => $new,
            'argument' => $argument, 'local' => $parameter->var->name, 'class' => $new->class->toString(), 'namespaceFallback' => $fallback];
    }

    private function callback(FunctionLikeMetadata $bound, array $proof, TypeComparator $types): bool
    {
        $syntax = $proof['callback'];
        $parameter = $bound->parameters[0] ?? null;
        if ($bound->kind !== ($syntax instanceof Node\Expr\ArrowFunction ? FunctionLikeKind::ArrowFunction : FunctionLikeKind::Closure)
            || $bound->identifier->kind !== \Mago\Sdk\Analyzer\Type\FunctionLikeKind::Closure || $bound->identifier->class !== null
            || $bound->name !== $bound->identifier->name || $bound->originalName !== $bound->name
            || ! $bound->flags->contains(MetadataFlags::USER_DEFINED) || $bound->flags->contains(MetadataFlags::BUILTIN)
            // Current SDK closure metadata uses static=false even for source `static fn`; source static syntax was checked independently.
            || $bound->flags->contains(MetadataFlags::BY_REFERENCE) || $bound->static || $bound->abstract || $bound->constructor || $bound->nameLocation !== null
            || $bound->templates !== [] || $bound->globalsAccessed !== [] || $bound->attributes !== [] || $bound->hasDocblock
            || $bound->assertions !== [] || $bound->ifTrueAssertions !== [] || $bound->ifFalseAssertions !== [] || $bound->whereConstraints !== []
            || ! $this->located($bound->location, $syntax, $proof['file']) || count($bound->parameters) !== 1 || $parameter === null
            || $parameter->name !== '$'.$proof['local'] || $parameter->attributes !== [] || $parameter->outType !== null || $parameter->closureThisType !== null
            || $parameter->defaultType !== null || $parameter->flags->contains(MetadataFlags::BY_REFERENCE)
            || $parameter->flags->contains(MetadataFlags::VARIADIC) || $parameter->flags->contains(MetadataFlags::HAS_DEFAULT)
            || ! $this->located($parameter->location, $syntax->params[0], $proof['file']) || ! $this->located($parameter->nameLocation, $syntax->params[0]->var, $proof['file'])
            || $parameter->declaredType === null || $parameter->type === null || $parameter->type->fromDocblock
            || ! $this->located($parameter->declaredType->location, $syntax->params[0]->type, $proof['file'])
            || ! $this->located($parameter->type->location, $syntax->params[0]->type, $proof['file']) || $parameter->declaredType->fromDocblock
            || ! self::clean($parameter->declaredType->type) || ! self::clean($parameter->type->type)
            || ! $types->equals($parameter->declaredType->type, Type::string()) || ! $types->equals($parameter->type->type, Type::string())) { return false; }
        $return = $syntax->returnType;
        return $return === null || $bound->declaredReturnType !== null && $bound->returnType !== null
            && $this->located($bound->declaredReturnType->location, $return, $proof['file'])
            && $this->located($bound->returnType->location, $return, $proof['file']) && ! $bound->returnType->fromDocblock && ! $bound->declaredReturnType->fromDocblock
            && $types->equals($bound->declaredReturnType->type, Type::namedObject($proof['class']))
            && $types->equals($bound->returnType->type, $bound->declaredReturnType->type);
    }

    private function constructor(FunctionLikeMetadata $bound, Node\Stmt\ClassMethod $syntax, array $entry, TypeComparator $types): bool
    {
        if ($bound->kind !== FunctionLikeKind::Method || $bound->identifier->kind !== \Mago\Sdk\Analyzer\Type\FunctionLikeKind::Method
            || strcasecmp($bound->identifier->name, '__construct') !== 0 || strcasecmp($bound->originalName, '__construct') !== 0
            || strcasecmp($bound->name, '__construct') !== 0 || ! self::sourceMetadata($bound->flags)
            || ! $bound->constructor || $bound->visibility !== Visibility::Public || ! $syntax->isPublic() || $syntax->isAbstract() || $syntax->isStatic()
            || $bound->static || $bound->abstract || $bound->flags->contains(MetadataFlags::BY_REFERENCE) || $syntax->byRef
            || $bound->declaredReturnType !== null || $bound->returnType !== null || $syntax->returnType !== null
            || $bound->templates !== [] || $bound->attributes !== [] || $bound->globalsAccessed !== []
            || ! $this->located($bound->location, $syntax, $entry['file']) || ! $this->located($bound->nameLocation, $syntax->name, $entry['file'])
            || count($syntax->params) !== count($bound->parameters) || $syntax->params === []) { return false; }
        foreach ($syntax->params as $position => $parameter) {
            $item = $bound->parameters[$position];
            $declared = self::syntaxType($parameter->type);
            if (! is_string($parameter->var->name) || $item->name !== '$'.$parameter->var->name || $parameter->byRef || $parameter->variadic
                || $item->flags->contains(MetadataFlags::BY_REFERENCE) || $item->flags->contains(MetadataFlags::VARIADIC)
                || $item->outType !== null || $item->closureThisType !== null || $item->attributes !== []
                || ! $this->located($item->location, $parameter, $entry['file']) || ! $this->located($item->nameLocation, $parameter->var, $entry['file'])
                || $declared === null || $item->declaredType === null || ! $types->equals($declared, $item->declaredType->type)
                || $item->declaredType->fromDocblock || ! self::clean($item->declaredType->type)
                || $item->flags->contains(MetadataFlags::PROMOTED_PROPERTY) !== ($parameter->flags !== 0)
                || ! $this->located($item->declaredType->location, $parameter->type, $entry['file'])
                || $item->flags->contains(MetadataFlags::HAS_DEFAULT) !== ($parameter->default !== null)
                || $parameter->default === null && $item->defaultType !== null) { return false; }
            if ($parameter->default !== null) {
                $value = self::defaultType($parameter->default);
                if ($value === null || $item->defaultType === null || $item->defaultType->fromDocblock || ! $types->equals($value, $item->defaultType->type)
                    || ! self::initializer($item->defaultType->location, $parameter->default, $entry, $this->path(...))) { return false; }
            }
        }
        return $syntax->params[0]->type instanceof Node\Identifier && strtolower($syntax->params[0]->type->name) === 'string'
            && $bound->parameters[0]->declaredType !== null && $types->equals($bound->parameters[0]->declaredType->type, Type::string());
    }

    private function declaration(?string $file, string $class): ?array
    {
        if ($file === null) { return null; }
        $disk = $this->disk($file);
        $size = @filesize($disk);
        $contents = $size !== false && $size <= 2_000_000 ? @file_get_contents($disk) : false;
        if ($contents === false) { return null; }
        $hash = hash('sha256', $contents);
        $analyzed = $this->files[$this->path($file)] ?? null;
        if ($analyzed !== null && $analyzed['hash'] !== $hash) { return null; }
        try {
            $nodes = (new NodeTraverser(new NameResolver))->traverse((new ParserFactory)->createForNewestSupportedVersion()->parse($contents) ?? []);
        } catch (\PhpParser\Error) { return null; }
        $classes = (new NodeFinder)->find($nodes, static fn (Node $node): bool => $node instanceof Node\Stmt\Class_
            && strcasecmp($node->namespacedName?->toString() ?? '', $class) === 0);
        if (count($classes) !== 1) { return null; }
        return [$classes[0], ['file' => $file, 'diskFile' => $disk, 'hash' => $hash, 'contents' => $contents]];
    }

    private static function nativeMap(Codebase $codebase, TypeComparator $types): bool
    {
        $bound = $codebase->getFunction('array_map');
        if ($bound === null || $bound->kind !== FunctionLikeKind::Function_ || $bound->identifier->kind !== \Mago\Sdk\Analyzer\Type\FunctionLikeKind::Function_
            || $bound->identifier->class !== null || $bound->identifier->name !== 'array_map' || $bound->name !== 'array_map' || $bound->originalName !== 'array_map'
            || ! $bound->flags->contains(MetadataFlags::BUILTIN) || $bound->flags->contains(MetadataFlags::USER_DEFINED) || $bound->flags->contains(MetadataFlags::BY_REFERENCE)
            || $bound->static || $bound->abstract || $bound->constructor || $bound->globalsAccessed !== [] || $bound->attributes !== []
            || $bound->location->file !== 'mago_prelude_extensions/standard.php' || $bound->nameLocation === null
            || $bound->nameLocation->file !== $bound->location->file || $bound->nameLocation->span->end - $bound->nameLocation->span->start !== 9
            || $bound->nameLocation->span->start < $bound->location->span->start || $bound->nameLocation->span->end > $bound->location->span->end
            || count($bound->parameters) !== 3 || count($bound->templates) !== 4 || $bound->declaredReturnType === null || $bound->returnType === null
            || ! $types->equals($bound->declaredReturnType->type, self::arrayType())) { return false; }
        foreach ($bound->parameters as $position => $parameter) {
            if ($parameter->name !== ['$callback', '$array', '$arrays'][$position] || ! $parameter->flags->contains(MetadataFlags::BUILTIN)
                || $parameter->flags->contains(MetadataFlags::USER_DEFINED) || $parameter->flags->contains(MetadataFlags::BY_REFERENCE)
                || $parameter->flags->contains(MetadataFlags::HAS_DEFAULT) || $parameter->flags->contains(MetadataFlags::VARIADIC) !== ($position === 2)
                || $parameter->defaultType !== null || $parameter->outType !== null || $parameter->closureThisType !== null || $parameter->attributes !== []
                || $parameter->declaredType === null || $parameter->type === null
                || $parameter->location->file !== $bound->location->file || $parameter->nameLocation->file !== $bound->location->file
                || $parameter->location->span->start < $bound->location->span->start || $parameter->location->span->end > $bound->location->span->end
                || $parameter->nameLocation->span->start < $parameter->location->span->start || $parameter->nameLocation->span->end !== $parameter->location->span->end
                || $parameter->nameLocation->span->end - $parameter->nameLocation->span->start !== strlen($parameter->name)) { return false; }
            if ($position === 0) {
                $atoms = $parameter->declaredType->type->atomicTypes;
                if (count($atoms) !== 2 || ! self::nullableCallable($parameter->declaredType->type, $types)) { return false; }
            } elseif (! $types->equals($parameter->declaredType->type, self::arrayType())) { return false; }
        }
        foreach ($bound->templates as $position => $template) {
            if ($template->name !== ['K', 'V', 'S', 'U'][$position] || $template->default !== null || $template->readonly
                || $template->variance !== \Mago\Sdk\Analyzer\Type\Variance::Invariant
                || $template->definingEntity->kind !== GenericParentKind::FunctionLike || $template->definingEntity->name !== '' || $template->definingEntity->member !== 'array_map'
                || ! $types->equals($template->constraint, $position === 0 ? self::arrayKey() : Type::mixed())) { return false; }
        }
        $array = $bound->parameters[1]->type->type->atomicTypes;
        return count($array) === 1 && $array[0] instanceof KeyedArrayType && $array[0]->knownItems === null && ! $array[0]->nonEmpty
            && self::generic($array[0]->keyType, 'K', $types) && self::generic($array[0]->valueType, 'V', $types)
            && self::nativeCallback($bound->parameters[0]->type->type, $types)
            && self::genericArray($bound->parameters[2]->type->type, null, 'S', $types)
            && self::nativeReturn($bound->returnType->type, $types);
    }

    private static function nativeCallback(Type $type, TypeComparator $types): bool
    {
        if (count($type->atomicTypes) !== 3) { return false; }
        $null = 0; $counts = [];
        foreach ($type->atomicTypes as $atom) {
            if ($atom instanceof \Mago\Sdk\Analyzer\Type\SimpleAtomicType && $atom->kind === \Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind::Null) { ++$null; continue; }
            if (! $atom instanceof CallableType || $atom->alias !== null || $atom->signature === null || $atom->signature->closure || $atom->signature->pure
                || $atom->signature->source !== null || $atom->signature->constraints !== [] || ! self::generic($atom->signature->returnType, 'U', $types)) { return false; }
            $count = count($atom->signature->parameters);
            if (! in_array($count, [1, 2], true) || isset($counts[$count])) { return false; }
            $counts[$count] = true;
            foreach ($atom->signature->parameters as $position => $parameter) {
                if ($parameter->name !== null || $parameter->byReference || $parameter->variadic || $parameter->hasDefault || $parameter->closureThisType !== null
                    || ! self::generic($parameter->type, $position === 0 ? 'V' : 'S', $types)) { return false; }
            }
        }
        return $null === 1 && count($counts) === 2;
    }

    private static function genericArray(Type $type, ?string $key, string $value, TypeComparator $types, bool $nonEmpty = false): bool
    {
        $atoms = $type->atomicTypes;
        return count($atoms) === 1 && $atoms[0] instanceof KeyedArrayType && $atoms[0]->knownItems === null && $atoms[0]->nonEmpty === $nonEmpty
            && ($key === null ? $atoms[0]->keyType !== null && $types->equals($atoms[0]->keyType, self::arrayKey()) : self::generic($atoms[0]->keyType, $key, $types))
            && self::generic($atoms[0]->valueType, $value, $types);
    }

    private static function nativeReturn(Type $type, TypeComparator $types): bool
    {
        $top = self::conditional($type);
        if ($top === null || ! self::genericList($top->target, 'V', false, $types)) { return false; }
        $list = self::conditional($top->then);
        $array = self::conditional($top->otherwise);
        return $list !== null && $array !== null && self::genericList($list->target, 'V', true, $types)
            && self::genericList($list->then, 'U', true, $types) && self::genericList($list->otherwise, 'U', false, $types)
            && self::genericArray($array->target, 'K', 'V', $types, true) && self::genericArray($array->then, 'K', 'U', $types, true)
            && self::genericArray($array->otherwise, 'K', 'U', $types);
    }

    private static function conditional(Type $type): ?\Mago\Sdk\Analyzer\Type\ConditionalType
    {
        $atom = $type->atomicTypes[0] ?? null;
        if (count($type->atomicTypes) !== 1 || ! $atom instanceof \Mago\Sdk\Analyzer\Type\ConditionalType || $atom->negated
            || count($atom->subject->atomicTypes) !== 1 || ! $atom->subject->atomicTypes[0] instanceof \Mago\Sdk\Analyzer\Type\VariableType
            || $atom->subject->atomicTypes[0]->name !== '$array') { return null; }
        return $atom;
    }

    private static function genericList(Type $type, string $value, bool $nonEmpty, TypeComparator $types): bool
    {
        $atom = $type->atomicTypes[0] ?? null;
        return count($type->atomicTypes) === 1 && $atom instanceof ListType && $atom->knownElements === null && $atom->knownCount === null
            && $atom->nonEmpty === $nonEmpty && self::generic($atom->elementType, $value, $types);
    }

    private static function generic(?Type $type, string $name, TypeComparator $types): bool
    {
        $atoms = $type?->atomicTypes ?? [];
        return count($atoms) === 1 && $atoms[0] instanceof GenericParameterType && $atoms[0]->name === $name && $atoms[0]->intersections === null
            && $atoms[0]->definingEntity->kind === GenericParentKind::FunctionLike && $atoms[0]->definingEntity->name === ''
            && $atoms[0]->definingEntity->member === 'array_map' && $types->equals($atoms[0]->constraint, $name === 'K' ? self::arrayKey() : Type::mixed());
    }

    private static function nullableCallable(Type $type, TypeComparator $types): bool
    {
        $null = $callable = 0;
        foreach ($type->atomicTypes as $atom) {
            if ($atom instanceof \Mago\Sdk\Analyzer\Type\SimpleAtomicType && $atom->kind === \Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind::Null) { ++$null; }
            elseif ($atom instanceof CallableType && $atom->alias === null && $atom->signature !== null && ! $atom->signature->closure && ! $atom->signature->pure
                && count($atom->signature->parameters) === 1 && $atom->signature->returnType !== null && $types->equals($atom->signature->returnType, Type::mixed())
                && $atom->signature->source === null && $atom->signature->constraints === []) {
                $parameter = $atom->signature->parameters[0];
                if ($parameter->name !== null || $parameter->type === null || ! $types->equals($parameter->type, Type::mixed())
                    || $parameter->closureThisType !== null || $parameter->byReference || ! $parameter->variadic || ! $parameter->hasDefault) { return false; }
                ++$callable;
            }
            else { return false; }
        }
        return $null === 1 && $callable === 1;
    }

    /** An observed engine coordinate is a fallible lookup key, never an inferred callback contract. */
    public static function callbackIdentifier(array $proof, string $file): \Mago\Sdk\Analyzer\Type\FunctionLikeIdentifier
    {
        $offset = $proof['callback']->getStartFilePos();
        $prefix = substr($proof['contents'], 0, $offset);
        $newline = strrpos($prefix, "\n");
        $column = $offset - ($newline === false ? -1 : $newline);
        return new \Mago\Sdk\Analyzer\Type\FunctionLikeIdentifier(\Mago\Sdk\Analyzer\Type\FunctionLikeKind::Closure,
            '{closure:'.$file.':'.$proof['callback']->getStartLine().':'.$column.'}');
    }

    private static function documented(Node\Stmt\ClassMethod $method, string $name, string $type): bool
    {
        $found = false;
        foreach ($method->getComments() as $comment) {
            preg_match_all('/@(?:phpstan-|psalm-)?param\b([^\r\n@]*)/i', $comment->getText(), $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                if (preg_match('/^\s*(.*?)\s+(\$[A-Za-z_][A-Za-z0-9_]*)\b/', $match[1], $parameter) !== 1) { return false; }
                if ($parameter[2] === $name) { if (trim($parameter[1]) !== $type) { return false; } $found = true; }
            }
        }
        return $found;
    }

    private static function binding(array $arguments, FunctionLikeMetadata $method): bool
    {
        $seen = [];
        $named = false;
        foreach ($arguments as $position => $argument) {
            if ($argument->name === null) {
                if ($named || ! isset($method->parameters[$position])) { return false; }
                $index = $position;
            } else {
                $named = true;
                $index = null;
                foreach ($method->parameters as $candidate => $parameter) {
                    if ($parameter->name === '$'.$argument->name->name) { $index = $candidate; break; }
                }
                if ($index === null) { return false; }
            }
            if (isset($seen[$index])) { return false; }
            $seen[$index] = true;
        }
        foreach ($method->parameters as $index => $parameter) {
            if (! isset($seen[$index]) && ! $parameter->flags->contains(MetadataFlags::HAS_DEFAULT)) { return false; }
        }
        return true;
    }

    private static function docAnchor(SourceLocation $location, Node\Stmt\ClassMethod $method, string $contents, string $type): bool
    {
        if (substr($contents, $location->span->start, $location->span->end - $location->span->start) !== $type) { return false; }
        foreach ($method->getComments() as $comment) {
            if ($comment->getStartFilePos() <= $location->span->start && $comment->getEndFilePos() + 1 >= $location->span->end) { return true; }
        }
        return false;
    }

    private static function strongDocs(Node $node): bool
    {
        foreach ($node->getComments() as $comment) {
            if (preg_match('/@(?:phpstan-|psalm-)?(?:param|return|var|template)\b/i', $comment->getText()) === 1
                && ! $node instanceof Node\Stmt\Function_ && ! $node instanceof Node\Stmt\ClassMethod && ! $node instanceof Node\Stmt\ClassLike) { return true; }
        }
        return false;
    }

    private static function syntaxType(?Node $node): ?Type
    {
        if ($node instanceof Node\UnionType) {
            $result = null;
            foreach ($node->types as $part) { $type = self::syntaxType($part); if ($type === null) { return null; } $result = $result === null ? $type : Type::union($result, $type); }
            return $result;
        }
        if ($node instanceof Node\Identifier && strtolower($node->name) === 'iterable') { return Type::fromAtomic(new IterableType(Type::mixed(), Type::mixed(), null)); }
        return DirectCallbackReferenceEffects::syntaxType($node, '');
    }

    private static function defaultType(Node $node): ?Type
    {
        if ($node instanceof Node\Expr\Array_ && $node->items === []) { return Type::fromAtomic(new ListType(Type::never(), [], 0, false)); }
        $value = PhpSource::value($node);
        return match (true) { is_string($value) => Type::literalString($value), is_int($value) => Type::literalInt($value), is_bool($value) => $value ? Type::true() : Type::false(), $value === null => Type::null(), default => null };
    }

    private static function initializer(?SourceLocation $location, Node $node, array $entry, \Closure $path): bool
    {
        if ($location === null || $path($location->file ?? '') !== $path($entry['file']) || $location->span->end !== $node->getEndFilePos() + 1
            || $location->span->start > $node->getStartFilePos()) { return false; }
        $prefix = substr($entry['contents'], $location->span->start, $node->getStartFilePos() - $location->span->start);
        return $prefix === '' || preg_match('/^=\s*$/D', $prefix) === 1;
    }

    private static function sourceMetadata(MetadataFlags $flags): bool
    {
        return ! $flags->contains(MetadataFlags::BUILTIN)
            && ($flags->contains(MetadataFlags::USER_DEFINED) || $flags->contains(self::INCLUDED_SOURCE));
    }
    private static function clean(Type $type): bool
    {
        return ! $type->flags->byReference && ! $type->flags->possiblyUndefined && ! $type->flags->possiblyUndefinedFromTry
            && ! $type->flags->ignoreNullableIssues && ! $type->flags->ignoreFalsableIssues && ! $type->flags->nullsafeNull;
    }

    private static function arrayKey(): Type { return Type::fromAtomic(new ScalarType(ScalarTypeKind::ArrayKey)); }
    private static function arrayType(): Type { return Type::array(self::arrayKey(), Type::mixed()); }
    private static function key(Node $node): string { return $node->getStartFilePos().':'.($node->getEndFilePos() + 1); }
    private static function current(array $entry): bool { return RefreshedModelProperties::current($entry); }
    private function located(?SourceLocation $location, ?Node $node, string $file): bool
    {
        return $location !== null && $node !== null && $this->path($location->file ?? '') === $this->path($file)
            && $location->span->start === $node->getStartFilePos() && $location->span->end === $node->getEndFilePos() + 1;
    }
    private function path(string $file): string
    {
        $disk = $this->disk($file);
        return RefreshedModelProperties::path(realpath($disk) ?: $disk);
    }
    private function disk(string $file): string
    {
        $file = str_replace('\\', '/', $file);
        if (str_starts_with($file, '//?/')) { $file = substr($file, 4); }
        return str_starts_with($file, '/') || preg_match('~^[a-z]:/~i', $file) === 1 ? $file : rtrim(str_replace('\\', '/', $this->root), '/').'/'.$file;
    }
}
