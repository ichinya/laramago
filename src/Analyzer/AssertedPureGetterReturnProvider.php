<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\AssertedPureGetterCalls;
use Ichinya\Laramago\Analyzer\StaticAnalysis\AssertedPureGetterContracts;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\InvocationKind;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;

/** Refine only one source-certified use; nullable declarations remain unchanged. */
final class AssertedPureGetterReturnProvider implements MethodReturnTypeProvider, InitializationHook
{
    public readonly AssertedPureGetterCalls $calls;
    private AssertedPureGetterContracts $contracts;
    private int $generation = -1;

    public function __construct(private readonly string $root = '.')
    {
        $this->calls = new AssertedPureGetterCalls($root);
        $this->contracts = new AssertedPureGetterContracts($this->calls, new PhpSource($root));
    }

    public function initialize(InitializationContext $context): void
    {
        $this->calls->reset();
        $this->generation = -1;
    }

    public function getTargets(): array { return [new MethodTarget('*', '*')]; }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $call = $context->invocation;
        if ($call->kind !== InvocationKind::InstanceMethod || $call->arguments !== []) { return null; }
        $candidate = $this->calls->call($call->span);
        if ($candidate === null || strcasecmp($call->name, $candidate['getter']->name->name) !== 0) { return null; }
        if ($this->generation !== $this->calls->generation) {
            $this->contracts = new AssertedPureGetterContracts($this->calls, new PhpSource($this->root));
            $this->generation = $this->calls->generation;
        }
        return $this->contracts->result($context, $candidate);
    }
}
