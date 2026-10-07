<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;

/** Register bounded native false operand compatibility without any type provider. */
final class FalseOperandCompatibilityPlugin implements Plugin
{
    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition('ichinya/laramago-false-operands', 'Native false operand compatibility',
            'Respect native PHP false comparison and string conversion at source-certified primitive operand sites.');
    }

    public function register(PluginRegistry $registry): void
    {
        $filter = new FalseOperandCompatibilityFilter;
        $registry->registerInitializationHook($filter);
        $registry->registerIssueFilterHook($filter);
    }
}
