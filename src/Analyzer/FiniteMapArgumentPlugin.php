<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\FiniteMapArgumentProofs;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;

/** Share analyzed finite-map input certificates with their native issue filter. */
final class FiniteMapArgumentPlugin implements Plugin
{
    public function __construct(private readonly string $root = '.') {}
    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition('ichinya/laramago-finite-map-arguments', 'Finite map arguments',
            'Preserve fresh literal mapper inputs at an explicitly typed constructor boundary.');
    }
    public function register(PluginRegistry $registry): void
    {
        $proofs = new FiniteMapArgumentProofs($this->root);
        $registry->registerInitializationHook($proofs);
        $registry->registerCodebaseScanHook($proofs);
        $registry->registerIssueFilterHook(new FiniteMapArgumentIssueFilter($proofs));
    }
}
