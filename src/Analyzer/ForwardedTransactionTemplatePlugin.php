<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;

/** Scoped source-derived templates must not be memoized as ordinary facade results. */
final class ForwardedTransactionTemplatePlugin implements Plugin
{
    public function __construct(private readonly string $root = '.') {}
    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition('ichinya/laramago-forwarded-transaction-templates', 'Forwarded transaction templates',
            'Carry independently declared callable results through verified native transaction wrappers.');
    }
    public function register(PluginRegistry $registry): void
    {
        $provider = new ForwardedTransactionTemplateProvider($this->root);
        $registry->registerInitializationHook($provider);
        $registry->registerCodebaseScanHook($provider->calls);
        $registry->registerMethodReturnTypeProvider($provider);
    }
}
