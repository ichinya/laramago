<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\DefensiveArrayGuardProofs;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;

/** Permit a certified defensive idiom without changing its correct native type. */
final class DefensiveArrayGuardPlugin implements Plugin
{
    public function __construct(private readonly string $root = '.') {}

    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition(
            'ichinya/laramago-defensive-array-guards',
            'Defensive array guard policy',
            'Permit a source-certified protective array guard while retaining native types and errors.',
        );
    }

    public function register(PluginRegistry $registry): void
    {
        $proofs = new DefensiveArrayGuardProofs($this->root);
        $registry->registerInitializationHook($proofs);
        $registry->registerCodebaseScanHook($proofs);
        $registry->registerIssueFilterHook(new DefensiveArrayGuardIssueFilter($proofs));
    }
}
