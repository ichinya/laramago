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
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\TypeComparator;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** Analyzed source identities for directly invoked lexical reference effects. */
final class StoredCallbackSourceDeclarations implements InitializationHook, CodebaseScanHook
{
    private const RESERVED = ['GLOBALS', '_GET', '_POST', '_COOKIE', '_REQUEST', '_SERVER', '_ENV', '_FILES', '_SESSION', 'http_response_header', 'argv', 'argc'];
    private array $files = [];
    private array $classes = [];
    private array $hydrated = [];
    private int $hydratedBytes = 0;
    private array $checked = [];
    private array $hierarchyDeclarations = [];
    private int $hierarchyBytes = 0;
    private array $assertionDeclarations = [];
    private bool $complete = false;
    private bool $failed = false;
    private int $bytes = 0;
    private readonly NativeModelAttributeRefresh $assertions;

    public function __construct(private readonly string $root = '.')
    {
        $this->assertions = new NativeModelAttributeRefresh($root);
    }

    public function initialize(InitializationContext $context): void
    {
        $this->files = $this->classes = $this->checked = $this->hydrated = []; $this->hydratedBytes = 0;
        $this->complete = $this->failed = false;
        $this->bytes = $this->hierarchyBytes = 0;
        $this->hierarchyDeclarations = [];
    }

    public function getTargets(): array { return ['**']; }

