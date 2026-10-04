<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\RefreshedModelProperties;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;

/** Keep independently verified refresh effects and virtual read contracts together. */
final class RefreshedModelPropertyPlugin implements Plugin
{
    public function __construct(private readonly string $root = '.') {}

    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition(
            'ichinya/laramago-refreshed-model-properties',
            'Refreshed model properties',
            'Correct stale virtual attribute comparisons after a verified native refresh.',
        );
    }

    public function register(PluginRegistry $registry): void
    {
        $properties = new RefreshedModelProperties($this->root);
        $registry->registerInitializationHook($properties);
        $registry->registerCodebaseScanHook($properties);
        $registry->registerIssueFilterHook(new RefreshedModelPropertyIssueFilter($properties));
    }
}
