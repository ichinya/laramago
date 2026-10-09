<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\InstalledMethodTokens;
use Mago\Sdk\Analyzer\{CallableSignatureOverride, CallableSignatureProviderContext, EffectiveCallableSignature, InvocationKind, MethodReturnTypeProvider, MethodTarget, ReturnTypeProviderContext, Type};
use Mago\Sdk\Analyzer\Type\{CallableParameter, NamedObjectType};

/** The installed Migrator explicitly resolves a null name through setConnection(). */
final class MigratorConnectionProvider implements MethodReturnTypeProvider, CallableSignatureOverride
{
    private const MIGRATOR = 'Illuminate\\Database\\Migrations\\Migrator';
    private const USING = <<<'PHP'
        public function usingConnection($name, callable $callback)
        {
            $previousConnection = $this->connection;
            $previousDefaultConnection = $this->resolver->getDefaultConnection();
            $this->setConnection($name);
            try { return $callback(); }
            finally {
                $this->repository->setSource($previousConnection);
                $this->resolver->setDefaultConnection($previousDefaultConnection);
                $this->connection = $previousConnection;
            }
        }
        PHP;
    private const SET = <<<'PHP'
        public function setConnection($name)
        {
            if (is_null($name)) {
                $defaultName = $this->resolver->getDefaultConnection();
                $directName = $this->directConnectionName($defaultName);
                if ($directName === $defaultName) {
                    $this->repository->setSource(null);
                    $this->connection = null;
                    return;
                }
                $name = $directName;
            } else { $name = $this->directConnectionName($name); }
            $this->repository->setSource($name);
            $this->resolver->setDefaultConnection($name);
            $this->connection = $name;
        }
        PHP;
    private const DIRECT = <<<'PHP'
        protected function directConnectionName($name)
        {
            $name ??= $this->resolver->getDefaultConnection();
            if (Str::endsWith($name, ['::read', '::write', '::direct'])) { return $name; }
            return $this->resolver->connection($name)->hasDirectConnection() ? $name.'::direct' : $name;
        }
        PHP;
    private readonly InstalledMethodTokens $native;
    public function __construct(string $root) { $this->native = new InstalledMethodTokens($root); }
    public function getTargets(): array { return [MethodTarget::exact(self::MIGRATOR, 'usingConnection')]; }

    public function getCallableSignature(CallableSignatureProviderContext $context): ?EffectiveCallableSignature
    {
        $call = $context->invocation;
        $object = $call->receiverType?->atomicTypes[0] ?? null;
        if ($call->kind !== InvocationKind::InstanceMethod || ! $object instanceof NamedObjectType
            || count($call->receiverType->atomicTypes) !== 1 || strcasecmp($object->name, self::MIGRATOR) !== 0) { return null; }
        $path = '/laravel/framework/src/Illuminate/Database/Migrations/Migrator.php';
        if (! $this->native->proves($context->codebase, self::MIGRATOR, 'usingConnection', self::MIGRATOR, $path, self::USING)
            || ! $this->native->proves($context->codebase, self::MIGRATOR, 'setConnection', self::MIGRATOR, $path, self::SET)
            || ! $this->native->proves($context->codebase, self::MIGRATOR, 'directConnectionName', self::MIGRATOR, $path, self::DIRECT)) { return null; }
        $method = $context->codebase->getDeclaringMethod(self::MIGRATOR, 'usingConnection');
        $callback = $method?->parameters[1]->type?->type;
        if ($method === null || count($method->parameters) !== 2 || $callback === null) { return null; }
        return new EffectiveCallableSignature([
            new CallableParameter('$name', Type::union(Type::string(), Type::null())),
            new CallableParameter('$callback', $callback),
        ], displayName: self::MIGRATOR.'::usingConnection');
    }

    // Native TReturn callback substitution remains authoritative.
    public function getReturnType(ReturnTypeProviderContext $context): ?Type { return null; }
}
