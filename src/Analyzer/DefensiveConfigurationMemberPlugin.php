<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;

final class DefensiveConfigurationMemberPlugin implements Plugin
{
    private readonly DefensiveBoundaryGuardProof $proofs;

    public function __construct(string $root)
    {
        $this->proofs = new DefensiveBoundaryGuardProof($root);
    }

    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition('ichinya/defensive-configuration-members', 'Defensive configuration members',
            'Source-bound optional member validation preserves native value types and unrelated diagnostics.');
    }

    public function register(PluginRegistry $registry): void
    {
        $registry->registerInitializationHook($this->proofs);
        $registry->registerCodebaseScanHook($this->proofs);
        $registry->registerIssueFilterHook(new DefensiveConfigurationMemberFilter($this->proofs));
    }
}
