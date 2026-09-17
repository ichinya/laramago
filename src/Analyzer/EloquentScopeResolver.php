<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Invocation;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\Analyzer\TypeComparator;

/** Resolves declared scopes while preserving custom Eloquent dispatch. */
final class EloquentScopeResolver
{
    private const MODEL = 'Illuminate\Database\Eloquent\Model';
    private const BUILDER = 'Illuminate\Database\Eloquent\Builder';
    private const SCOPE = 'Illuminate\Database\Eloquent\Attributes\Scope';

    /** @return array{FunctionLikeMetadata, Type}|null */
    public function resolve(Codebase $codebase, Invocation $call, TypeComparator $types): ?array
    {
        $receiver = $call->receiverType;
        if (
            $receiver === null
            || count($receiver->atomicTypes) !== 1
            || ! $receiver->atomicTypes[0] instanceof NamedObjectType
        ) {
            return null;
        }
        $atom = $receiver->atomicTypes[0];
        $onBuilder = strcasecmp($atom->name, self::BUILDER) === 0;
        $model = $onBuilder ? $atom->parameters[0] ?? null : Type::namedObject($atom->name);
        $modelAtom = $model?->atomicTypes[0] ?? null;
        if ($model === null || count($model->atomicTypes) !== 1 || ! $modelAtom instanceof NamedObjectType) {
            return null;
        }
        $class = $modelAtom->name;
        if (! in_array(
            strtolower(self::MODEL),
            array_map(strtolower(...), $codebase->getClassAncestors($class)),
            true,
        )) {
            return null;
        }
        // Native builder methods win over scopes. Query builder forwarding follows scopes.
        if ($this->method($codebase, self::BUILDER, $call->name) !== null) {
            return null;
        }
        $declared = $this->method($codebase, $class, $call->name);
        // Direct declared model methods keep native visibility and static-call checks.
        // In particular, the SDK cannot make a protected #[Scope] method public.
        if ($declared !== null && ! $onBuilder) {
            return null;
        }
        $scope = null;
        foreach ($declared->attributes ?? [] as $attribute) {
            if (strcasecmp($attribute->name, self::SCOPE) === 0 && $declared?->visibility !== Visibility::Private) {
                $scope = $declared;
            }
        }
        $scope ??= $this->method($codebase, $class, 'scope'.ucfirst($call->name));
        if (
            $scope === null
            || $scope->static
            || $scope->visibility === Visibility::Private
            || $scope->templates !== []
            || $scope->parameters === []
        ) {
            return null;
        }
        if (! $this->standardDispatch($codebase, $class, $call->name)) {
            return null;
        }
        // Generic method substitution and malformed scope contracts stay native.
        $query = $scope->parameters[0];
        if ($query->flags->contains(MetadataFlags::VARIADIC) || $query->flags->contains(MetadataFlags::BY_REFERENCE)) {
            return null;
        }
        $queryType = $query->type->type ?? $query->declaredType?->type;
        if ($queryType !== null && ! $types->isContainedBy(Type::namedObject(self::BUILDER, $model), $queryType)) {
            return null;
        }

        return [$scope, $model];
    }

    private function standardDispatch(Codebase $codebase, string $class, string $methodName): bool
    {
        $dispatch = new EloquentModelDispatch;
        foreach (['hasNamedScope', 'callNamedScope', 'isScopeMethodWithAttribute'] as $name) {
            if ($dispatch->overrides($codebase, $class, $name)) {
                return false;
            }
        }

        return $dispatch->supportsModel($codebase, $class, $methodName);
    }

    private function method(Codebase $codebase, string $class, string $method): ?FunctionLikeMetadata
    {
        return $codebase->getMethod($class, $method) ?? $codebase->getDeclaringMethod($class, $method);
    }
}
