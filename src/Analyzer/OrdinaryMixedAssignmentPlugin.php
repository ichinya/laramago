<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;

/** PHPStan-compatible advisory policy for unannotated ordinary local mixed bindings. */
final class OrdinaryMixedAssignmentPlugin implements Plugin
{
    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition('ichinya/laramago-ordinary-mixed-assignment', 'Ordinary mixed assignment advisory', 'Preserve native types and unsafe-use diagnostics while accepting source-proven ordinary local mixed storage.');
    }

    public function register(PluginRegistry $registry): void
    {
        $filter = new OrdinaryMixedAssignmentFilter();
        $registry->registerInitializationHook($filter);
        $registry->registerIssueFilterHook($filter);
    }
}
