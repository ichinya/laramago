<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ContainerBindings;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ModelReflection;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Ichinya\Laramago\Analyzer\StaticAnalysis\VoltRouteComponents;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\NodeAnalysisHook;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Syntax\NodeKind;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/** Check literal Volt route components against an independent effective-name assertion. */
final class VoltRouteComponentReferencesHook implements NodeAnalysisHook, InitializationHook
{
    private const VOLT = 'Livewire\\Volt\\Volt';
    private const MANAGER = 'Livewire\\Volt\\VoltManager';
    // Complete native methods in livewire/volt main; comments and whitespace excluded.
    private const ROUTE_HASH = '17a5b29669423123aa1182e40c5510bb700868da334dcd2ec6874026c3de629d';
    private const ACCESSOR_HASH = '6d9041455b84c4eff96b9b39cc8afbf94714f71c65d486284a2f0839743b7d8b';

    private ?VoltRouteComponents $components = null;
    private PhpSource $source;
    private ?ContainerBindings $bindings = null;
    private string $sourceHash = '';
    /** @var array<string, Node\Expr\StaticCall> */
    private array $calls = [];

    public function __construct(
        private readonly string $root = '.',
    ) {
        $this->source = new PhpSource($root);
    }

    public function initialize(InitializationContext $context): void
    {
        $this->components = null;
        $this->source = new PhpSource($this->root);
        $this->bindings = null;
        $this->sourceHash = '';
        $this->calls = [];
    }

    public function getTargets(): array
    {
        return [NodeKind::StaticMethodCall];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $components = $this->components ??= new VoltRouteComponents($this->root);
        if (! $components->isComplete()) {
            return;
        }
        $call = $this->call($context);
        if (
            $call === null
            || $call->isFirstClassCallable()
            || ! $call->class instanceof Node\Name
            || strcasecmp($call->class->toString(), self::VOLT) !== 0
            || ! $call->name instanceof Node\Identifier
            || strcasecmp($call->name->name, 'route') !== 0
        ) {
            return;
        }
        if (! $this->nativeRoute($context)) {
            return;
        }

        $component = null;
        $named = false;
        foreach ($call->getArgs() as $offset => $argument) {
            if ($argument->unpack || $named && $argument->name === null) {
                return;
            }
            $name = $argument->name?->name ?? ['uri', 'componentName'][$offset] ?? null;
            if ($name === null || ! in_array($name, ['uri', 'componentName'], true)) {
                return;
            }
            $named = $argument->name !== null;
            if ($name === 'componentName') {
                if ($component !== null) {
                    return;
                }
                $component = $argument->value;
            }
        }
        if (
            ! $component instanceof Node\Scalar\String_
            || preg_match('/[.-]/', $component->value) !== 1
            || $components->contains($component->value) !== false
        ) {
            return;
        }
        $context->report(
            Level::Warning,
            'laramago-missing-volt-route-component',
            Issue::at(
                'Volt route component "'
                .$component->value
                .'" is absent from the explicitly complete effective volt-route-components catalog.',
                new SourceLocation($context->source->path, $context->node->span),
            ),
        );
    }

    private function nativeRoute(NodeAnalysisContext $context): bool
    {
        $class = $context->codebase->getClassLike(self::VOLT);
        $manager = $context->codebase->getClassLike(self::MANAGER);
        $facadeFile = str_replace('\\', '/', $class?->location->file ?? '');
        $managerFile = str_replace('\\', '/', $manager?->location->file ?? '');
        if (
            $class === null
            || $manager === null
            || $class->hasIncompleteHierarchy()
            || strcasecmp($class->directParentClass ?? '', 'Illuminate\\Support\\Facades\\Facade') !== 0
            || ! str_ends_with($facadeFile, '/livewire/volt/src/Volt.php')
            || ! str_ends_with($managerFile, '/livewire/volt/src/VoltManager.php')
            || ($this->bindings ??= new ContainerBindings($this->root))->configured(self::MANAGER)
        ) {
            return false;
        }
        $facadeNode = (new NodeFinder)->findFirst(
            $this->source->read($facadeFile) ?? [],
            static fn (Node $node): bool => (
                $node instanceof Node\Stmt\Class_
                && $node->namespacedName?->toString() === self::VOLT
            ),
        );
        if (
            ! $facadeNode instanceof Node\Stmt\Class_
            || $facadeNode->getMethod('route') !== null
            || $facadeNode->getTraitUses() !== []
        ) {
            return false;
        }
        $accessor = $context->codebase->getDeclaringMethod(self::VOLT, 'getFacadeAccessor');
        $route = $context->codebase->getDeclaringMethod(self::MANAGER, 'route');
        $dispatcher = $context->codebase->getDeclaringMethod(self::VOLT, '__callStatic');
        $rootGetter = $context->codebase->getDeclaringMethod(self::VOLT, 'getFacadeRoot');
        $resolver = $context->codebase->getDeclaringMethod(self::VOLT, 'resolveFacadeInstance');
        if (
            $accessor === null
            || $route === null
            || $dispatcher === null
            || $rootGetter === null
            || $resolver === null
            || strcasecmp($accessor->identifier->class ?? '', self::VOLT) !== 0
            || strcasecmp($route->identifier->class ?? '', self::MANAGER) !== 0
            || $route->static
            || PhpSource::value(
                (new ModelReflection($context->codebase, $this->source))->returnExpression($accessor),
                self::VOLT,
                self::VOLT,
            ) !== self::MANAGER
        ) {
            return false;
        }
        foreach ([$dispatcher, $rootGetter, $resolver] as $dispatchMethod) {
            if (
                strcasecmp($dispatchMethod->identifier->class ?? '', 'Illuminate\\Support\\Facades\\Facade') !== 0
                || ! str_ends_with(
                    str_replace('\\', '/', $dispatchMethod->location->file ?? ''),
                    '/laravel/framework/src/Illuminate/Support/Facades/Facade.php',
                )
            ) {
                return false;
            }
        }
        $reflection = new ModelReflection($context->codebase, $this->source);
        $accessorNode = $reflection->methodNode($accessor);
        $routeNode = $reflection->methodNode($route);

        return (
            $accessorNode !== null
            && $routeNode !== null
            && self::fingerprint($accessorNode) === self::ACCESSOR_HASH
            && self::fingerprint($routeNode) === self::ROUTE_HASH
        );
    }

    private static function fingerprint(Node $node): string
    {
        $normalized = '';
        foreach (token_get_all('<?php '.(new Standard)->prettyPrint([$node])) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $normalized .= str_replace(["\r\n", "\r"], "\n", $token[1]);
            } else {
                $normalized .= $token;
            }
        }

        return hash('sha256', $normalized);
    }

    private function call(NodeAnalysisContext $context): ?Node\Expr\StaticCall
    {
        $hash = hash('sha256', $context->source->path."\0".$context->source->contents);
        if ($hash !== $this->sourceHash) {
            $this->sourceHash = $hash;
            $this->calls = [];
            try {
                $nodes = (new ParserFactory)
                    ->createForNewestSupportedVersion()
                    ->parse($context->source->contents);
                $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes ?? []);
            } catch (\PhpParser\Error) {
                return null;
            }
            foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Expr\StaticCall::class) as $call) {
                $this->calls[$call->getStartFilePos().':'.($call->getEndFilePos() + 1)] = $call;
            }
        }

        return $this->calls[$context->node->span->start.':'.$context->node->span->end] ?? null;
    }
}
