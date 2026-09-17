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
        $properties = new EloquentPropertyProvider($this->projectRoot);
        $relationMethods = new EloquentRelationMethodProvider($this->projectRoot);
        $registry->registerMethodReturnTypeProvider($relationMethods);
        $registry->registerInitializationHook($relationMethods);
        $registry->registerMethodReturnTypeProvider(new HigherOrderMapProvider);
        $registry->registerMethodReturnTypeProvider(new EloquentRelationProvider);
        $registry->registerMethodReturnTypeProvider(new CollectionFilterProvider);
        $registry->registerMethodReturnTypeProvider(new CollectionAggregateProvider($properties));
        $macros = new MacroProvider($this->projectRoot);
        if ($macros->hasTargets()) {
            $registry->registerMethodReturnTypeProvider($macros);
            $registry->registerInitializationHook($macros);
        }
        $registry->registerMethodReturnTypeProvider(new FacadeRootProvider($this->projectRoot));
        $bindings = new ContainerBindingProvider($this->projectRoot);
        $registry->registerMethodReturnTypeProvider($bindings);
        $registry->registerInitializationHook($bindings);
        $containerHelpers = new ContainerHelperProvider($this->projectRoot);
        $registry->registerFunctionReturnTypeProvider($containerHelpers);
        $registry->registerInitializationHook($containerHelpers);
        $httpTests = new TestResponseCallbackProvider($this->projectRoot);
        $registry->registerMethodReturnTypeProvider($httpTests);
        $registry->registerInitializationHook($httpTests);
        $registry->registerMethodReturnTypeProvider(new LaratestoResponseCallbackProvider($httpTests));
        $configuration = new ConfigurationProvider($this->projectRoot);
        $registry->registerFunctionReturnTypeProvider($configuration);
        $registry->registerInitializationHook($configuration);
        $registry->registerBeforeAnalysisHook($configuration);
        $configurationFacade = new ConfigurationFacadeProvider($this->projectRoot, $configuration);
        $registry->registerMethodReturnTypeProvider($configurationFacade);
        $registry->registerMethodReturnTypeProvider(new FacadeCallProvider($this->projectRoot));
        $translations = new TranslationStringProvider($this->projectRoot);
        $registry->registerFunctionReturnTypeProvider($translations);
        $registry->registerInitializationHook($translations);
        $registry->registerMethodReturnTypeProvider(new EloquentRelationCallbackProvider($this->projectRoot));
        $registry->registerMethodCallAnalysisHook(new EloquentRelationNamesHook);
        $registry->registerMethodCallAnalysisHook(new RouteParametersHook($this->projectRoot));
        $registry->registerMethodReturnTypeProvider(new EloquentBuilderProvider);
        $registry->registerMethodReturnTypeProvider(new EloquentBuilderForwardingProvider);
        $registry->registerMethodReturnTypeProvider(new EloquentScopeProvider);
        $registry->registerMethodReturnTypeProvider(new EloquentWhereProvider);
        $registry->registerMethodReturnTypeProvider(new EloquentFindProvider);
        $registry->registerMethodReturnTypeProvider(new EloquentCreateProvider);
        $projections = new EloquentProjectionProvider($this->projectRoot, $properties);
        $registry->registerMethodReturnTypeProvider($projections);
        $registry->registerInitializationHook($projections);
        $registry->registerMethodReturnTypeProvider(new EloquentQueryProvider);
        $validated = new ValidatedInputProvider($this->projectRoot);
        $registry->registerMethodReturnTypeProvider($validated);
        $registry->registerInitializationHook($validated);
        $auth = new AuthUserProvider($this->projectRoot);
        $registry->registerMethodReturnTypeProvider($auth);
        $registry->registerInitializationHook($auth);
        $authHelper = new AuthHelperProvider($this->projectRoot);
        $registry->registerFunctionReturnTypeProvider($authHelper);
        $registry->registerInitializationHook($authHelper);
        $authGuards = new AuthGuardProvider($this->projectRoot);
        $registry->registerMethodReturnTypeProvider($authGuards);
        $registry->registerInitializationHook($authGuards);
        $factories = new EloquentFactoryProvider($this->projectRoot);
        $registry->registerMethodReturnTypeProvider($factories);
        $registry->registerInitializationHook($factories);
        $registry->registerPropertyTypeProvider(new HigherOrderMapPropertyProvider($properties));
        $registry->registerPropertyTypeProvider($properties);
        $registry->registerInitializationHook($properties);
        $registry->registerBeforeAnalysisHook($properties);
        $registry->enableProviderMemoization();
    }
}
