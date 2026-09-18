<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ModelReflection;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\MethodCallAnalysisHook;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;
use PhpParser\Node;

/** Check explicit write contracts, never schema-derived input assumptions. */
final class ForceFillWriteContractHook implements MethodCallAnalysisHook
{
    public function getTargets(): array
    {
        return [MethodTarget::exact(ModelReflection::MODEL, 'forceFill')];
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
        $call = NativeForceFill::literalCall($context);
        if ($call === null) {
            return;
        }
        $model = $call['model'];
        $types = $context->argumentTypes[0] ?? null;
        $shape = $types !== null && count($types->atomicTypes) === 1 ? $types->atomicTypes[0] : null;
        if (! $shape instanceof KeyedArrayType) {
            return;
        }
        $values = [];
        foreach ($call['attributes']->items as $item) {
            if (
                $item->unpack
                || $item->byRef
                || ! $item->key instanceof Node\Scalar\String_
                || array_key_exists($item->key->value, $values)
            ) {
                return;
            }
            $values[$item->key->value] = $item->value;
        }
        foreach ($shape->knownItems ?? [] as $item) {
            $key = $item->key->value;
            if (! is_string($key) || $item->optional || ! isset($values[$key]) || str_contains($key, '->')) {
                continue;
            }
            // A real PHP property is not a mass-assigned Eloquent attribute.
            if ($context->codebase->getDeclaringProperty($model->name, '$'.$key) !== null) {
                continue;
            }
            $property = $context->codebase->getDeclaringMagicProperty($model->name, '$'.$key);
            if ($property === null || $property->flags->contains(MetadataFlags::READONLY)) {
                continue;
            }
            // Separate effective write metadata or an explicitly write-only magic
            // declaration is required. Ordinary @property/read types are insufficient.
            $write =
                $property->writeType?->type
                ?? ($property->flags->contains(MetadataFlags::WRITEONLY) ? $property->type?->type : null);
            if (
                $write === null
                || ! self::structured($write)
                || ! self::structured($item->type)
                || $context->types->isContainedBy($item->type, $write)
                || $context->types->canBeIdentical($item->type, $write)
            ) {
                continue;
            }
            $value = $values[$key];
            $context->report(Level::Warning, 'laramago-force-fill-write-contract', Issue::at(
                'Value for "'.$key.'" is incompatible with its declared write contract '.(string) $write.'.',
                new SourceLocation(
                    $context->source->path,
                    new Span($value->getStartFilePos(), $value->getEndFilePos() + 1),
                ),
            ));
        }
    }

    private static function structured(Type $type): bool
    {
        foreach ($type->atomicTypes as $atom) {
            if (
                ! $atom instanceof NamedObjectType
                && ! $atom instanceof KeyedArrayType
                && ! $atom instanceof ListType
            ) {
                return false;
            }
        }

        return $type->atomicTypes !== [] && CollectionItemProperty::concrete($type);
    }
}
