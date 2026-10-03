<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\SimpleXmlProvenance;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;

/** Span provenance must remain visible; this plugin does not memoize providers. */
final class SimpleXmlProvenancePlugin implements Plugin
{
    public function __construct(private readonly string $root = '') {}

    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition('ichinya/laramago-simplexml-provenance', 'Native XML provenance', 'Preserve proven native XML child domains through cardinality checks.');
    }

    public function register(PluginRegistry $registry): void
    {
        $provenance = new SimpleXmlProvenance($this->root);
        $registry->registerInitializationHook($provenance);
        $registry->registerCodebaseScanHook($provenance);
        $registry->registerPropertyTypeProvider(new SimpleXmlProvenancePropertyProvider($provenance));
        $registry->registerIssueFilterHook(new SimpleXmlProvenanceIssueFilter($provenance));
    }
}
