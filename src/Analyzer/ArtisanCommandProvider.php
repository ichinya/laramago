<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ContainerBindings;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ModelReflection;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\CallableSignatureOverride;
use Mago\Sdk\Analyzer\CallableSignatureProviderContext;
use Mago\Sdk\Analyzer\EffectiveCallableSignature;
use Mago\Sdk\Analyzer\InvocationKind;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\CallableParameter;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use PhpParser\Node;
use PhpParser\NodeFinder;

/** Types the closure receiver only when Laravel's native command binding is proven. */
final class ArtisanCommandProvider implements CallableSignatureOverride, MethodReturnTypeProvider
{
    private const FACADE = 'Illuminate\\Support\\Facades\\Artisan';
    private const KERNEL = 'Illuminate\\Foundation\\Console\\Kernel';
    private const CONTRACT = 'Illuminate\\Contracts\\Console\\Kernel';
    private const COMMAND = 'Illuminate\\Foundation\\Console\\ClosureCommand';

    private readonly PhpSource $source;
    private readonly ContainerBindings $bindings;

    public function __construct(string $root)
    {
        $this->source = new PhpSource($root);
        $this->bindings = new ContainerBindings($root);
    }

    public function getTargets(): array
    {
        return [MethodTarget::exact(self::FACADE, 'command')];
    }

    public function getCallableSignature(CallableSignatureProviderContext $context): ?EffectiveCallableSignature
    {
        $call = $context->invocation;
        $receiver = $call->receiverType;
        $object = $receiver?->atomicTypes[0] ?? null;
        if (
            $call->kind !== InvocationKind::StaticMethod
            || $receiver === null
            || count($receiver->atomicTypes) !== 1
            || ! $object instanceof NamedObjectType
            || $object->name !== self::FACADE
            || ($object->parameters ?? []) !== []
            || ($object->intersections ?? []) !== []
            || $object->static
            || $object->isThis
            || $call->declaringClass !== self::FACADE
            || $this->bindings->configured(self::CONTRACT)
        ) {
            return null;
        }
        $codebase = $context->codebase;
        $facade = $codebase->getClass(self::FACADE);
        $method = $codebase->getMethod(self::FACADE, 'command');
        $accessor = $codebase->getDeclaringMethod(self::FACADE, 'getFacadeAccessor');
        $dispatcher = $codebase->getDeclaringMethod(self::FACADE, '__callStatic');
        $kernel = $codebase->getDeclaringMethod(self::KERNEL, 'command');
        $execute = $codebase->getDeclaringMethod(self::COMMAND, 'execute');
        $reflection = new ModelReflection($codebase, $this->source);
        if (
            $facade === null
            || ! $this->nativeFile($facade->location->file, 'Support/Facades/Artisan.php')
            || self::hasNativeCommand($this->source->read($facade->location->file ?? '') ?? [])
            || $method === null
            || $method->identifier->class !== self::FACADE
            || $reflection->methodNode($method) !== null
            || count($method->parameters) !== 2
            || $method->parameters[0]->name !== '$signature'
            || $method->parameters[1]->name !== '$callback'
            || (string) ($method->parameters[0]->type?->type ?? $method->parameters[0]->declaredType?->type) !== 'string'
            || (string) ($method->parameters[1]->type?->type ?? $method->parameters[1]->declaredType?->type) !== 'callable'
            || (string) ($method->returnType?->type ?? $method->declaredReturnType?->type) !== self::COMMAND
            || $method->parameters[1]->closureThisType !== null
            || $accessor === null
            || $accessor->identifier->class !== self::FACADE
            || PhpSource::value($reflection->returnExpression($accessor), self::FACADE, self::FACADE) !== self::CONTRACT
            || $dispatcher?->identifier->class !== FacadeCallResolver::FACADE
            || $kernel === null
            || $kernel->identifier->class !== self::KERNEL
            || ! $this->nativeFile($kernel->location->file, 'Foundation/Console/Kernel.php')
            || $execute === null
            || $execute->identifier->class !== self::COMMAND
            || ! $this->nativeFile($execute->location->file, 'Foundation/Console/ClosureCommand.php')
        ) {
            return null;
        }
        $kernelNode = $reflection->methodNode($kernel);
        $executeNode = $reflection->methodNode($execute);
        if (
            $kernelNode === null
            || $executeNode === null
            || ! self::constructsCommand($kernelNode)
            || ! self::bindsCallback($executeNode)
        ) {
            return null;
        }

        return new EffectiveCallableSignature([
            new CallableParameter('$signature', Type::string()),
            new CallableParameter(
                '$callback',
                $method->parameters[1]->type?->type ?? $method->parameters[1]->declaredType?->type,
                closureThisType: Type::namedObject(self::COMMAND),
            ),
        ], displayName: self::FACADE.'::command');
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        return null;
    }

    private function nativeFile(?string $file, string $suffix): bool
    {
        return $file !== null && str_ends_with(
            str_replace('\\', '/', $this->source->path($file)),
            '/laravel/framework/src/Illuminate/'.$suffix,
        );
    }

    private static function constructsCommand(Node\Stmt\ClassMethod $method): bool
    {
        $constructed = false;
        $returned = false;
        foreach ($method->stmts ?? [] as $statement) {
            if (
                $statement instanceof Node\Stmt\Expression
                && $statement->expr instanceof Node\Expr\Assign
                && $statement->expr->var instanceof Node\Expr\Variable
                && $statement->expr->var->name === 'command'
                && $statement->expr->expr instanceof Node\Expr\New_
            ) {
                $new = $statement->expr->expr;
                if (
                    $new->class instanceof Node\Name
                    && $new->class->toString() === self::COMMAND
                    && count($new->args) === 2
                    && $new->args[0]->value instanceof Node\Expr\Variable
                    && $new->args[0]->value->name === 'signature'
                    && $new->args[1]->value instanceof Node\Expr\Variable
                    && $new->args[1]->value->name === 'callback'
                ) {
                    $constructed = true;
                }
            }
            if (
                $statement instanceof Node\Stmt\Return_
                && $statement->expr instanceof Node\Expr\Variable
                && $statement->expr->name === 'command'
            ) {
                $returned = true;
            }
        }

        return $constructed && $returned;
    }

    private static function bindsCallback(Node\Stmt\ClassMethod $method): bool
    {
        foreach ((new NodeFinder)->findInstanceOf($method->stmts ?? [], Node\Expr\MethodCall::class) as $call) {
            if (
                $call->name instanceof Node\Identifier
                && $call->name->toString() === 'bindTo'
                && $call->var instanceof Node\Expr\PropertyFetch
                && $call->var->var instanceof Node\Expr\Variable
                && $call->var->var->name === 'this'
                && $call->var->name instanceof Node\Identifier
                && $call->var->name->toString() === 'callback'
                && count($call->args) === 2
                && $call->args[0]->value instanceof Node\Expr\Variable
                && $call->args[0]->value->name === 'this'
                && $call->args[1]->value instanceof Node\Expr\Variable
                && $call->args[1]->value->name === 'this'
            ) {
                return true;
            }
        }

        return false;
    }

    /** @param array<array-key, Node> $nodes */
    private static function hasNativeCommand(array $nodes): bool
    {
        foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Stmt\ClassMethod::class) as $method) {
            if (strcasecmp($method->name->toString(), 'command') === 0) {
                return true;
            }
        }

        return false;
    }
}
