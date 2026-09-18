<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\CallableSignatureOverride;
use Mago\Sdk\Analyzer\CallableSignatureProviderContext;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\EffectiveCallableSignature;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\CallableParameter;
use Mago\Sdk\Analyzer\Type\CallableSignature;
use Mago\Sdk\Analyzer\Type\CallableType;
use Mago\Sdk\Analyzer\Type\ClassLikeStringType;
use Mago\Sdk\Analyzer\Type\ClassLikeStringVariant;
use Mago\Sdk\Analyzer\Type\GenericParameterType;
use Mago\Sdk\Analyzer\Type\MixedType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Analyzer\Type\Visibility;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\ParserFactory;

/** Explicit polymorphic existence constraints, without discovering runtime morph targets. */
final class EloquentMorphCallbackProvider implements CallableSignatureOverride, MethodReturnTypeProvider
{
    private const BUILDER = 'Illuminate\\Database\\Eloquent\\Builder';
    private const MODEL = 'Illuminate\\Database\\Eloquent\\Model';
    private const QUERIES = 'Illuminate\\Database\\Eloquent\\Concerns\\QueriesRelationships';
    private const METHODS = ['wherehasmorph', 'orwherehasmorph', 'wheredoesnthavemorph', 'orwheredoesnthavemorph'];

    /** @var list<string> */
    private array $identities = [];

