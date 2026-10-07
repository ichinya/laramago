<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ValidatedYieldedLists;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;

/** Certify exhaustive string-list validation before a yielded record copy. */
final class ValidatedYieldedListPlugin implements Plugin
{
    public function __construct(private readonly string $root = '.') {}

    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition('ichinya/laramago-validated-yielded-lists', 'Validated yielded lists',
            'Verify an unchanged list copied into a yielded record after exhaustive native string checks.');
    }

    public function register(PluginRegistry $registry): void
    {
        $proofs = new ValidatedYieldedLists($this->root);
        $registry->registerInitializationHook($proofs);
        $registry->registerCodebaseScanHook($proofs);
        $registry->registerIssueFilterHook(new ValidatedYieldedListIssueFilter($proofs));
    }
}
