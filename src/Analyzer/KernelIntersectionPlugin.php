<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;

/** Guard-sensitive dispatch must retain the invocation's source span. */
final class KernelIntersectionPlugin implements Plugin
{
    public function __construct(private readonly string $root = '.') {}

    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition('ichinya/laramago-kernel-intersections', 'Laravel kernel intersections', 'Guarded HTTP and console kernel contracts.');
    }

    public function register(PluginRegistry $registry): void
    {
        $provider = new KernelIntersectionProvider($this->root);
        $registry->registerCodebaseScanHook($provider->calls);
        $registry->registerInitializationHook($provider);
        $registry->registerMethodReturnTypeProvider($provider);
    }
}
