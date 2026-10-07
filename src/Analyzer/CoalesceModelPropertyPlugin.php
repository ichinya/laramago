<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;
use Mago\Sdk\Analyzer\{Plugin,PluginDefinition,PluginRegistry};
final class CoalesceModelPropertyPlugin implements Plugin {
    public function __construct(private readonly string $root='.') {}
    public function getDefinition():PluginDefinition { return new PluginDefinition('ichinya/coalesce-model-property','Direct coalesce property probes','Current source and native declarations bind exact compatibility issues.'); }
    public function register(PluginRegistry $registry):void { $registry->registerIssueFilterHook(new CoalesceModelPropertyIssueFilter($this->root)); }
}
