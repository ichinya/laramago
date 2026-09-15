<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;

final class LaravelPlugin implements Plugin
{
    public function __construct(
        private readonly string $projectRoot = '.',
    ) {}

    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition(
            'ichinya/laramago',
            'Laravel',
            'Laravel method signatures, model properties and model-aware query types.',
        );
    }

    public function register(PluginRegistry $registry): void
    {
        $registry->registerMethodReturnTypeProvider(new EloquentWhereProvider);
        $registry->registerMethodReturnTypeProvider(new EloquentFindProvider);
        $registry->registerMethodReturnTypeProvider(new EloquentCreateProvider);
        $registry->registerMethodReturnTypeProvider(new ValidatedInputProvider);
        $factories = new EloquentFactoryProvider($this->projectRoot);
        $registry->registerMethodReturnTypeProvider($factories);
        $registry->registerInitializationHook($factories);
        $properties = new EloquentPropertyProvider($this->projectRoot);
        $registry->registerPropertyTypeProvider($properties);
        $registry->registerInitializationHook($properties);
        $registry->registerBeforeAnalysisHook($properties);
        $registry->enableProviderMemoization();
    }
}
