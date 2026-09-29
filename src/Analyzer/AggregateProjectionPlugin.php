<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;

/** Source-sensitive providers require spans, which provider memoization removes. */
final class AggregateProjectionPlugin implements Plugin
{
    public function __construct(
        private readonly string $projectRoot = '.',
    ) {}

    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition(
            'ichinya/laramago-projections',
            'Laravel aggregate projections',
            'Literal aggregate aliases on complete Eloquent query chains.',
        );
    }

    public function register(PluginRegistry $registry): void
    {
        $provider = new EloquentAggregateProjectionProvider($this->projectRoot);
        $registry->registerCodebaseScanHook($provider->calls);
        $registry->registerInitializationHook($provider);
        $registry->registerMethodReturnTypeProvider($provider);
    }
}
