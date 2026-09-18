<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ModelReflection;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\MethodCallAnalysisHook;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

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
        $receiver = $context->receiverType;
        $model = $receiver !== null && count($receiver->atomicTypes) === 1 ? $receiver->atomicTypes[0] : null;
        if (
            ! $model instanceof NamedObjectType
            || ($model->parameters ?? []) !== []
            || ($model->intersections ?? []) !== []
        ) {
            return;
        }
        if (! $context->types->isContainedBy(Type::fromAtomic($model), Type::namedObject(ModelReflection::MODEL))) {
            return;
        }
        $reflection = new ModelReflection($context->codebase, new PhpSource('.'));
        $forceFill = $reflection->method($model->name, 'forceFill');
        $parameter = $forceFill?->parameters[0] ?? null;
        $parameterType = $parameter?->type?->type;
        $expression = $forceFill === null ? null : $reflection->returnExpression($forceFill);
        if (
            $forceFill === null
            || $forceFill->static
            || $forceFill->visibility !== Visibility::Public
            || count($forceFill->parameters) !== 1
            || $parameter === null
            || $parameter->name !== '$attributes'
            || $parameter->flags->contains(MetadataFlags::VARIADIC)
            || $parameter->flags->contains(MetadataFlags::BY_REFERENCE)
            || $parameterType === null
            || ! $context->types->equals($parameterType, Type::array(Type::string(), Type::mixed()))
            || $expression === null
            || (new Standard)->prettyPrintExpr($expression) !== 'static::unguarded(fn() => $this->fill($attributes))'
        ) {
            return;
        }
        foreach ([
            'forceFill',
            'fill',
            'setAttribute',
            'unguarded',
            'unguard',
            'reguard',
            'isUnguarded',
            'fillableFromArray',
            'isFillable',
        ] as $name) {
            $method = $reflection->method($model->name, $name);
            if ($method === null || $reflection->customMethod($model->name, $name) !== null) {
                return;
            }
            $file = str_replace('\\', '/', $method->location->file ?? '');
            if (! str_contains($file, '/laravel/framework/src/Illuminate/Database/Eloquent/')) {
                return;
            }
        }
        foreach ([$model->name, ...$context->codebase->getClassAncestors($model->name)] as $ancestor) {
            foreach ($context->codebase->getClassLike($ancestor)?->pseudoMethods ?? [] as $name) {
                if (in_array(strtolower($name), ['forcefill', 'fill', 'setattribute'], true)) {
                    return;
                }
            }
        }
        try {
            $nodes = (new ParserFactory)
                ->createForNewestSupportedVersion()
                ->parse($context->source->contents) ?? [];
        } catch (\PhpParser\Error) {
            return;
        }
        $call = (new NodeFinder)->findFirst(
            $nodes,
            static fn (Node $node): bool => (
                $node instanceof Node\Expr\MethodCall
                && $node->getStartFilePos() === $context->node->span->start
                && ($node->getEndFilePos() + 1) === $context->node->span->end
            ),
        );
        if (! $call instanceof Node\Expr\MethodCall || $call->isFirstClassCallable() || count($call->getArgs()) !== 1) {
            return;
        }
        $argument = $call->getArgs()[0];
        if (
            $argument->unpack
            || $argument->name !== null
            && $argument->name->name !== 'attributes'
            || ! $argument->value instanceof Node\Expr\Array_
        ) {
            return;
        }
        $types = $context->argumentTypes[0] ?? null;
        $shape = $types !== null && count($types->atomicTypes) === 1 ? $types->atomicTypes[0] : null;
        if (! $shape instanceof KeyedArrayType) {
            return;
        }
        $values = [];
        foreach ($argument->value->items as $item) {
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
