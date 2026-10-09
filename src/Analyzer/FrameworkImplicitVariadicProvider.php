<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\InstalledMethodTokens;
use Mago\Sdk\Analyzer\{CallableSignatureOverride, CallableSignatureProviderContext, EffectiveCallableSignature, InvocationKind, MethodReturnTypeProvider, MethodTarget, ReturnTypeProviderContext, Type};
use Mago\Sdk\Analyzer\Type\{CallableParameter, NamedObjectType};

/** Positional func_get_args contracts for complete, unchanged framework methods. */
final class FrameworkImplicitVariadicProvider implements MethodReturnTypeProvider, CallableSignatureOverride
{
    private const REDIRECT = 'Illuminate\\Http\\RedirectResponse';
    private const FACADE = 'Illuminate\\Support\\Facades\\Facade';
    private readonly InstalledMethodTokens $native;

    public function __construct(string $root) { $this->native = new InstalledMethodTokens($root); }

    public function getTargets(): array
    {
        return [MethodTarget::exact(self::REDIRECT, 'onlyInput'), MethodTarget::exact(self::REDIRECT, 'exceptInput'),
            MethodTarget::exact(self::FACADE, 'shouldReceive'), MethodTarget::exact(self::FACADE, 'expects')];
    }

    public function getCallableSignature(CallableSignatureProviderContext $context): ?EffectiveCallableSignature
    {
        $call = $context->invocation;
        $object = $call->receiverType?->atomicTypes[0] ?? null;
        if (! $object instanceof NamedObjectType || count($call->receiverType->atomicTypes) !== 1 || $call->arguments === []) { return null; }
        foreach ($call->arguments as $argument) {
            if ($argument->name !== null || $argument->unpacked || $argument->placeholder) { return null; }
        }
        $name = strtolower($call->name);
        if (in_array($name, ['onlyinput', 'exceptinput'], true)) {
            $owner = self::REDIRECT;
            if ($call->kind !== InvocationKind::InstanceMethod) { return null; }
            $method = $name === 'onlyinput' ? 'onlyInput' : 'exceptInput';
            $selector = $name === 'onlyinput' ? 'only' : 'except';
            $body = 'public function '.$method.'() { return $this->withInput($this->request->'.$selector.'(func_get_args())); }';
        } else {
            $owner = self::FACADE;
            if ($call->kind !== InvocationKind::StaticMethod) { return null; }
            $method = $name === 'shouldreceive' ? 'shouldReceive' : 'expects';
            $body = 'public static function '.$method.'() { $name = static::getFacadeAccessor(); $mock = static::isMock() ? static::$resolvedInstance[$name] : static::createFreshMockInstance(); return $mock->'.$method.'(...func_get_args()); }';
        }
        if (! $this->native->proves($context->codebase, $object->name, $method, $owner,
            '/laravel/framework/src/'.str_replace('\\', '/', $owner).'.php', $body)) { return null; }

        return new EffectiveCallableSignature([new CallableParameter('$arguments', Type::mixed(), variadic: true)],
            allowsNamedArguments: false, displayName: $owner.'::'.$method);
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type { return null; }
}
