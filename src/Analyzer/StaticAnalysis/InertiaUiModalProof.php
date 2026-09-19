<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
use PhpParser\Node;

/** Proves the installed Inertia UI modal macro and its page forwarding path. */
final class InertiaUiModalProof
{
    // SHA-256 of complete upstream PHP files after CRLF/CR normalization. These
    // pin the registration, promoted fields, and toResponse forwarding bodies.
    private const PROVIDER_HASH = '6978cfd2cc68adfd0814da4ccbcfe64b7cdc217194874e2af8a0d8939b94a1aa';
    private const MODAL_HASH = '731a7f7899285b5c0925eb1e14de180d61f084e19a030f912591a10c1f4651ae';
    private const MACROABLE_HASH = '6dd85b1e28b55ebeee678f9ca423dfb8375e1342bc42d8658da6321bdf97b3c0';
    private const HELPER_HASH = 'c8c89ae1924be565d7b42b7416581bd8cd89c523026bdc51da9d5874cd252d20';
    private const PROVIDER = 'InertiaUI\\Modal\\ModalServiceProvider';
    private const MODAL = 'InertiaUI\\Modal\\Modal';
    private const FACTORY = 'Inertia\\ResponseFactory';
    private const MACROABLE = 'Illuminate\\Support\\Traits\\Macroable';

    private ?bool $supported = null;

    public function __construct(
        private readonly string $root,
        private readonly PhpSource $source,
    ) {}

    public function supports(Codebase $codebase): bool
    {
        return $this->supported ??= $this->prove($codebase);
    }

    private function prove(Codebase $codebase): bool
    {
        if (! $this->assertedActive()) {
            return false;
        }

        $helper = $codebase->getFunction('inertia');
        if (
            $helper === null
            || $codebase->getFunction('InertiaUI\\Modal\\inertia') !== null
            || ! str_ends_with(
                str_replace('\\', '/', $helper->location->file ?? ''),
                '/inertiajs/inertia-laravel/helpers.php',
            )
            || self::sourceHash($helper->location->file) !== self::HELPER_HASH
        ) {
            return false;
        }

        $macros = new MacroIndex($this->root);
        if (
            $macros->hasUnknownRegistrations()
            || $macros->mayRegister(self::FACTORY, 'modal')
            || $macros->mayRegister('Inertia\\Inertia', 'modal')
            || $codebase->getMethod(self::FACTORY, 'modal') !== null
            || $codebase->getDeclaringMethod(self::FACTORY, 'modal') !== null
            || $codebase->getMethod('Inertia\\Inertia', 'modal') !== null
            || $codebase->getDeclaringMethod('Inertia\\Inertia', 'modal') !== null
        ) {
            return false;
        }

        foreach (['macro', 'hasMacro', '__call'] as $method) {
            $dispatch = $codebase->getDeclaringMethod(self::FACTORY, $method);
            if (
                $dispatch === null
                || strcasecmp($dispatch->identifier->class ?? '', self::MACROABLE) !== 0
                || ! str_ends_with(
                    str_replace('\\', '/', $dispatch->location->file ?? ''),
                    '/laravel/framework/src/Illuminate/Macroable/Traits/Macroable.php',
                )
                || self::sourceHash($dispatch->location->file) !== self::MACROABLE_HASH
            ) {
                return false;
            }
        }

        $provider = $codebase->getDeclaringMethod(self::PROVIDER, 'boot');
        $constructor = $codebase->getDeclaringMethod(self::MODAL, '__construct');
        $response = $codebase->getDeclaringMethod(self::MODAL, 'toResponse');
        if (
            $provider === null
            || $constructor === null
            || $response === null
            || ! self::packageFile($provider->location->file, 'ModalServiceProvider.php')
            || ! self::packageFile($constructor->location->file, 'Modal.php')
            || ! self::packageFile($response->location->file, 'Modal.php')
            || self::sourceHash($provider->location->file) !== self::PROVIDER_HASH
            || self::sourceHash($constructor->location->file) !== self::MODAL_HASH
            || strcasecmp($provider->identifier->class ?? '', self::PROVIDER) !== 0
            || strcasecmp($constructor->identifier->class ?? '', self::MODAL) !== 0
            || strcasecmp($response->identifier->class ?? '', self::MODAL) !== 0
        ) {
            return false;
        }

        $reflection = new ModelReflection($codebase, $this->source);
        $boot = $reflection->methodNode($provider);
        $construct = $reflection->methodNode($constructor);
        $toResponse = $reflection->methodNode($response);

        return (
            $boot !== null
            && $construct !== null
            && $toResponse !== null
            && self::registersModal($boot)
            && self::storesComponent($construct)
            && self::rendersComponent($toResponse)
        );
    }

