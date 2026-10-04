<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ReconstructedArrayShapes;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;

/** Preserve the source proof for freshly reconstructed primitive array shapes. */
final class ReconstructedArrayShapePlugin implements Plugin
{
    public function __construct(private readonly string $root = '.') {}

    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition(
            'ichinya/laramago-reconstructed-array-shapes',
            'Reconstructed array shapes',
            'Verify dependent primitive fields passed to their declaring constructor.',
        );
    }

    public function register(PluginRegistry $registry): void
    {
        $shapes = new ReconstructedArrayShapes($this->root);
        $registry->registerInitializationHook($shapes);
        $registry->registerCodebaseScanHook($shapes);
        $registry->registerIssueFilterHook(new ReconstructedArrayShapeIssueFilter($shapes));
    }
}
