<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;
use Mago\Sdk\Analyzer\{Plugin,PluginDefinition,PluginRegistry};
final class GuardedLocalModelTuplePlugin implements Plugin
{
    public function __construct(private readonly string $root='.'){}
    public function getDefinition():PluginDefinition{return new PluginDefinition('ichinya/laramago-guarded-local-model-tuple','Guarded local model tuple','A current literal model query and null guard retain the model domain through a closed possible-write tuple.');}
    public function register(PluginRegistry $registry):void{$registry->registerIssueFilterHook(new GuardedLocalModelTupleArgumentFilter($this->root));}
}
