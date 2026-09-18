<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ModelReflection;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\Visibility;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/** Shared native dispatch and literal-argument boundary for forceFill contracts. */
final class NativeForceFill
{
    /** @return array{model: NamedObjectType, attributes: Node\Expr\Array_}|null */
    public static function literalCall(NodeAnalysisContext $context): ?array
    {
        $receiver = $context->receiverType;
        $model = $receiver !== null && count($receiver->atomicTypes) === 1 ? $receiver->atomicTypes[0] : null;
        if (
            ! $model instanceof NamedObjectType
            || ($model->parameters ?? []) !== []
            || ($model->intersections ?? []) !== []
        ) {
            return null;
        }
        if (! $context->types->isContainedBy(Type::fromAtomic($model), Type::namedObject(ModelReflection::MODEL))) {
            return null;
        }
        $metadata = $context->codebase->getClass($model->name);
        if ($metadata === null || $metadata->hasIncompleteHierarchy()) {
            return null;
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
            return null;
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
                return null;
            }
            $file = str_replace('\\', '/', $method->location->file ?? '');
            if (! str_contains($file, '/laravel/framework/src/Illuminate/Database/Eloquent/')) {
                return null;
            }
        }
        foreach ([$model->name, ...$context->codebase->getClassAncestors($model->name)] as $ancestor) {
            foreach ($context->codebase->getClassLike($ancestor)?->pseudoMethods ?? [] as $name) {
                if (in_array(strtolower($name), ['forcefill', 'fill', 'setattribute'], true)) {
                    return null;
                }
            }
        }
        try {
            $nodes = (new ParserFactory)
                ->createForNewestSupportedVersion()
                ->parse($context->source->contents) ?? [];
        } catch (\PhpParser\Error) {
            return null;
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
            return null;
        }
        $argument = $call->getArgs()[0];
        if (
            $argument->unpack
            || $argument->name !== null
            && $argument->name->name !== 'attributes'
            || ! $argument->value instanceof Node\Expr\Array_
        ) {
            return null;
        }

        return ['model' => $model, 'attributes' => $argument->value];
    }
}
