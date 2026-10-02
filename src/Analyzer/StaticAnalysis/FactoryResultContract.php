<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\CodebaseScanContext;
use Mago\Sdk\Analyzer\CodebaseScanHook;
use Mago\Sdk\Analyzer\Type;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/** Independent syntax and native-contract proof for an uncounted model factory create. */
final class FactoryResultContract implements CodebaseScanHook
{
    private const GUARDS = 'Illuminate\\Database\\Eloquent\\Concerns\\GuardsAttributes';
    /** Native Laravel method and PHPDoc excerpts retained in the licensed factory-result fixtures. */
    private const NATIVE_METHODS = [
        FactoryReflection::FACTORY => [
            '__construct' => '861ab0a5a82aefe5af4b76174beda56efd0b13188c5f11e0dc1fb7c57331d97f',
            'new' => 'b269b68984d2b427e3ed0d8e50eb50262002e6552275c13944bec0f52bd391ce',
            'configure' => 'c5e276685138ad31c1628b98ce67e642d5324b2430f9b6ab717c6c9bdd86d743',
            'create' => 'd49d5360ae56706ba225d8a6726864c6913b4515107f0b5f32bef6503c836bff',
            'make' => 'f2720ef68f3ea251beba951cc67ab3f982e23637e3dd9c1629498c48d33c5bbc',
            'makeInstance' => 'f407c3bad766be3746cc50b449b0a187f39ca1fa720b53e330e6dcc94d628b62',
            'state' => '6ddd945800cc8c8eea7c51313c282fbada43f6e78cce3409a9d03602e884d1b5',
            'count' => '628ea9d53cc1500c1e94ada926dfa1f9b6b52ae108b1ceebf189521a290a7b8f',
            'newInstance' => 'f74168f5a1b50238b2eb9fa4c1f6b71784007a224c3e8133a56808b5994af1eb',
            'newModel' => 'e8a95b5b299d3c78b31c8faa94a1641b810bbee9c17c44dcaa52f604f152caf7',
            'modelName' => 'a1d3eb4886d223e7c283632066a450c1c8e60840a40aec21e3af843783c8c999',
            'factoryForModel' => '6b71ce643be3f433b153e9b7634922608bd96244940df7bb697d99beeebb75bb',
            'resolveFactoryName' => '5f499e4a46617baf0ef649d6d4fa8bf0ea55eb4dc553b8a2e440aaf2b0205318',
            'appNamespace' => '476d2904766c3d5c04d20096e8baab7750bb26fae91cd15ff5a2c9fd235a809f',
        ],
        FactoryReflection::HAS_FACTORY => [
            'factory' => '11ebbc699c98f6dc9b19a094372fd0285ea1363068f21d1dd1d4e495672bacd1',
            'newFactory' => '08509527b1c58a1fadea0dd1879f0deca255438d7f9a706427cf963e76be82c1',
            'getUseFactoryAttribute' => '0e7dbdcbdaf73266ffe7c38776486efb5fa713cba7a305d44b35892f88109955',
        ],
        self::GUARDS => [
            'unguard' => 'bcbdb8c080b526643dcc9cb1bd03ea93e593185b3e497bf16869ed34f7bf5d8d',
            'reguard' => 'b671eb2d9f4601156fb9f7a1af76b149a37158fa4365ef35be6fdb4f8a6b06b2',
            'unguarded' => '8a70a4d751417ece68d933c2b4c8489b1916be7a46c75196b5dbb00bd7aa37cc',
        ],
    ];

    private PhpSource $source;
    /** @var array<string, bool> */
    private array $standardFactories = [];
    private ?bool $native = null;
    /** @var array<string, string|null> Exact analyzed host-file hashes; null poisons duplicate paths. */
    private array $analyzedSources = [];
    private bool $scanStarted = false;
    private bool $scanComplete = false;
    private bool $scanFailed = false;
    private bool $mappingConfigured = false;

    public function __construct(string $root = '.')
    {
        $this->source = new PhpSource($root);
    }

    public function getTargets(): array
    {
        return ['**'];
    }

