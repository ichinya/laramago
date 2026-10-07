<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ArgvNormalizationAdvisories;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;

/** Register the source-bound defensive argv normalization advisory policy. */
final class ArgvNormalizationAdvisoryPlugin implements Plugin
{
    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition('ichinya/laramago-argv-normalization-advisory', 'Argv normalization advisory', 'Preserve source-proven defensive normalization of argv without changing native types.');
    }

    public function register(PluginRegistry $registry): void
    {
        $advisories = new ArgvNormalizationAdvisories();
        $registry->registerInitializationHook($advisories);
        $registry->registerCodebaseScanHook($advisories);
        $registry->registerIssueFilterHook(new ArgvNormalizationAdvisoryIssueFilter($advisories));
    }
}
