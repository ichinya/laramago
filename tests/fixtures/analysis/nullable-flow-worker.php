<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Tests;
use Ichinya\Laramago\Analyzer\GuardedNullFlowIssueFilter;
use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterDecision,IssueFilterHook,Plugin,PluginDefinition,PluginRegistry};
require $argv[1];
require $argv[5].'/src/Analyzer/StaticAnalysis/SourceNullFacts.php';
require $argv[5].'/src/Analyzer/StaticAnalysis/NullFlowNativeContracts.php';
require $argv[5].'/src/Analyzer/GuardedNullFlowIssueFilter.php';
require __DIR__.'/nullable-flow-controls.php';

/** Actual native issues only. No invocation, positive DTO or provider return is manufactured. */
final class NullFlowTestObserver implements IssueFilterHook
{
    public function __construct(private readonly GuardedNullFlowIssueFilter $filter,private readonly string $root,private readonly string $mode) {}
    public function getCodes():array { return $this->filter->getCodes(); }
    public function filterIssue(IssueFilterContext $context):IssueFilterDecision {
        $decision=$this->filter->filterIssue($context);$completed=$this->filter->observation();
        file_put_contents($this->root.'/'.$this->mode.'-issues.jsonl',json_encode(['file'=>$context->file,'sourceSha256'=>hash('sha256',$context->contents),
            'completeSdkIssue'=>self::value($context->issue),'candidateDecision'=>$decision->name,'completedObservation'=>self::english(self::value($completed))],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n",FILE_APPEND|LOCK_EX);
        return IssueFilterDecision::Keep;
    }
    public static function value(mixed $value):mixed { if($value instanceof \UnitEnum) { return $value->name; }if(is_object($value)) { return self::value(get_object_vars($value)); }return is_array($value)?array_map(self::value(...),$value):$value; }
    public static function english(mixed $value):mixed { if(is_string($value)) { return preg_replace('/[^\x00-\x7F]+/u','[non-ASCII source text]',$value); }return is_array($value)?array_map(self::english(...),$value):$value; }
}
final class NullFlowTestPlugin implements Plugin
{
    public function __construct(private readonly string $root,private readonly string $mode,private readonly ?string $outputRoot=null) {}
    public function getDefinition():PluginDefinition { return new PluginDefinition('fixture/guarded-null-flow','Guarded null-flow fixtures','Provider-free native source/envelope controls.'); }
    public function register(PluginRegistry $registry):void {
        $filter=new GuardedNullFlowIssueFilter($this->root);$registry->registerInitializationHook($filter);
        $registry->registerIssueFilterHook($this->mode==='guards'?new NullFlowNativeControls($filter,$this->root,$this->outputRoot??$this->root):new NullFlowTestObserver($filter,$this->outputRoot??$this->root,$this->mode));
    }
}
$mode=$argv[3]??'native';
if(str_starts_with($mode,'full-')||str_starts_with($mode,'original-')) { require $argv[6]; }
else {
    $plugins=match($mode) { 'native'=>[],'compatibility'=>[new GuardedNullFlowIssueFilter($argv[2])],default=>[new NullFlowTestPlugin($argv[2],$mode)] };
    (new \Mago\Sdk\Worker(new \Mago\Sdk\Extension(identifier:'fixture/guarded-null-flow',name:'Guarded null flow fixture',version:'1',analyzerPlugins:$plugins)))->run();
}
