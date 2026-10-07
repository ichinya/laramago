<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;
use Mago\Sdk\Analyzer\{Plugin,PluginDefinition,PluginRegistry};
final class ObjectBooleanCompatibilityPlugin implements Plugin
{
    public function getDefinition():PluginDefinition { return new PluginDefinition('ichinya/object-boolean-compatibility','Object boolean compatibility','Source-bound object boolean advisories without changing native types.'); }
    public function register(PluginRegistry $registry):void {
        $filter=new ObjectBooleanCompatibilityFilter;
        $registry->registerInitializationHook($filter);$registry->registerIssueFilterHook($filter);
    }
}