<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\LaravelPlugin;
use Ichinya\Laramago\Analyzer\AggregateProjectionPlugin;
use Ichinya\Laramago\Analyzer\ArrayAssertionPlugin;
use Ichinya\Laramago\Analyzer\ArgvNormalizationAdvisoryPlugin;
use Ichinya\Laramago\Analyzer\AssertedPureGetterPlugin;
use Ichinya\Laramago\Analyzer\ConditionalArrayGuardPlugin;
use Ichinya\Laramago\Analyzer\ContextualCollectionMemberPlugin;
use Ichinya\Laramago\Analyzer\DefensiveArrayGuardPlugin;
use Ichinya\Laramago\Analyzer\DirectCallbackReferencePlugin;
use Ichinya\Laramago\Analyzer\FactoryModelDeclarationPlugin;
use Ichinya\Laramago\Analyzer\FalseOperandCompatibilityPlugin;
use Ichinya\Laramago\Analyzer\FiniteMapArgumentPlugin;
use Ichinya\Laramago\Analyzer\ForeachObjectDocblockPlugin;
use Ichinya\Laramago\Analyzer\IntegerValidationPlugin;
use Ichinya\Laramago\Analyzer\KernelIntersectionPlugin;
use Ichinya\Laramago\Analyzer\ModelRefreshPlugin;
use Ichinya\Laramago\Analyzer\NonEmptyCollectionPlugin;
use Ichinya\Laramago\Analyzer\OrdinaryMixedAssignmentPlugin;
use Ichinya\Laramago\Analyzer\ReconstructedArrayShapePlugin;
use Ichinya\Laramago\Analyzer\RefreshedModelPropertyPlugin;
use Ichinya\Laramago\Analyzer\RedundantLocalObjectDocblockPlugin;
use Ichinya\Laramago\Analyzer\ScriptMixedAssignmentPlugin;
use Ichinya\Laramago\Analyzer\SimpleXmlProvenancePlugin;
use Ichinya\Laramago\Analyzer\ValidatedYieldedListPlugin;
use Ichinya\Laramago\Analyzer\WeakNumericFloatCompatibilityPlugin;
use Mago\Sdk\Extension;
use Mago\Sdk\Worker;

$autoload = $argv[1] ?? null;
if ($autoload === null || ! is_file($autoload)) {
    fwrite(STDERR, "Usage: php laramago-worker.php <vendor/autoload.php> [project-root]\n");
    exit(2);
}

require $autoload;

// Long analysis runs accumulate parsed syntax beyond PHP's conservative CLI
// default; raise the worker's own ceiling unless the operator already chose a
// larger (or unlimited) one.
$limit = ini_get('memory_limit');
if (is_string($limit) && $limit !== '-1') {
    $unit = strtolower(substr($limit, -1));
    $bytes = (int) $limit;
    if (in_array($unit, ['k', 'm', 'g'], true)) {
        $bytes *= ['k' => 1024, 'm' => 1048576, 'g' => 1073741824][$unit];
    }
    if ($bytes < 512 * 1048576) {
        ini_set('memory_limit', '512M');
    }
}

$projectRoot = $argv[2] ?? Composer\InstalledVersions::getRootPackage()['install_path'] ?? getcwd();
if (! is_string($projectRoot) || ! is_dir($projectRoot)) {
    fwrite(STDERR, "Cannot locate the application root for static model metadata.\n");
    exit(2);
}

