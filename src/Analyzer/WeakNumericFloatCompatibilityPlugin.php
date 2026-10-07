<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;

/** Register source-certified native weak float compatibility. */
final class WeakNumericFloatCompatibilityPlugin implements Plugin
{
    public function __construct(private readonly string $root = '.') {}
    public function getDefinition(): PluginDefinition { return new PluginDefinition('ichinya/laramago-weak-numeric-float', 'Native weak float compatibility', 'Respect source-certified weak PHP numeric-string conversion at native float callable boundaries.'); }
    public function register(PluginRegistry $registry): void
    {
        $filter = new WeakNumericFloatCompatibilityFilter($this->root);
        $registry->registerInitializationHook($filter);
        $registry->registerIssueFilterHook($filter);
    }
}
