<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\CodebaseScanContext;
use Mago\Sdk\Analyzer\CodebaseScanHook;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\Metadata\ClassLikeKind;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\TypeComparator;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** Index stable local attribute observations separated by an unconditional native refresh. */
final class RefreshedModelProperties implements InitializationHook, CodebaseScanHook
{
    private const RESERVED = ['this', 'GLOBALS', '_GET', '_POST', '_COOKIE', '_REQUEST', '_SERVER', '_ENV', '_FILES', '_SESSION', 'http_response_header', 'argv', 'argc'];
    /** @var array<string, array{hash:string, proofs:list<array<string,mixed>>}> */
    private array $files = [];
    private bool $complete = false;
    private bool $failed = false;
    private ?ModelAttributeReadDomains $domains = null;
    private readonly NativeModelAttributeRefresh $native;
    private FactoryResultContract $factories;

    public function __construct(private readonly string $root = '.')
    {
        $this->native = new NativeModelAttributeRefresh($root);
        $this->factories = new FactoryResultContract($root);
    }

    public function initialize(InitializationContext $context): void
    {
        $this->files = [];
        $this->complete = $this->failed = false;
        $this->domains = null;
        $this->factories = new FactoryResultContract($this->root);
    }

    public function getTargets(): array { return ['**']; }

