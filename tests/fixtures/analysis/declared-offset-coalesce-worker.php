<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Tests;
use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterDecision,IssueFilterHook,Plugin,PluginDefinition,PluginRegistry};
require $argv[1];
$candidate=$argv[6];$family=$argv[7];
foreach($family==='coalesce'?['CoalesceModelPropertyIssueFilter','CoalesceModelPropertyPlugin']:['ScopedAccumulatorBorrow','NullableCollectionOffsetContract','NullableCollectionOffsetIssueFilter','NullableCollectionOffsetPlugin'] as $name) { require_once $candidate.'/src/Analyzer/'.$name.'.php'; }
require_once __DIR__.($family==='coalesce'?'/coalesce-model-property-controls.php':'/nullable-collection-offset-controls.php');

/** Each genuine issue evaluation owns its source/native proof across RPC reentry. */
final class DeclaredOffsetCoalesceObserver implements IssueFilterHook
{
    public function __construct(private readonly string $root,private readonly string $output,private readonly string $family,private readonly string $mode) {}
    public function getCodes():array { return [$this->family==='coalesce'?'non-documented-property':'invalid-array-index']; }
    public function filterIssue(IssueFilterContext $context):IssueFilterDecision
    {
        $filter=$this->family==='coalesce'?new \Ichinya\Laramago\Analyzer\CoalesceModelPropertyIssueFilter($this->root):new \Ichinya\Laramago\Analyzer\NullableCollectionOffsetIssueFilter($this->root);
        $decision=$filter->filterIssue($context);$stages=$this->family==='coalesce'?$filter->stages:$filter->contract->stages;
        $event=['file'=>$context->file,'sourceSha256'=>hash('sha256',$context->contents),'candidateDecision'=>$decision->name,'completeSdkIssue'=>self::value($context->issue),'completedStages'=>self::value($stages)];
        file_put_contents($this->output.'/'.$this->mode.'-issues.jsonl',json_encode($event,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n",FILE_APPEND|LOCK_EX);
        return IssueFilterDecision::Keep;
    }
    public static function value(mixed $value):mixed { if($value instanceof \UnitEnum) { return $value->name; }if(is_object($value)) { return self::value(get_object_vars($value)); }return is_array($value)?array_map(self::value(...),$value):$value; }
}
final class DeclaredOffsetCoalesceTestPlugin implements Plugin
{
    public function __construct(private readonly string $root,private readonly string $output,private readonly string $family,private readonly string $mode) {}
    public function getDefinition():PluginDefinition { return new PluginDefinition('fixture/declared-offset-coalesce','Declared offset and coalesce test','Genuine native declaration observation; no provider types manufactured.'); }
    public function register(PluginRegistry $registry):void { $registry->registerIssueFilterHook(new DeclaredOffsetCoalesceObserver($this->root,$this->output,$this->family,$this->mode)); }
}
$root=$argv[2];$mode=$argv[3];$output=$argv[4];
if(str_starts_with($mode,'full-')) { require $argv[5]; }
else {
    $plugins=match($mode) {
        'native'=>[],
        'guards'=>$family==='coalesce'?[new \Ichinya\Laramago\Analyzer\CoalesceModelPropertyNativeControls($root)]:[new \Ichinya\Laramago\Analyzer\NullableCollectionOffsetNativeControls($root)],
        'compatibility'=>$family==='coalesce'?[new \Ichinya\Laramago\Analyzer\CoalesceModelPropertyPlugin($root)]:[new \Ichinya\Laramago\Analyzer\NullableCollectionOffsetPlugin($root)],
        default=>[new DeclaredOffsetCoalesceTestPlugin($root,$output,$family,$mode)],
    };
    (new \Mago\Sdk\Worker(new \Mago\Sdk\Extension('fixture/declared-offset-coalesce','Declared offset and coalesce test','1',analyzerPlugins:$plugins)))->run();
}
