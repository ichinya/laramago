<?php

declare (strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\{Plugin, PluginDefinition, PluginRegistry};
/** Bounded remembered getter and captured-this method_exists facts. */
final class GuardedMethodCompatibilityPlugin implements Plugin
{
    public function __construct(private readonly string $root)
    {
    }
    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition('ichinya/larastan-guarded-methods', 'Guarded method compatibility', 'Source-bound getter and captured-this facts from the current native metadata.');
    }
    public function register(PluginRegistry $registry): void
    {
        $getter = new RememberedGuardedGetterProvider($this->root);
        $registry->registerInitializationHook($getter);
        $registry->registerCodebaseScanHook(new RememberedGuardedGetterScan($getter));
        $registry->registerMethodReturnTypeProvider($getter);
        $registry->registerIssueFilterHook(new CapturedThisMethodExistsFilter($this->root));
    }
}