    public function scan(CodebaseScanContext $context): void
    {
        $this->factories->scan($context);
        if ($context->firstBatch) { $this->files = []; $this->failed = false; $this->domains = null; }
        $this->complete = false;
        foreach ($context->files as $file) {
            $context->cancellation->throwIfCancelled();
            $path = self::path($file->path);
            if ($this->failed || isset($this->files[$path]) || strlen($file->contents) > 2_000_000 || count($this->files) >= 100_000) { $this->failed = true; break; }
            try {
                $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($file->contents) ?? [];
                $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes);
            } catch (\PhpParser\Error) { $this->failed = true; break; }
            $proofs = [];
            if ((new NodeFinder)->findFirst($nodes, static fn (Node $node): bool => $node instanceof Node\DeclareItem && strtolower($node->key->name) === 'ticks') === null) {
                foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Stmt\Class_::class) as $class) {
                    if ($class->name === null || $class->namespacedName === null) { continue; }
                    foreach ($class->getMethods() as $scope) {
                        if ($scope->stmts === null || ! self::localScope($scope)) { continue; }
                        foreach (self::candidates($scope) as $proof) {
                            $proofs[] = $proof + ['scope' => $scope, 'owner' => $class->namespacedName->toString(), 'callerClass' => $class,
                                'file' => $file->path, 'diskFile' => $this->disk($file->path), 'hash' => hash('sha256', $file->contents)];
                        }
                    }
                }
            }
            $this->files[$path] = ['hash' => hash('sha256', $file->contents), 'proofs' => $proofs];
        }
        if ($this->failed) { $this->files = []; }
        $this->complete = $context->lastBatch && ! $this->failed;
    }

    /** @return list<array<string,mixed>> */
    public function proofs(string $file, string $contents): array
    {
        $entry = $this->complete ? ($this->files[self::path($file)] ?? null) : null;
        return $entry !== null && hash('sha256', $contents) === $entry['hash'] ? $entry['proofs'] : [];
    }

    /** Source and effective codebase contracts independently establish that this new value is admissible. */
    public function valid(Codebase $codebase, TypeComparator $types, array $proof): bool
    {
        if (! self::current($proof) || ! self::caller($codebase, $proof)
            || ! $this->native->assertion($codebase, $proof['assertion']->name->name)
            || ! $this->native->assertion($codebase, $proof['observation']['assertion']->name->name)) { return false; }
        foreach ($proof['intermediate'] as $assertion) {
            if (! $this->native->assertion($codebase, $assertion->name->name)) { return false; }
        }
        $class = $this->originClass($codebase, $proof);
        if ($class === null) { return false; }
        $classes = [$class, ...$codebase->getClassDescendants($class)];
        if (count($classes) > 10_000) { return false; }
        $this->domains ??= new ModelAttributeReadDomains($this->root, array_map(static fn (array $file): string => $file['hash'], $this->files));
        $read = null;
        foreach (array_unique($classes) as $candidate) {
            $metadata = $codebase->getClass($candidate);
            if ($metadata === null || $metadata->kind !== ClassLikeKind::Class_ || $metadata->hasIncompleteHierarchy()
                || ! in_array(strtolower(ModelReflection::MODEL), array_map('strtolower', $metadata->parentClasses), true)
                || $metadata->templates !== [] || ! $this->compatibleMixins($codebase, $metadata)
                || ! $this->native->proves($codebase, $candidate)) { return false; }
            if (! $this->updates($codebase, $candidate, $proof['updates'], $proof['scope'], $types)) { return false; }
            if (! $this->native->reads($codebase, $candidate, $proof['reads'])) { return false; }
            foreach ($proof['reads'] as $property) {
                $studly = ModelReflection::studly($property);
                if ($codebase->getMethod($candidate, 'get'.$studly.'Attribute') !== null || $codebase->getMethod($candidate, lcfirst($studly)) !== null) { return false; }
            }
            $domain = $this->domains->resolve($codebase, $candidate, $proof['property'], $proof['expected'], types: $types);
            if ($domain === null || ! $types->isContainedBy(self::literal($proof['expected']), $domain)
                || $read !== null && ! $types->equals($read, $domain)) { return false; }
            foreach ($proof['intermediate'] as $call) {
                $intermediate = self::assertion($call);
                if ($intermediate === null || $intermediate['receiver'] !== $proof['receiver']) { return false; }
                $other = $this->domains->resolve($codebase, $candidate, $intermediate['property'], $intermediate['expected'], true, $types);
                if ($other === null || ! $types->isContainedBy(self::literal($intermediate['expected']), $other)) { return false; }
            }
            $read = $domain;
        }
        return $read !== null && $this->domains->current() && self::current($proof);
    }

    /** A redundant native ancestor annotation adds no separate dispatch or read contract. */
    private function compatibleMixins(Codebase $codebase, \Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata $metadata): bool
    {
        if ($metadata->mixins === []) { return true; }
        if (! in_array(strtolower(ModelReflection::MODEL), array_map('strtolower', $metadata->parentClasses), true)) { return false; }
        foreach ($metadata->mixins as $mixin) {
            $atoms = $mixin->atomicTypes;
            $atom = count($atoms) === 1 ? $atoms[0] : null;
            if (! $atom instanceof NamedObjectType || strcasecmp($atom->name, ModelReflection::MODEL) !== 0
                || ($atom->parameters ?? []) !== [] || ($atom->intersections ?? []) !== [] || ($atom->variances ?? []) !== []
                || $atom->static || $atom->isThis || $atom->remappedParameters) { return false; }
        }
        $ancestor = $codebase->getClass(ModelReflection::MODEL);
        return $ancestor !== null && $ancestor->kind === ClassLikeKind::Class_ && ! $ancestor->hasIncompleteHierarchy()
            && strcasecmp($ancestor->name, ModelReflection::MODEL) === 0 && $ancestor->mixins === []
            && $this->native->proves($codebase, ModelReflection::MODEL);
    }

    private function originClass(Codebase $codebase, array $proof): ?string
    {
        $expression = $proof['origin']->expr;
        if ($expression instanceof Node\Expr\MethodCall && $expression->var instanceof Node\Expr\Variable && $expression->var->name === 'this'
            && $expression->name instanceof Node\Identifier && self::plain($expression->args)) {
            $method = $codebase->getMethod($proof['owner'], $expression->name->name);
            $node = $proof['callerClass']->getMethod($expression->name->name);
            if ($method === null || $node === null || $method->static || $method->abstract || $method->flags->contains(MetadataFlags::BY_REFERENCE)
                || $method->returnType === null || $method->location->file === null || self::path($method->location->file) !== self::path($proof['file'])
                || ! self::methodMatches($codebase, $proof['owner'], $node, $proof['file']) || $node->stmts === null || ! self::localScope($node)
                || $method->visibility !== \Mago\Sdk\Analyzer\Type\Visibility::Private && ! $method->final && ! $proof['callerClass']->isFinal()) { return null; }
            $atoms = $method->returnType->type->atomicTypes;
            $type = count($atoms) === 1 ? $atoms[0] : null;
            if (! $type instanceof NamedObjectType || ($type->parameters ?? []) !== [] || ($type->intersections ?? []) !== [] || $type->static || $type->isThis) { return null; }
            if (! $node->returnType instanceof Node\Name || strcasecmp($node->returnType->toString(), $type->name) !== 0) { return null; }
            if (self::unsavedHelper($codebase, $proof['owner'], $type->name, $node)) { return null; }
            $returns = (new NodeFinder)->findInstanceOf($node->stmts, Node\Stmt\Return_::class);
            foreach ($node->params as $position => $parameter) {
                $argument = $expression->args[$position]->value ?? null;
                if ($argument === null || $parameter->type instanceof Node\Identifier && in_array(strtolower($parameter->type->name), ['int', 'float', 'string', 'bool'], true)) { continue; }
                $forwarded = array_filter($returns, static fn (Node\Stmt\Return_ $return): bool => $return->expr instanceof Node\Expr\Variable
                    && $return->expr->name === $parameter->var->name) !== [];
                foreach ((new NodeFinder)->findInstanceOf([$argument], Node\Expr\Variable::class) as $alias) {
                    if (! is_string($alias->name) || (new NodeFinder)->findFirst($proof['scope']->stmts, static fn (Node $use): bool => $use instanceof Node\Expr\Variable
                        && $use->name === $alias->name && $use->getStartFilePos() < $proof['assertion']->getEndFilePos()
                        && ($use->getStartFilePos() > $proof['origin']->getEndFilePos()
                            || $forwarded && $use->getStartFilePos() < $proof['origin']->getStartFilePos())) !== null) { return null; }
                }
            }
            if ($returns === [] || ! array_filter($returns, static fn (Node\Stmt\Return_ $return): bool => $return->expr !== null && ! $return->expr instanceof Node\Expr\New_)) { return null; }
            return $type->name;
        }
        $type = $this->factories->resolve($codebase, $expression, $proof['owner']);
        $atom = $type !== null && count($type->atomicTypes) === 1 ? $type->atomicTypes[0] : null;
        return $atom instanceof NamedObjectType && ($atom->parameters ?? []) === [] && ($atom->intersections ?? []) === [] ? $atom->name : null;
    }

    /** A typed return does not make an explicitly fresh or existence-dependent helper result persisted. */
    private static function unsavedHelper(Codebase $codebase, string $owner, string $model, Node\Stmt\ClassMethod $helper): bool
    {
        return (new NodeFinder)->findFirst($helper->stmts ?? [], static function (Node $node) use ($codebase, $owner, $model): bool {
            if ($node instanceof Node\Expr\PropertyFetch || $node instanceof Node\Expr\NullsafePropertyFetch) {
                if (! $node->name instanceof Node\Identifier || $node->name->name === 'exists') { return true; }
            }
            if ($node instanceof Node\Expr\New_) {
                return ! $node->class instanceof Node\Name || self::possibleModelClass($codebase, $owner, $model, $node->class);
            }
            if ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall) {
                return ! $node->name instanceof Node\Identifier
                    || in_array(strtolower($node->name->name), ['make', 'newinstance', 'newmodelinstance', 'replicate', 'replicatequietly', 'makeone', 'newmodel'], true);
            }
            if ($node instanceof Node\Expr\StaticCall) {
                if (! $node->name instanceof Node\Identifier) { return true; }
                if (! in_array(strtolower($node->name->name), ['make', 'newinstance', 'newmodelinstance', 'replicate', 'replicatequietly', 'makeone', 'newmodel'], true)) { return false; }
                return ! $node->class instanceof Node\Name || self::possibleModelClass($codebase, $owner, $model, $node->class);
            }
            return false;
        }) !== null;
    }

    /** Unknown constructor hierarchies cannot establish that a fresh object is unrelated to the model. */
    private static function possibleModelClass(Codebase $codebase, string $owner, string $model, Node\Name $syntax): bool
    {
        $name = $syntax->toString();
        if (in_array(strtolower($name), ['self', 'static'], true)) { $name = $owner; }
        if (strtolower($name) === 'parent') { return true; }
        if (strcasecmp($name, $model) === 0 || strcasecmp($name, ModelReflection::MODEL) === 0) { return true; }
        $metadata = $codebase->getClass($name);
        return $metadata === null || $metadata->hasIncompleteHierarchy()
            || in_array(strtolower(ModelReflection::MODEL), array_map('strtolower', $metadata->parentClasses), true);
    }

    private static function caller(Codebase $codebase, array $proof): bool
    {
        $class = $codebase->getClass($proof['owner']);
        $method = $codebase->getMethod($proof['owner'], $proof['scope']->name->name);
        return $class !== null && ! $class->hasIncompleteHierarchy() && $class->kind === ClassLikeKind::Class_
            && strcasecmp($class->name, $proof['owner']) === 0 && self::startMatches($proof['callerClass'], $class->location->span->start)
            && $class->location->span->end === $proof['callerClass']->getEndFilePos() + 1
            && self::path($class->nameLocation?->file ?? '') === self::path($proof['file'])
            && $class->nameLocation?->span->start === $proof['callerClass']->name->getStartFilePos()
            && $class->nameLocation?->span->end === $proof['callerClass']->name->getEndFilePos() + 1
            && $method !== null && ! $method->abstract && ! $method->static && ! $proof['scope']->isStatic()
            && ! $method->flags->contains(MetadataFlags::BY_REFERENCE) && self::path($class->location->file ?? '') === self::path($proof['file'])
            && self::methodMatches($codebase, $proof['owner'], $proof['scope'], $proof['file']);
    }

    private static function startMatches(Node $node, int $start): bool
    {
        if ($start === $node->getStartFilePos()) { return true; }
        foreach ($node->getComments() as $comment) { if ($start === $comment->getStartFilePos()) { return true; } }
        return false;
    }

    private static function methodMatches(Codebase $codebase, string $owner, Node\Stmt\ClassMethod $syntax, string $file): bool
    {
        $metadata = $codebase->getMethod($owner, $syntax->name->name);
        if ($metadata === null || $metadata->kind !== \Mago\Sdk\Analyzer\Metadata\FunctionLikeKind::Method
            || $metadata->identifier->kind !== \Mago\Sdk\Analyzer\Type\FunctionLikeKind::Method || strcasecmp($metadata->identifier->class ?? '', $owner) !== 0
            || strcasecmp($metadata->identifier->name, $syntax->name->name) !== 0 || strcasecmp($metadata->originalName, $syntax->name->name) !== 0
            || $metadata->static !== $syntax->isStatic() || $metadata->abstract !== $syntax->isAbstract() || $metadata->flags->contains(MetadataFlags::BY_REFERENCE)
            || self::path($metadata->location->file ?? '') !== self::path($file) || ! self::startMatches($syntax, $metadata->location->span->start)
            || $metadata->location->span->end !== $syntax->getEndFilePos() + 1 || self::path($metadata->nameLocation?->file ?? '') !== self::path($file)
            || $metadata->nameLocation?->span->start !== $syntax->name->getStartFilePos() || $metadata->nameLocation?->span->end !== $syntax->name->getEndFilePos() + 1
            || count($metadata->parameters) !== count($syntax->params)) { return false; }
        foreach ($syntax->params as $position => $parameter) {
            $bound = $metadata->parameters[$position];
            if ($parameter->byRef || $parameter->variadic || ! is_string($parameter->var->name) || $bound->name !== '$'.$parameter->var->name
                || $bound->flags->contains(MetadataFlags::BY_REFERENCE) || $bound->flags->contains(MetadataFlags::VARIADIC)
                || self::path($bound->location->file) !== self::path($file) || $bound->location->span->start !== $parameter->getStartFilePos()
                || $bound->location->span->end !== $parameter->getEndFilePos() + 1 || self::path($bound->nameLocation->file) !== self::path($file)
                || $bound->nameLocation->span->start !== $parameter->var->getStartFilePos() || $bound->nameLocation->span->end !== $parameter->var->getEndFilePos() + 1) { return false; }
        }
        return true;
    }

    /** Native update delegates to user-overridable save/fill/mutator hooks before the next refresh. */
    private function updates(Codebase $codebase, string $class, array $updates, ?Node\Stmt\ClassMethod $scope = null, ?TypeComparator $types = null): bool
    {
        if ($updates === []) { return true; }
        if (! $this->native->update($codebase, $class)) { return false; }
        $reflection = new ModelReflection($codebase, new PhpSource($this->root));
        if ($reflection->default($class, 'touches', []) !== []) { return false; }
        $casts = $reflection->casts($class);
        if (! is_array($casts)) { return false; }
        $json = false;
        foreach ($casts as $cast) {
            if (! is_string($cast)) { return false; }
            if (in_array(strtolower(explode(':', $cast, 2)[0]), ['int', 'integer', 'bool', 'boolean', 'string', 'decimal'], true)) { continue; }
            // This field is only read by the already audited native dirty chain.
            // JSON writes, object/collection, encrypted and custom casts stay out.
            if (in_array(strtolower($cast), ['array', 'json', 'json:unicode'], true)) { $json = true; continue; }
            return false;
        }
        if ($json && ($scope === null || $types === null || ! $this->native->jsonUpdateReads($codebase, $class, $types)
            || ! self::ordinaryJsonContinuation($scope, $updates))) { return false; }
        if ($reflection->default($class, 'timestamps', true) !== false) {
            foreach (['CREATED_AT', 'UPDATED_AT'] as $constant) {
                $key = $this->domains?->constantString($codebase, $class, $constant);
                if ($key === null) { return false; }
                $studly = ModelReflection::studly($key);
                if ($codebase->getMethod($class, 'set'.$studly.'Attribute') !== null || $codebase->getMethod($class, lcfirst($studly)) !== null) { return false; }
            }
        }
        foreach ($updates as $update) {
            if (count($update->args) !== 1 || ! $update->args[0]->value instanceof Node\Expr\Array_) { return false; }
            foreach ($update->args[0]->value->items as $item) {
                if ($item === null || $item->byRef || $item->unpack || ! $item->key instanceof Node\Scalar\String_) { return false; }
                if (str_contains($item->key->value, '->')) { return false; }
                $value = PhpSource::value($item->value);
                if (! is_int($value) && ! is_string($value) && ! is_bool($value) && $value !== null) { return false; }
                $cast = $casts[$item->key->value] ?? null;
                if ($cast !== null && (! is_string($cast) || ! in_array(strtolower(explode(':', $cast, 2)[0]), ['int', 'integer', 'bool', 'boolean', 'string', 'decimal'], true))) { return false; }
                $studly = ModelReflection::studly($item->key->value);
                if ($codebase->getMethod($class, 'set'.$studly.'Attribute') !== null || $codebase->getMethod($class, lcfirst($studly)) !== null) { return false; }
            }
        }
        return true;
    }

    /** Known callback activation is not evidence for the codec's ordinary branch. */
    private static function ordinaryJsonContinuation(Node\Stmt\ClassMethod $scope, array $updates): bool
    {
        $last = max(array_map(static fn (Node\Expr\MethodCall $update): int => $update->getEndFilePos(), $updates));
        return (new NodeFinder)->findFirst($scope->stmts ?? [], static function (Node $node) use ($last): bool {
            if ($node->getStartFilePos() > $last) { return false; }
            if ($node instanceof Node\Expr\StaticCall) {
                // A source-bound decoder setter (including an alias/subclass), or
                // uncertain static dispatch, cannot certify the ordinary branch.
                return ! $node->class instanceof Node\Name || ! $node->name instanceof Node\Identifier
                    || in_array(strtolower($node->name->name), ['decodeusing', 'encodeusing'], true);
            }
            return $node instanceof Node\Expr\StaticPropertyFetch && (! $node->class instanceof Node\Name
                || ! $node->name instanceof Node\VarLikeIdentifier || in_array(strtolower($node->name->name), ['decoder', 'encoder'], true));
        }) === null;
    }

    /** @return iterable<array<string,mixed>> */
    private static function candidates(Node\Stmt\ClassMethod $scope): iterable
    {
        $origins = $observed = $refreshes = $exists = [];
        foreach ($scope->stmts as $index => $statement) {
            $expression = $statement instanceof Node\Stmt\Expression ? $statement->expr : null;
            if ($expression instanceof Node\Expr\Assign && $expression->var instanceof Node\Expr\Variable
                && is_string($expression->var->name) && ! in_array($expression->var->name, self::RESERVED, true)) {
                $local = $expression->var->name;
                if (! isset($origins[$local])) { $origins[$local] = [$expression, $index]; }
                else { $origins[$local] = null; }
                $observed[$local] = $refreshes[$local] = [];
                continue;
            }
            if ($expression instanceof Node\Expr\Assign && $expression->var instanceof Node\Expr\PropertyFetch
                && $expression->var->var instanceof Node\Expr\Variable && is_string($expression->var->var->name)
                && $expression->var->name instanceof Node\Identifier && $expression->var->name->name === 'exists') {
                $exists[$expression->var->var->name] = PhpSource::value($expression->expr);
                continue;
            }
            if ($expression instanceof Node\Expr\MethodCall && $expression->var instanceof Node\Expr\Variable && is_string($expression->var->name)
                && $expression->name instanceof Node\Identifier && strtolower($expression->name->name) === 'refresh' && $expression->args === []) {
                $local = $expression->var->name;
                if (($exists[$local] ?? null) !== false && isset($origins[$local])) { $refreshes[$local] = [$expression, $index]; }
                else { $refreshes[$local] = []; }
                continue;
            }
            $assertion = self::assertion($expression);
            if ($assertion === null) { continue; }
            $local = $assertion['receiver'];
            $property = $assertion['property'];
            if ($property === 'exists' && is_bool($assertion['expected'])) {
                $exists[$local] = $assertion['expected'];
                $refreshes[$local] = [];
            }
            $previous = $observed[$local][$property] ?? null;
            $refresh = $refreshes[$local] ?? [];
            $origin = $origins[$local] ?? null;
            if ($previous !== null && $refresh !== [] && $origin !== null && $previous['index'] < $refresh[1]
                && $previous['expected'] !== $assertion['expected']) {
                $isolation = self::isolated($scope, $local, $origin[0], $origin[1], $index);
                $between = array_slice($scope->stmts, $refresh[1] + 1, $index - $refresh[1] - 1);
                if ($isolation !== null && ! array_filter($between, static fn (Node $node): bool => ! $node instanceof Node\Stmt\Expression
                    || self::assertion($node->expr) === null)) {
                    yield $assertion + ['observation' => $previous, 'refresh' => $refresh[0], 'origin' => $origin[0], 'reads' => $isolation['reads'], 'updates' => $isolation['updates'],
                        'intermediate' => array_map(static fn (Node\Stmt\Expression $node): Node\Expr\StaticCall => $node->expr, $between)];
                }
            }
            $observed[$local][$property] = $assertion + ['index' => $index];
        }
    }

    private static function assertion(?Node\Expr $expression): ?array
    {
        if (! $expression instanceof Node\Expr\StaticCall || ! self::testo($expression) || ! self::plain($expression->args)) { return null; }
        $name = strtolower($expression->name->name);
        if (! in_array($name, ['same', 'true', 'false'], true) || count($expression->args) !== ($name === 'same' ? 2 : 1)) { return null; }
        $argument = $expression->args[0];
        $fetch = $argument->value;
        if (! $fetch instanceof Node\Expr\PropertyFetch || ! $fetch->var instanceof Node\Expr\Variable || ! is_string($fetch->var->name)
            || in_array($fetch->var->name, self::RESERVED, true) || ! $fetch->name instanceof Node\Identifier) { return null; }
        if ($name === 'same') {
            $literal = $expression->args[1]->value;
            if (! $literal instanceof Node\Scalar\String_ && ! $literal instanceof Node\Scalar\Int_
                && (! $literal instanceof Node\Expr\ConstFetch || ! in_array(strtolower($literal->name->toString()), ['null', 'true', 'false'], true))) { return null; }
        }
        $value = $name === 'same' ? PhpSource::value($expression->args[1]->value) : $name === 'true';
        if (! is_int($value) && ! is_string($value) && ! is_bool($value) && $value !== null) { return null; }
        return ['receiver' => $fetch->var->name, 'property' => $fetch->name->name, 'expected' => $value, 'assertion' => $expression, 'argument' => $argument];
    }

    private static function testo(Node\Expr\StaticCall $call): bool
    {
        return $call->class instanceof Node\Name && strcasecmp($call->class->toString(), 'Testo\\Assert') === 0 && $call->name instanceof Node\Identifier;
    }

    /** @return array{reads:list<string>,updates:list<Node\Expr\MethodCall>}|null */
    private static function isolated(Node\Stmt\ClassMethod $scope, string $local, Node\Expr\Assign $origin, int $originIndex, int $last): ?array
    {
        $allowed = [$origin->var];
        $reads = [];
        $updates = [];
        foreach (array_slice($scope->stmts, $originIndex, $last - $originIndex + 1) as $statement) {
            if (! $statement instanceof Node\Stmt\Expression && (new NodeFinder)->findFirst([$statement], static fn (Node $node): bool =>
                $node instanceof Node\Expr\Variable && $node->name === $local) !== null) { return null; }
            foreach ((new NodeFinder)->findInstanceOf([$statement], Node\Expr\PropertyFetch::class) as $fetch) {
                if ($fetch->var instanceof Node\Expr\Variable && $fetch->var->name === $local) {
                    if (! $fetch->name instanceof Node\Identifier) { return null; }
                    $allowed[] = $fetch->var;
                    $reads[] = $fetch->name->name;
                }
            }
            foreach ((new NodeFinder)->findInstanceOf([$statement], Node\Expr\MethodCall::class) as $call) {
                if ($call->var instanceof Node\Expr\Variable && $call->var->name === $local) {
                    if (! $call->name instanceof Node\Identifier || ! in_array(strtolower($call->name->name), ['refresh', 'update'], true) || ! self::plain($call->args)) { return null; }
                    $allowed[] = $call->var;
                    if (strtolower($call->name->name) === 'update') { $updates[] = $call; }
                }
            }
            foreach ((new NodeFinder)->find([$statement], static fn (Node $node): bool => $node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignOp
                || $node instanceof Node\Expr\PreInc || $node instanceof Node\Expr\PostInc || $node instanceof Node\Expr\PreDec || $node instanceof Node\Expr\PostDec) as $write) {
                if ($write === $origin) { continue; }
                if ((new NodeFinder)->findFirst([$write->var], static fn (Node $target): bool => $target instanceof Node\Expr\Variable && $target->name === $local) !== null) { return null; }
            }
        }
        foreach ((new NodeFinder)->findInstanceOf(array_slice($scope->stmts, 0, $last + 1), Node\Expr\Variable::class) as $variable) {
            if ($variable->name === $local && ! in_array($variable, $allowed, true)) { return null; }
        }
        return ['reads' => array_values(array_unique($reads)), 'updates' => $updates];
    }

    private static function localScope(Node\Stmt\ClassMethod $scope): bool
    {
        return ! $scope->byRef && (new NodeFinder)->findFirst([$scope], static fn (Node $node): bool =>
            $node !== $scope && ($node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike)
            || $node instanceof Node\Expr\AssignRef || $node instanceof Node\Stmt\Global_ || $node instanceof Node\Stmt\Static_
            || $node instanceof Node\Expr\Eval_ || $node instanceof Node\Expr\Include_ || $node instanceof Node\Stmt\Goto_ || $node instanceof Node\Stmt\Label
            || $node instanceof Node\Param && $node->byRef || $node instanceof Node\Arg && $node->byRef || $node instanceof Node\ArrayItem && $node->byRef
            || $node instanceof Node\Stmt\Foreach_ && $node->byRef || $node instanceof Node\Expr\Variable && (! is_string($node->name) || $node->name === 'GLOBALS')
            || $node instanceof Node\Expr\FuncCall && (! $node->name instanceof Node\Name
                || in_array(strtolower($node->name->getLast()), ['extract', 'parse_str', 'call_user_func', 'call_user_func_array', 'compact', 'get_defined_vars'], true))) === null;
    }

    private static function plain(array $arguments): bool
    {
        foreach ($arguments as $argument) { if (! $argument instanceof Node\Arg || $argument->byRef || $argument->unpack || $argument->name !== null) { return false; } }
        return true;
    }

    public static function literal(int|string|bool|null $value): Type
    {
        return match (true) { is_string($value) => Type::literalString($value), is_int($value) => Type::literalInt($value), $value === true => Type::true(), $value === false => Type::false(), default => Type::null() };
    }

    public static function current(array $proof): bool
    {
        $size = @filesize($proof['diskFile']);
        $contents = $size !== false && $size <= 2_000_000 ? @file_get_contents($proof['diskFile']) : false;
        return $contents !== false && hash('sha256', $contents) === $proof['hash'];
    }

    public static function key(Node $node): string { return $node->getStartFilePos().':'.($node->getEndFilePos() + 1); }

    public static function path(string $file): string
    {
        $file = str_replace('\\', '/', $file);
        if (preg_match('~^//\\?/[A-Za-z]:/~', $file) === 1) { $file = substr($file, 4); }
        return PHP_OS_FAMILY === 'Windows' ? strtolower($file) : $file;
    }

    private function disk(string $file): string
    {
        $path = self::path($file);
        return str_starts_with($path, '/') || preg_match('~^[a-z]:/~i', $path) === 1 ? $path : $this->root.'/'.$path;
    }
}
