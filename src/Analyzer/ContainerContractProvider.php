<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\FrameworkContainerAliases;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\InvocationKind;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Reporting\AnnotationKind;

/**
 * Resolves method calls on `Illuminate\Contracts\*` interfaces through the concrete
 * class the framework's core alias table binds them to.
 *
 * Larastan resolves contract method calls against the container binding; Mago reports
 * them as `non-existent-method` because the interface lacks the method. The provider
 * supplies the concrete method's return type whenever the analyzer consults it, and
 * the issue filter removes the proven false positive when no precise type is declared.
 * Both mechanisms only act when the method is provably callable on the mapped root
 * concrete class.
 */
final class ContainerContractProvider implements MethodReturnTypeProvider, IssueFilterHook, InitializationHook
{
    private const FILTER_CODE = 'non-existent-method';
    private const CONTRACT_PREFIX = 'Illuminate\\Contracts\\';

    private ?FrameworkContainerAliases $aliases = null;
    /** @var array<string, string> Lowercase contract interface => root concrete class. */
    private array $contracts = [];

    public function __construct(
        private readonly string $root,
    ) {}

    public function initialize(InitializationContext $context): void
    {
        $this->aliases = null;
        $this->contracts = [];
    }

    public function hasTargets(): bool
    {
        return $this->sourceContracts() !== [];
    }

    public function getTargets(): array
    {
        $targets = [];
        foreach ($this->sourceContracts() as $contract => $_) {
            $targets[] = MethodTarget::allMethods($contract);
        }
        if ($targets === []) {
            throw new \LogicException('Register ContainerContractProvider only when hasTargets() is true.');
        }

        return $targets;
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $call = $context->invocation;
        $receiver = $call->receiverType;
        $object = $receiver?->atomicTypes[0] ?? null;
        if (
            $call->kind !== InvocationKind::InstanceMethod
            || $receiver === null
            || count($receiver->atomicTypes) !== 1
            || ! $object instanceof NamedObjectType
            || ($object->parameters ?? []) !== []
            || ($object->intersections ?? []) !== []
        ) {
            return null;
        }
        $codebase = $context->codebase;
        $contract = $this->contract($object->name, $codebase);
        if ($contract === null) {
            return null;
        }
        if ($this->nativelyDeclared($object->name, $call->name, $codebase)) {
            return null;
        }
        $method = $codebase->getDeclaringMethod($contract, $call->name);
        if ($method === null) {
            return null;
        }

        return $method->returnType?->type ?? $method->declaredReturnType?->type;
    }

    public function getCodes(): array
    {
        return [self::FILTER_CODE];
    }

    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        if ($context->issue->code !== self::FILTER_CODE) {
            return IssueFilterDecision::Keep;
        }
        if (preg_match('/^Method `([^`]+)` does not exist on type `([^`]+)`\.$/D', $context->issue->message, $match) !== 1) {
            return IssueFilterDecision::Keep;
        }
        [$method, $type] = [$match[1], $match[2]];
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $method) !== 1) {
            return IssueFilterDecision::Keep;
        }
        if (
            preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $type) !== 1
            || stripos($type, self::CONTRACT_PREFIX) !== 0
        ) {
            return IssueFilterDecision::Keep;
        }
        $codebase = $context->codebase;
        $contract = $this->contract($type, $codebase);
        if (
            $contract === null
            || $codebase->methodExists($type, $method)
            || $codebase->getDeclaringMethod($contract, $method) === null
        ) {
            return IssueFilterDecision::Keep;
        }

        return $this->spanMentions($context->contents, $context->issue->annotations, $method)
            ? IssueFilterDecision::Remove
            : IssueFilterDecision::Keep;
    }

    /** The mapped concrete class when the receiver is exactly a mapped framework contract. */
    private function contract(string $receiver, Codebase $codebase): ?string
    {
        if ($this->contracts === []) {
            $map = ($this->aliases ??= new FrameworkContainerAliases(new PhpSource($this->root)))->contracts($codebase);
            foreach ($map as $interface => $concrete) {
                $this->contracts[strtolower($interface)] = $concrete;
            }
            if ($this->contracts === []) {
                return null;
            }
        }

        return $this->contracts[strtolower($receiver)] ?? null;
    }

    /** @return array<string, string> Contract interface => root concrete class, read from source alone. */
    private function sourceContracts(): array
    {
        return ($this->aliases ??= new FrameworkContainerAliases(new PhpSource($this->root)))->contractsFromSource();
    }

    /** Native analysis already handles methods the contract (or its parents) declares. */
    private function nativelyDeclared(string $contract, string $method, Codebase $codebase): bool
    {
        if ($codebase->methodExists($contract, $method)) {
            return true;
        }
        foreach ($codebase->getMultipleClasses([$contract, ...$codebase->getClassAncestors($contract)]) as $metadata) {
            foreach ([...($metadata?->pseudoMethods ?? []), ...($metadata?->staticPseudoMethods ?? [])] as $pseudo) {
                if (strcasecmp($pseudo, $method) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Cheap sanity check that the annotated call site really mentions the method.
     *
     * @param list<\Mago\Sdk\Reporting\Annotation> $annotations
     */
    private function spanMentions(string $contents, array $annotations, string $method): bool
    {
        foreach ($annotations as $annotation) {
            if ($annotation->kind !== AnnotationKind::Primary) {
                continue;
            }
            if ($annotation->file !== null && $annotation->file !== '') {
                return false;
            }
            $start = $annotation->span->start;
            $end = $annotation->span->end;
            $length = strlen($contents);
            if ($start > $length || $end > $length) {
                return false;
            }
            $window = substr($contents, max(0, $start - 64), min($length, $end + 64) - max(0, $start - 64));

            // Native messages lowercase the method name, so match the word without case.
            return preg_match('/\b'.preg_quote($method, '/').'\b/iD', $window) === 1;
        }

        return false;
    }
}
