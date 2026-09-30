<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;

/** These source-dependent assertions need the original, nonmemoized invocation spans. */
final class IntegerValidationPlugin implements Plugin
{
    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition('ichinya/laramago-integer-validation', 'Integer validation', 'Literal integer filter ranges after successful guards.');
    }

    public function register(PluginRegistry $registry): void
    {
        $provider = new ValidatedIntegerAssertionProvider;
        $registry->registerCodebaseScanHook($provider->calls);
        $registry->registerFunctionAssertionProvider($provider);
    }
}
