<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\MethodCallAnalysisHook;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;
use PhpParser\Node;

/** Checks minimum arity only after proving native first-object dispatch. */
final class PipelineArityHook implements MethodCallAnalysisHook
{
    private const PIPELINE = 'Illuminate\\Pipeline\\Pipeline';

    public function __construct(
        private readonly string $projectRoot = '.',
    ) {}

    public function getTargets(): array
    {
        return [MethodTarget::exact(self::PIPELINE, 'thenReturn')];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $pipe = StaticAnalysis\ImmediatePipelinePipe::find($context);
        if ($pipe === null || ! $pipe->class instanceof Node\Name) {
            return;
        }
        $class = $context->codebase->getClass($pipe->class->toString());
        if (
            $class === null
            || $class->hasIncompleteHierarchy()
            || $class->flags->contains(MetadataFlags::ABSTRACT)
            || $class->directParentClass !== null
            || $class->usedTraits !== []
            || $class->pseudoMethods !== []
            || $class->staticPseudoMethods !== []
            || $class->mixins !== []
        ) {
            return;
        }
        $names = array_map(strtolower(...), $class->methods);
        foreach (['__construct', '__call', '__callstatic'] as $uncertain) {
            if (in_array($uncertain, $names, true)) {
                return;
            }
        }
        // Callable objects run __invoke before Pipeline considers handle.
        $name = in_array('__invoke', $names, true) ? '__invoke' : 'handle';
        if (! in_array($name, $names, true)) {
            return;
        }
        $method = $context->codebase->getMethod($class->name, $name);
        if (
            $method === null
            || $method->visibility !== Visibility::Public
            || $method->static
            || $method->abstract
            || $method->hasDocblock
            || $method->flags->contains(MetadataFlags::MAGIC_METHOD)
        ) {
            return;
        }
        $required = 0;
        foreach ($method->parameters as $index => $parameter) {
            if (
                ! $parameter->flags->contains(MetadataFlags::VARIADIC)
                && ! $parameter->flags->contains(MetadataFlags::HAS_DEFAULT)
            ) {
                $required = $index + 1;
            }
        }
        if ($required <= 2) {
            return;
        }
        if (! (new StaticAnalysis\NativePipelineContract($this->projectRoot))->matches($context->codebase)) {
            return;
        }
        $context->report(Level::Warning, 'laramago-pipeline-required-arguments', Issue::at(
            'Pipeline passes 2 arguments to '.$class->name.'::'.$name.', but at least '.$required.' are required.',
            new SourceLocation($context->source->path, new Span($pipe->getStartFilePos(), $pipe->getEndFilePos() + 1)),
        ));
    }
}
