<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\StringType;
use PhpParser\Node;

/** Resolves conventional Request authentication from literal configuration without executing it. */
final class AuthUserProvider implements MethodReturnTypeProvider, InitializationHook
{
    private const REQUEST = 'Illuminate\\Http\\Request';

    private ?PhpSource $source = null;

    public function __construct(
        private readonly string $root,
    ) {}

    public function initialize(InitializationContext $context): void
    {
        $this->source = null;
    }

    public function getTargets(): array
    {
        return [MethodTarget::exact(self::REQUEST, 'user')];
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $call = $context->invocation;
        $atoms = $call->receiverType?->atomicTypes ?? [];
        if (count($atoms) !== 1 || ! $atoms[0] instanceof NamedObjectType) {
            return null;
        }
        $method = $context->codebase->getMethod($atoms[0]->name, 'user') ?? $context->codebase->getDeclaringMethod(
            $atoms[0]->name,
            'user',
        );
        if ($method === null || strcasecmp($method->identifier->class ?? '', self::REQUEST) !== 0) {
            return null;
        }
        $resolver = $context->codebase->getMethod(
            $atoms[0]->name,
            'getUserResolver',
        ) ?? $context->codebase->getDeclaringMethod($atoms[0]->name, 'getUserResolver');
        if ($resolver !== null && strcasecmp($resolver->identifier->class ?? '', self::REQUEST) !== 0) {
            return null;
        }
        foreach ($context->codebase->getMultipleClasses([
            $atoms[0]->name,
            ...$context->codebase->getClassAncestors($atoms[0]->name),
        ]) as $metadata) {
            foreach ($metadata->pseudoMethods ?? [] as $name) {
                if (strcasecmp($name, 'user') === 0) {
                    return null;
                }
            }
        }
        foreach ($call->arguments as $argument) {
            if ($argument->unpacked || $argument->placeholder) {
                return null;
            }
        }
        $config = $this->configuration();
        if ($config === null) {
            return null;
        }
        $guardArgument = $call->getArgument(0, 'guard');
        $guard = null;
        if ($guardArgument !== null && (string) $guardArgument->type !== 'null') {
            $guardAtoms = $guardArgument->type?->atomicTypes ?? [];
            if (
                count($guardAtoms) !== 1
                || ! $guardAtoms[0] instanceof ScalarType
                || ! $guardAtoms[0]->refinement instanceof StringType
            ) {
                return null;
            }
            $guard = $guardAtoms[0]->refinement->literalValue;
            if ($guard === null) {
                return null;
            }
        } else {
            $guard = $this->literal($this->entry($this->entry($config, 'defaults'), 'guard'));
        }
        if (! is_string($guard)) {
            return null;
        }
        $guardConfig = $this->entry($this->entry($config, 'guards'), $guard);
        $driver = $this->literal($this->entry($guardConfig, 'driver'));
        if (! in_array($driver, ['session', 'token'], true)) {
            return null;
        }
        $providerName = $this->literal($this->entry($guardConfig, 'provider'));
        if (! is_string($providerName)) {
            return null;
        }
        $provider = $this->entry($this->entry($config, 'providers'), $providerName);
        if ($this->literal($this->entry($provider, 'driver')) !== 'eloquent') {
            return null;
        }
        $model = $this->literal($this->entry($provider, 'model'));
        if (! is_string($model) || $context->codebase->getClass($model) === null) {
            return null;
        }
        $type = Type::namedObject($model);
        if (! $context->types->isContainedBy(
            $type,
            Type::namedObject('Illuminate\\Contracts\\Auth\\Authenticatable'),
        )) {
            return null;
        }

        return Type::union($type, Type::null());
    }

    private function configuration(): ?Node\Expr\Array_
    {
        if (! is_file($this->root.'/config/auth.php')) {
            return null;
        }
        $this->source ??= new PhpSource($this->root);
        $nodes = $this->source->read('config/auth.php') ?? [];
        $result = null;
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Return_) {
                if ($result !== null || ! $node->expr instanceof Node\Expr\Array_) {
                    return null;
                }
                $result = $node->expr;
            } elseif (
                ! $node instanceof Node\Stmt\Use_
                && ! $node instanceof Node\Stmt\Declare_
                && ! $node instanceof Node\Stmt\Expression
                && ! $node instanceof Node\Stmt\Nop
            ) {
                return null;
            }
        }

        return $result;
    }

    private function entry(?Node $array, string $key): ?Node
    {
        if (! $array instanceof Node\Expr\Array_) {
            return null;
        }
        $result = null;
        foreach ($array->items as $item) {
            if ($item->unpack || ! $item->key instanceof Node\Scalar\String_) {
                return null;
            }
            if ($item->key->value === $key) {
                $result = $item->value;
            }
        }

        return $result;
    }

    private function literal(?Node $node): ?string
    {
        $value = PhpSource::value($node);

        return is_string($value) ? $value : null;
    }
}
