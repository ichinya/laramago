<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ModelReflection;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ModelPropertyReadContracts;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\PropertyAccess;
use Mago\Sdk\Analyzer\PropertyAccessKind;
use Mago\Sdk\Analyzer\PropertyTypeProviderContext;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\MixedType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use PhpParser\Node;

/** Resolve the native getKey() read through a proven Eloquent attribute type. */
final class EloquentKeyProvider implements MethodReturnTypeProvider, InitializationHook
{
    private ?PhpSource $source = null;
    private ?ModelPropertyReadContracts $readContracts = null;

    public function __construct(
        private readonly string $root,
        private readonly EloquentPropertyProvider $properties,
    ) {}

    public function initialize(InitializationContext $context): void
    {
        $this->source = null;
        $this->readContracts = null;
    }

    public function getTargets(): array
    {
        return [MethodTarget::exact(ModelReflection::MODEL, 'getKey')];
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $call = $context->invocation;
        $receiver = $call->receiverType;
        if (
            $call->arguments !== []
            || $receiver === null
            || count($receiver->atomicTypes) !== 1
            || ! $receiver->atomicTypes[0] instanceof NamedObjectType
        ) {
            return null;
        }
        $atom = $receiver->atomicTypes[0];
        if (
            ($atom->parameters ?? []) !== []
            || ($atom->intersections ?? []) !== []
            || strcasecmp($atom->name, ModelReflection::MODEL) === 0
            || ! $context->types->isContainedBy(
                Type::fromAtomic($atom),
                Type::namedObject(ModelReflection::MODEL),
            )
        ) {
            return null;
        }

        $reflection = new ModelReflection($context->codebase, $this->source ??= new PhpSource($this->root));
        $method = $reflection->method($atom->name, 'getKey');
        $key = $reflection->key($atom->name);
        $return = $method?->returnType?->type;
        if (
            $method === null
            || strcasecmp($method->identifier->class ?? '', ModelReflection::MODEL) !== 0
            || $method->declaredReturnType !== null
            || $return === null
            || count($return->atomicTypes) !== 1
            || ! $return->atomicTypes[0] instanceof MixedType
            || $key === null
            || $key === ''
            || ! self::nativeBody($reflection->returnExpression($method))
            || $reflection->customMethod($atom->name, 'getAttribute') !== null
            || $context->codebase->getDeclaringProperty($atom->name, '$'.$key) !== null
        ) {
            return null;
        }

        $documented = $context->codebase->getDeclaringMagicProperty($atom->name, '$'.$key)
            ?? $context->codebase->getMagicProperty($atom->name, '$'.$key);
        if ($documented !== null) {
            $contracts = $this->readContracts ??= new ModelPropertyReadContracts($this->source);
            $read = $contracts->scalarKeyRead($context->codebase, $context->types, $atom->name, $key);
            // A general scalar read tag remains usable without schema metadata.
            // Preserve custom attribute dispatch and the possibility of an unsaved key.
            foreach (['getAttributeValue', 'castAttribute', 'getClassCastableAttributeValue'] as $name) {
                if ($reflection->customMethod($atom->name, $name) !== null) {
                    return null;
                }
            }

            return $read === null || ! $contracts->current() || ! $this->source->isCurrent()
                ? null : Type::union($read, Type::int(), Type::string(), Type::null());
        }

        $property = $this->properties->getPropertyType(new PropertyTypeProviderContext(
            $context->phpVersion,
            $context->codebase,
            new PropertyAccess(
                $atom->name,
                $key,
                PropertyAccessKind::Read,
                Type::fromAtomic($atom),
                $call->span,
            ),
            $context->types,
            $context->cancellation,
        ));
        $read = $property?->readType;
        if ($read === null || ! CollectionItemProperty::concrete($read)) {
            return null;
        }

        // An unsaved model has no key even when its database column is NOT NULL.
        // Keep scalar driver alternatives so defensive key checks stay valid.
        return Type::union($read, Type::int(), Type::string(), Type::null());
    }

    private static function nativeBody(?Node\Expr $expression): bool
    {
        if (
            ! $expression instanceof Node\Expr\MethodCall
            || ! $expression->var instanceof Node\Expr\Variable
            || $expression->var->name !== 'this'
            || ! $expression->name instanceof Node\Identifier
            || strcasecmp($expression->name->toString(), 'getAttribute') !== 0
            || count($expression->args) !== 1
            || ! $expression->args[0] instanceof Node\Arg
            || $expression->args[0]->unpack
        ) {
            return false;
        }
        $key = $expression->args[0]->value;

        return $key instanceof Node\Expr\MethodCall
            && $key->var instanceof Node\Expr\Variable
            && $key->var->name === 'this'
            && $key->name instanceof Node\Identifier
            && strcasecmp($key->name->toString(), 'getKeyName') === 0
            && $key->args === [];
    }
}
