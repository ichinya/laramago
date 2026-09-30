<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;

/** Source-dependent assertions retain spans by using a registry without provider memoization. */
final class ArrayAssertionPlugin implements Plugin
{
    public function __construct(private readonly string $root = '.') {}

    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition('ichinya/laramago-array-assertions', 'Laravel array assertions', 'Proven PHP-array elements in consecutive assertion statements.');
    }

    public function register(PluginRegistry $registry): void
    {
        $provider = new LaratestoArrayAssertionProvider($this->root);
        $registry->registerCodebaseScanHook($provider->calls);
        $registry->registerInitializationHook($provider);
        $registry->registerMethodAssertionProvider($provider);
    }
}
