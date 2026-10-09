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
        $registry->registerMethodReturnTypeProvider(new FrameworkImplicitVariadicProvider($this->projectRoot));
        $registry->registerMethodReturnTypeProvider(new ApplicationEnvironmentProvider($this->projectRoot));
        $registry->registerFunctionReturnTypeProvider(new StringPredicateArrayProvider($this->projectRoot));
        $registry->registerMethodReturnTypeProvider(new DomXPathResultProvider);
        $registry->registerMethodReturnTypeProvider(new MigratorConnectionProvider($this->projectRoot));
        $registry->registerFunctionReturnTypeProvider(new BackedEnumColumnProvider);
        $registry->registerIssueFilterHook(new NeverCallReturnFilter);
        $registry->registerIssueFilterHook(new ProcOpenDescriptorFilter);
        $mixedAssignments = new LocalMixedAssignmentFilter;
        $registry->registerIssueFilterHook($mixedAssignments);
        $registry->registerInitializationHook($mixedAssignments);
        $casts = new CastCompatibilityFilter;
        $registry->registerIssueFilterHook($casts);
        $registry->registerInitializationHook($casts);
        $scalarOperands = new ScalarOperandCompatibilityFilter;
        $registry->registerIssueFilterHook($scalarOperands);
        $registry->registerInitializationHook($scalarOperands);
        $openShapes = new OpenArrayShapeReturnFilter;
        $registry->registerIssueFilterHook($openShapes);
        $registry->registerInitializationHook($openShapes);
        $constantMaps = new ClassConstantStringMapReturnFilter;
        $registry->registerIssueFilterHook($constantMaps);
        $registry->registerInitializationHook($constantMaps);
        $validatedForeach = new ValidatedForeachReturnFilter;
        $registry->registerIssueFilterHook($validatedForeach);
        $registry->registerInitializationHook($validatedForeach);
        $literalSelections = new LiteralForeachSelectionReturnFilter($this->projectRoot);
        $registry->registerIssueFilterHook($literalSelections);
        $registry->registerInitializationHook($literalSelections);
        $registry->registerCodebaseScanHook($literalSelections);
        $arrayContracts = new StructuralArrayContractFilter;
        $registry->registerIssueFilterHook($arrayContracts);
        $registry->registerInitializationHook($arrayContracts);
        $numericArguments = new NumericArgumentCompatibilityFilter;
        $registry->registerIssueFilterHook($numericArguments);
        $registry->registerInitializationHook($numericArguments);
        $numericReturns = new NumericReturnCompatibilityFilter;
        $registry->registerIssueFilterHook($numericReturns);
        $registry->registerInitializationHook($numericReturns);
        $stringableArguments = new WeakStringableArgumentFilter;
        $registry->registerIssueFilterHook($stringableArguments);
        $registry->registerInitializationHook($stringableArguments);
        $stringableReturns = new WeakStringableReturnFilter;
        $registry->registerIssueFilterHook($stringableReturns);
        $registry->registerInitializationHook($stringableReturns);
        $outputParameters = new OutputParameterInitializationFilter;
        $registry->registerIssueFilterHook($outputParameters);
        $registry->registerInitializationHook($outputParameters);
        $propertyLists = new EloquentPropertyListContractFilter($this->projectRoot);
        $registry->registerIssueFilterHook($propertyLists);
        $registry->registerInitializationHook($propertyLists);
        $collectionReturns = new CollectionReturnCompatibilityFilter($this->projectRoot);
        $registry->registerIssueFilterHook($collectionReturns);
        $registry->registerInitializationHook($collectionReturns);
        $parameterDocs = new PhpDocParameterCompatibilityFilter;
        $registry->registerIssueFilterHook($parameterDocs);
        $registry->registerInitializationHook($parameterDocs);
        $infiniteLoops = new InfiniteForReturnFilter;
        $registry->registerIssueFilterHook($infiniteLoops);
        $registry->registerInitializationHook($infiniteLoops);
        $abortConditions = new AbortConditionProvider($this->projectRoot);
        $registry->registerFunctionAssertionProvider($abortConditions);
        $registry->registerInitializationHook($abortConditions);
        $requestProperties = new RequestInputPropertyProvider($this->projectRoot);
        $registry->registerPropertyTypeProvider($requestProperties);
        $registry->registerInitializationHook($requestProperties);
        $registry->registerPropertyTypeProvider(new SimpleXmlPropertyProvider);
        $middlewareCycles = new MiddlewareGroupCyclesHook($this->projectRoot);
        if ($middlewareCycles->hasSources()) {
            $registry->registerNodeAnalysisHook($middlewareCycles);
            $registry->registerInitializationHook($middlewareCycles);
        }
        $middlewareReferences = new MiddlewareReferencesHook($this->projectRoot);
        $registry->registerMethodCallAnalysisHook($middlewareReferences);
        $registry->registerInitializationHook($middlewareReferences);
        $middlewareParameters = new MiddlewareParametersHook($this->projectRoot);
        $registry->registerMethodCallAnalysisHook($middlewareParameters);
        $registry->registerInitializationHook($middlewareParameters);
        $properties = new EloquentPropertyProvider($this->projectRoot);
        $registry->registerMethodCallAnalysisHook(new PipelineDispatchHook($this->projectRoot));
        $registry->registerMethodCallAnalysisHook(new PipelineArityHook($this->projectRoot));
        $registry->registerMethodCallAnalysisHook(new ContainerSelfAliasHook($this->projectRoot));
        $registry->registerMethodCallAnalysisHook(new ForceFillWriteContractHook);
        $registry->registerMethodCallAnalysisHook(new ForceFillFieldNamesHook($this->projectRoot));
        $queryColumns = new QueryColumnReferencesHook($this->projectRoot);
        $registry->registerMethodCallAnalysisHook($queryColumns);
        $registry->registerInitializationHook($queryColumns);
        $searchAttributes = new SearchAttributeReferencesHook($this->projectRoot);
        $registry->registerMethodCallAnalysisHook($searchAttributes);
        $registry->registerInitializationHook($searchAttributes);
        $relatedProjectionColumns = new RelatedProjectionColumnsHook($this->projectRoot);
        $registry->registerMethodCallAnalysisHook($relatedProjectionColumns);
        $registry->registerInitializationHook($relatedProjectionColumns);
        $relationMethods = new EloquentRelationMethodProvider($this->projectRoot);
        $registry->registerMethodReturnTypeProvider($relationMethods);
        $registry->registerInitializationHook($relationMethods);
        $registry->registerMethodReturnTypeProvider(new HigherOrderMapProvider);
        $registry->registerMethodReturnTypeProvider(new InternalContainerProvider);
        $registry->registerMethodReturnTypeProvider(new EloquentNestedWhereCallbackProvider);
        $chunkCallbacks = new EloquentChunkCallbackProvider($this->projectRoot);
        $registry->registerMethodReturnTypeProvider($chunkCallbacks);
        $registry->registerInitializationHook($chunkCallbacks);
        $queryAggregates = new QueryAggregateProvider($this->projectRoot);
        $registry->registerMethodReturnTypeProvider($queryAggregates);
        $registry->registerInitializationHook($queryAggregates);
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
        $injectionContracts = new ContextualInjectionContractsHook($this->projectRoot);
        if ($injectionContracts->enabled()) {
            $registry->registerNodeAnalysisHook($injectionContracts);
            $registry->registerInitializationHook($injectionContracts);
        }
        $controllerCalls = new ControllerCallContractsHook($this->projectRoot);
        if ($controllerCalls->enabled()) {
            $registry->registerNodeAnalysisHook($controllerCalls);
            $registry->registerInitializationHook($controllerCalls);
        }
        $bindingCandidates = new BindingCompatibilityCandidatesHook($this->projectRoot);
        if ($bindingCandidates->enabled()) {
            $registry->registerNodeAnalysisHook($bindingCandidates);
            $registry->registerInitializationHook($bindingCandidates);
        }
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
        $testoArrays = new TestoArrayAssertionProvider($this->projectRoot);
        $registry->registerMethodAssertionProvider($testoArrays);
        $registry->registerInitializationHook($testoArrays);
        $laratestoAssertions = new LaratestoAssertionProvider($this->projectRoot);
        $registry->registerMethodAssertionProvider($laratestoAssertions);
        $registry->registerInitializationHook($laratestoAssertions);
        $assertViewIdentities = new AssertViewIdentityReferencesHook($this->projectRoot);
        $registry->registerMethodCallAnalysisHook($assertViewIdentities);
        $registry->registerInitializationHook($assertViewIdentities);
        $configuration = new ConfigurationProvider($this->projectRoot);
        $registry->registerFunctionReturnTypeProvider($configuration);
        $registry->registerInitializationHook($configuration);
        $registry->registerBeforeAnalysisHook($configuration);
        $configurationFacade = new ConfigurationFacadeProvider($this->projectRoot, $configuration);
        $registry->registerMethodReturnTypeProvider($configurationFacade);
        $registry->registerFunctionReturnTypeProvider(new EnvironmentValueProvider($this->projectRoot));
        $configurationKeys = new ConfigurationKeyContractsHook($this->projectRoot);
        $registry->registerNodeAnalysisHook($configurationKeys);
        $registry->registerInitializationHook($configurationKeys);
        $configurationWriters = new ConfigurationArrayWriterTargetsHook($this->projectRoot);
        $registry->registerNodeAnalysisHook($configurationWriters);
        $registry->registerInitializationHook($configurationWriters);
        $configurationAttributes = new ConfigurationAttributeContractsHook($this->projectRoot);
        $registry->registerNodeAnalysisHook($configurationAttributes);
        $registry->registerInitializationHook($configurationAttributes);
        $serviceIds = new ServiceIdReferencesHook($this->projectRoot);
        $registry->registerNodeAnalysisHook($serviceIds);
        $registry->registerInitializationHook($serviceIds);
        $policyMethods = new PolicyMethodDeclarationsHook($this->projectRoot);
        $registry->registerNodeAnalysisHook($policyMethods);
        $registry->registerInitializationHook($policyMethods);
        $gateAbilities = new GateAbilityReferencesHook($this->projectRoot);
        $registry->registerMethodCallAnalysisHook($gateAbilities);
        $registry->registerInitializationHook($gateAbilities);
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
        $registry->registerMethodReturnTypeProvider(new ArtisanCommandProvider($this->projectRoot));
        $consoleOptions = new ConsoleOptionProvider($this->projectRoot);
        $registry->registerMethodReturnTypeProvider($consoleOptions);
        $registry->registerInitializationHook($consoleOptions);
        $containerContracts = new ContainerContractProvider($this->projectRoot);
        if ($containerContracts->hasTargets()) {
            $registry->registerMethodReturnTypeProvider($containerContracts);
            $registry->registerIssueFilterHook($containerContracts);
            $registry->registerInitializationHook($containerContracts);
        }
        $classAliases = new ClassAliasFilter($this->projectRoot);
        $registry->registerIssueFilterHook($classAliases);
        $registry->registerInitializationHook($classAliases);
        $registry->registerFunctionReturnTypeProvider(new JsonDecodeProvider);
        $classTraits = new ClassUsesReturnTypeProvider($this->projectRoot);
        $registry->registerFunctionReturnTypeProvider($classTraits);
        $registry->registerInitializationHook($classTraits);
        $registry->registerFunctionReturnTypeProvider(new ArrayReplaceProvider);
        $collectionHelper = new CollectionHelperProvider($this->projectRoot);
        $registry->registerFunctionReturnTypeProvider($collectionHelper);
        $registry->registerInitializationHook($collectionHelper);
        $filesystemFactories = new FilesystemFactoryProvider($this->projectRoot);
        $registry->registerMethodReturnTypeProvider($filesystemFactories);
        $registry->registerInitializationHook($filesystemFactories);
        $registry->registerMethodReturnTypeProvider(new TransactionProvider($this->projectRoot));
        $translations = new TranslationStringProvider($this->projectRoot);
        $translationQuality = new TranslationSourceQualityHook($this->projectRoot);
        $templateReferences = new TemplateReferencePolicyHook($this->projectRoot);
        if ($templateReferences->enabled()) {
            $registry->registerAfterAnalysisHook($templateReferences);
        }
        if ($translationQuality->enabled()) {
            $registry->registerAfterAnalysisHook($translationQuality);
        }
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
        $mailRender = new MailRenderReferencesHook($this->projectRoot);
        if ($mailRender->enabled()) {
            $registry->registerMethodCallAnalysisHook($mailRender);
            $registry->registerInitializationHook($mailRender);
        }
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
        $routeNameDuplicates = new RouteNameDuplicateCandidatesHook($this->projectRoot);
        $effectiveRouteNames = new EffectiveRouteNameConflictsHook($this->projectRoot);
        if ($effectiveRouteNames->enabled()) {
            $registry->registerAfterAnalysisHook($effectiveRouteNames);
        }
        if ($routeNameDuplicates->enabled()) {
            $registry->registerAfterAnalysisHook($routeNameDuplicates);
        }
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
        $newCollections = new EloquentNewCollectionProvider($this->projectRoot);
        $registry->registerMethodReturnTypeProvider($newCollections);
        $registry->registerInitializationHook($newCollections);
        $keys = new EloquentKeyProvider($this->projectRoot, $properties);
        $registry->registerMethodReturnTypeProvider($keys);
        $registry->registerInitializationHook($keys);
        $projections = new EloquentProjectionProvider($this->projectRoot, $properties);
        $registry->registerMethodReturnTypeProvider($projections);
        $registry->registerInitializationHook($projections);
        $registry->registerMethodReturnTypeProvider(new EloquentQueryProvider);
        $registry->registerMethodReturnTypeProvider(new QueryBuilderVariadicProvider($this->projectRoot));
        $registry->registerMethodReturnTypeProvider(new ModelLoadVariadicProvider($this->projectRoot));
        $validationParameters = new ValidationRuleParametersHook($this->projectRoot);
        $registry->registerMethodCallAnalysisHook($validationParameters);
        $registry->registerInitializationHook($validationParameters);
        $validated = new ValidatedInputProvider($this->projectRoot);
        $registry->registerMethodReturnTypeProvider($validated);
        $registry->registerInitializationHook($validated);
        $registry->registerMethodReturnTypeProvider(new RequestQueryAllProvider($this->projectRoot));
        $registry->registerMethodReturnTypeProvider(new RequestOnlyVariadicProvider($this->projectRoot));
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
        $iterableDocs = new IterablePropertyDocProvider($this->projectRoot);
        $registry->registerPropertyTypeProvider($iterableDocs);
        $registry->registerInitializationHook($iterableDocs);
        $registry->registerPropertyTypeProvider($properties);
        $registry->registerInitializationHook($properties);
        $registry->registerBeforeAnalysisHook($properties);
        $registry->enableProviderMemoization();
    }
}
