<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ModelReflection;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\MethodCallAnalysisHook;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;
use PhpParser\Node;

/** Missing forceFill names require a complete exact-model contract. */
final class ForceFillFieldNamesHook implements MethodCallAnalysisHook
{
    private readonly ModelFieldCatalog $fields;

    public function __construct(string $projectRoot)
    {
        $this->fields = new ModelFieldCatalog($projectRoot);
    }

    public function getTargets(): array
    {
        return [MethodTarget::exact(ModelReflection::MODEL, 'forceFill')];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::SourceText, FileAnalysisRequirement::ReceiverType];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $receivers = $context->receiverType?->atomicTypes ?? [];
        if (
            count($receivers) !== 1
            || ! $receivers[0] instanceof NamedObjectType
            || ! $this->fields->has($receivers[0]->name)
        ) {
            return;
        }
        $call = NativeForceFill::literalCall($context);
        if ($call === null) {
            return;
        }
        $model = $call['model']->name;
        /** @var array<array-key, Node\Scalar\String_> $keys */
        $keys = [];
        foreach ($call['attributes']->items as $item) {
            if (
                $item->unpack
                || $item->byRef
                || ! $item->key instanceof Node\Scalar\String_
                || array_key_exists($item->key->value, $keys)
            ) {
                return;
            }
            $keys[$item->key->value] = $item->key;
        }
        foreach ($keys as $key => $node) {
            // PHP coerces canonical numeric string keys to integers; nested JSON
            // paths have their own contract and cannot be checked as flat fields.
            if (! is_string($key) || $key === '' || str_contains($key, '->')) {
                continue;
            }
            if (
                $this->fields->contains($model, $key) !== false
                || $context->codebase->getDeclaringProperty($model, '$'.$key) !== null
                || $context->codebase->getDeclaringMagicProperty($model, '$'.$key) !== null
            ) {
                continue;
            }
            $issue = Issue::at(
                'Attribute "'.$key.'" is absent from the complete field catalog for '.$model.'.',
                new SourceLocation(
                    $context->source->path,
                    new Span($node->getStartFilePos(), $node->getEndFilePos() + 1),
                ),
            );
            $suggestion = $this->fields->closestField($model, $key);
            if ($suggestion !== null) {
                $issue = $issue->withHelp('Did you mean "'.$suggestion.'"?');
            }
            $context->report(Level::Warning, 'laramago-force-fill-missing-field', $issue);
        }
    }
}
