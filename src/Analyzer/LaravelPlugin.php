<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
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
        PhpSource::clearSharedCache();
        $middlewareCycles = new MiddlewareGroupCyclesHook($this->projectRoot);
        if ($middlewareCycles->hasSources()) {
            $registry->registerNodeAnalysisHook($middlewareCycles);
            $registry->registerInitializationHook($middlewareCycles);
        }
        $properties = new EloquentPropertyProvider($this->projectRoot);
        $registry->registerMethodCallAnalysisHook(new PipelineDispatchHook($this->projectRoot));
        $registry->registerMethodCallAnalysisHook(new PipelineArityHook($this->projectRoot));
        $registry->registerMethodCallAnalysisHook(new ContainerSelfAliasHook($this->projectRoot));
        $registry->registerMethodCallAnalysisHook(new ForceFillWriteContractHook);
        $registry->registerMethodCallAnalysisHook(new ForceFillFieldNamesHook($this->projectRoot));
        $queryColumns = new QueryColumnReferencesHook($this->projectRoot);
        $registry->registerMethodCallAnalysisHook($queryColumns);
        $registry->registerInitializationHook($queryColumns);
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
        $stringHelper = new StringHelperProvider($this->projectRoot);
        $registry->registerFunctionReturnTypeProvider($stringHelper);
        $registry->registerInitializationHook($stringHelper);
        $registry->registerMethodReturnTypeProvider(new StringProxyProvider($stringHelper));
        $pathHelpers = new PathHelperProvider($this->projectRoot);
        $registry->registerFunctionReturnTypeProvider($pathHelpers);
        $registry->registerInitializationHook($pathHelpers);
        $registry->registerNodeAnalysisHook(new RequiredFileReferencesHook);
        $pestForwarding = new PestExpectationForwardingProvider($this->projectRoot);
        $registry->registerMethodReturnTypeProvider($pestForwarding);
        $registry->registerInitializationHook($pestForwarding);
        $pestConflicts = new PestDeclaredContextConflictsHook($this->projectRoot);
        $registry->registerNodeAnalysisHook($pestConflicts);
        $registry->registerInitializationHook($pestConflicts);
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
        $configurationKeys = new ConfigurationKeyContractsHook($this->projectRoot);
        $registry->registerNodeAnalysisHook($configurationKeys);
        $registry->registerInitializationHook($configurationKeys);
        $configurationAttributes = new ConfigurationAttributeContractsHook($this->projectRoot);
        $registry->registerNodeAnalysisHook($configurationAttributes);
        $registry->registerInitializationHook($configurationAttributes);
        $serviceIds = new ServiceIdReferencesHook($this->projectRoot);
        $registry->registerNodeAnalysisHook($serviceIds);
        $registry->registerInitializationHook($serviceIds);
        $environmentNames = new EnvironmentHelperReferencesHook($this->projectRoot);
        $registry->registerNodeAnalysisHook($environmentNames);
        $registry->registerInitializationHook($environmentNames);
        $assetReferences = new AssetReferencesHook($this->projectRoot);
        $registry->registerNodeAnalysisHook($assetReferences);
        $registry->registerInitializationHook($assetReferences);
        $environmentMethods = new EnvironmentMethodReferencesHook($this->projectRoot);
        $registry->registerNodeAnalysisHook($environmentMethods);
        $registry->registerInitializationHook($environmentMethods);
        $registry->registerMethodReturnTypeProvider(new FacadeCallProvider($this->projectRoot));
        $filesystemFactories = new FilesystemFactoryProvider($this->projectRoot);
        $registry->registerMethodReturnTypeProvider($filesystemFactories);
        $registry->registerInitializationHook($filesystemFactories);
        $registry->registerMethodReturnTypeProvider(new TransactionProvider($this->projectRoot));
        $translations = new TranslationStringProvider($this->projectRoot);
        $registry->registerFunctionReturnTypeProvider($translations);
        $registry->registerInitializationHook($translations);
        $references = new ReferenceCatalogHook($this->projectRoot);
        $firstViews = new ViewFirstReferencesHook($this->projectRoot);
        $conditionalViews = new ConditionalViewReferencesHook($this->projectRoot);
        $registry->registerMethodCallAnalysisHook($conditionalViews);
        $registry->registerInitializationHook($conditionalViews);
        $registry->registerMethodCallAnalysisHook($firstViews);
        $registry->registerInitializationHook($firstViews);
        $translationReferences = new TranslationReferencesHook($this->projectRoot);
        $registry->registerMethodCallAnalysisHook($translationReferences);
        $registry->registerInitializationHook($translationReferences);
        $viewReferences = new ViewFactoryReferencesHook($this->projectRoot);
        $responseViews = new ResponseViewReferencesHook($this->projectRoot);
        $registry->registerNodeAnalysisHook($responseViews);
        $registry->registerInitializationHook($responseViews);
        $registry->registerMethodCallAnalysisHook($viewReferences);
        $registry->registerInitializationHook($viewReferences);
        $registry->registerNodeAnalysisHook($references);
        $registry->registerInitializationHook($references);
        $voltRoutes = new VoltRouteComponentReferencesHook($this->projectRoot);
        $registry->registerNodeAnalysisHook($voltRoutes);
        $registry->registerInitializationHook($voltRoutes);
        $registry->registerMethodReturnTypeProvider(new EloquentRelationCallbackProvider($this->projectRoot));
        $registry->registerMethodReturnTypeProvider(new EloquentMorphCallbackProvider($this->projectRoot));
        $registry->registerMethodCallAnalysisHook(new EloquentRelationNamesHook($this->projectRoot));
        $validationRuleNames = new ValidationRuleNamesHook($this->projectRoot);
        $registry->registerMethodCallAnalysisHook($validationRuleNames);
        $registry->registerInitializationHook($validationRuleNames);
        $registry->registerClassLikeAnalysisHook(new ModelFieldNamesHook($this->projectRoot));
        $registry->registerMethodCallAnalysisHook(new RouteParametersHook($this->projectRoot));
        $registry->registerMethodCallAnalysisHook(new ControllerActionClassHook($this->projectRoot));
        $registry->registerMethodCallAnalysisHook(new NamedRouteContractsHook($this->projectRoot));
        $namedRouteAttributes = new NamedRouteAttributeContractsHook($this->projectRoot);
        $registry->registerNodeAnalysisHook($namedRouteAttributes);
        $registry->registerInitializationHook($namedRouteAttributes);
        $registry->registerNodeAnalysisHook(new NamedRouteHelperContractsHook($this->projectRoot));
        $registry->registerNodeAnalysisHook(new NamedRouteFacadeContractsHook($this->projectRoot));
        $mixParse = new MixManifestParseHook($this->projectRoot);
        $registry->registerNodeAnalysisHook($mixParse);
        $registry->registerInitializationHook($mixParse);
        $mixReferences = new MixManifestReferencesHook($this->projectRoot);
        $registry->registerNodeAnalysisHook($mixReferences);
        $registry->registerInitializationHook($mixReferences);
        $registry->registerNodeAnalysisHook(new NamedRouteResponseContractsHook($this->projectRoot));
        $storageDisks = new StorageDiskContractsHook($this->projectRoot);
        $registry->registerNodeAnalysisHook($storageDisks);
        $registry->registerInitializationHook($storageDisks);
        $inertiaEntries = new InertiaEntryReferencesHook($this->projectRoot);
        $registry->registerNodeAnalysisHook($inertiaEntries);
        $registry->registerInitializationHook($inertiaEntries);
        $inertiaPages = new InertiaPageReferencesHook($this->projectRoot);
        $registry->registerMethodCallAnalysisHook($inertiaPages);
        $registry->registerInitializationHook($inertiaPages);
        $inertiaTestPages = new InertiaTestComponentReferencesHook($this->projectRoot);
        $registry->registerMethodCallAnalysisHook($inertiaTestPages);
        $registry->registerInitializationHook($inertiaTestPages);
        $livewireComponents = new LivewireComponentReferencesHook($this->projectRoot);
        $registry->registerMethodCallAnalysisHook($livewireComponents);
        $registry->registerInitializationHook($livewireComponents);
        $registry->registerMethodReturnTypeProvider(new EloquentBuilderProvider);
        $registry->registerMethodReturnTypeProvider(new EloquentBuilderForwardingProvider($this->projectRoot));
        $scopes = new EloquentScopeProvider($this->projectRoot);
        $registry->registerMethodReturnTypeProvider($scopes);
        $registry->registerInitializationHook($scopes);
        $registry->registerMethodReturnTypeProvider(new EloquentWhereProvider);
        $registry->registerMethodReturnTypeProvider(new EloquentFindProvider);
        $registry->registerMethodReturnTypeProvider(new EloquentCreateProvider);
        $projections = new EloquentProjectionProvider($this->projectRoot, $properties);
        $registry->registerMethodReturnTypeProvider($projections);
        $registry->registerInitializationHook($projections);
        $registry->registerMethodReturnTypeProvider(new EloquentQueryProvider);
        $validationParameters = new ValidationRuleParametersHook($this->projectRoot);
        $registry->registerMethodCallAnalysisHook($validationParameters);
        $registry->registerInitializationHook($validationParameters);
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