    public function scan(CodebaseScanContext $context): void
    {
        if ($context->firstBatch) {
            $this->source = new PhpSource($this->source->root);
            $this->analyzedSources = [];
            $this->scanFailed = false;
            $this->native = null;
            $this->standardFactories = [];
            $this->mappingConfigured = false;
        }
        $this->scanStarted = true;
        $this->scanComplete = false;
        foreach ($context->files as $file) {
            $context->cancellation->throwIfCancelled();
            $path = $this->path($file->path);
            $this->analyzedSources[$path] = array_key_exists($path, $this->analyzedSources)
                ? null : hash('sha256', $file->contents);
            if (! $this->mappingConfigured) {
                if (strlen($file->contents) > 2_000_000) {
                    $this->scanFailed = true;
                    break;
                }
                try {
                    $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($file->contents) ?? [];
                    $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes);
                    // Native setter declarations do not execute during inclusion.
                    // Actual top-level configuration in a native host file still poisons the proof.
                    if (str_contains(str_replace('\\', '/', $file->path), '/laravel/framework/src/')) {
                        $nodes = self::outsideClasses($nodes);
                    }
                    $this->mappingConfigured = (new NodeFinder)->findFirst($nodes, self::knownMappingMutation(...)) !== null;
                } catch (\PhpParser\Error) {
                    $this->scanFailed = true;
                    break;
                }
            }
            if (count($this->analyzedSources) > 100_000) {
                $this->scanFailed = true;
                break;
            }
        }
        $this->scanComplete = $context->lastBatch && ! $this->scanFailed;
    }

    /** Keep the provider's existing subclass/state contract shared with source proofs. */
    public function standardCore(FactoryReflection $reflection, string $factory): bool
    {
        if (array_key_exists($factory, $this->standardFactories)) {
            return $this->standardFactories[$factory];
        }
        foreach (['__construct', 'new', 'newInstance', 'newModel', 'modelName', 'create', 'make', 'makeInstance', 'count', 'state'] as $name) {
            $method = $reflection->method($factory, $name);
            if ($method !== null && strcasecmp($method->identifier->class ?? '', FactoryReflection::FACTORY) !== 0) {
                return $this->standardFactories[$factory] = false;
            }
        }

        // Publish only complete metadata; concurrent SDK requests can interleave in a worker.
        return $this->standardFactories[$factory] = ! $reflection->hasStateMutation($factory);
    }

    public function resolve(Codebase $codebase, Node\Expr $expr, ?string $scopeClass = null): ?Type
    {
        if (! $this->scanStarted || ! $this->scanComplete || $this->mappingConfigured) {
            return null;
        }
        if (! $expr instanceof Node\Expr\MethodCall || ! $expr->name instanceof Node\Identifier
            || strcasecmp($expr->name->name, 'create') !== 0 || count($expr->args) > 1
            || ! $expr->var instanceof Node\Expr\StaticCall || ! $expr->var->class instanceof Node\Name
            || ! $expr->var->name instanceof Node\Identifier || strcasecmp($expr->var->name->name, 'factory') !== 0
            || $expr->var->args !== []) {
            return null;
        }
        foreach ($expr->args as $argument) {
            if (! $argument instanceof Node\Arg || $argument->unpack || $argument->byRef
                || ($argument->name !== null && $argument->name->name !== 'attributes')
                || ! $argument->value instanceof Node\Expr\Array_) {
                return null;
            }
            if ((new NodeFinder)->findFirst($argument->value->items, static fn (Node $node): bool =>
                $node instanceof Node\ArrayItem && ($node->byRef || $node->unpack)
                || $node instanceof Node\Expr\AssignRef || self::knownMappingMutation($node)) !== null) {
                return null;
            }
        }
        $model = $expr->var->class->toString();
        if (in_array(strtolower($model), ['self', 'static', 'parent'], true)) {
            // Late static binding is exact only inside a final declaring class.
            if ($scopeClass === null || strtolower($model) === 'parent'
                || ! ($codebase->getClass($scopeClass)?->flags->contains(\Mago\Sdk\Analyzer\Metadata\MetadataFlags::FINAL) ?? false)) {
                return null;
            }
            $model = $scopeClass;
        } elseif (! $expr->var->class instanceof Node\Name\FullyQualified) {
            // The caller must supply a namespace-resolved AST.
            return null;
        }
        $reflection = new FactoryReflection($codebase, $this->source);
        if (! $reflection->inherits($model, ModelReflection::MODEL) || ! $this->classSourcesMatch($codebase, $model)
            || ! $this->nativeSourcesMatch($codebase) || ! $this->native($codebase)
            || $codebase->getClass($model)?->hasIncompleteHierarchy()) {
            return null;
        }
        foreach (['factory', 'newFactory', 'getUseFactoryAttribute'] as $name) {
            $method = $reflection->method($model, $name);
            if ($method === null || strcasecmp($method->identifier->class ?? '', FactoryReflection::HAS_FACTORY) !== 0) {
                return null;
            }
        }
        $factory = $reflection->modelFactory($model);
        if ($factory === null || ! $this->classSourcesMatch($codebase, $factory)
            || ! $this->standardCore($reflection, $factory) || $this->hasMappingMutation($codebase, $reflection, $factory)
            || $codebase->getClass($factory)?->hasIncompleteHierarchy()
            || strcasecmp($reflection->method($factory, 'configure')?->identifier->class ?? '', FactoryReflection::FACTORY) !== 0) {
            return null;
        }
        foreach (['appNamespace', 'factoryForModel', 'resolveFactoryName'] as $name) {
            if (strcasecmp($reflection->method($factory, $name)?->identifier->class ?? '', FactoryReflection::FACTORY) !== 0) {
                return null;
            }
        }
        foreach ([[$model, 'Illuminate\\Database\\Eloquent\\Attributes\\UseFactory'], [$factory, 'Illuminate\\Database\\Eloquent\\Factories\\Attributes\\UseModel']] as [$class, $attributeName]) {
            foreach ([$class, ...$codebase->getClassAncestors($class)] as $ancestor) {
                foreach ($codebase->getClassLike($ancestor)?->attributes ?? [] as $attribute) {
                    if (strcasecmp($attribute->name, $attributeName) === 0) {
                        return null;
                    }
                }
            }
        }
        $modelReflection = new ModelReflection($codebase, $this->source);
        foreach (['namespace' => 'Database\\Factories\\', 'modelNameResolver' => null, 'factoryNameResolver' => null, 'modelNameResolvers' => [], 'cachedModelAttributes' => []] as $property => $expected) {
            if ($modelReflection->default($factory, $property) !== $expected) {
                return null;
            }
        }
        $configuredFactory = $modelReflection->default($model, 'factory');
        $relative = self::relativeModel($codebase->getClass($model)?->originalName ?? $model);
        $dispatchedFactory = $configuredFactory ?? ($relative === null ? null : 'Database\\Factories\\'.$relative.'Factory');
        if (!is_string($dispatchedFactory) || strcasecmp($dispatchedFactory, $factory) !== 0) {
            // Generic documentation must agree with the actual native dispatch.
            return null;
        }
        $result = $reflection->factoryModel($factory);
        $configuredModel = $modelReflection->default($factory, 'model');
        if ($configuredModel === null && str_starts_with($factory, 'Database\\Factories\\') && str_ends_with($factory, 'Factory')) {
            $relative = substr($factory, 19, -7);
            $candidate = 'App\\Models\\'.$relative;
            $configuredModel = $codebase->classExists($candidate) ? $candidate : 'App\\'.$relative;
        }

        return $result === null || !is_string($configuredModel) || strcasecmp($configuredModel, $result) !== 0
            ? null : Type::namedObject($result);
    }

    private static function relativeModel(string $model): ?string
    {
        return str_starts_with($model, 'App\\Models\\') ? substr($model, 11)
            : (str_starts_with($model, 'App\\') ? substr($model, 4) : null);
    }

    /** Native defaults do not survive resolver writes or reference escapes in custom methods. */
    private function hasMappingMutation(Codebase $codebase, FactoryReflection $reflection, string $factory): bool
    {
        foreach ([$factory, ...$codebase->getClassAncestors($factory)] as $class) {
            if (strcasecmp($class, FactoryReflection::FACTORY) === 0) {
                continue;
            }
            foreach ($codebase->getClassLike($class)?->methods ?? [] as $name) {
                $method = $reflection->method($class, $name);
                if ($method === null || strcasecmp($method->identifier->class ?? '', FactoryReflection::FACTORY) === 0) {
                    continue;
                }
                // Definition is the supported customization point; native hooks can
                // change count or mapping before create/make constructs its result.
                if (strtolower($name) !== 'definition' && $reflection->method(FactoryReflection::FACTORY, $name) !== null) {
                    return true;
                }
                if (! $this->sourceMatches($method->location->file)) {
                    return true;
                }
                $node = (new ModelReflection($codebase, $this->source))->methodNode($method);
                if ($node === null || (new NodeFinder)->findFirst([$node], self::mappingMutation(...)) !== null) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function mappingMutation(Node $node): bool
    {
        if ($node instanceof Node\Expr\AssignRef || $node instanceof Node\Param && $node->byRef
            || $node instanceof Node\ClosureUse && $node->byRef || $node instanceof Node\ArrayItem && $node->byRef
            || $node instanceof Node\Arg && $node->byRef || $node instanceof Node\Stmt\ClassMethod && $node->byRef) {
            return true;
        }
        if ($node instanceof Node\Expr\PropertyFetch
            && (! $node->name instanceof Node\Identifier || in_array(strtolower($node->name->name), ['count', 'model'], true))) {
            return true;
        }
        if ($node instanceof Node\Expr\FuncCall && ! $node->name instanceof Node\Name) {
            return true;
        }
        if ($node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\MethodCall) {
            if (! $node->name instanceof Node\Identifier) {
                return true;
            }
        }
        return self::knownMappingMutation($node);
    }

    private static function knownMappingMutation(Node $node): bool
    {
        if (($node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\MethodCall)
            && $node->name instanceof Node\Identifier && in_array(strtolower($node->name->name), [
                'guessmodelnamesusing', 'guessfactorynamesusing', 'usenamespace', 'flushstate',
            ], true)) {
            return true;
        }
        if ($node instanceof Node\Stmt\Unset_) {
            foreach ($node->vars as $target) {
                if (self::mappingProperty($target)) {
                    return true;
                }
            }
        }
        return ($node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignRef || $node instanceof Node\Expr\AssignOp
            || $node instanceof Node\Expr\PreInc || $node instanceof Node\Expr\PostInc
            || $node instanceof Node\Expr\PreDec || $node instanceof Node\Expr\PostDec)
            && self::mappingProperty($node->var);
    }

    private static function mappingProperty(Node\Expr $target): bool
    {
        while ($target instanceof Node\Expr\ArrayDimFetch) {
            $target = $target->var;
        }
        return $target instanceof Node\Expr\StaticPropertyFetch
            && (! $target->name instanceof Node\VarLikeIdentifier || in_array(strtolower($target->name->name), [
                'namespace', 'modelnameresolver', 'modelnameresolvers', 'factorynameresolver',
                'cachedmodelattributes', 'factory', 'model', 'count',
            ], true));
    }

    /** @param array<Node> $nodes
     * @return array<Node>
     */
    private static function outsideClasses(array $nodes): array
    {
        $result = [];
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                $result = [...$result, ...self::outsideClasses($node->stmts)];
            } elseif (! $node instanceof Node\Stmt\ClassLike) {
                $result[] = $node;
            }
        }
        return $result;
    }

    private function classSourcesMatch(Codebase $codebase, string $class): bool
    {
        foreach ([$class, ...$codebase->getClassAncestors($class)] as $ancestor) {
            if (! $this->sourceMatches($codebase->getClassLike($ancestor)?->location->file)) {
                return false;
            }
        }
        return true;
    }

    private function nativeSourcesMatch(Codebase $codebase): bool
    {
        foreach (self::NATIVE_METHODS as $class => $methods) {
            foreach (array_keys($methods) as $name) {
                if (! $this->sourceMatches($codebase->getDeclaringMethod($class, $name)?->location->file)) {
                    return false;
                }
            }
        }
        return $this->sourceMatches($codebase->getFunction('tap')?->location->file);
    }

    private function sourceMatches(?string $file): bool
    {
        if ($file === null) {
            return false;
        }
        $path = $this->path($file);
        if (! array_key_exists($path, $this->analyzedSources)) {
            // The SDK scans host files only. Includes have no exposed source snapshot;
            // they retain the separate audited disk/source/signature contract.
            return true;
        }
        return $this->analyzedSources[$path] !== null
            && @hash_file('sha256', $path) === $this->analyzedSources[$path];
    }

    private function path(string $file): string
    {
        $file = str_replace('\\', '/', $file);
        if (preg_match('~^//\\?/[A-Za-z]:/~', $file) === 1) {
            $file = substr($file, 4);
        }
        $file = str_replace('\\', '/', $this->source->path($file));
        return PHP_OS_FAMILY === 'Windows' ? strtolower($file) : $file;
    }

    private function native(Codebase $codebase): bool
    {
        return $this->native ??= $this->verifyNative($codebase);
    }

    private function verifyNative(Codebase $codebase): bool
    {
        $reflection = new ModelReflection($codebase, $this->source);
        $factoryFile = $codebase->getClassLike(FactoryReflection::FACTORY)?->location->file;
        $factoryNodes = $factoryFile === null ? null : $this->source->read($factoryFile);
        if (count($factoryNodes ?? []) !== 1 || ! $factoryNodes[0] instanceof Node\Stmt\Namespace_
            || self::fingerprint($factoryNodes[0]) !== 'c31ae49f829b78f3aeb2165de80cf4e9e2a06887de5aec2aebb248adc56c9cd3') {
            return false;
        }
        foreach (self::NATIVE_METHODS as $class => $methods) {
            foreach ($methods as $name => $hash) {
                $method = $codebase->getDeclaringMethod($class, $name);
                if ($method === null || strcasecmp($method->identifier->class ?? '', $class) !== 0
                    || ! self::nativeFile($method->location->file, str_replace('\\', '/', $class).'.php')) {
                    return false;
                }
                $node = $reflection->methodNode($method);
                if ($node === null || self::fingerprint($node) !== $hash) {
                    return false;
                }
            }
        }
        foreach ([FactoryReflection::FACTORY => 'f53a355dc8f08e79d078fc82c20a9538ba15445da5cd623324166240dc2b31b2', FactoryReflection::HAS_FACTORY => '6391e747685350bd276c6fbc9cbc814c1a49e393fd53176d050e781d1bde2840'] as $class => $hash) {
            $metadata = $codebase->getClassLike($class);
            $file = $metadata?->location->file;
            if ($file === null) {
                return false;
            }
            $declaration = (new NodeFinder)->findFirst($this->source->read($file) ?? [], static fn (Node $node): bool =>
                $node instanceof Node\Stmt\ClassLike && strcasecmp($node->namespacedName?->toString() ?? '', $class) === 0
                && $node->getStartFilePos() >= $metadata->location->span->start
                && $node->getStartFilePos() < $metadata->location->span->end);
            if ($declaration === null || hash('sha256', preg_replace('/\s+/', '', $declaration->getDocComment()?->getText() ?? '') ?? '') !== $hash) {
                return false;
            }
        }
        foreach (['unguarded', 'unguard', 'reguard'] as $name) {
            if (strcasecmp($codebase->getDeclaringMethod(ModelReflection::MODEL, $name)?->identifier->class ?? '', self::GUARDS) !== 0) {
                return false;
            }
        }
        foreach (['is_numeric', 'is_callable', 'is_array', 'array_values', 'array_merge', 'array_key_exists', 'class_exists', 'class_basename', 'tap'] as $name) {
            if ($codebase->functionExists('Illuminate\\Database\\Eloquent\\Factories\\'.$name)) {
                return false;
            }
        }
        $helper = $codebase->getFunction('tap');
        $path = $helper?->location->file;
        if ($path === null || ! self::nativeFile($path, 'Illuminate/Support/helpers.php')) {
            return false;
        }
        $node = (new NodeFinder)->findFirst($this->source->read($path) ?? [], static fn (Node $node): bool =>
            $node instanceof Node\Stmt\Function_ && $node->name->name === 'tap'
            && $node->getStartFilePos() >= $helper->location->span->start
            && $node->getStartFilePos() < $helper->location->span->end);

        return $node !== null && self::fingerprint($node) === '0ddab4ca1b8d0069f53d909f477442b0ec5f334ac00df5c0f2ad737f6b183312';
    }

    private static function nativeFile(?string $path, string $suffix): bool
    {
        return str_ends_with(str_replace('\\', '/', $path ?? ''), '/laravel/framework/src/'.$suffix);
    }

    private static function fingerprint(Node $node): string
    {
        $normalized = '';
        foreach (token_get_all('<?php '.(new Standard)->prettyPrint([$node])) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_OPEN_TAG], true)) {
                    continue;
                }
                $normalized .= str_replace(["\r\n", "\r"], "\n", $token[1]);
            } else {
                $normalized .= $token;
            }
        }

        return hash('sha256', $normalized);
    }
}
