<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ContainerBindings;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\Invocation;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use PhpParser\Node;

/** Literal authentication metadata only; environment helpers are never evaluated. */
final class AuthConfiguration
{
    private readonly PhpSource $source;
    private ?\stdClass $metadata = null;
    private bool $metadataLoaded = false;
    private ?ContainerBindings $bindings = null;

    public function __construct(
        private readonly string $root,
    ) {
        $this->source = new PhpSource($root);
    }

    public function guardName(Invocation $call, string $parameter): ?string
    {
        foreach ($call->arguments as $argument) {
            if ($argument->unpacked || $argument->placeholder) {
                return null;
            }
        }
        $argument = $call->getArgument(0, $parameter);
        if ($argument !== null && (string) $argument->type !== 'null') {
            $guard = $argument->type?->getLiteralString();
            if (! in_array($guard, ['', '0'], true)) {
                return $guard;
            }
        }

        $metadata = $this->metadata();
        if (property_exists($metadata, 'default-guard')) {
            return $this->string($metadata->{'default-guard'});
        }

        return $this->literal($this->entry($this->entry($this->configuration(), 'defaults'), 'guard'));
    }

    public function guardClass(?string $guard, ReturnTypeProviderContext $context): ?string
    {
        if ($guard === null || $this->hasCustomFactory()) {
            return null;
        }
        $metadata = $this->guardMetadata($guard);
        if (property_exists($metadata, 'class')) {
            $class = $this->string($metadata->class);

            return $class !== null
            && $context->codebase->getClass($class) !== null
            && $context->types->isContainedBy(
                Type::namedObject($class),
                Type::namedObject('Illuminate\\Contracts\\Auth\\Guard'),
            )
                ? $class
                : null;
        }
        $config = $this->entry($this->entry($this->configuration(), 'guards'), $guard);

        return match ($this->literal($this->entry($config, 'driver'))) {
            'session' => 'Illuminate\\Auth\\SessionGuard',
            'token' => 'Illuminate\\Auth\\TokenGuard',
            default => null,
        };
    }

    public function userType(?string $guard, ReturnTypeProviderContext $context): ?Type
    {
        if ($guard === null || $this->hasCustomFactory()) {
            return null;
        }
        $metadata = $this->guardMetadata($guard);
        if (property_exists($metadata, 'model')) {
            $type = $this->modelType($this->string($metadata->model), $context);
            if ($type !== null && property_exists($metadata, 'class')) {
                $class = $this->guardClass($guard, $context);
                $method = $class === null
                    ? null
                    : $context->codebase->getMethod($class, 'user') ?? $context->codebase->getDeclaringMethod(
                        $class,
                        'user',
                    );
                $declared = $method?->returnType?->type;
                if ($declared === null || ! $context->types->isContainedBy($type, $declared)) {
                    return null;
                }
            }

            return $type;
        }
        // A custom guard class does not imply that its configured provider supplies its users.
        if (property_exists($metadata, 'class') || $this->guardClass($guard, $context) === null) {
            return null;
        }
        $config = $this->configuration();
        $guardConfig = $this->entry($this->entry($config, 'guards'), $guard);
        $providerName = $this->literal($this->entry($guardConfig, 'provider'));
        if ($providerName === null) {
            return null;
        }
        $provider = $this->entry($this->entry($config, 'providers'), $providerName);
        if ($this->literal($this->entry($provider, 'driver')) !== 'eloquent') {
            return null;
        }

        return $this->modelType($this->literal($this->entry($provider, 'model')), $context);
    }

    public function hasCustomFactory(): bool
    {
        $bindings = $this->bindings ??= new ContainerBindings($this->root);

        return $bindings->configured('auth') || $bindings->configured('Illuminate\\Contracts\\Auth\\Factory');
    }

    private function modelType(?string $model, ReturnTypeProviderContext $context): ?Type
    {
        if ($model === null || $context->codebase->getClass($model) === null) {
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

    /** Explicit analysis contracts only: JSON is read as data, never loaded through Composer. */
    private function metadata(): \stdClass
    {
        if (! $this->metadataLoaded) {
            $this->metadataLoaded = true;
            $file = $this->root.'/composer.json';
            $json = is_file($file) ? @file_get_contents($file) : false;
            /** @var mixed $composer */
            $composer = $json === false ? null : json_decode($json);
            /** @var mixed $extra */
            $extra = $composer instanceof \stdClass ? $composer->extra ?? null : null;
            /** @var mixed $laramago */
            $laramago = $extra instanceof \stdClass ? $extra->laramago ?? null : null;
            /** @var mixed $auth */
            $auth = $laramago instanceof \stdClass ? $laramago->auth ?? null : null;
            $this->metadata = $auth instanceof \stdClass ? $auth : new \stdClass;
        }

        return $this->metadata ?? new \stdClass;
    }

    private function guardMetadata(string $guard): \stdClass
    {
        /** @var mixed $guards */
        $guards = $this->metadata()->guards ?? null;
        /** @var mixed $metadata */
        $metadata = $guards instanceof \stdClass ? get_object_vars($guards)[$guard] ?? null : null;

        return $metadata instanceof \stdClass ? $metadata : new \stdClass;
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function configuration(): ?Node\Expr\Array_
    {
        if (! is_file($this->root.'/config/auth.php')) {
            return null;
        }
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
