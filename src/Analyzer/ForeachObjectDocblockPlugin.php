<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;

/** Advisory policy for an explicitly documented, already typed foreach object. */
final class ForeachObjectDocblockPlugin implements Plugin
{
    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition('ichinya/laramago-foreach-object-docblocks', 'Foreach object documentation policy',
            'Permit a source-certified duplicate object tag while preserving its native type and every error.');
    }

    public function register(PluginRegistry $registry): void
    {
        $filter = new ForeachObjectDocblockFilter;
        $registry->registerInitializationHook($filter);
        $registry->registerIssueFilterHook($filter);
    }
}
