<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ContainerBindings;
use Ichinya\Laramago\Analyzer\StaticAnalysis\GateAbilityPolicy;
use Ichinya\Laramago\Analyzer\StaticAnalysis\NativeFacade;
use Ichinya\Laramago\Analyzer\StaticAnalysis\NativeGateAbilityContract;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\MethodCallAnalysisHook;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** Advise on exact literal native Gate calls under an explicit permitted-name policy. */
final class GateAbilityReferencesHook implements MethodCallAnalysisHook, InitializationHook
{
    private const METHODS = ['allows', 'denies', 'check', 'any', 'none', 'authorize', 'inspect', 'raw'];

    private ?GateAbilityPolicy $policy = null;
    private ?NativeGateAbilityContract $contract = null;
    private ?NativeFacade $facade = null;
    private ?ContainerBindings $bindings = null;
    private string $sourceHash = '';
    /** @var array<string, Node\Expr\MethodCall|Node\Expr\StaticCall> */
    private array $calls = [];

    public function __construct(
        private readonly string $root = '.',
    ) {}

    public function initialize(InitializationContext $context): void
    {
        $this->policy = null;
        $this->contract = null;
        $this->facade = null;
        $this->bindings = null;
        $this->sourceHash = '';
        $this->calls = [];
    }

    public function getTargets(): array
    {
        $targets = [];
        foreach (self::METHODS as $method) {
            $targets[] = MethodTarget::exact(NativeGateAbilityContract::GATE, $method);
            $targets[] = MethodTarget::exact(NativeGateAbilityContract::FACADE, $method);
        }

        return $targets;
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::ReceiverType, FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $policy = $this->policy ??= new GateAbilityPolicy($this->root);
        if (! $policy->enabled()) {
            return;
        }
        $call = $this->call($context);
        if ($call === null || $call->isFirstClassCallable() || ! $call->name instanceof Node\Identifier) {
            return;
        }
        $method = $call->name->toString();
        if (! NativeGateAbilityContract::supports($method) || ! $this->nativeReceiver($context, $call, $method)) {
            return;
        }
        $parameter = NativeGateAbilityContract::abilityParameter($method);
        $arguments = self::arguments($call->getArgs(), $parameter);
        $ability = $arguments[$parameter] ?? null;
        if (! $ability instanceof Node\Scalar\String_ || $policy->permits($ability->value) !== false) {
            return;
        }

        $context->report(
            Level::Warning,
            'laramago-gate-ability-outside-policy',
            Issue::at(
                'Gate ability "'.$ability->value.'" is not listed in the configured permitted Gate ability policy.',
                new SourceLocation(
                    $context->source->path,
                    new Span($ability->getStartFilePos(), $ability->getEndFilePos() + 1),
                ),
            ),
        );
    }

    private function nativeReceiver(
        NodeAnalysisContext $context,
        Node\Expr\MethodCall|Node\Expr\StaticCall $call,
        string $method,
    ): bool {
        $this->contract ??= new NativeGateAbilityContract($this->root);
        if (! $this->contract->matchesMethods($context->codebase)) {
            return false;
        }
        if ($call instanceof Node\Expr\StaticCall) {
            if (
                ! $call->class instanceof Node\Name
                || strcasecmp($call->class->toString(), NativeGateAbilityContract::FACADE) !== 0
                || ! $this->contract->matchesFacade($context->codebase)
                || $this->bindings()->configured(NativeGateAbilityContract::ACCESSOR)
                || $this->bindings()->configured(NativeGateAbilityContract::GATE)
            ) {
                return false;
            }
            $this->facade ??= new NativeFacade($this->root);

            return $this->facade->dispatchesClass(
                $context->codebase,
                NativeGateAbilityContract::FACADE,
                NativeGateAbilityContract::ACCESSOR,
                NativeGateAbilityContract::GATE,
                strtolower($method),
            );
        }
        $receiver = $context->receiverType?->atomicTypes[0] ?? null;

        return (
            $context->receiverType !== null
            && count($context->receiverType->atomicTypes) === 1
            && $receiver instanceof NamedObjectType
            && strcasecmp($receiver->name, NativeGateAbilityContract::GATE) === 0
        );
    }

    /**
     * @param array<array-key, Node\Arg|Node\VariadicPlaceholder> $arguments
     * @return array<string, Node\Expr>|null
     */
    private static function arguments(array $arguments, string $abilityParameter): ?array
    {
        if (count($arguments) < 1 || count($arguments) > 2) {
            return null;
        }
        $parameters = [$abilityParameter, 'arguments'];
        $mapped = [];
        $named = false;
        foreach ($arguments as $offset => $argument) {
            if (! $argument instanceof Node\Arg || $argument->unpack || $argument->byRef) {
                return null;
            }
            $parameter = $argument->name?->toString() ?? $parameters[$offset] ?? null;
            if (
                $parameter === null
                || ! in_array($parameter, $parameters, true)
                || isset($mapped[$parameter])
                || $named
                && $argument->name === null
            ) {
                return null;
            }
            $named = $argument->name !== null;
            $mapped[$parameter] = $argument->value;
        }

        return isset($mapped[$abilityParameter]) ? $mapped : null;
    }

    private function call(NodeAnalysisContext $context): Node\Expr\MethodCall|Node\Expr\StaticCall|null
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
            foreach ((new NodeFinder)->find(
                $nodes,
                static fn (Node $node): bool => (
                    $node instanceof Node\Expr\MethodCall
                    || $node instanceof Node\Expr\StaticCall
                ),
            ) as $call) {
                if (! $call instanceof Node\Expr\MethodCall && ! $call instanceof Node\Expr\StaticCall) {
                    continue;
                }
                $this->calls[$call->getStartFilePos().':'.($call->getEndFilePos() + 1)] = $call;
            }
        }

        return $this->calls[$context->node->span->start.':'.$context->node->span->end] ?? null;
    }

    private function bindings(): ContainerBindings
    {
        return $this->bindings ??= new ContainerBindings($this->root);
    }
}
