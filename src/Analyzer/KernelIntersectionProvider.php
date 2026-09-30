<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Ichinya\Laramago\Analyzer\StaticAnalysis\KernelIntersectionCalls;
use Mago\Sdk\Analyzer\CallableSignatureOverride;
use Mago\Sdk\Analyzer\CallableSignatureProviderContext;
use Mago\Sdk\Analyzer\EffectiveCallableSignature;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\Invocation;
use Mago\Sdk\Analyzer\InvocationKind;
use Mago\Sdk\Analyzer\Metadata\ClassLikeKind;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\CallableParameter;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\Visibility;
use PhpParser\Node;
use PhpParser\NodeFinder;

/** Preserve the asserted kernel interface when Mago also dispatches its intersection. */
final class KernelIntersectionProvider implements MethodReturnTypeProvider, CallableSignatureOverride, InitializationHook
{
    private const HTTP = 'Illuminate\\Contracts\\Http\\Kernel';
    private const CONSOLE = 'Illuminate\\Contracts\\Console\\Kernel';
    private const REQUEST = 'Symfony\\Component\\HttpFoundation\\Request';
    private const RESPONSE = 'Symfony\\Component\\HttpFoundation\\Response';
    private const INPUT = 'Symfony\\Component\\Console\\Input\\InputInterface';
    private const OUTPUT = 'Symfony\\Component\\Console\\Output\\OutputInterface';

    private ?PhpSource $source = null;
    private ?bool $verified = null;

    public readonly KernelIntersectionCalls $calls;

    public function __construct(private readonly string $root = '.')
    {
        $this->calls = new KernelIntersectionCalls;
    }

    public function initialize(InitializationContext $context): void
    {
        $this->source = null;
        $this->verified = null;
    }

    public function getTargets(): array
    {
        return [MethodTarget::exact(self::HTTP, 'handle'), MethodTarget::exact(self::CONSOLE, 'handle'),
            MethodTarget::exact(self::HTTP, 'terminate'), MethodTarget::exact(self::CONSOLE, 'terminate')];
    }

    public function getCallableSignature(CallableSignatureProviderContext $context): ?EffectiveCallableSignature
    {
        $owner = $this->selected($context->invocation);
        if ($owner === null || ! ($this->verified ??= $this->verify($context))) {
            return null;
        }
        $method = $context->codebase->getDeclaringMethod($owner, $context->invocation->name);

        return new EffectiveCallableSignature(array_map(static fn ($parameter): CallableParameter =>
            new CallableParameter($parameter->name, $parameter->type->type,
                hasDefault: $parameter->defaultType !== null), $method->parameters));
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $owner = $this->selected($context->invocation);
        if ($owner === null || ! ($this->verified ??= $this->verify($context))) {
            return null;
        }

        return $context->codebase->getDeclaringMethod($owner, $context->invocation->name)?->returnType?->type;
    }

    private function selected(Invocation $call): ?string
    {
        $receiver = $call->receiverType?->atomicTypes[0] ?? null;
        if ($call->kind !== InvocationKind::InstanceMethod || count($call->receiverType?->atomicTypes ?? []) !== 1
            || ! $receiver instanceof NamedObjectType || count($receiver->intersections ?? []) !== 1
            || ! in_array(strtolower($call->name), ['handle', 'terminate'], true)
            || ! in_array(strtolower($call->declaringClass ?? ''), [strtolower(self::HTTP), strtolower(self::CONSOLE)], true)) {
            return null;
        }
        $intersection = $receiver->intersections[0];
        if (! $intersection instanceof NamedObjectType || ($intersection->intersections ?? []) !== []) {
            return null;
        }
        foreach ([$receiver, $intersection] as $type) {
            if (($type->parameters ?? []) !== [] || $type->static || $type->isThis
                || ! in_array(strtolower($type->name), [strtolower(self::HTTP), strtolower(self::CONSOLE)], true)) {
                return null;
            }
        }
        if (strcasecmp($receiver->name, $intersection->name) === 0) {
            return null;
        }

        $owner = $this->calls->owner($call->span, $call->name);

        // Select only the member asserted by a proven direct positive guard.
        // Calls outside that branch retain Mago's native intersection dispatch.
        return $owner !== null && (strcasecmp($receiver->name, $owner) === 0 || strcasecmp($intersection->name, $owner) === 0)
            ? $owner : null;
    }

