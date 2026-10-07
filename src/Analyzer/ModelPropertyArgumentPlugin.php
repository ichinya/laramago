<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\{Plugin,PluginDefinition,PluginRegistry};

/** Exact receiving-domain compatibility for current physical model properties. */
final class ModelPropertyArgumentPlugin implements Plugin
{
    public function __construct(private readonly string $root){}
    public function getDefinition():PluginDefinition
    {
        return new PluginDefinition('ichinya/laramago-model-property-arguments','Model property arguments',
            'Current explicit casts and selected primitive schema columns retain their source and native declaration contracts.');
    }
    public function register(PluginRegistry $registry):void
    {
        $registry->registerIssueFilterHook(new ModelPropertyArgumentFilter($this->root));
    }
}