(new Worker(new Extension(
    identifier: 'ichinya/laramago',
    name: 'Laramago',
    version: '0.0.26',
    analyzerPlugins: [
        new AggregateProjectionPlugin($projectRoot),
        new ArrayAssertionPlugin($projectRoot),
        new ArgvNormalizationAdvisoryPlugin,
        new AssertedPureGetterPlugin($projectRoot),
        new ContextualCollectionMemberPlugin($projectRoot),
        new DefensiveArrayGuardPlugin($projectRoot),
        new DirectCallbackReferencePlugin($projectRoot),
        new FactoryModelDeclarationPlugin($projectRoot),
        new FalseOperandCompatibilityPlugin,
        new \Ichinya\Laramago\Analyzer\PossibleCallbackTupleCompatibilityPlugin($projectRoot),
        new \Ichinya\Laramago\Analyzer\GuardedRequestHeaderArgumentPlugin($projectRoot),
        new \Ichinya\Laramago\Analyzer\SessionArrayKeyArgumentPlugin($projectRoot),
        new \Ichinya\Laramago\Analyzer\SelectedChunkCollectionArgumentPlugin($projectRoot),
        new \Ichinya\Laramago\Analyzer\SelectedChunkModelWarningPlugin($projectRoot),
        new \Ichinya\Laramago\Analyzer\CapturedStateGuardPlugin($projectRoot),
        new \Ichinya\Laramago\Analyzer\DefensiveResponseGuardPlugin($projectRoot),
        new \Ichinya\Laramago\Analyzer\CoalesceModelPropertyPlugin($projectRoot),
        new \Ichinya\Laramago\Analyzer\NullableCollectionOffsetPlugin($projectRoot),
        new \Ichinya\Laramago\Analyzer\CollectionOffsetGuardPlugin($projectRoot),
        new \Ichinya\Laramago\Analyzer\RepeatedRefreshNoValuePlugin($projectRoot),
        new \Ichinya\Laramago\Analyzer\ClosedConfigurationArgumentPlugin($projectRoot),
        new \Ichinya\Laramago\Analyzer\DefaultDateArgumentPlugin($projectRoot),
        new \Ichinya\Laramago\Analyzer\ObjectBooleanCompatibilityPlugin,
        new \Ichinya\Laramago\Analyzer\SourceArgumentContractPlugin($projectRoot),
        new \Ichinya\Laramago\Analyzer\GuardedNullFlowIssueFilter($projectRoot),
        new \Ichinya\Laramago\Analyzer\ArgumentClosurePossibleWritePlugin($projectRoot),
        new \Ichinya\Laramago\Analyzer\GuardedMethodCompatibilityPlugin($projectRoot),
        new \Ichinya\Laramago\Analyzer\DeprecatedMethodCompatibilityPlugin($projectRoot),
        new \Ichinya\Laramago\Analyzer\DefensiveBoundaryGuardPlugin($projectRoot),
        new \Ichinya\Laramago\Analyzer\RenamedParameterAdvisoryFilter($projectRoot),
        new \Ichinya\Laramago\Analyzer\GuardedStringCastCompatibilityPlugin($projectRoot),
        new FiniteMapArgumentPlugin($projectRoot),
        new ConditionalArrayGuardPlugin($projectRoot),
        new ReconstructedArrayShapePlugin($projectRoot),
        new IntegerValidationPlugin,
        new KernelIntersectionPlugin($projectRoot),
        new ModelRefreshPlugin($projectRoot),
        new RefreshedModelPropertyPlugin($projectRoot),
        new NonEmptyCollectionPlugin($projectRoot),
        new SimpleXmlProvenancePlugin($projectRoot),
        new ScriptMixedAssignmentPlugin,
        new OrdinaryMixedAssignmentPlugin,
        new RedundantLocalObjectDocblockPlugin,
        new ForeachObjectDocblockPlugin,
        new \Ichinya\Laramago\Analyzer\ValidatedArrayContractPlugin($projectRoot),
        new ValidatedYieldedListPlugin($projectRoot),
        new WeakNumericFloatCompatibilityPlugin($projectRoot),
        new \Ichinya\Laramago\Analyzer\ForwardedTransactionTemplatePlugin($projectRoot),
        new \Ichinya\Laramago\Analyzer\StoredReferencePossibleWritePlugin($projectRoot),
        new \Ichinya\Laramago\Analyzer\DeclaredValuePostconditionPlugin($projectRoot),
        new \Ichinya\Laramago\Analyzer\CapturedArrayPostconditionPlugin($projectRoot),
        new \Ichinya\Laramago\Analyzer\DefensiveConfigurationMemberPlugin($projectRoot),
        new \Ichinya\Laramago\Analyzer\XmlCardinalityGuardPlugin($projectRoot),
        new LaravelPlugin($projectRoot),
        new \Ichinya\Laramago\Analyzer\GuardedLocalModelTuplePlugin($projectRoot),
        new \Ichinya\Laramago\Analyzer\FactoryFakerPlugin($projectRoot),
        new \Ichinya\Laramago\Analyzer\ModelPropertyArgumentPlugin($projectRoot),
    ],
)))->run();
