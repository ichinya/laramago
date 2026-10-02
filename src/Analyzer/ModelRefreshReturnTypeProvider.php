<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ModelRefreshCalls;
use Ichinya\Laramago\Analyzer\StaticAnalysis\NativeModelRefresh;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\InvocationKind;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\GenericParameterType;
use Mago\Sdk\Analyzer\Type\GenericParentKind;
use Mago\Sdk\Analyzer\Type\NamedObjectType;

/** Restore the exact caller template, never a synthesized or foreign template. */
final class ModelRefreshReturnTypeProvider implements MethodReturnTypeProvider, InitializationHook
{
    private const MODEL = 'Illuminate\\Database\\Eloquent\\Model';
    public readonly ModelRefreshCalls $calls;
    private NativeModelRefresh $native;

    public function __construct(private readonly string $root = '.')
    {
        $this->calls = new ModelRefreshCalls;
        $this->native = new NativeModelRefresh($root);
    }

    public function initialize(InitializationContext $context): void
    {
        $this->native = new NativeModelRefresh($this->root);
    }

    public function getTargets(): array
    {
        return [MethodTarget::exact(self::MODEL, 'fresh'), MethodTarget::exact(self::MODEL, 'refresh')];
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $call = $context->invocation;
        $candidate = $this->calls->call($call->span);
        $receiver = $call->receiverType?->atomicTypes[0] ?? null;
        if ($candidate === null || $call->kind !== InvocationKind::InstanceMethod || $call->arguments !== []
            || strcasecmp($call->declaringClass ?? '', self::MODEL) !== 0
            || strcasecmp($call->name, $candidate['call']->name->name) !== 0
            || count($call->receiverType?->atomicTypes ?? []) !== 1 || ! self::plainModel($receiver)) {
            return null;
        }
        $scope = $candidate['scope'];
        $owner = $candidate['owner'];
        $name = $candidate['name'];
        $caller = $owner === null ? $context->codebase->getFunction($name) : $context->codebase->getDeclaringMethod($owner, $name);
        if ($caller === null || strcasecmp($caller->identifier->class ?? '', $owner ?? '') !== 0
            || strcasecmp($caller->identifier->name, $name) !== 0
            || $this->path($caller->location->file ?? '') !== $this->path($candidate['file'])
            || $caller->nameLocation?->span->start !== $scope->name->getStartFilePos()
            || $caller->nameLocation?->span->end !== $scope->name->getEndFilePos() + 1
            || $caller->location->span->start > $scope->getStartFilePos()
            || $caller->location->span->end !== $scope->getEndFilePos() + 1
            || $caller->flags->contains(MetadataFlags::BY_REFERENCE) || $caller->globalsAccessed !== []) {
            return null;
        }
        $parameter = $caller->parameters[$candidate['parameter']] ?? null;
        $syntax = $scope->params[$candidate['parameter']] ?? null;
        $type = $parameter?->type?->type;
        $generic = $type?->atomicTypes[0] ?? null;
        if ($parameter === null || $syntax === null || $parameter->name !== '$'.$candidate['call']->var->name
            || $parameter->nameLocation->span->start !== $syntax->var->getStartFilePos()
            || $parameter->nameLocation->span->end !== $syntax->var->getEndFilePos() + 1
            || $parameter->flags->contains(MetadataFlags::BY_REFERENCE) || $parameter->flags->contains(MetadataFlags::VARIADIC)
            || $parameter->outType !== null || $parameter->closureThisType !== null
            || count($type?->atomicTypes ?? []) !== 1 || ! $generic instanceof GenericParameterType
            || ($generic->intersections ?? []) !== [] || count($generic->constraint->atomicTypes) !== 1
            || ! self::plainModel($generic->constraint->atomicTypes[0])
            || $generic->definingEntity->kind !== GenericParentKind::FunctionLike
            || strcasecmp($generic->definingEntity->name, $owner ?? '') !== 0
            || strcasecmp($generic->definingEntity->member ?? '', $name) !== 0) {
            return null;
        }
        $declared = false;
        foreach ($caller->templates as $template) {
            if ($template->name === $generic->name && $template->definingEntity == $generic->definingEntity
                && $context->types->equals($template->constraint, $generic->constraint)) {
                $declared = true;
            }
        }
        if (! $declared || ! $this->native->proves($context, strtolower($call->name))) {
            return null;
        }

        return strcasecmp($call->name, 'fresh') === 0 ? Type::union($type, Type::null()) : $type;
    }

    private static function plainModel(mixed $type): bool
    {
        return $type instanceof NamedObjectType && strcasecmp($type->name, self::MODEL) === 0
            && ($type->parameters ?? []) === [] && ($type->intersections ?? []) === [] && ! $type->static && ! $type->isThis;
    }

    private function path(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (preg_match('~^//\\?/[A-Za-z]:/~', $path) === 1) {
            $path = substr($path, 4);
        }
        if (! str_starts_with($path, '/') && preg_match('~^[A-Za-z]:/~', $path) !== 1) {
            $path = str_replace('\\', '/', $this->root).'/'.$path;
        }
        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }
}