    private function verify(CallableSignatureProviderContext|ReturnTypeProviderContext $context): bool
    {
        foreach ([self::HTTP, self::CONSOLE] as $owner) {
            $class = $context->codebase->getClassLike($owner);
            if ($class === null || $class->kind !== ClassLikeKind::Interface || $class->hasIncompleteHierarchy()
                || $class->parentInterfaces !== [] || $class->templates !== [] || $class->pseudoMethods !== []
                || $class->staticPseudoMethods !== [] || $class->mixins !== []) {
                return false;
            }
            foreach (['handle', 'terminate'] as $name) {
                $http = $owner === self::HTTP;
                $parameters = $http ? [Type::namedObject(self::REQUEST)] : [Type::namedObject(self::INPUT)];
                $names = $http ? ['$request'] : ['$input'];
                if ($name === 'terminate') {
                    $parameters[] = $http ? Type::namedObject(self::RESPONSE) : Type::int();
                    $names[] = $http ? '$response' : '$status';
                } elseif (! $http) {
                    $parameters[] = Type::union(Type::namedObject(self::OUTPUT), Type::null());
                    $names[] = '$output';
                }
                $return = $name === 'terminate' ? Type::void() : ($http ? Type::namedObject(self::RESPONSE) : Type::int());
                $method = $context->codebase->getDeclaringMethod($owner, $name);
                if ($method === null || strcasecmp($method->identifier->class ?? '', $owner) !== 0
                    || $method->static || ! $method->abstract || $method->visibility !== Visibility::Public
                    || $method->declaredReturnType !== null || $method->templates !== []
                    || $method->assertions !== [] || $method->ifTrueAssertions !== [] || $method->ifFalseAssertions !== []
                    || $method->flags->contains(MetadataFlags::BY_REFERENCE)
                    || $method->returnType === null || ! $method->returnType->fromDocblock
                    || ! $context->types->equals($method->returnType->type, $return)
                    || count($method->parameters) !== count($parameters)
                    || ! str_ends_with('/'.ltrim(str_replace('\\', '/', $method->location->file ?? ''), '/'),
                        '/laravel/framework/src/'.str_replace('\\', '/', $owner).'.php')) {
                    return false;
                }
                $node = $this->methodNode($method);
                if ($node === null || $node->stmts !== null || $node->returnType !== null
                    || count($node->params) !== count($parameters) || $node->byRef) {
                    return false;
                }
                foreach ($parameters as $index => $type) {
                    $parameter = $method->parameters[$index];
                    $syntax = $node->params[$index];
                    $optional = ! $http && $name === 'handle' && $index === 1;
                    if ($parameter->name !== $names[$index] || $parameter->declaredType !== null
                        || $parameter->type === null || ! $parameter->type->fromDocblock
                        || ! $context->types->equals($parameter->type->type, $type)
                        || $parameter->outType !== null || $parameter->closureThisType !== null
                        || $parameter->flags->contains(MetadataFlags::BY_REFERENCE)
                        || $parameter->flags->contains(MetadataFlags::VARIADIC)
                        || ($parameter->defaultType !== null) !== $optional
                        || ($optional && ! $context->types->equals($parameter->defaultType->type, Type::null()))
                        || $syntax->byRef || $syntax->variadic || $syntax->type !== null) {
                        return false;
                    }
                }
            }
        }
        return true;
    }

    private function methodNode(FunctionLikeMetadata $method): ?Node\Stmt\ClassMethod
    {
        $file = str_replace('\\', '/', $method->location->file ?? '');
        // External includes use Windows' extended drive spelling in SDK metadata.
        if (preg_match('~^//\\?/[A-Za-z]:/~', $file) === 1) {
            $file = substr($file, 4);
        }
        $source = $this->source ??= new PhpSource($this->root);
        foreach ((new NodeFinder)->findInstanceOf($source->read($file) ?? [], Node\Stmt\ClassMethod::class) as $node) {
            if (strcasecmp($node->name->toString(), $method->originalName) === 0
                && $node->getStartFilePos() >= $method->location->span->start
                && $node->getStartFilePos() < $method->location->span->end) {
                return $node;
            }
        }
        return null;
    }
}
