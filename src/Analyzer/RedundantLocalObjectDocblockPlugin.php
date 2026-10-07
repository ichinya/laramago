<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;

/** Retain useful explicit documentation when native Mago proves it duplicates an object type. */
final class RedundantLocalObjectDocblockPlugin implements Plugin
{
    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition('ichinya/laramago-local-object-docblocks', 'Local object documentation policy',
            'Permit a certified duplicate object annotation while preserving its native type and every error.');
    }

    public function register(PluginRegistry $registry): void
    {
        $filter = new RedundantLocalObjectDocblockFilter;
        $registry->registerInitializationHook($filter);
        $registry->registerIssueFilterHook($filter);
    }
}
