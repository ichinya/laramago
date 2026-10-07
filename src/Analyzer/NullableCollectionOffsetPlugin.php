<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;
use Mago\Sdk\Analyzer\{Plugin,PluginDefinition,PluginRegistry};
final class NullableCollectionOffsetPlugin implements Plugin {
    public function __construct(private readonly string $root='.') {}
    public function getDefinition():PluginDefinition { return new PluginDefinition('ichinya/nullable-collection-offset','Declared collection offset contracts','Current source and native declarations bind exact compatibility issues.'); }
    public function register(PluginRegistry $registry):void { $registry->registerIssueFilterHook(new NullableCollectionOffsetIssueFilter($this->root)); }
}
