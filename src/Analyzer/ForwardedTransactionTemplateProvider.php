<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ForwardedTransactionTemplateCalls;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ForwardedTransactionTemplateContract;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\InvocationKind;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;

/** Restore an exact enclosing callable template only at its proved transaction invocation. */
final class ForwardedTransactionTemplateProvider implements MethodReturnTypeProvider, InitializationHook
{
    public readonly ForwardedTransactionTemplateCalls $calls;
    private ForwardedTransactionTemplateContract $contract;
    public function __construct(private readonly string $root = '.')
    {
        $this->calls = new ForwardedTransactionTemplateCalls($root);
        $this->contract = new ForwardedTransactionTemplateContract($root, $this->calls);
    }
    public function getTargets(): array { return [MethodTarget::exact('Illuminate\\Support\\Facades\\DB', 'transaction')]; }
    public function initialize(InitializationContext $context): void
    {
        $this->calls->reset();
        $this->contract = new ForwardedTransactionTemplateContract($this->root, $this->calls);
    }
    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $call = $context->invocation;
        $type = $call->receiverType;
        $atom = $type?->atomicTypes[0] ?? null;
        if ($call->kind !== InvocationKind::StaticMethod || $call->declaringClass !== 'Illuminate\\Support\\Facades\\DB'
            || strcasecmp($call->name, 'transaction') !== 0 || count($type?->atomicTypes ?? []) !== 1
            || ! $atom instanceof NamedObjectType || $atom->name !== $call->declaringClass
            || ($atom->parameters ?? []) !== [] || ($atom->intersections ?? []) !== [] || $atom->static || $atom->isThis
            || $atom->remappedParameters || ($atom->variances ?? []) !== [] || $type->flags->possiblyUndefined
            || $type->flags->possiblyUndefinedFromTry || $type->flags->nullsafeNull) { return null; }
        $proof = $this->calls->proof($call);
        return $proof === null ? null : $this->contract->result($context, $proof);
    }
}
