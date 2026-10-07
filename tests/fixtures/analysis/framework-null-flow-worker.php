<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Tests;
use Ichinya\Laramago\Analyzer\GuardedNullFlowIssueFilter;
use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterDecision,IssueFilterHook,Plugin,PluginDefinition,PluginRegistry};
require $argv[1];
$candidate=$argv[6];
require_once $candidate.'/src/Analyzer/ConsoleOptionProvider.php';
require_once $candidate.'/src/Analyzer/StaticAnalysis/PhysicalSetOnlyPropertyRead.php';
require_once $candidate.'/src/Analyzer/StaticAnalysis/NullFlowNativeContracts.php';
require_once $candidate.'/src/Analyzer/GuardedNullFlowIssueFilter.php';
require_once __DIR__.'/framework-null-flow-controls.php';

/** A native observer records current DTOs and never changes an issue. */
final class FrameworkNullFlowObserver implements IssueFilterHook
{
    public function __construct(private readonly string $root,private readonly string $output,private readonly string $mode) {}
    public function getCodes():array { return (new GuardedNullFlowIssueFilter($this->root))->getCodes(); }
    public function filterIssue(IssueFilterContext $context):IssueFilterDecision {
        // Each evaluation owns its local proof across SDK calls, including a reentrant hook request.
        $filter=new GuardedNullFlowIssueFilter($this->root);$decision=$filter->filterIssue($context);$completed=$filter->observation();
        $event=['file'=>$context->file,'sourceSha256'=>hash('sha256',$context->contents),'completeSdkIssue'=>self::value($context->issue),'candidateDecision'=>$decision->name,'completedObservation'=>self::value($completed)];
        if($this->mode==='original-observe') { $event['actualHeaderReceivingDeclarations']=self::value(['headers'=>$context->codebase->getDeclaringProperty('Illuminate\\Http\\Request','$headers'),
            'header'=>$context->codebase->getDeclaringMethod('Illuminate\\Http\\Request','header'),'retrieveItem'=>$context->codebase->getDeclaringMethod('Illuminate\\Http\\Request','retrieveItem'),
            'HeaderBagGet'=>$context->codebase->getDeclaringMethod('Symfony\\Component\\HttpFoundation\\HeaderBag','get')]); }
        file_put_contents($this->output.'/'.$this->mode.'-issues.jsonl',json_encode($event,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n",FILE_APPEND|LOCK_EX);
        return IssueFilterDecision::Keep;
    }
    public static function value(mixed $value):mixed { if($value instanceof \UnitEnum) { return $value->name; }if(is_object($value)) { return self::value(get_object_vars($value)); }return is_array($value)?array_map(self::value(...),$value):$value; }
}
final class FrameworkNullFlowTestPlugin implements Plugin
{
    public function __construct(private readonly string $root,private readonly string $output,private readonly string $mode) {}
    public function getDefinition():PluginDefinition { return new PluginDefinition('fixture/framework-null-flow','Framework null flow probe','Current declaration compatibility without provider registrations.'); }
    public function register(PluginRegistry $registry):void {
        $registry->registerIssueFilterHook($this->mode==='guards'?new FrameworkNullFlowNativeControls($this->root,$this->output):new FrameworkNullFlowObserver($this->root,$this->output,$this->mode));
    }
}
$mode=$argv[3]??'native';$root=$argv[2];$output=$argv[4];
if(str_starts_with($mode,'full-')||str_starts_with($mode,'original-')) { require $argv[5]; }
else {
    $plugins=match($mode) { 'native'=>[],'compatibility'=>[new GuardedNullFlowIssueFilter($root)],default=>[new FrameworkNullFlowTestPlugin($root,$output,$mode)] };
    (new \Mago\Sdk\Worker(new \Mago\Sdk\Extension('fixture/framework-null-flow','Framework null flow','1',analyzerPlugins:$plugins)))->run();
}
