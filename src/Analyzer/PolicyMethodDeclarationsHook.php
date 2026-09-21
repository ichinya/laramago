<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ContainerBindings;
use Ichinya\Laramago\Analyzer\StaticAnalysis\GateDefinitionCatalog;
use Ichinya\Laramago\Analyzer\StaticAnalysis\NativeFacade;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PolicyMappingCatalog;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\NodeAnalysisHook;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;
use Mago\Sdk\Syntax\NodeKind;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** Report opt-in declaration-quality notes for selected policy mappings at native Gate calls. */
final class PolicyMethodDeclarationsHook implements NodeAnalysisHook, InitializationHook
{
    private const FACADE = 'Illuminate\\Support\\Facades\\Gate';
    private const CONTRACT = 'Illuminate\\Contracts\\Auth\\Access\\Gate';
    private const GATE = 'Illuminate\\Auth\\Access\\Gate';

    /** @var array<string, list<string>> */
    private const PARAMETERS = [
        'allows' => ['ability', 'arguments'],
        'denies' => ['ability', 'arguments'],
        'check' => ['abilities', 'arguments'],
        'any' => ['abilities', 'arguments'],
        'none' => ['abilities', 'arguments'],
        'authorize' => ['ability', 'arguments'],
        'inspect' => ['ability', 'arguments'],
        'raw' => ['ability', 'arguments'],
    ];

    private ?bool $enabled = null;
    private ?PolicyCallContracts $callContracts = null;
    private ?PolicyMappingCatalog $policies = null;
    private ?GateDefinitionCatalog $definitions = null;
    private ?ContainerBindings $bindings = null;
    private ?NativeFacade $facade = null;
    private ?string $sourceHash = null;
    /** @var array<string, Node\Expr\MethodCall|Node\Expr\StaticCall> */
    private array $calls = [];

    public function __construct(
        private readonly string $root = '.',
    ) {}

    public function initialize(InitializationContext $context): void
    {
        $this->enabled = null;
        $this->callContracts = null;
        $this->policies = null;
        $this->definitions = null;
        $this->bindings = null;
        $this->facade = null;
        $this->sourceHash = null;
        $this->calls = [];
    }

    public function getTargets(): array
    {
        return [NodeKind::MethodCall, NodeKind::StaticMethodCall];
    }

    public function getRequirements(): array
    {
        return [
            FileAnalysisRequirement::SourceText,
            FileAnalysisRequirement::ReceiverType,
            FileAnalysisRequirement::ArgumentTypes,
        ];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        if ($this->enabled === null) {
            $this->enabled = $this->enabled();
        }
        $contracts = $this->callContracts ??= new PolicyCallContracts($this->root);
        if (! $this->enabled && ! $contracts->enabled()) {
            return;
        }
        $call = $this->call($context);
        if ($call === null || ! $call->name instanceof Node\Identifier || $call->isFirstClassCallable()) {
            return;
        }
        $method = strtolower($call->name->toString());
        $parameters = self::PARAMETERS[$method] ?? null;
        if ($parameters === null || ! $this->nativeGateCall($context, $call, $method)) {
            return;
        }
        $arguments = self::arguments($call->getArgs(), $parameters);
        if ($arguments === null) {
            return;
        }
        $ability = $arguments[$parameters[0]]['value'] ?? null;
        $target = $arguments['arguments'] ?? null;
        if (! $ability instanceof Node\Scalar\String_ || $target === null) {
            return;
        }
        if (($this->definitions ??= new GateDefinitionCatalog($this->root))->definition($ability->value) !== null) {
            return;
        }
        $model = $this->modelClass($target['value'], $context->argumentTypes[$target['offset']] ?? null);
        if ($model === null) {
            return;
        }
        $contractPolicy = $contracts->policy($model);
        $policy = $contractPolicy ?? ($this->policies ??= new PolicyMappingCatalog($this->root))->policyForExactKey(
            $model,
        );
        if ($policy === null || ($this->bindings ??= new ContainerBindings($this->root))->configured($policy)) {
            return;
        }
        $class = $context->codebase->getClass($policy);
        if ($class === null || $class->hasIncompleteHierarchy()) {
            return;
        }
        $policyMethod = self::abilityMethod($ability->value);
        if (
            $contractPolicy !== null
            && ! ($call instanceof Node\Expr\MethodCall
            && $call->var instanceof Node\Expr\MethodCall)
        ) {
            $contracts->check($context, $policy, $policyMethod, $target['value']);
        }
        if (! $this->enabled) {
            return;
        }
        if (
            self::publicMethod($context, $policy, $policyMethod)
            || self::publicMethod($context, $policy, '__call')
        ) {
            return;
        }

        $context->report(
            Level::Note,
            'laramago-policy-method-declaration',
            Issue::at(
                'Selected policy "'
                .$policy
                .'" has no statically declared public "'
                .$policyMethod
                .'" method for ability "'
                .$ability->value
                .'". This opt-in declaration-quality note does not establish a runtime authorization failure; '
                .'Gate callbacks, fallback definitions, container replacement, magic dispatch, and guest handling may change the result.',
                new SourceLocation(
                    $context->source->path,
                    new Span($ability->getStartFilePos(), $ability->getEndFilePos() + 1),
                ),
            ),
        );
    }

    private function enabled(): bool
    {
        $text = @file_get_contents(rtrim($this->root, '/\\').'/composer.json');
        /** @var mixed $composer */
        $composer = $text === false ? null : json_decode($text, true);
        /** @var mixed $policy */
        $policy = is_array($composer)
            ? $composer['extra']['laramago']['policy-method-declarations'] ?? null
            : null;

        return is_array($policy) && ($policy['diagnose'] ?? null) === true;
    }

