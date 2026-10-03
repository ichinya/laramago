<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;

/** Native branch proofs depend on invocation spans and cannot be memoized. */
final class NonEmptyCollectionPlugin implements Plugin
{
    public function __construct(private readonly string $root = '.') {}

    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition(
            'ichinya/laramago-nonempty-collection',
            'Nonempty eager collections',
            'Preserve native item types in proven nonempty collection branches.',
        );
    }

    public function register(PluginRegistry $registry): void
    {
        $provider = new NonEmptyCollectionResultProvider($this->root);
        $registry->registerInitializationHook($provider);
        $registry->registerCodebaseScanHook($provider->calls);
        $registry->registerMethodReturnTypeProvider($provider);
    }
}
