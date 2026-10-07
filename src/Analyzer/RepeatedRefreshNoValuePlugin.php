<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\RefreshedModelProperties;
use Mago\Sdk\Analyzer\{Plugin, PluginDefinition, PluginRegistry};

final class RepeatedRefreshNoValuePlugin implements Plugin
{
    public function __construct(private readonly string $root = '.') {}

    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition('ichinya/laramago-repeated-refresh-values', 'Repeated refreshed model values',
            'Restore one source-bound model attribute domain after a second native refresh.');
    }

    public function register(PluginRegistry $registry): void
    {
        $provenance = new RefreshedModelProperties($this->root);
        $registry->registerInitializationHook($provenance);
        $registry->registerCodebaseScanHook($provenance);
        $registry->registerIssueFilterHook(new RepeatedRefreshNoValueFilter($provenance, $this->root));
    }
}
