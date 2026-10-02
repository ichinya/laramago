<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\CallableSignatureOverride;
use Mago\Sdk\Analyzer\CallableSignatureProviderContext;
use Mago\Sdk\Analyzer\EffectiveCallableSignature;
use Mago\Sdk\Analyzer\InvocationKind;
use Mago\Sdk\Analyzer\Metadata\ClassLikeKind;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\AnyObjectType;
use Mago\Sdk\Analyzer\Type\CallableParameter;
use Mago\Sdk\Analyzer\Type\ClassLikeStringKind;
use Mago\Sdk\Analyzer\Type\ClassLikeStringType;
use Mago\Sdk\Analyzer\Type\ClassLikeStringVariant;
use Mago\Sdk\Analyzer\Type\GenericParameterType;
use Mago\Sdk\Analyzer\Type\GenericParent;
use Mago\Sdk\Analyzer\Type\GenericParentKind;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Analyzer\Type\Visibility;

/** Distributes the public Internal Container class-string contract across union members. */
final class InternalContainerProvider implements MethodReturnTypeProvider, CallableSignatureOverride
{
    private const CONTAINER = 'Internal\\Container\\Container';

    public function getTargets(): array
    {
        return [MethodTarget::exact(self::CONTAINER, 'get'), MethodTarget::exact(self::CONTAINER, 'make')];
    }

    public function getCallableSignature(CallableSignatureProviderContext $context): ?EffectiveCallableSignature
    {
        $method = $this->contract($context);
        if ($method === null) {
            return null;
        }

        // Signature hooks precede argument analysis. The admissible input of this
        // single-template contract is class-string<object>; the return hook restores
        // its dependency on the complete argument union after argument analysis.
        $classString = Type::fromAtomic(new ScalarType(ScalarTypeKind::ClassLikeString,
            new ClassLikeStringType(ClassLikeStringVariant::Any, ClassLikeStringKind::Class_)));

        return new EffectiveCallableSignature([
            new CallableParameter($method->parameters[0]->name, $classString),
            new CallableParameter($method->parameters[1]->name, $method->parameters[1]->type->type, hasDefault: true),
        ]);
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $method = $this->contract($context);
        if ($method === null) {
            return null;
        }
        $argument = $context->invocation->getArgument(0, ltrim($method->parameters[0]->name, '$'));
        $atoms = $argument?->type?->atomicTypes ?? [];
        if ($argument === null || $argument->unpacked || $argument->placeholder || $atoms === [] || count($atoms) > 32) {
            return null;
        }
        $result = null;
        foreach ($atoms as $atom) {
            if (! $atom instanceof ScalarType) {
                return null;
            }
            $literal = Type::fromAtomic($atom)->getLiteralString();
            if ($literal !== null) {
                $metadata = $context->codebase->getClassLike($literal);
                if ($metadata === null || $metadata->hasIncompleteHierarchy() || $metadata->kind === ClassLikeKind::Trait) {
                    return null;
                }
                $type = Type::namedObject($metadata->originalName);
                $result = $result === null ? $type : Type::union($result, $type);
                continue;
            }
            if ($atom->kind !== ScalarTypeKind::ClassLikeString || ! $atom->refinement instanceof ClassLikeStringType) {
                return null;
            }
            $class = $atom->refinement;
            if ($class->variant === ClassLikeStringVariant::Literal) {
                $metadata = $class->literal === null ? null : $context->codebase->getClassLike($class->literal);
                if ($metadata === null || $metadata->hasIncompleteHierarchy() || $metadata->kind === ClassLikeKind::Trait) {
                    return null;
                }
                $type = Type::namedObject($metadata->originalName);
            } elseif ($class->kind !== ClassLikeStringKind::Class_) {
                return null;
            } elseif ($class->variant === ClassLikeStringVariant::Any) {
                $type = Type::object();
            } elseif ($class->variant === ClassLikeStringVariant::OfType && $class->constraint !== null) {
                $type = Type::fromAtomic($class->constraint);
            } elseif ($class->variant === ClassLikeStringVariant::Generic && $class->constraint !== null
                && $class->parameterName !== null && $class->definingEntity !== null) {
                $type = Type::fromAtomic(new GenericParameterType($class->parameterName,
                    Type::fromAtomic($class->constraint), $class->definingEntity, null));
            } else {
                return null;
            }
            if (! $context->types->isContainedBy($type, Type::object())) {
                return null;
            }
            $result = $result === null ? $type : Type::union($result, $type);
        }

        return $result;
    }

