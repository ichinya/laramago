<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\DirectCallbackReferenceEffects;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;

/** Keep reference-effect certificates tied to their analyzed source generation. */
final class DirectCallbackReferencePlugin implements Plugin
{
    public function __construct(private readonly string $root = '.') {}

    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition(
            'ichinya/laramago-direct-callback-references',
            'Direct callback reference effects',
            'Correct stale boolean comparisons after source-proven callback reference writes.',
        );
    }

    public function register(PluginRegistry $registry): void
    {
        $effects = new DirectCallbackReferenceEffects($this->root);
        $registry->registerInitializationHook($effects);
        $registry->registerCodebaseScanHook($effects);
        $registry->registerIssueFilterHook(new DirectCallbackReferenceIssueFilter($effects));
    }
}
