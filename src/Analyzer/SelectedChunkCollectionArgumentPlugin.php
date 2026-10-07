<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;
use Mago\Sdk\Analyzer\{Plugin,PluginDefinition,PluginRegistry};
final class SelectedChunkCollectionArgumentPlugin implements Plugin
{
    public function __construct(private readonly string $root='.') {}
    public function getDefinition():PluginDefinition { return new PluginDefinition('ichinya/laramago-selected-chunk-collection','Selected chunk collection arguments','Native receiving contracts for an unchanged typed literal Eloquent chunk input.'); }
    public function register(PluginRegistry $registry):void { $registry->registerIssueFilterHook(new SelectedChunkCollectionArgumentFilter($this->root)); }
}
