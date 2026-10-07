<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;

/** Site-specific facts cannot share memoized results with unguarded getter calls. */
final class AssertedPureGetterPlugin implements Plugin
{
    public function __construct(private readonly string $root = '.') {}

    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition('ichinya/laramago-asserted-pure-getter', 'Asserted pure getter',
            'Preserve a proven scalar field value at an immediate repeated getter call.');
    }

    public function register(PluginRegistry $registry): void
    {
        $provider = new AssertedPureGetterReturnProvider($this->root);
        $registry->registerInitializationHook($provider);
        $registry->registerCodebaseScanHook($provider->calls);
        $registry->registerMethodReturnTypeProvider($provider);
    }
}