    /** @return Node\Expr\MethodCall|Node\Expr\StaticCall|null */
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
                if ($call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\StaticCall) {
                    $this->calls[$call->getStartFilePos().':'.($call->getEndFilePos() + 1)] = $call;
                }
            }
        }

        return $this->calls[$context->node->span->start.':'.$context->node->span->end] ?? null;
    }

    /** @param Node\Expr\MethodCall|Node\Expr\StaticCall $call */
    private function nativeGateCall(NodeAnalysisContext $context, Node $call, string $method): bool
    {
        foreach ([self::CONTRACT, self::GATE] as $service) {
            if (($this->bindings ??= new ContainerBindings($this->root))->configured($service)) {
                return false;
            }
        }
        if (! self::nativeGateMethod($context, $method)) {
            return false;
        }
        if ($call instanceof Node\Expr\StaticCall) {
            return (
                $call->class instanceof Node\Name
                && strcasecmp($call->class->toString(), self::FACADE) === 0
                && ($this->facade ??= new NativeFacade($this->root))->dispatchesClass(
                    $context->codebase,
                    self::FACADE,
                    self::CONTRACT,
                    self::GATE,
                    $method,
                )
            );
        }
        $receiver = $context->receiverType?->atomicTypes[0] ?? null;
        if (
            $context->receiverType === null
            || count($context->receiverType->atomicTypes) !== 1
            || ! $receiver instanceof NamedObjectType
            || strcasecmp($receiver->name, self::GATE) !== 0
        ) {
            return false;
        }

        return true;
    }

    private static function nativeGateMethod(NodeAnalysisContext $context, string $method): bool
    {
        $target = $context->codebase->getDeclaringMethod(self::GATE, $method);
        $parameters = self::PARAMETERS[$method] ?? null;
        if (
            $target === null
            || $parameters === null
            || $target->static
            || $target->abstract
            || $target->visibility !== Visibility::Public
            || $target->flags->contains(MetadataFlags::BY_REFERENCE)
            || strcasecmp($target->identifier->class ?? '', self::GATE) !== 0
            || ! str_ends_with(
                str_replace('\\', '/', $target->location->file ?? ''),
                '/laravel/framework/src/Illuminate/Auth/Access/Gate.php',
            )
            || array_map(static fn ($parameter): string => $parameter->name, $target->parameters) !== [
                '$'.$parameters[0],
                '$'.$parameters[1],
            ]
        ) {
            return false;
        }
        foreach ($target->parameters as $offset => $parameter) {
            if (
                $parameter->flags->contains(MetadataFlags::BY_REFERENCE)
                || $parameter->flags->contains(MetadataFlags::VARIADIC)
                || $parameter->flags->contains(MetadataFlags::HAS_DEFAULT) !== ($offset === 1)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<array-key, Node\Arg|Node\VariadicPlaceholder> $arguments
     * @param list<string> $parameters
     * @return array<string, array{value: Node\Expr, offset: int}>|null
     */
    private static function arguments(array $arguments, array $parameters): ?array
    {
        $resolved = [];
        $position = 0;
        $named = false;
        $offset = 0;
        foreach ($arguments as $argument) {
            if (! $argument instanceof Node\Arg || $argument->unpack || $argument->byRef) {
                return null;
            }
            if ($argument->name === null) {
                if ($named || ! isset($parameters[$position])) {
                    return null;
                }
                $name = $parameters[$position++];
            } else {
                $named = true;
                $name = $argument->name->toString();
                if (! in_array($name, $parameters, true)) {
                    return null;
                }
            }
            if (isset($resolved[$name])) {
                return null;
            }
            $resolved[$name] = ['value' => $argument->value, 'offset' => $offset];
            ++$offset;
        }

        return isset($resolved[$parameters[0]]) ? $resolved : null;
    }

    private function modelClass(Node\Expr $expression, ?Type $type): ?string
    {
        if ($expression instanceof Node\Expr\Array_) {
            $first = $expression->items[0] ?? null;
            if (
                ! $first instanceof Node\ArrayItem
                || $first->key !== null
                || $first->unpack
                || $first->byRef
            ) {
                return null;
            }

            return $this->modelClass($first->value, null);
        }
        if (
            $expression instanceof Node\Expr\ClassConstFetch
            && $expression->class instanceof Node\Name
            && ! $expression->class->isSpecialClassName()
            && $expression->name instanceof Node\Identifier
            && strcasecmp($expression->name->toString(), 'class') === 0
        ) {
            return $expression->class->toString();
        }
        if (
            $expression instanceof Node\Expr\New_
            && $expression->class instanceof Node\Name
            && ! $expression->class->isSpecialClassName()
        ) {
            return $expression->class->toString();
        }
        $literalClass = $type?->getLiteralClassString();
        if ($literalClass !== null) {
            return $literalClass;
        }
        $object = $type?->atomicTypes[0] ?? null;

        return $type !== null && count($type->atomicTypes) === 1 && $object instanceof NamedObjectType
            ? $object->name
            : null;
    }

    private static function abilityMethod(string $ability): string
    {
        if (! str_contains($ability, '-')) {
            return $ability;
        }

        return lcfirst(str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $ability))));
    }

    private static function publicMethod(NodeAnalysisContext $context, string $policy, string $method): bool
    {
        $metadata = $context->codebase->getMethod($policy, $method) ?? $context->codebase->getDeclaringMethod(
            $policy,
            $method,
        );

        return (
            $metadata !== null
            && $metadata->visibility === Visibility::Public
            && ! $metadata->flags->contains(MetadataFlags::MAGIC_METHOD)
        );
    }
}