    private function assertedActive(): bool
    {
        $contents = @file_get_contents($this->root.'/composer.json');
        if ($contents === false) {
            return false;
        }
        try {
            /** @var mixed $composer */
            $composer = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }
        if (! is_array($composer)) {
            return false;
        }
        /** @var mixed $extra */
        $extra = $composer['extra'] ?? null;
        /** @var mixed $settings */
        $settings = is_array($extra) ? $extra['laramago'] ?? null : null;
        /** @var mixed $catalogs */
        $catalogs = is_array($settings) ? $settings['reference-catalogs'] ?? null : null;
        /** @var mixed $catalog */
        $catalog = is_array($catalogs) ? $catalogs['inertia-modal'] ?? null : null;

        return (
            is_array($catalog)
            && ($catalog['provider-active'] ?? null) === true
            && ($catalog['macro-unmodified'] ?? null) === true
            && ($catalog['native-helper-active'] ?? null) === true
        );
    }

    private static function registersModal(Node\Stmt\ClassMethod $boot): bool
    {
        $registrations = 0;
        foreach ($boot->stmts ?? [] as $statement) {
            if (
                ! $statement instanceof Node\Stmt\Expression
                || ! $statement->expr instanceof Node\Expr\StaticCall
                || ! self::named($statement->expr->class, self::FACTORY)
                || ! self::identifier($statement->expr->name, 'macro')
            ) {
                continue;
            }
            $name = PhpSource::argument($statement->expr->args, 0, 'name');
            if (! $name instanceof Node\Scalar\String_ || $name->value !== 'modal') {
                continue;
            }
            ++$registrations;
            $callable = PhpSource::argument($statement->expr->args, 1, 'macro');
            if (
                ! $callable instanceof Node\Expr\ArrowFunction
                || count($callable->params) !== 2
                || ! self::variable($callable->params[0]->var, 'component')
                || ! self::variable($callable->params[1]->var, 'props')
                || ! $callable->params[1]->default instanceof Node\Expr\Array_
                || $callable->params[1]->default->items !== []
                || ! $callable->expr instanceof Node\Expr\New_
                || ! self::named($callable->expr->class, self::MODAL)
                || ! self::variable(PhpSource::argument($callable->expr->args, 0, 'component'), 'component')
                || ! self::variable(PhpSource::argument($callable->expr->args, 1, 'props'), 'props')
            ) {
                return false;
            }
        }

        return $registrations === 1;
    }

    private static function storesComponent(Node\Stmt\ClassMethod $constructor): bool
    {
        if (count($constructor->params) !== 2) {
            return false;
        }
        $component = $constructor->params[0];
        $props = $constructor->params[1];

        return (
            self::variable($component->var, 'component')
            && self::identifier($component->type, 'string')
            && $component->flags !== 0
            && self::variable($props->var, 'props')
            && self::identifier($props->type, 'array')
            && $props->flags !== 0
        );
    }

    private static function rendersComponent(Node\Stmt\ClassMethod $method): bool
    {
        foreach ($method->stmts ?? [] as $statement) {
            if (
                ! $statement instanceof Node\Stmt\Expression
                || ! $statement->expr instanceof Node\Expr\Assign
                || ! $statement->expr->expr instanceof Node\Expr\MethodCall
            ) {
                continue;
            }
            $call = $statement->expr->expr;
            if (
                ! self::identifier($call->name, 'render')
                || ! $call->var instanceof Node\Expr\FuncCall
                || ! self::named($call->var->name, 'inertia')
                || count($call->var->args) !== 0
                || ! self::property(PhpSource::argument($call->args, 0, 'component'), 'component')
                || ! self::property(PhpSource::argument($call->args, 1, 'props'), 'props')
            ) {
                continue;
            }

            return true;
        }

        return false;
    }

    private static function property(?Node $node, string $name): bool
    {
        return (
            $node instanceof Node\Expr\PropertyFetch
            && self::variable($node->var, 'this')
            && self::identifier($node->name, $name)
        );
    }

    private static function variable(?Node $node, string $name): bool
    {
        return $node instanceof Node\Expr\Variable && $node->name === $name;
    }

    private static function identifier(?Node $node, string $name): bool
    {
        return $node instanceof Node\Identifier && $node->toString() === $name;
    }

    private static function named(?Node $node, string $name): bool
    {
        return $node instanceof Node\Name && strcasecmp($node->toString(), $name) === 0;
    }

    private static function packageFile(?string $path, string $file): bool
    {
        return str_ends_with(str_replace('\\', '/', $path ?? ''), '/inertiaui/modal/src/'.$file);
    }

    private static function sourceHash(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }
        $source = @file_get_contents($path);

        return $source === false ? null : hash('sha256', str_replace(["\r\n", "\r"], "\n", $source));
    }
}
