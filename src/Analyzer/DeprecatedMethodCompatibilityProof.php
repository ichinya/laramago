<?php

declare (strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\{Codebase, CodebaseScanContext, CodebaseScanHook, InitializationContext, InitializationHook, IssueFilterContext, Type, TypeComparator};
use Mago\Sdk\Analyzer\Metadata\{ClassLikeKind, FunctionLikeKind, FunctionLikeMetadata, MetadataFlags, TypeMetadata};
use Mago\Sdk\Reporting\{AnnotationKind, Level};
use Mago\Sdk\SourceLocation;
use PhpParser\{Node, NodeFinder, NodeTraverser, ParserFactory};
use PhpParser\NodeVisitor\NameResolver;
/** Diagnostic compatibility only: native method types and actual deprecation status remain authoritative. */
final class DeprecatedMethodCompatibilityProof implements InitializationHook, CodebaseScanHook
{
    private array $files = [];
    private array $classes = [];
    private bool $started = false;
    private bool $complete = false;
    private bool $failed = false;
    private int $bytes = 0;
    private const SOURCE_PROFILES = ['illuminate\http\request::get' => ['class' => 'Illuminate\Http\Request', 'method' => 'get', 'parameters' => ['key', 'default']], 'sebastianbergmann\codecoverage\driver\selector::forlinecoverage' => ['class' => 'SebastianBergmann\CodeCoverage\Driver\Selector', 'method' => 'forLineCoverage', 'parameters' => ['filter']]];
    public function __construct(private readonly string $root)
    {
    }
    public function getTargets(): array
    {
        return ['**'];
    }
    public function initialize(InitializationContext $context): void
    {
        $this->reset();
    }
    private function reset(): void
    {
        $this->files = $this->classes = [];
        $this->started = $this->complete = $this->failed = false;
        $this->bytes = 0;
    }
    public function scan(CodebaseScanContext $context): void
    {
        if ($context->firstBatch) {
            $this->reset();
            $this->started = true;
        } elseif (!$this->started || $this->complete) {
            $this->failed = true;
        }
        $this->complete = false;
        foreach ($context->files as $file) {
            $path = self::path($file->path);
            $this->bytes += strlen($file->contents);
            if ($this->failed || $context->cancellation->isCancelled() || isset($this->files[$path]) || $path === '' || strlen($file->contents) > 1000000 || $this->bytes > 64 * 1024 * 1024 || count($this->files) >= 100000) {
                $this->failed = true;
                break;
            }
            $nodes = self::parse($file->contents);
            if ($nodes === null) {
                $this->failed = true;
                break;
            }
            $this->files[$path] = ['file' => $file->path, 'sha256' => hash('sha256', $file->contents)];
            foreach ((new NodeFinder())->findInstanceOf($nodes, Node\Stmt\ClassLike::class) as $class) {
                if ($class->name === null || !isset($class->namespacedName)) {
                    continue;
                }
                $name = strtolower($class->namespacedName->toString());
                $this->classes[$name] = array_key_exists($name, $this->classes) ? null : ['file' => $file->path, 'sha256' => hash('sha256', $file->contents), 'span' => self::span($class)];
                if (count($this->classes) > 100000) {
                    $this->failed = true;
                    break;
                }
            }
            unset($nodes);
        }
        if ($context->cancellation->isCancelled()) {
            $this->failed = true;
        }
        if ($this->failed) {
            $this->files = $this->classes = [];
        }
        $this->complete = $context->lastBatch && $this->started && !$this->failed;
    }
    /** Every stage and consumed-source ledger is local to this genuine native request across Fiber awaits. */
    public function prove(IssueFilterContext $context): array
    {
        $stages = ['envelope' => false, 'lifecycle' => false, 'currentSource' => false, 'caller' => false, 'receiver' => false, 'callee' => false, 'builtinOrSourceProfile' => false, 'finalSourceCheck' => false];
        $receipt = ['remove' => false, 'stages' => $stages, 'actualNativeContext' => true, 'nativeTypesChanged' => false, 'realDeprecatedAPIStatusPreserved' => true, 'requestLocalProof' => true];
        $issue = $context->issue;
        if ($issue->level !== Level::Warning || $issue->code !== 'deprecated-method' || $issue->edits !== [] || $issue->link !== null || count($issue->annotations) !== 1 || $issue->annotations[0]->kind !== AnnotationKind::Primary || $issue->annotations[0]->message !== 'This method is deprecated' || $issue->annotations[0]->file !== null || !preg_match('/^Call to deprecated method: `([^`]+)::([^`]+)`\.$/D', $issue->message, $matched)) {
            return $receipt;
        }
        $owner = $matched[1];
        $methodName = $matched[2];
        $key = strtolower($owner . '::' . $methodName);
        $profile = self::SOURCE_PROFILES[$key] ?? null;
        $builtin = $key === 'reflectionmethod::setaccessible';
        if ($builtin && ($context->phpVersion->major() !== 8 || $context->phpVersion->minor() !== 5)) {
            return $receipt;
        }
        if ($profile === null && !$builtin || $issue->notes !== ['The method `' . $owner . '::' . $methodName . '` is marked as deprecated and may be removed or its behavior changed in future versions.'] || $issue->help !== 'Consult the documentation for `' . $owner . '::' . $methodName . '` for alternatives or migration instructions.') {
            return $receipt;
        }
        $receipt['stages']['envelope'] = true;
        if (!$this->complete || !$this->started || $this->failed) {
            return $receipt;
        }
        $receipt['stages']['lifecycle'] = true;
        $entry = $this->files[self::path($context->file)] ?? null;
        if ($entry === null || $entry['sha256'] !== hash('sha256', $context->contents)) {
            return $receipt;
        }
        $consumed = [];
        $nodes = $this->read($context->file, $consumed, $context->contents);
        if ($nodes === null) {
            return $receipt;
        }
        $receipt['stages']['currentSource'] = true;
        $span = $issue->annotations[0]->span;
        $finder = new NodeFinder();
        $calls = $finder->find($nodes, static fn(Node $node): bool => $node instanceof Node\Expr\MethodCall && self::span($node) === [$span->start, $span->end]);
        if (count($calls) !== 1 || !$calls[0]->name instanceof Node\Identifier || strcasecmp($calls[0]->name->name, $methodName) !== 0 || $calls[0]->isFirstClassCallable() || !self::plain($calls[0]->args)) {
            return $receipt;
        }
        $call = $calls[0];
        $scopes = $finder->find($nodes, static fn(Node $node): bool => $node instanceof Node\Stmt\ClassMethod && $node->getStartFilePos() <= $call->getStartFilePos() && $node->getEndFilePos() >= $call->getEndFilePos());
        if (count($scopes) !== 1) {
            return $receipt;
        }
        $scope = $scopes[0];
        $classes = $finder->find($nodes, static fn(Node $node): bool => $node instanceof Node\Stmt\Class_ && $node->getStartFilePos() <= $scope->getStartFilePos() && $node->getEndFilePos() >= $scope->getEndFilePos());
        if (count($classes) !== 1 || !isset($classes[0]->namespacedName) || !self::closed($scope, $call->getStartFilePos())) {
            return $receipt;
        }
        $class = $classes[0];
        $callerOwner = $class->namespacedName->toString();
        $binding = $this->classes[strtolower($callerOwner)] ?? null;
        if ($binding === null || self::path($binding['file']) !== self::path($context->file) || $binding['span'] !== self::span($class)) {
            return $receipt;
        }
        $callerClass = $context->codebase->getClass($callerOwner);
        $caller = $context->codebase->getMethod($callerOwner, $scope->name->name);
        if (!self::classMatches($callerClass, $class, $context->file) || $caller === null || !$caller->flags->contains(MetadataFlags::USER_DEFINED) || !self::methodMatches($caller, $scope, $callerOwner, $context->file, $context->types)) {
            return $receipt;
        }
        $receipt['stages']['caller'] = true;
        $receipt['caller'] = ['owner' => $callerOwner, 'name' => $scope->name->name, 'span' => self::span($scope)];
        $receiver = self::receiver($call, $scope);
        $receipt['receiverSourceAttempt'] = $receiver;
        if ($receiver === null || strcasecmp($receiver['class'], $owner) !== 0) {
            return $receipt;
        }
        $receiverClass = $context->codebase->getClass($receiver['class']);
        $receipt['receiverNativeAttempt'] = $receiverClass === null ? null : ['name' => $receiverClass->name, 'originalName' => $receiverClass->originalName, 'kind' => $receiverClass->kind->name, 'flags' => $receiverClass->flags->bits, 'builtin' => $receiverClass->flags->contains(MetadataFlags::BUILTIN), 'polyfill' => $receiverClass->flags->contains(MetadataFlags::POLYFILL), 'location' => self::canonical($receiverClass->location), 'nameLocation' => self::canonical($receiverClass->nameLocation), 'templates' => self::canonical($receiverClass->templates), 'mixins' => self::canonical($receiverClass->mixins), 'directParentClass' => $receiverClass->directParentClass, 'unresolvedHierarchyDependencies' => $receiverClass->unresolvedHierarchyDependencies, 'availableVersions' => self::canonical($receiverClass->availableVersions)];
        if ($receiverClass === null || $receiverClass->kind !== ClassLikeKind::Class_ || strcasecmp($receiverClass->name, $owner) !== 0 || $receiverClass->hasIncompleteHierarchy() || !$builtin && $receiverClass->templates !== [] || $receiverClass->mixins !== [] || $receiverClass->flags->contains(MetadataFlags::POLYFILL) || $receiverClass->flags->contains(MetadataFlags::BUILTIN) !== $builtin) {
            return $receipt;
        }
        if ($builtin && hash('sha256', json_encode(self::canonical($receipt['receiverNativeAttempt']), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) !== DeprecatedMethodBuiltinReceiverProfile::SHA256) {
            return $receipt;
        }
        $receipt['stages']['receiver'] = true;
        $receipt['receiver'] = $receiver;
        $callee = $context->codebase->getDeclaringMethod($receiver['class'], $methodName);
        $receipt['calleeNativeAttempt'] = $callee === null ? null : self::profile($callee);
        if ($callee === null || $callee->kind !== FunctionLikeKind::Method || strcasecmp($callee->identifier->class ?? '', $owner) !== 0 || strcasecmp($callee->name, $methodName) !== 0 || strcasecmp($callee->originalName, $methodName) !== 0 || $callee->static || $callee->abstract || $callee->flags->contains(MetadataFlags::BY_REFERENCE) || $callee->flags->contains(MetadataFlags::POLYFILL) || !$callee->flags->contains(MetadataFlags::DEPRECATED) || $callee->flags->contains(MetadataFlags::BUILTIN) !== $builtin) {
            return $receipt;
        }
        $receipt['stages']['callee'] = true;
        if ($builtin) {
            if (hash('sha256', json_encode(self::canonical(self::profile($callee)), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) !== DeprecatedMethodBuiltinProfile::SHA256) {
                return $receipt;
            }
            $receipt['stages']['builtinOrSourceProfile'] = true;
            $receipt['profileKind'] = 'observed-native-builtin-ReflectionMethod';
        } else {
            $declaration = $this->read($callee->location->file, $consumed);
            if ($declaration === null) {
                return $receipt;
            }
            $owners = $finder->find($declaration, static fn(Node $node): bool => $node instanceof Node\Stmt\Class_ && isset($node->namespacedName) && strcasecmp($node->namespacedName->toString(), $owner) === 0);
            if (count($owners) !== 1 || !self::classMatches($receiverClass, $owners[0], $callee->location->file)) {
                return $receipt;
            }
            $methods = array_values(array_filter($owners[0]->getMethods(), static fn(Node\Stmt\ClassMethod $node): bool => strcasecmp($node->name->name, $methodName) === 0));
            if (count($methods) !== 1 || preg_match('/@deprecated\b/i', $methods[0]->getDocComment()?->getText() ?? '') !== 1 || array_map(static fn(Node\Param $param): string => $param->var->name, $methods[0]->params) !== $profile['parameters'] || !self::methodMatches($callee, $methods[0], $owner, $callee->location->file, $context->types)) {
                return $receipt;
            }
            $receipt['stages']['builtinOrSourceProfile'] = true;
            $receipt['profileKind'] = 'current-physical-source-deprecated-method';
            $receipt['declaration'] = ['file' => $callee->location->file, 'sha256' => $consumed[self::path($callee->location->file)]['sha256'], 'span' => self::span($methods[0])];
        }
        foreach ($consumed as $source) {
            if (hash_file('sha256', $source['disk']) !== $source['sha256']) {
                return $receipt;
            }
        }
        $receipt['stages']['finalSourceCheck'] = true;
        $receipt['remove'] = true;
        $receipt['nativeMethod'] = self::profile($callee);
        return $receipt;
    }
    private function read(string $file, array &$consumed, ?string $expected = null): ?array
    {
        $disk = self::disk($this->root, $file);
        clearstatcache(true, $disk);
        $size = @filesize($disk);
        if ($size === false || $size > 1000000 || count($consumed) >= 16) {
            return null;
        }
        $bytes = @file_get_contents($disk);
        if ($bytes === false || $expected !== null && $bytes !== $expected) {
            return null;
        }
        $hash = hash('sha256', $bytes);
        $scan = $this->files[self::path($file)] ?? null;
        if ($scan !== null && $hash !== $scan['sha256']) {
            return null;
        }
        $consumed[self::path($file)] = ['disk' => $disk, 'sha256' => $hash];
        return self::parse($bytes);
    }
    public static function parse(string $bytes): ?array
    {
        try {
            return (new NodeTraverser(new NameResolver()))->traverse((new ParserFactory())->createForNewestSupportedVersion()->parse($bytes) ?? []);
        } catch (\PhpParser\Error|\LogicException) {
            return null;
        }
    }
    public static function path(string $file): string
    {
        $file = str_replace('\\', '/', $file);
        if (str_starts_with($file, '//?/')) {
            $file = substr($file, 4);
        }
        return PHP_OS_FAMILY === 'Windows' ? strtolower($file) : $file;
    }
    private static function disk(string $root, string $file): string
    {
        $file = self::path($file);
        return preg_match('~^(?:[A-Za-z]:/|/)~', $file) === 1 ? $file : rtrim($root, '/\\') . '/' . $file;
    }
    public static function span(Node $node): array
    {
        return [$node->getStartFilePos(), $node->getEndFilePos() + 1];
    }
    private static function located(?SourceLocation $location, Node $node, string $file): bool
    {
        return $location !== null && self::path($location->file) === self::path($file) && [$location->span->start, $location->span->end] === self::span($node);
    }
    private static function plain(array $args): bool
    {
        foreach ($args as $arg) {
            if (!$arg instanceof Node\Arg || $arg->unpack || $arg->byRef || $arg->name !== null) {
                return false;
            }
        }
        return true;
    }
    private static function closed(Node\Stmt\ClassMethod $scope, int $before): bool
    {
        if ($scope->byRef || $scope->stmts === null) {
            return false;
        }
        foreach ($scope->params as $param) {
            if ($param->byRef || $param->variadic || !$param->var instanceof Node\Expr\Variable || !is_string($param->var->name)) {
                return false;
            }
        }
        return (new NodeFinder())->findFirst($scope->stmts, static fn(Node $node): bool => $node->getStartFilePos() < $before && ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction || $node instanceof Node\Expr\AssignRef || $node instanceof Node\Expr\Eval_ || $node instanceof Node\Expr\Include_ || $node instanceof Node\Stmt\Global_ || $node instanceof Node\Stmt\Static_ || $node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassLike || $node instanceof Node\Expr\Variable && !is_string($node->name) || $node instanceof Node\Expr\FuncCall && (!$node->name instanceof Node\Name || in_array(strtolower($node->name->getLast()), ['extract', 'parse_str', 'class_alias', 'compact'], true)))) === null;
    }
    private static function receiver(Node\Expr\MethodCall $call, Node\Stmt\ClassMethod $scope): ?array
    {
        if ($call->var instanceof Node\Expr\New_ && $call->var->class instanceof Node\Name\FullyQualified && self::plain($call->var->args)) {
            return ['class' => $call->var->class->toString(), 'sourceKind' => 'direct-new'];
        }
        if (!$call->var instanceof Node\Expr\Variable || !is_string($call->var->name) || in_array($call->var->name, ['this', 'GLOBALS', 'argv', '_SERVER'], true)) {
            return null;
        }
        $name = $call->var->name;
        $param = null;
        foreach ($scope->params as $candidate) {
            if ($candidate->var->name === $name) {
                $param = $candidate;
                break;
            }
        }
        $prefix = (new NodeFinder())->find($scope->stmts ?? [], static fn(Node $node): bool => $node->getStartFilePos() < $call->getStartFilePos() && $node instanceof Node\Expr\Variable && $node->name === $name);
        $safeMentions = [];
        foreach ((new NodeFinder())->findInstanceOf($scope->stmts ?? [], Node\Expr\MethodCall::class) as $priorCall) {
            if ($priorCall->getStartFilePos() >= $call->getStartFilePos()) {
                continue;
            }
            if ($priorCall->var instanceof Node\Expr\Variable && $priorCall->var->name === $name && $priorCall->name instanceof Node\Identifier && !$priorCall->isFirstClassCallable() && self::plain($priorCall->args)) {
                $safeMentions[spl_object_id($priorCall->var)] = true;
            }
        }
        $unsafePrefix = array_values(array_filter($prefix, static fn(Node $node): bool => !isset($safeMentions[spl_object_id($node)])));
        if ($param !== null && $unsafePrefix === [] && $param->type instanceof Node\Name\FullyQualified) {
            return ['class' => $param->type->toString(), 'sourceKind' => 'declared-native-formal', 'name' => $name];
        }
        // A fresh top-level physical new binding; every other prior mention conservatively defers.
        $assignments = [];
        foreach ($scope->stmts ?? [] as $statement) {
            if ($statement->getStartFilePos() >= $call->getStartFilePos()) {
                break;
            }
            $assignment = $statement instanceof Node\Stmt\Expression && $statement->expr instanceof Node\Expr\Assign ? $statement->expr : null;
            if ($assignment !== null && $assignment->var instanceof Node\Expr\Variable && $assignment->var->name === $name) {
                $assignments[] = $assignment;
            }
        }
        if ($param !== null || count($assignments) !== 1 || count($unsafePrefix) !== 1 || $unsafePrefix[0] !== $assignments[0]->var || !$assignments[0]->expr instanceof Node\Expr\New_ || !$assignments[0]->expr->class instanceof Node\Name\FullyQualified || !self::plain($assignments[0]->expr->args)) {
            return null;
        }
        return ['class' => $assignments[0]->expr->class->toString(), 'sourceKind' => 'fresh-local-new', 'name' => $name];
    }
    private static function classMatches(?\Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata $metadata, Node\Stmt\Class_ $syntax, string $file): bool
    {
        return $metadata !== null && isset($syntax->namespacedName) && $metadata->kind === ClassLikeKind::Class_ && !$metadata->hasIncompleteHierarchy() && $metadata->templates === [] && $metadata->mixins === [] && strcasecmp($metadata->name, $syntax->namespacedName->toString()) === 0 && !$metadata->flags->contains(MetadataFlags::BUILTIN) && !$metadata->flags->contains(MetadataFlags::POLYFILL) && self::located($metadata->nameLocation, $syntax->name, $file) && self::located($metadata->location, $syntax, $file) && strcasecmp($metadata->directParentClass ?? '', $syntax->extends?->toString() ?? '') === 0 && $syntax->isFinal() === $metadata->flags->contains(MetadataFlags::FINAL) && $syntax->isAbstract() === $metadata->flags->contains(MetadataFlags::ABSTRACT);
    }
    private static function syntaxType(?Node $syntax): ?Type
    {
        if ($syntax === null) {
            return Type::mixed();
        }
        if ($syntax instanceof Node\NullableType) {
            $inner = self::syntaxType($syntax->type);
            return $inner === null ? null : Type::union($inner, Type::null());
        }
        if ($syntax instanceof Node\UnionType) {
            $items = array_map(self::syntaxType(...), $syntax->types);
            return in_array(null, $items, true) ? null : Type::union(...$items);
        }
        if ($syntax instanceof Node\Name\FullyQualified) {
            return Type::namedObject($syntax->toString());
        }
        if (!$syntax instanceof Node\Identifier) {
            return null;
        }
        return match (strtolower($syntax->name)) {
            'string' => Type::string(),
            'int' => Type::int(),
            'float' => Type::float(),
            'bool' => Type::bool(),
            'mixed' => Type::mixed(),
            'void' => Type::void(),
            'array' => Type::array(Type::fromAtomic(new \Mago\Sdk\Analyzer\Type\ScalarType(\Mago\Sdk\Analyzer\Type\ScalarTypeKind::ArrayKey)), Type::mixed()),
            'object' => Type::object(),
            'null' => Type::null(),
            default => null,
        };
    }
    private static function methodMatches(FunctionLikeMetadata $metadata, Node\Stmt\ClassMethod $syntax, string $owner, string $file, TypeComparator $types): bool
    {
        if ($metadata->kind !== FunctionLikeKind::Method || strcasecmp($metadata->identifier->class ?? '', $owner) !== 0 || strcasecmp($metadata->name, $syntax->name->name) !== 0 || strcasecmp($metadata->originalName, $syntax->name->name) !== 0 || !self::located($metadata->location, $syntax, $file) || !self::located($metadata->nameLocation, $syntax->name, $file) || $metadata->static !== $syntax->isStatic() || $metadata->abstract !== $syntax->isAbstract() || $metadata->final !== $syntax->isFinal() || $metadata->flags->contains(MetadataFlags::BY_REFERENCE) !== $syntax->byRef || $metadata->templates !== [] || $metadata->whereConstraints !== [] || $metadata->assertions !== [] || $metadata->ifTrueAssertions !== [] || $metadata->ifFalseAssertions !== [] || $metadata->globalsAccessed !== [] || count($metadata->parameters) !== count($syntax->params)) {
            return false;
        }
        $expected = self::syntaxType($syntax->returnType);
        if ($syntax->returnType === null || $expected === null || $metadata->declaredReturnType === null || $metadata->returnType === null || $metadata->declaredReturnType->fromDocblock || $metadata->declaredReturnType->inferred || !self::located($metadata->declaredReturnType->location, $syntax->returnType, $file) || !$types->equals($metadata->declaredReturnType->type, $expected) || !$types->equals($metadata->returnType->type, $expected)) {
            return false;
        }
        foreach ($syntax->params as $index => $param) {
            $native = $metadata->parameters[$index];
            $type = self::syntaxType($param->type);
            if (!$param->var instanceof Node\Expr\Variable || !is_string($param->var->name) || $type === null || $native->name !== '$' . $param->var->name || !self::located($native->location, $param, $file) || !self::located($native->nameLocation, $param->var, $file) || $native->flags->contains(MetadataFlags::BY_REFERENCE) !== $param->byRef || $native->flags->contains(MetadataFlags::VARIADIC) !== $param->variadic || $param->byRef || $param->variadic || $native->outType !== null || $native->closureThisType !== null || $native->flags->contains(MetadataFlags::HAS_DEFAULT) !== ($param->default !== null) || $native->declaredType === null || $native->type === null || $native->declaredType->fromDocblock || $native->declaredType->inferred || !$types->equals($native->declaredType->type, $type) || !$types->equals($native->type->type, $type)) {
                return false;
            }
            if ($param->type !== null && !self::located($native->declaredType->location, $param->type, $file)) {
                return false;
            }
            if ($param->default !== null && (!$param->default instanceof Node\Expr\ConstFetch || strtolower($param->default->name->toString()) !== 'null' || $native->defaultType === null || !$types->equals($native->defaultType->type, Type::null()))) {
                return false;
            }
        }
        return true;
    }
    /** Same bounded projection as the parent's genuine builtin observation; enums are explicitly normalized. */
    public static function profile(FunctionLikeMetadata $method): array
    {
        return ['identifier' => self::canonical($method->identifier), 'kind' => $method->kind->name, 'name' => $method->name, 'originalName' => $method->originalName, 'flags' => $method->flags->bits, 'deprecated' => $method->flags->contains(MetadataFlags::DEPRECATED), 'builtin' => $method->flags->contains(MetadataFlags::BUILTIN), 'userDefined' => $method->flags->contains(MetadataFlags::USER_DEFINED), 'location' => self::canonical($method->location), 'nameLocation' => self::canonical($method->nameLocation), 'declaredReturn' => self::canonical($method->declaredReturnType), 'effectiveReturn' => self::canonical($method->returnType), 'parameters' => self::canonical($method->parameters), 'availableVersions' => self::canonical($method->availableVersions), 'attributes' => self::canonical($method->attributes), 'templates' => self::canonical($method->templates), 'globalsAccessed' => $method->globalsAccessed, 'static' => $method->static, 'abstract' => $method->abstract, 'final' => $method->final];
    }
    public static function canonical(mixed $value): mixed
    {
        if ($value instanceof \UnitEnum) {
            return ['enum' => $value::class, 'name' => $value->name];
        }
        if (is_object($value)) {
            $value = ['objectClass' => $value::class] + get_object_vars($value);
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::canonical($item);
            }
            if (!array_is_list($value)) {
                ksort($value);
            }
        }
        return $value;
    }
}
