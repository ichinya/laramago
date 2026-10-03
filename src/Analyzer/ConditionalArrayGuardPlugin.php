<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ConditionalArrayGuards;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;

/** Keep source and branch identity visible for conditional native array facts. */
final class ConditionalArrayGuardPlugin implements Plugin
{
    public function __construct(private readonly string $root = '.') {}

    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition(
            'ichinya/laramago-conditional-array-guards',
            'Conditional array guards',
            'Preserve proven native array fields in optional-key branches.',
        );
    }

    public function register(PluginRegistry $registry): void
    {
        $guards = new ConditionalArrayGuards($this->root);
        $registry->registerInitializationHook($guards);
        $registry->registerCodebaseScanHook($guards);
        $registry->registerIssueFilterHook(new ConditionalArrayGuardIssueFilter($guards));
    }
}
