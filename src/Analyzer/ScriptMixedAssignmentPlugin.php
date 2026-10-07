<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;

/** Permit untyped script storage without claiming a type for an included file. */
final class ScriptMixedAssignmentPlugin implements Plugin
{
    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition(
            'ichinya/laramago-script-include-storage',
            'Script include storage policy',
            'Permit a direct untyped script binding while preserving the mixed include result and unsafe-use errors.',
        );
    }

    public function register(PluginRegistry $registry): void
    {
        $filter = new ScriptMixedAssignmentFilter;
        $registry->registerInitializationHook($filter);
        $registry->registerIssueFilterHook($filter);
    }
}