    public function scan(CodebaseScanContext $context): void
    {
        if ($context->firstBatch) { $this->files = $this->classes = $this->checked = $this->hierarchyDeclarations = $this->hydrated = []; $this->hydratedBytes = 0; $this->failed = false; $this->bytes = $this->hierarchyBytes = 0; }
        $this->complete = false;
        foreach ($context->files as $file) {
            try { $context->cancellation->throwIfCancelled(); }
            catch (\Throwable $interrupted) { $this->failed = true; $this->files = $this->classes = []; throw $interrupted; }
            $path = RefreshedModelProperties::path($file->path);
            $this->bytes += strlen($file->contents);
            if ($this->failed || isset($this->files[$path]) || strlen($file->contents) > 2_000_000 || count($this->files) >= 100_000 || $this->bytes > 67_108_864) { $this->failed = true; break; }
            try {
                $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($file->contents) ?? [];
                $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes);
            } catch (\PhpParser\Error) { $this->failed = true; break; }
            $entry = ['file' => $file->path, 'diskFile' => $this->disk($file->path), 'hash' => hash('sha256', $file->contents), 'proofs' => []];
            $this->files[$path] = $entry;
            if ((new NodeFinder)->findFirst($nodes, static fn (Node $node): bool => $node instanceof Node\DeclareItem && strtolower($node->key->name) === 'ticks') !== null) { continue; }
            foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Stmt\ClassLike::class) as $class) {
                if ($class->name === null || $class->namespacedName === null) { continue; }
                $name = strtolower($class->namespacedName->toString());
                if (isset($this->classes[$name])) { $this->failed = true; break 2; }
                $this->classes[$name] = ['name' => $class->namespacedName->toString(), 'kind' => $class->getType(),
                    'start' => $class->getStartFilePos(), 'end' => $class->getEndFilePos() + 1, 'entry' => $entry];
            }
            $this->files[$path] = $entry;
        }
        if ($this->failed) { $this->files = $this->classes = []; }
        $this->complete = $context->lastBatch && ! $this->failed;
    }

    public function proofs(string $file, string $contents): array
    {
        $entry = $this->complete ? ($this->files[RefreshedModelProperties::path($file)] ?? null) : null;
        return $entry !== null && hash('sha256', $contents) === $entry['hash'] ? $entry['proofs'] : [];
    }

    /** Only final values on supported normal continuations contribute to this domain. */
    

    /** Every consumed user body must come from a complete analyzed source snapshot. */
    public function method(Codebase $codebase, TypeComparator $types, string $class, string $name): ?array
    {
        $bound = $codebase->getMethod($class, $name);
        if ($bound === null || $bound->identifier->class === null) { return null; }
        $owner = $bound->identifier->class;
        $entry = $this->declaration($owner);
        if ($entry === null) { return null; }
        $syntax = $entry['node']->getMethod($name);
        $metadata = $codebase->getClassLike($owner);
        if ($entry === null || $syntax === null || $syntax->stmts === null || ! self::closedScope($syntax) || $metadata === null
            || ! $this->classMatches($codebase, $class) || ! $this->classMatches($codebase, $owner) || ! self::current($entry['entry'])
            || strcasecmp($owner, $class) !== 0 && ! in_array(strtolower($owner), array_map('strtolower', $codebase->getClassAncestors($class)), true)
            || $bound->kind !== FunctionLikeKind::Method || $bound->identifier->kind !== \Mago\Sdk\Analyzer\Type\FunctionLikeKind::Method
            || strcasecmp($bound->identifier->name, $syntax->name->name) !== 0 || strcasecmp($bound->originalName, $syntax->name->name) !== 0
            || $bound->static !== $syntax->isStatic() || $bound->abstract !== $syntax->isAbstract() || $bound->final !== $syntax->isFinal()
            || $bound->flags->contains(MetadataFlags::BY_REFERENCE) !== $syntax->byRef || $syntax->byRef || $bound->templates !== [] || $bound->globalsAccessed !== []
            || ! self::located($bound->location, $syntax, $entry['entry']['file'])
            || ! self::located($bound->nameLocation, $syntax->name, $entry['entry']['file']) || count($bound->parameters) !== count($syntax->params)
            || $bound->visibility !== ( $syntax->isPrivate() ? \Mago\Sdk\Analyzer\Type\Visibility::Private : ($syntax->isProtected() ? \Mago\Sdk\Analyzer\Type\Visibility::Protected : \Mago\Sdk\Analyzer\Type\Visibility::Public))) { return null; }
        $declared = self::syntaxType($syntax->returnType, $owner);
        if ($syntax->returnType === null && $bound->returnType?->fromDocblock) { return null; }
        if ($declared !== null && ($bound->declaredReturnType === null || ! $types->equals($declared, $bound->declaredReturnType->type)
            || $bound->returnType === null || ! $types->equals($declared, $bound->returnType->type))) { return null; }
        foreach ($syntax->params as $position => $parameter) {
            $item = $bound->parameters[$position];
            $type = self::syntaxType($parameter->type, $owner);
            if ($parameter->type === null && $item->type?->fromDocblock) { return null; }
            if (! is_string($parameter->var->name) || $item->name !== '$'.$parameter->var->name || $parameter->variadic || $item->type !== null && (string) $item->type->type === 'never'
                || $item->flags->contains(MetadataFlags::BY_REFERENCE) !== $parameter->byRef || $item->flags->contains(MetadataFlags::VARIADIC)
                || ! self::located($item->location, $parameter, $entry['entry']['file']) || ! self::located($item->nameLocation, $parameter->var, $entry['entry']['file'])
                || $type !== null && ($item->declaredType === null || ! $types->equals($type, $item->declaredType->type))
                || $item->flags->contains(MetadataFlags::HAS_DEFAULT) !== ($parameter->default !== null)) { return null; }
            if ($parameter->default !== null) {
                $value = PhpSource::value($parameter->default);
                $literal = self::literalType($value);
                if ($literal === null || $item->defaultType === null || ! self::defaultLocated($item->defaultType->location, $parameter, $entry['entry'])
                    || ! $types->equals($literal, $item->defaultType->type)) { return null; }
            } elseif ($item->defaultType !== null) { return null; }
            if ($type !== null && $item->type !== null && ! $parameter->type instanceof Node\Identifier && ! $types->equals($item->type->type, $type)) { return null; }
            if ($parameter->type instanceof Node\Identifier && strtolower($parameter->type->name) !== 'array'
                && $item->declaredType !== null && ($item->type === null || ! $types->equals($item->type->type, $item->declaredType->type))) { return null; }
        }
        $this->checked[RefreshedModelProperties::path($entry['entry']['file'])] = $entry['entry'];
        return ['node' => $syntax, 'owner' => $owner, 'class' => $entry['node'], 'metadata' => $bound, 'entry' => $entry['entry']];
    }

    public function finalClass(Codebase $codebase, string $name): bool
    {
        $entry = $this->declaration($name);
        $metadata = $codebase->getClass($name);
        return $entry !== null && $entry['node'] instanceof Node\Stmt\Class_ && $entry['node']->isFinal()
            && $metadata !== null && $metadata->kind === ClassLikeKind::Class_ && $metadata->flags->contains(MetadataFlags::FINAL)
            && $this->classMatches($codebase, $name) && self::current($entry['entry']);
    }

    /** Expose only a current complete analyzed declaration to finite constructor plans. */
    public function classSource(Codebase $codebase, string $name): ?array
    {
        $entry = $this->declaration($name);
        $metadata = $codebase->getClassLike($name);
        if ($entry === null || $metadata === null || ! $this->classMatches($codebase, $name) || ! self::current($entry['entry'])) { return null; }
        return ['node' => $entry['node'], 'metadata' => $metadata, 'entry' => $entry['entry']];
    }

    /** Bodyless reflected field facts must agree with any original analyzed snapshot. */
    

    public function nativeClosure(Codebase $codebase): bool
    {
        $class = $codebase->getClass('Closure');
        $method = $codebase->getMethod('Closure', 'fromCallable');
        return $class !== null && $class->kind === ClassLikeKind::Class_ && strcasecmp($class->name, 'Closure') === 0
            && ! $class->hasIncompleteHierarchy() && $class->templates === [] && $class->mixins === []
            && $class->flags->contains(MetadataFlags::BUILTIN) && ! $class->flags->contains(MetadataFlags::USER_DEFINED)
            && $method !== null && strcasecmp($method->identifier->class ?? '', 'Closure') === 0
            && $method->kind === FunctionLikeKind::Method && $method->identifier->kind === \Mago\Sdk\Analyzer\Type\FunctionLikeKind::Method
            && strcasecmp($method->identifier->name, 'fromCallable') === 0 && strcasecmp($method->originalName, 'fromCallable') === 0
            && $method->flags->contains(MetadataFlags::BUILTIN) && ! $method->flags->contains(MetadataFlags::USER_DEFINED)
            && ! $method->flags->contains(MetadataFlags::BY_REFERENCE) && $method->static && ! $method->abstract
            && $method->visibility === \Mago\Sdk\Analyzer\Type\Visibility::Public && count($method->parameters) === 1
            && ! $method->parameters[0]->flags->contains(MetadataFlags::BY_REFERENCE) && ! $method->parameters[0]->flags->contains(MetadataFlags::VARIADIC)
            && ! $method->parameters[0]->flags->contains(MetadataFlags::HAS_DEFAULT) && $method->parameters[0]->name === '$callback'
            && $method->parameters[0]->type !== null && self::genericCallable($method->parameters[0]->type->type, false)
            && $method->parameters[0]->declaredType !== null && self::genericCallable($method->parameters[0]->declaredType->type, false)
            && $method->parameters[0]->defaultType === null && $method->templates === []
            && $method->returnType !== null && self::genericCallable($method->returnType->type, true)
            && $method->declaredReturnType !== null && self::genericCallable($method->declaredReturnType->type, true);
    }

    

    /** Empty analyzed constructors and the fixed native exception signature are a closed initial batch. */
    public function construction(Codebase $codebase, TypeComparator $types, string $name): ?array
    {
        $class = $codebase->getClass($name);
        if ($class === null || $class->kind !== ClassLikeKind::Class_ || $class->hasIncompleteHierarchy() || $class->templates !== [] || $class->mixins !== []) { return null; }
        $constructor = $codebase->getDeclaringMethod($name, '__construct');
        if ($class->flags->contains(MetadataFlags::BUILTIN) && ! $class->flags->contains(MetadataFlags::USER_DEFINED)
            && in_array(strtolower($name), ['exception', 'runtimeexception'], true)) {
            $exception = $codebase->getClass('Exception');
            if ($exception === null || $exception->kind !== ClassLikeKind::Class_ || strcasecmp($exception->name, 'Exception') !== 0
                || $exception->hasIncompleteHierarchy() || ! $exception->flags->contains(MetadataFlags::BUILTIN)
                || $exception->flags->contains(MetadataFlags::USER_DEFINED) || $exception->templates !== [] || $exception->mixins !== []
                || $exception->directParentClass !== null || $exception->parentClasses !== []
                || strcasecmp($class->name, $name) !== 0 || strcasecmp($class->originalName, $name) !== 0
                || strtolower($name) === 'runtimeexception' && (strcasecmp($class->directParentClass ?? '', 'Exception') !== 0
                    || ! self::sameNames($class->parentClasses, ['Exception']))) { return null; }
            foreach ([$name, ...$codebase->getClassAncestors($name)] as $ancestor) {
                $metadata = $codebase->getClassLike($ancestor);
                if ($metadata === null || $metadata->hasIncompleteHierarchy() || strcasecmp($metadata->name, $ancestor) !== 0
                    || ! $metadata->flags->contains(MetadataFlags::BUILTIN) || $metadata->flags->contains(MetadataFlags::USER_DEFINED)) { return null; }
            }
            if ($constructor === null || ! $constructor->flags->contains(MetadataFlags::BUILTIN) || $constructor->flags->contains(MetadataFlags::USER_DEFINED)
                || $constructor->kind !== FunctionLikeKind::Method || $constructor->identifier->kind !== \Mago\Sdk\Analyzer\Type\FunctionLikeKind::Method
                || strcasecmp($constructor->identifier->class ?? '', 'Exception') !== 0 || strcasecmp($constructor->identifier->name, '__construct') !== 0
                || strcasecmp($constructor->originalName, '__construct') !== 0 || $constructor->static || $constructor->abstract
                || $constructor->visibility !== \Mago\Sdk\Analyzer\Type\Visibility::Public || $constructor->flags->contains(MetadataFlags::BY_REFERENCE)
                || count($constructor->parameters) !== 3 || $constructor->templates !== []
                || $constructor->declaredReturnType !== null || $constructor->returnType !== null) { return null; }
            $parameters = [new Node\Param(new Node\Expr\Variable('message'), new Node\Scalar\String_(''), new Node\Identifier('string')),
                new Node\Param(new Node\Expr\Variable('code'), new Node\Scalar\Int_(0), new Node\Identifier('int')),
                new Node\Param(new Node\Expr\Variable('previous'), new Node\Expr\ConstFetch(new Node\Name('null')), new Node\NullableType(new Node\Name('Throwable')))];
            foreach ($parameters as $position => $parameter) {
                $metadata = $constructor->parameters[$position];
                $type = self::syntaxType($parameter->type, 'Exception');
                $default = $position === 0 ? Type::literalString('') : ($position === 1 ? Type::literalInt(0) : Type::null());
                if ($metadata->name !== '$'.$parameter->var->name || $metadata->flags->contains(MetadataFlags::BY_REFERENCE)
                    || $metadata->flags->contains(MetadataFlags::VARIADIC) || ! $metadata->flags->contains(MetadataFlags::HAS_DEFAULT)
                    || $metadata->type === null || $type === null || ! $types->equals($metadata->type->type, $type)
                    || $metadata->declaredType === null || ! $types->equals($metadata->declaredType->type, $type)
                    || $metadata->defaultType === null || ! $types->equals($metadata->defaultType->type, $default)) { return null; }
            }
            return ['parameters' => $parameters];
        }
        return null; // Stored reference guards support only the observed builtin Exception/RuntimeException constructors.
    }

    /** The SDK represents native Closure as the unrestricted closure callable signature. */
    public static function genericCallable(Type $type, bool $closure): bool
    {
        $atoms = $type->atomicTypes;
        $atom = count($atoms) === 1 ? $atoms[0] : null;
        if (! $atom instanceof \Mago\Sdk\Analyzer\Type\CallableType || $atom->alias !== null || $atom->signature === null) { return false; }
        $signature = $atom->signature;
        $parameter = count($signature->parameters) === 1 ? $signature->parameters[0] : null;
        return $signature->closure === $closure && ! $signature->pure && $signature->source === null && $signature->constraints === []
            && $signature->returnType !== null && (string) $signature->returnType === 'mixed'
            && $parameter !== null && $parameter->name === null && $parameter->type !== null && (string) $parameter->type === 'mixed'
            && $parameter->closureThisType === null && ! $parameter->byReference && $parameter->variadic && $parameter->hasDefault;
    }

    /** Effective native traits include the complete parent and nested-trait closure. */
    private function classMatches(Codebase $codebase, string $name): bool
    {
        $memo = $active = [];
        $visited = 0;
        $ancestors = $codebase->getClassAncestors($name);
        if (count($ancestors) > 128) { return false; }
        foreach ([$name, ...$ancestors] as $owner) {
            if ($this->effectiveTraits($codebase, $owner, $memo, $active, $visited) === null) { return false; }
        }
        return true;
    }

    /**
     * Declarations outside the analyzed index certify hierarchy only, never bodies.
     * Every consumed body still passes method() against the analyzed source index.
     *
     * @return array{traits: list<string>, parents: list<string>}|null
     */
    private function effectiveTraits(Codebase $codebase, string $owner, array &$memo, array &$active, int &$visited): ?array
    {
        $key = strtolower($owner);
        if (isset($memo[$key])) { return $memo[$key]; }
        if ($key === '' || isset($active[$key]) || count($active) >= 32 || ++$visited > 128) { return null; }
        $metadata = $codebase->getClassLike($owner);
        if ($metadata === null || $metadata->hasIncompleteHierarchy() || strcasecmp($metadata->name, $owner) !== 0
            || strcasecmp($metadata->originalName, $owner) !== 0 || $metadata->templates !== [] || $metadata->mixins !== []) { return null; }
        $entry = $this->hierarchyDeclaration($metadata);
        if ($entry === null || ! self::current($entry['entry'])) { return null; }
        $node = $entry['node'];
        $kind = $node instanceof Node\Stmt\Class_ ? ClassLikeKind::Class_
            : ($node instanceof Node\Stmt\Trait_ ? ClassLikeKind::Trait
                : ($node instanceof Node\Stmt\Interface_ ? ClassLikeKind::Interface : null));
        if ($kind === null || $metadata->kind !== $kind || $node->name === null || $node->namespacedName === null
            || strcasecmp($node->namespacedName->toString(), $owner) !== 0
            || ! self::located($metadata->location, $node, $entry['entry']['file'])
            || ! self::located($metadata->nameLocation, $node->name, $entry['entry']['file'])) { return null; }
        if ($node instanceof Node\Stmt\Class_ && ($metadata->flags->contains(MetadataFlags::FINAL) !== $node->isFinal()
            || $metadata->flags->contains(MetadataFlags::ABSTRACT) !== $node->isAbstract()
            || $metadata->flags->contains(MetadataFlags::READONLY) !== $node->isReadonly()
            || strcasecmp($metadata->directParentClass ?? '', $node->extends?->toString() ?? '') !== 0)) { return null; }
        if (! $node instanceof Node\Stmt\Class_ && $metadata->directParentClass !== null) { return null; }
        $interfaces = $node instanceof Node\Stmt\Class_ ? $node->implements : ($node instanceof Node\Stmt\Interface_ ? $node->extends : []);
        $directInterfaces = array_map(static fn (Node\Name $name): string => $name->toString(), $interfaces);
        if (! self::sameNames($directInterfaces, $metadata->directParentInterfaces)) { return null; }
        $directTraits = [];
        foreach ($node->stmts as $statement) {
            if (! $statement instanceof Node\Stmt\TraitUse) { continue; }
            if ($statement->adaptations !== []) { return null; }
            foreach ($statement->traits as $trait) { $directTraits[] = $trait->toString(); }
        }
        if (count($directTraits) !== count(array_unique(array_map('strtolower', $directTraits)))) { return null; }
        $active[$key] = true;
        try {
            $traits = $parents = [];
            if ($metadata->directParentClass !== null) {
                $parent = $codebase->getClassLike($metadata->directParentClass);
                if ($parent === null || $parent->kind !== ClassLikeKind::Class_) { return null; }
                $inherited = $this->effectiveTraits($codebase, $metadata->directParentClass, $memo, $active, $visited);
                if ($inherited === null) { return null; }
                $traits = $inherited['traits'];
                $parents = [$metadata->directParentClass, ...$inherited['parents']];
            }
            foreach ($directInterfaces as $interface) {
                $parent = $codebase->getClassLike($interface);
                if ($parent === null || $parent->kind !== ClassLikeKind::Interface
                    || $this->effectiveTraits($codebase, $interface, $memo, $active, $visited) === null) { return null; }
            }
            foreach ($directTraits as $trait) {
                $used = $codebase->getClassLike($trait);
                if ($used === null || $used->kind !== ClassLikeKind::Trait) { return null; }
                $nested = $this->effectiveTraits($codebase, $trait, $memo, $active, $visited);
                if ($nested === null) { return null; }
                array_push($traits, $trait, ...$nested['traits']);
            }
            $traits = array_values(array_unique(array_map('strtolower', $traits)));
            // Native lists are checked without deduplication: unexpected duplicates defer.
            if (! self::sameNames($traits, $metadata->usedTraits) || ! self::sameNames($parents, $metadata->parentClasses)) { return null; }
            $this->checked[RefreshedModelProperties::path($entry['entry']['file'])] = $entry['entry'];
            return $memo[$key] = ['traits' => $traits, 'parents' => $parents];
        } finally { unset($active[$key]); }
    }

    /** Current native-located external syntax never becomes an analyzed body provider. */
    private function hierarchyDeclaration(\Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata $metadata): ?array
    {
        $key = strtolower($metadata->name);
        if (isset($this->classes[$key])) { return $this->declaration($key); }
        if (isset($this->hierarchyDeclarations[$key])) {
            $entry = $this->hierarchyDeclarations[$key];
            return self::current($entry['entry']) ? $entry : null;
        }
        if ($metadata->flags->contains(MetadataFlags::BUILTIN) || $metadata->location->file === null
            || count($this->hierarchyDeclarations) >= 128) { return null; }
        $file = $metadata->location->file;
        $disk = $this->disk($file);
        $size = @filesize($disk);
        if ($size === false || $size > 2_000_000 || $size < 0 || $this->hierarchyBytes + $size > 8_388_608) { return null; }
        $contents = @file_get_contents($disk);
        if ($contents === false || strlen($contents) > 2_000_000 || $this->hierarchyBytes + strlen($contents) > 8_388_608) { return null; }
        try {
            $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($contents) ?? [];
            $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes);
        } catch (\PhpParser\Error) { return null; }
        // Only unconditional top-level declarations (including namespace blocks).
        $pending = $nodes;
        $matches = [];
        while ($pending !== []) {
            $node = array_shift($pending);
            if ($node instanceof Node\Stmt\Namespace_) { array_push($pending, ...$node->stmts); continue; }
            if ($node instanceof Node\Stmt\ClassLike && $node->namespacedName !== null
                && strcasecmp($node->namespacedName->toString(), $metadata->name) === 0) { $matches[] = $node; }
        }
        if (count($matches) !== 1) { return null; }
                // External ancestry consumes header/trait declarations only; no external method body is retained or indexed.
        $header = clone $matches[0];
        $header->stmts = array_values(array_filter($header->stmts, static fn (Node $node): bool => $node instanceof Node\Stmt\TraitUse));
        $entry = ['node' => $header, 'entry' => ['file' => $file, 'diskFile' => $disk, 'hash' => hash('sha256', $contents)]];
        if (! self::current($entry['entry'])) { return null; }
        $this->hierarchyBytes += strlen($contents);
        return $this->hierarchyDeclarations[$key] = $entry;
    }

    private static function sameNames(array $left, array $right): bool
    {
        $left = array_map('strtolower', $left); $right = array_map('strtolower', $right);
        sort($left); sort($right);
        return $left === $right;
    }

    public static function located(?\Mago\Sdk\SourceLocation $location, Node $node, string $file): bool
    {
        if ($location === null || RefreshedModelProperties::path($location->file ?? '') !== RefreshedModelProperties::path($file)
            || $location->span->end !== $node->getEndFilePos() + 1) { return false; }
        if ($location->span->start === $node->getStartFilePos()) { return true; }
        foreach ($node->getComments() as $comment) { if ($location->span->start === $comment->getStartFilePos()) { return true; } }
        return false;
    }

    /** Default metadata may cover the initializer's equals token in addition to its literal. */
    private static function defaultLocated(?\Mago\Sdk\SourceLocation $location, Node\Param $parameter, array $entry): bool
    {
        $expression = $parameter->default;
        if ($expression === null || $location === null) { return false; }
        if (self::located($location, $expression, $entry['file'])) { return true; }
        if (RefreshedModelProperties::path($location->file ?? '') !== RefreshedModelProperties::path($entry['file'])
            || $location->span->end !== $expression->getEndFilePos() + 1
            || $location->span->start <= $parameter->var->getEndFilePos()
            || $location->span->start >= $expression->getStartFilePos()
            || $expression->getStartFilePos() - $location->span->start > 8192) { return false; }
        $contents = @file_get_contents($entry['diskFile']);
        if ($contents === false || hash('sha256', $contents) !== $entry['hash']) { return false; }
        $prefix = substr($contents, $location->span->start, $expression->getStartFilePos() - $location->span->start);
        $equals = 0;
        foreach (token_get_all('<?php '.$prefix) as $lexeme) {
            if (is_array($lexeme) && in_array($lexeme[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { continue; }
            if ($lexeme !== '=' || ++$equals !== 1) { return false; }
        }
        return $equals === 1;
    }

    public static function syntaxType(?Node $node, string $owner): ?Type
    {
        if ($node instanceof Node\NullableType) {
            $inner = self::syntaxType($node->type, $owner);
            return $inner === null ? null : Type::union($inner, Type::null());
        }
        if ($node instanceof Node\Name) {
            return strcasecmp($node->toString(), 'Closure') === 0 ? self::callableType(true)
                : Type::namedObject(in_array(strtolower($node->toString()), ['self', 'static'], true) ? $owner : $node->toString());
        }
        if (! $node instanceof Node\Identifier) { return null; }
        return match (strtolower($node->name)) {
            'void' => Type::void(), 'never' => Type::never(), 'bool' => Type::bool(), 'true' => Type::true(), 'false' => Type::false(),
            'int' => Type::int(), 'float' => Type::float(), 'string' => Type::string(), 'null' => Type::null(), 'mixed' => Type::mixed(),
            'array' => Type::array(Type::fromAtomic(new \Mago\Sdk\Analyzer\Type\ScalarType(\Mago\Sdk\Analyzer\Type\ScalarTypeKind::ArrayKey)), Type::mixed()),
            'callable' => self::callableType(false),
            default => null,
        };
    }

    /** Closed scalar array defaults retain their exact keys, order and element literals. */
    private static function literalType(mixed $value, int $depth = 0): ?Type
    {
        if ($depth > 8) { return null; }
        if (is_bool($value)) { return $value ? Type::true() : Type::false(); }
        if (is_int($value)) { return Type::literalInt($value); }
        if (is_string($value)) { return Type::literalString($value); }
        if ($value === null) { return Type::null(); }
        if (! is_array($value) || count($value) > 64) { return null; }
        if ($value === []) { return Type::fromAtomic(new \Mago\Sdk\Analyzer\Type\ListType(Type::never(), [], 0, false)); }
        $items = [];
        foreach ($value as $key => $item) {
            $type = self::literalType($item, $depth + 1);
            if ($type === null) { return null; }
            $items[] = new \Mago\Sdk\Analyzer\Type\ArrayItem(new \Mago\Sdk\Analyzer\Type\ArrayKey(
                is_int($key) ? \Mago\Sdk\Analyzer\Type\ArrayKeyKind::Integer : \Mago\Sdk\Analyzer\Type\ArrayKeyKind::String, $key,
            ), false, $type);
        }
        return Type::fromAtomic(new \Mago\Sdk\Analyzer\Type\KeyedArrayType($items, null, null, $items !== []));
    }

    public static function callableType(bool $closure): Type
    {
        return Type::fromAtomic(new \Mago\Sdk\Analyzer\Type\CallableType(new \Mago\Sdk\Analyzer\Type\CallableSignature(
            false, $closure, [new \Mago\Sdk\Analyzer\Type\CallableParameter(null, Type::mixed(), null, false, true, true)], Type::mixed(), null, [],
        ), null));
    }

    private static function candidates(Node\Stmt\ClassMethod $scope): array
    {
        $result = [];
        $finder = new NodeFinder;
        foreach ($finder->findInstanceOf($scope->stmts ?? [], Node\Expr\StaticCall::class) as $call) {
            if (! $call->class instanceof Node\Name || strcasecmp($call->class->toString(), 'Testo\\Assert') !== 0 || ! $call->name instanceof Node\Identifier
                || ! in_array(strtolower($call->name->name), ['true', 'false'], true) || count($call->args) !== 1
                || ! $call->args[0] instanceof Node\Arg || $call->args[0]->byRef || $call->args[0]->unpack || $call->args[0]->name !== null
                || ! $call->args[0]->value instanceof Node\Expr\Variable || ! is_string($call->args[0]->value->name)) { continue; }
            $local = $call->args[0]->value->name;
            if ($local === 'this' || in_array($local, self::RESERVED, true)) { continue; }
            $initial = null;
            foreach ($finder->findInstanceOf($scope->stmts ?? [], Node\Expr\Assign::class) as $assignment) {
                if ($assignment->getEndFilePos() >= $call->getStartFilePos() || ! $assignment->var instanceof Node\Expr\Variable || $assignment->var->name !== $local) { continue; }
                $value = PhpSource::value($assignment->expr);
                if (is_bool($value) && ($initial === null || $assignment->getStartFilePos() < $initial->getStartFilePos())) { $initial = $assignment; }
            }
            if ($initial === null || $finder->findFirst([$scope], static fn (Node $node): bool => $node instanceof Node\Expr\Variable
                && $node->name === $local && $node->getStartFilePos() < $initial->getStartFilePos()) !== null) { continue; }
            foreach ($finder->find([$scope], static fn (Node $node): bool => $node->getComments() !== []) as $documented) {
                foreach ($documented->getComments() as $comment) {
                    if (preg_match('/@(?:phpstan-|psalm-)?var\b/', $comment->getText()) === 1) { continue 3; }
                }
            }
            $result[] = ['local' => $local, 'initial' => PhpSource::value($initial->expr), 'initialization' => $initial,
                'assertion' => $call, 'expected' => strtolower($call->name->name) === 'true'];
        }
        return $result;
    }

    /** References are permitted only as explicit lexical captures or independently checked formal bindings. */
    public static function closedScope(Node\Stmt\ClassMethod|Node\Expr\Closure|Node\Expr\ArrowFunction $scope): bool
    {
        return ! $scope->byRef && (new NodeFinder)->findFirst([$scope], static fn (Node $node): bool =>
            $node instanceof Node\Expr\AssignRef || $node instanceof Node\Stmt\Global_ || $node instanceof Node\Stmt\Static_
            || $node instanceof Node\Expr\Eval_ || $node instanceof Node\Expr\Include_ || $node instanceof Node\Stmt\Goto_ || $node instanceof Node\Stmt\Label
            || $node instanceof Node\Stmt\ClassLike || $node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\Foreach_ && $node->byRef
            || $node instanceof Node\Arg && $node->byRef || $node instanceof Node\ArrayItem && $node->byRef
            || $node instanceof Node\Expr\Variable && (! is_string($node->name) || in_array($node->name, self::RESERVED, true))
            || array_filter($node->getComments(), static fn ($comment): bool => preg_match('/@(?:phpstan-|psalm-)?var\b/', $comment->getText()) === 1) !== []
            || ($scope instanceof Node\Expr\Closure || $scope instanceof Node\Expr\ArrowFunction)
                && array_filter($node->getComments(), static fn ($comment): bool => preg_match('/@(?:phpstan-|psalm-)?(?:param|return)\b/', $comment->getText()) === 1) !== []
            || self::attachedClosureDocumentation($node)
            || $node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name
                && in_array(strtolower($node->name->getLast()), ['extract', 'parse_str', 'call_user_func', 'call_user_func_array', 'get_defined_vars'], true)) === null;
    }

    /** Leading callable documentation can belong to its expression or argument wrapper. */
    private static function attachedClosureDocumentation(Node $node): bool
    {
        if (! ($node instanceof Node\Expr || $node instanceof Node\Arg || $node instanceof Node\ArrayItem || $node instanceof Node\Stmt\Expression)
            || array_filter($node->getComments(), static fn ($comment): bool => preg_match('/@(?:phpstan-|psalm-)?(?:param|return)\b/', $comment->getText()) === 1) === []) { return false; }
        return (new NodeFinder)->findFirst([$node], static fn (Node $candidate): bool => $candidate instanceof Node\Expr\Closure || $candidate instanceof Node\Expr\ArrowFunction) !== null;
    }

    public static function current(array $entry): bool
    {
        $size = @filesize($entry['diskFile']);
        $contents = $size !== false && $size <= 2_000_000 ? @file_get_contents($entry['diskFile']) : false;
        return $contents !== false && hash('sha256', $contents) === $entry['hash'];
    }

    /** Hydrate only an already scanned declaration; unknown or duplicate bindings never become analyzed bodies. */
    private function declaration(string $name): ?array
    {
        $key = strtolower($name);
        $binding = $this->classes[$key] ?? null;
        if (! $this->complete || $this->failed || $binding === null || ! self::current($binding['entry'])) { return null; }
        if (isset($this->hydrated[$key])) {
            $entry = $this->hydrated[$key];
            return self::current($entry['entry']) ? $entry : null;
        }
        $entry = $binding['entry'];
        $bytes = @file_get_contents($entry['diskFile']);
        if ($bytes === false || strlen($bytes) > 2_000_000 || hash('sha256', $bytes) !== $entry['hash']
            || count($this->hydrated) >= 32 || $this->hydratedBytes + strlen($bytes) > 8_388_608) { return null; }
        try {
            $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($bytes) ?? [];
            $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes);
        } catch (\PhpParser\Error) { return null; }
        $matches = (new NodeFinder)->find($nodes, static fn (Node $node): bool => $node instanceof Node\Stmt\ClassLike
            && $node->name !== null && $node->namespacedName !== null && strtolower($node->namespacedName->toString()) === $key);
        if (count($matches) !== 1 || $matches[0]->getType() !== $binding['kind']
            || $matches[0]->getStartFilePos() !== $binding['start'] || $matches[0]->getEndFilePos() + 1 !== $binding['end']
            || ! self::current($entry)) { return null; }
        $this->hydratedBytes += strlen($bytes);
        return $this->hydrated[$key] = ['node' => $matches[0], 'entry' => $entry];
    }
    private function disk(string $file): string
    {
        $file = RefreshedModelProperties::path($file);
        return preg_match('~^(?:/|[a-z]:/)~i', $file) === 1 ? $file : $this->root.'/'.$file;
    }
}

