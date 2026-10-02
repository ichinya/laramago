<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;

/** Invocation spans must remain intact; this plugin deliberately avoids memoization. */
final class ModelRefreshPlugin implements Plugin
{
    public function __construct(private readonly string $root = '.') {}

    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition('ichinya/laramago-model-refresh', 'Generic model refresh', 'Preserve proven caller templates through native model reloads.');
    }

    public function register(PluginRegistry $registry): void
    {
        $provider = new ModelRefreshReturnTypeProvider($this->root);
        $registry->registerInitializationHook($provider);
        $registry->registerCodebaseScanHook($provider->calls);
        $registry->registerMethodReturnTypeProvider($provider);
    }
}
