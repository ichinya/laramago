<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ContainerBindings;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Ichinya\Laramago\Analyzer\StaticAnalysis\RequestRuleFields;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;

/** Refines validated results from literal rules without typing raw request input. */
final class ValidatedInputProvider implements MethodReturnTypeProvider, InitializationHook
{
    private const FORM_REQUEST = 'Illuminate\\Foundation\\Http\\FormRequest';

    private ?PhpSource $source = null;
    private ?ContainerBindings $bindings = null;

    public function __construct(
        private readonly string $root = '.',
    ) {}

    public function initialize(InitializationContext $context): void
    {
        $this->source = null;
        $this->bindings = null;
    }

    public function getTargets(): array
    {
        return [MethodTarget::exact(self::FORM_REQUEST, 'validated')];
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $call = $context->invocation;
        $receiver = $call->receiverType;
        if (
            $receiver === null
            || count($receiver->atomicTypes) !== 1
            || ! $receiver->atomicTypes[0] instanceof NamedObjectType
            || ! $this->nativeMethod($context->codebase, $receiver->atomicTypes[0]->name)
        ) {
            return null;
        }
        $bindings = $this->bindings ??= new ContainerBindings($this->root);
        if ($bindings->configured('validator') || $bindings->configured('Illuminate\\Contracts\\Validation\\Factory')) {
            return null;
        }
        foreach ($call->arguments as $argument) {
            if ($argument->unpacked || $argument->placeholder) {
                return null;
            }
        }
        $key = $call->getArgument(0, 'key');
        $keyType = $key?->type;
        $fields = RequestRuleFields::resolve(
            $receiver->atomicTypes[0]->name,
            $context->codebase,
            $this->source ??= new PhpSource($this->root),
        );
        // data_get returns the entire validated array for an omitted or null key.
        if ($key !== null && ($keyType === null || ! $context->types->isContainedBy($keyType, Type::null()))) {
            $name = $keyType?->getLiteralString();
            $field = $name === null || $fields === null ? null : RequestRuleFields::select($fields, $name);
            if ($field === null) {
                return null;
            }
            $default = $call->getArgument(1, 'default');
            if (
                $field->optional
                && $default !== null
                && (
                    $default->type === null
                    || ! $context->types->isContainedBy(
                        $default->type,
                        Type::union(
                            Type::null(),
                            Type::string(),
                            Type::int(),
                            Type::float(),
                            Type::bool(),
                            Type::array(Type::union(Type::int(), Type::string()), Type::mixed()),
                        ),
                    )
                )
            ) {
                // data_get evaluates Closure defaults. Unknown or object defaults
                // need callable return analysis and therefore defer to Laravel.
                return null;
            }

            return $field->optional ? Type::union($field->type, $default?->type ?? Type::null()) : $field->type;
        }

        return $fields === null
            ? Type::array(Type::union(Type::int(), Type::string()), Type::mixed())
            : RequestRuleFields::shape($fields);
    }

    private function nativeMethod(Codebase $codebase, string $class): bool
    {
        $method = $codebase->getMethod($class, 'validated') ?? $codebase->getDeclaringMethod($class, 'validated');
        if ($method === null || strcasecmp($method->identifier->class ?? '', self::FORM_REQUEST) !== 0) {
            return false;
        }
        $returnType = $method->returnType->type ?? $method->declaredReturnType?->type;
        if ($returnType !== null && (string) $returnType !== 'mixed') {
            return false;
        }
        $validator = $codebase->getDeclaringProperty($class, '$validator') ?? $codebase->getProperty(
            $class,
            '$validator',
        );
        $native = $codebase->getProperty(self::FORM_REQUEST, '$validator');
        $location = $validator->nameLocation ?? $validator?->location;
        $nativeLocation = $native->nameLocation ?? $native?->location;
        if (
            $location === null
            || $nativeLocation === null
            || $location->file !== $nativeLocation->file
            || $location->span->start !== $nativeLocation->span->start
        ) {
            return false;
        }
        foreach ($codebase->getMultipleClasses([$class, ...$codebase->getClassAncestors($class)]) as $metadata) {
            foreach ([...($metadata->pseudoMethods ?? []), ...($metadata->staticPseudoMethods ?? [])] as $name) {
                if (strcasecmp($name, 'validated') === 0) {
                    return false;
                }
            }
        }

        return true;
    }
}