    public function __construct(string $projectRoot = '.')
    {
        $json = @file_get_contents(rtrim($projectRoot, '/\\').'/composer.json');
        /** @var mixed $configuration */
        $configuration = $json === false ? null : json_decode($json, true);
        /** @var mixed $extra */
        $extra = is_array($configuration) ? $configuration['extra'] ?? null : null;
        /** @var mixed $options */
        $options = is_array($extra) ? $extra['laramago'] ?? null : null;
        /** @var mixed $identities */
        $identities = is_array($options) ? $options['morph-class-identity'] ?? null : null;
        if (! is_array($identities) || ! array_is_list($identities)) {
            return;
        }
        $classes = [];
        /** @var mixed $class */
        foreach ($identities as $class) {
            if (
                ! is_string($class)
                || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $class)
            ) {
                return;
            }
            $classes[] = $class;
        }
        $this->identities = $classes;
    }

    public function getTargets(): array
    {
        $targets = [];
        foreach ([
            self::MODEL,
            self::BUILDER,
            self::QUERIES,
        ] as $class) {
            foreach (self::METHODS as $method) {
                $targets[] = MethodTarget::exact($class, $method);
            }
        }

        return $targets;
    }

    public function getCallableSignature(CallableSignatureProviderContext $context): ?EffectiveCallableSignature
    {
        $call = $context->invocation;
        if (! $this->nativeMethod($context->codebase, $call->name)) {
            return null;
        }
        $receiver = $call->receiverType;
        $atom = count($receiver?->atomicTypes ?? []) === 1 ? $receiver?->atomicTypes[0] : null;
        if (! $atom instanceof NamedObjectType) {
            return null;
        }
        $dispatch = new EloquentModelDispatch;
        $onBuilder = strcasecmp($atom->name, self::BUILDER) === 0;
        $model = $onBuilder ? $atom->parameters[0] ?? null : $dispatch->modelType($context->codebase, $call);
        $owner = count($model?->atomicTypes ?? []) === 1 ? $model?->atomicTypes[0] : null;
        if (
            ! $owner instanceof NamedObjectType
            || ! $dispatch->supportsModel($context->codebase, $owner->name, $call->name)
        ) {
            return null;
        }
        $native = $dispatch->signature($context->codebase, $call->name);
        // Returning null lets native template inference retain complete control.
        if ($native === null || ! $this->standardCallback($native)) {
            return null;
        }
        $relation = $call->getArgument(0, 'relation');
        $types = $call->getArgument(1, 'types');
        $match = [];
        if (
            $relation === null
            || $relation->unpacked
            || $types === null
            || $types->unpacked
            || ! preg_match('/^[\'\"]([A-Za-z_][A-Za-z0-9_]*)[\'\"]$/D', $relation->expression, $match)
        ) {
            return null;
        }
        $method = $context->codebase->getDeclaringMethod($owner->name, $match[1]) ?? $context->codebase->getMethod(
            $owner->name,
            $match[1],
        );
        $return = $method?->returnType?->type ?? $method?->declaredReturnType?->type;
        $morph = count($return?->atomicTypes ?? []) === 1 ? $return?->atomicTypes[0] : null;
        if (
            $method === null
            || $method->static
            || $method->visibility !== Visibility::Public
            || $method->parameters !== []
            || ! $morph instanceof NamedObjectType
            || strcasecmp($morph->name, 'Illuminate\\Database\\Eloquent\\Relations\\MorphTo') !== 0
        ) {
            return null;
        }
        $classes = $this->classes($types->expression);
        if ($classes === null) {
            return null;
        }
        $queries = [];
        $names = [];
        foreach ($classes as $class) {
            if (
                // Morph-map keys are case sensitive: assertions apply only to
                // the exact class-string spelling, never every case variant.
                ! in_array($class, $this->identities, true)
                || ($context->codebase->getClass($class)?->templates ?? []) !== []
                || ! in_array(
                    strtolower(self::MODEL),
                    array_map(strtolower(...), $context->codebase->getClassAncestors($class)),
                    true,
                )
                || ! $dispatch->supportsModel($context->codebase, $class, $call->name)
            ) {
                return null;
            }
            // Keep alternatives separate: a callback must accept every model.
            $queries[] = Type::namedObject(self::BUILDER, Type::namedObject($class));
            $names[] = Type::fromAtomic(
                new ScalarType(
                    ScalarTypeKind::ClassLikeString,
                    new ClassLikeStringType(ClassLikeStringVariant::Literal, literal: $class),
                ),
            );
        }
        $callback = Type::union(Type::null(), Type::fromAtomic(new CallableType(new CallableSignature(
            false,
            true,
            [
                new CallableParameter('$query', $this->union($queries)),
                new CallableParameter('$type', $this->union($names)),
            ],
            Type::mixed(),
            null,
            [],
        ), null)));
        $parameters = [];
        foreach ($native->parameters as $parameter) {
            $parameters[] = new CallableParameter(
                $parameter->name,
                $parameter->name === '$callback' ? $callback : $parameter->type,
                $parameter->closureThisType,
                $parameter->byReference,
                $parameter->variadic,
                $parameter->hasDefault,
            );
        }

        return new EffectiveCallableSignature($parameters, displayName: self::BUILDER.'::'.$call->name);
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        if (! $this->nativeMethod($context->codebase, $context->invocation->name)) {
            return null;
        }
        $model = (new EloquentModelDispatch)->modelType($context->codebase, $context->invocation);

        return $model === null ? null : Type::namedObject(self::BUILDER, $model);
    }

    private function nativeMethod(Codebase $codebase, string $name): bool
    {
        // Verify the invoked method and the helper chain that actually creates
        // and invokes the callback, not just a compatible callback type shape.
        $methods = [$name, 'hasMorph', 'getBelongsToRelation'];
        if (str_contains(strtolower($name), 'doesnthave')) {
            $methods[] = 'doesntHaveMorph';
        }
        foreach ($methods as $methodName) {
            $method = $codebase->getDeclaringMethod(self::BUILDER, $methodName) ?? $codebase->getMethod(
                self::BUILDER,
                $methodName,
            );
            if (
                $method === null
                || strcasecmp($method->identifier->class ?? '', self::QUERIES) !== 0
                || ! str_ends_with(
                    str_replace('\\', '/', $method->location->file ?? ''),
                    '/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/QueriesRelationships.php',
                )
            ) {
                return false;
            }
        }

        return true;
    }

    /** @param non-empty-list<Type> $types */
    private function union(array $types): Type
    {
        $result = $types[0];
        foreach (array_slice($types, 1) as $type) {
            $result = Type::union($result, $type);
        }

        return $result;
    }

    /** @return non-empty-list<string>|null */
    private function classes(string $expression): ?array
    {
        try {
            $nodes = (new ParserFactory)
                ->createForNewestSupportedVersion()
                ->parse('<?php '.$expression.';') ?? [];
        } catch (Error) {
            return null;
        }
        if (count($nodes) !== 1 || ! $nodes[0] instanceof Node\Stmt\Expression) {
            return null;
        }
        $value = $nodes[0]->expr;
        $values = [$value];
        if ($value instanceof Node\Expr\Array_) {
            $values = [];
            foreach ($value->items as $item) {
                if ($item->key !== null || $item->unpack || $item->byRef) {
                    return null;
                }
                $values[] = $item->value;
            }
        }
        $classes = [];
        foreach ($values as $value) {
            // Pre-argument SDK context has no source file/import table. Relative
            // names, self/static and strings (possibly morph aliases) stay native.
            if (
                ! $value instanceof Node\Expr\ClassConstFetch
                || ! $value->class instanceof Node\Name\FullyQualified
                || ! $value->name instanceof Node\Identifier
                || strtolower($value->name->name) !== 'class'
            ) {
                return null;
            }
            $classes[] = $value->class->toString();
        }

        return $classes === [] ? null : array_values(array_unique($classes));
    }

    private function standardCallback(EffectiveCallableSignature $native): bool
    {
        foreach ($native->parameters as $parameter) {
            if ($parameter->name !== '$callback' || $parameter->byReference || $parameter->variadic) {
                continue;
            }
            $closures = 0;
            foreach ($parameter->type?->atomicTypes ?? [] as $atom) {
                if (! $atom instanceof CallableType) {
                    if ((string) Type::fromAtomic($atom) !== 'null') {
                        return false;
                    }
                    continue;
                }
                $closures++;
                $signature = $atom->signature;
                if (
                    $signature === null
                    || ! $signature->closure
                    || $signature->pure
                    || $signature->constraints !== []
                    || count($signature->parameters) !== 2
                    || count($signature->returnType?->atomicTypes ?? []) !== 1
                    || ! $signature->returnType?->atomicTypes[0] instanceof MixedType
                ) {
                    return false;
                }
                foreach ($signature->parameters as $arg) {
                    if ($arg->byReference || $arg->variadic || $arg->hasDefault || $arg->closureThisType !== null) {
                        return false;
                    }
                }
                $query = $signature->parameters[0]->type;
                $builder = count($query?->atomicTypes ?? []) === 1 ? $query?->atomicTypes[0] : null;
                if (
                    ! $builder instanceof NamedObjectType
                    || strcasecmp($builder->name, self::BUILDER) !== 0
                    || count($builder->parameters ?? []) !== 1
                    || (string) $signature->parameters[1]->type !== 'string'
                ) {
                    return false;
                }
                $model = $builder->parameters[0] ?? null;
                if ($model === null) {
                    return false;
                }
                $bound = count($model->atomicTypes) === 1 ? $model->atomicTypes[0] : null;
                if ($bound instanceof GenericParameterType) {
                    $bound = count($bound->constraint->atomicTypes) === 1 ? $bound->constraint->atomicTypes[0] : null;
                }
                if (! $bound instanceof NamedObjectType || strcasecmp($bound->name, self::MODEL) !== 0) {
                    return false;
                }
            }

            return $closures === 1;
        }

        return false;
    }
}
