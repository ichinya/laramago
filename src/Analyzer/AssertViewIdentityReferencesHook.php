<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\AssertViewIdentityPolicy;
use Ichinya\Laramago\Analyzer\StaticAnalysis\NativeAssertViewIsContract;
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
use PhpParser\ParserFactory;

/** Advise on literal native assertViewIs calls under an explicit identity policy. */
final class AssertViewIdentityReferencesHook implements MethodCallAnalysisHook, InitializationHook
{
    private ?AssertViewIdentityPolicy $policy = null;
    private ?NativeAssertViewIsContract $contract = null;
    private string $sourceHash = '';
    /** @var array<string, Node\Expr\MethodCall> */
    private array $calls = [];

    public function __construct(
        private readonly string $root = '.',
    ) {}

    public function initialize(InitializationContext $context): void
    {
        $this->policy = null;
        $this->contract = null;
        $this->sourceHash = '';
        $this->calls = [];
    }

    public function getTargets(): array
    {
        return [MethodTarget::exact(NativeAssertViewIsContract::TEST_RESPONSE, 'assertViewIs')];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::ReceiverType, FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $policy = $this->policy ??= new AssertViewIdentityPolicy($this->root);
        if (! $policy->enabled()) {
            return;
        }
        $call = $this->call($context);
        $atoms = $context->receiverType?->atomicTypes ?? [];
        if (
            $call === null
            || count($atoms) !== 1
            || ! $atoms[0] instanceof NamedObjectType
            || $atoms[0]->name !== NativeAssertViewIsContract::TEST_RESPONSE
            || ! ($this->contract ??= new NativeAssertViewIsContract($this->root))->matches($context->codebase)
        ) {
            return;
        }
        $arguments = $call->getArgs();
        if (count($arguments) !== 1) {
            return;
        }
        $argument = $arguments[0];
        if (
            $argument->unpack
            || $argument->byRef
            || $argument->name !== null
            && $argument->name->toString() !== 'value'
            || ! $argument->value instanceof Node\Scalar\String_
            || $policy->permits($argument->value->value) !== false
        ) {
            return;
        }
        $encoded = json_encode($argument->value->value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $context->report(
            Level::Warning,
            'laramago-assert-view-identity-outside-policy',
            Issue::at(
                'Expected view identity '
                .($encoded === false ? '"<unprintable>"' : $encoded)
                .' is not listed in the configured permitted assertViewIs identity policy.',
                new SourceLocation(
                    $context->source->path,
                    new Span($argument->value->getStartFilePos(), $argument->value->getEndFilePos() + 1),
                ),
            ),
        );
    }

    private function call(NodeAnalysisContext $context): ?Node\Expr\MethodCall
    {
        $hash = hash('sha256', $context->source->path."\0".$context->source->contents);
        if ($hash !== $this->sourceHash) {
            $this->sourceHash = $hash;
            $this->calls = [];
            try {
                $nodes = (new ParserFactory)
                    ->createForNewestSupportedVersion()
                    ->parse($context->source->contents) ?? [];
            } catch (\PhpParser\Error) {
                return null;
            }
            foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Expr\MethodCall::class) as $call) {
                $this->calls[$call->getStartFilePos().':'.($call->getEndFilePos() + 1)] = $call;
            }
        }
        $call = $this->calls[$context->node->span->start.':'.$context->node->span->end] ?? null;
        if (
            ! $call instanceof Node\Expr\MethodCall
            || ! $call->name instanceof Node\Identifier
            || strtolower($call->name->name) !== 'assertviewis'
            || $call->isFirstClassCallable()
        ) {
            return null;
        }

        return $call;
    }
}
