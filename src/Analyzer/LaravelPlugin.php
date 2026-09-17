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
        $registry->registerMethodReturnTypeProvider(new EloquentRelationProvider);
        $registry->registerMethodReturnTypeProvider(new CollectionFilterProvider);
        $macros = new MacroProvider($this->projectRoot);
        if ($macros->hasTargets()) {
            $registry->registerMethodReturnTypeProvider($macros);
            $registry->registerInitializationHook($macros);
        }
        $registry->registerMethodReturnTypeProvider(new FacadeRootProvider($this->projectRoot));
        $configuration = new ConfigurationProvider($this->projectRoot);
        $registry->registerFunctionReturnTypeProvider($configuration);
        $registry->registerInitializationHook($configuration);
        $registry->registerBeforeAnalysisHook($configuration);
        $translations = new TranslationStringProvider($this->projectRoot);
        $registry->registerFunctionReturnTypeProvider($translations);
        $registry->registerInitializationHook($translations);
        $registry->registerMethodReturnTypeProvider(new EloquentRelationCallbackProvider);
        $registry->registerMethodCallAnalysisHook(new EloquentRelationNamesHook);
        $registry->registerMethodCallAnalysisHook(new RouteParametersHook);
        $registry->registerMethodReturnTypeProvider(new EloquentBuilderProvider);
        $registry->registerMethodReturnTypeProvider(new EloquentBuilderForwardingProvider);
        $registry->registerMethodReturnTypeProvider(new EloquentScopeProvider);
        $registry->registerMethodReturnTypeProvider(new EloquentWhereProvider);
        $registry->registerMethodReturnTypeProvider(new EloquentFindProvider);
        $registry->registerMethodReturnTypeProvider(new EloquentCreateProvider);
        $registry->registerMethodReturnTypeProvider(new EloquentQueryProvider);
        $registry->registerMethodReturnTypeProvider(new ValidatedInputProvider);
        $auth = new AuthUserProvider($this->projectRoot);
        $registry->registerMethodReturnTypeProvider($auth);
        $registry->registerInitializationHook($auth);
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