    private function contract(CallableSignatureProviderContext|ReturnTypeProviderContext $context): ?FunctionLikeMetadata
    {
        $call = $context->invocation;
        $receiver = $call->receiverType?->atomicTypes[0] ?? null;
        $name = strtolower($call->name);
        if ($call->kind !== InvocationKind::InstanceMethod || ! in_array($name, ['get', 'make'], true)
            || strcasecmp($call->declaringClass ?? '', self::CONTAINER) !== 0
            || count($call->receiverType?->atomicTypes ?? []) !== 1 || ! $receiver instanceof NamedObjectType
            || strcasecmp($receiver->name, self::CONTAINER) !== 0 || $receiver->static || $receiver->isThis
            || ($receiver->parameters ?? []) !== [] || ($receiver->intersections ?? []) !== []) {
            return null;
        }
        foreach ($call->arguments as $argument) {
            if ($argument->unpacked || $argument->placeholder) {
                return null;
            }
        }
        $owner = $context->codebase->getClassLike(self::CONTAINER);
        $method = $context->codebase->getDeclaringMethod(self::CONTAINER, $name);
        if ($owner === null || $owner->kind !== ClassLikeKind::Interface || $owner->hasIncompleteHierarchy()
            || $owner->templates !== [] || $owner->mixins !== [] || $owner->pseudoMethods !== []
            || $method === null || strcasecmp($method->identifier->class ?? '', self::CONTAINER) !== 0
            || $method->static || ! $method->abstract || $method->visibility !== Visibility::Public
            || $method->flags->contains(MetadataFlags::BY_REFERENCE) || count($method->parameters) !== 2
            || count($method->templates) !== 1 || $method->whereConstraints !== [] || $method->assertions !== []
            || $method->ifTrueAssertions !== [] || $method->ifFalseAssertions !== []
            || $method->declaredReturnType === null || ! $context->types->equals($method->declaredReturnType->type, Type::object())
            || $method->returnType === null || ! $method->returnType->fromDocblock
            || ! str_ends_with('/'.ltrim(str_replace('\\', '/', $method->location->file ?? ''), '/'),
                '/internal/container/src/Container.php')) {
            return null;
        }
        $template = $method->templates[0];
        $parent = new GenericParent(GenericParentKind::FunctionLike, strtolower(self::CONTAINER), $name);
        $first = $method->parameters[0];
        $second = $method->parameters[1];
        $parameter = $first->type?->type->atomicTypes[0] ?? null;
        $return = $method->returnType->type->atomicTypes[0] ?? null;
        if ($template->default !== null || $template->readonly || $template->definingEntity != $parent
            || ! $context->types->equals($template->constraint, Type::object())
            || $first->name !== ($name === 'get' ? '$id' : '$class') || $second->name !== '$arguments'
            || $first->declaredType === null || ! $context->types->equals($first->declaredType->type, Type::string())
            || $first->type === null || ! $first->type->fromDocblock || count($first->type->type->atomicTypes) !== 1
            || ! $parameter instanceof ScalarType || $parameter->kind !== ScalarTypeKind::ClassLikeString
            || ! $parameter->refinement instanceof ClassLikeStringType || $first->defaultType !== null
            || $first->flags->contains(MetadataFlags::HAS_DEFAULT)
            || $second->type === null || ! $second->type->fromDocblock || $second->declaredType === null
            || ! $context->types->equals($second->declaredType->type,
                Type::array(Type::fromAtomic(new ScalarType(ScalarTypeKind::ArrayKey)), Type::mixed()))
            || ! $context->types->equals($second->type->type, Type::array(Type::string(), Type::mixed()))
            || $second->defaultType === null || ! $second->flags->contains(MetadataFlags::HAS_DEFAULT)
            || ! $context->types->equals($second->defaultType->type,
                Type::fromAtomic(new ListType(Type::never(), [], 0, false)))
            || count($method->returnType->type->atomicTypes) !== 1
            || ! $return instanceof GenericParameterType || $return->name !== $template->name
            || $return->definingEntity != $parent || ($return->intersections ?? []) !== []
            || ! $context->types->equals($return->constraint, Type::object())) {
            return null;
        }
        $classString = $parameter->refinement;
        if ($classString->variant !== ClassLikeStringVariant::Generic || $classString->kind !== ClassLikeStringKind::Class_
            || $classString->parameterName !== $template->name || $classString->definingEntity != $parent
            || ! $classString->constraint instanceof AnyObjectType) {
            return null;
        }
        foreach ($method->parameters as $parameter) {
            if ($parameter->flags->contains(MetadataFlags::BY_REFERENCE) || $parameter->flags->contains(MetadataFlags::VARIADIC)
                || $parameter->outType !== null || $parameter->closureThisType !== null) {
                return null;
            }
        }

        return $method;
    }
}
