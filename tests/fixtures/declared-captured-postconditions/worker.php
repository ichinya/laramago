<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Tests;
ini_set('display_errors','stderr');ini_set('log_errors','1');
$workerErrorFile=$argv[4].'/sdk-worker-'.getmypid().'.error.log';ini_set('error_log',$workerErrorFile);$fatalReserve=str_repeat(' ',65536);
register_shutdown_function(static function()use(&$fatalReserve,$workerErrorFile):void {
    $fatalReserve=null;$error=error_get_last();
    if($error!==null&&in_array($error['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR],true)) {
        file_put_contents($workerErrorFile,json_encode(['fatal'=>true,'pid'=>getmypid(),'type'=>$error['type'],'message'=>substr((string)$error['message'],0,4096),
            'file'=>basename((string)$error['file']),'line'=>$error['line']],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
    }
});
use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterDecision,IssueFilterHook,Plugin,PluginDefinition,PluginRegistry};
require $argv[1];require_once __DIR__.'/controls.php';
final class DeclaredCapturedWarningObserver implements IssueFilterHook
{
    public function __construct(private readonly string $root,private readonly string $output,private readonly string $mode) {}
    public function getCodes():array { return ['impossible-null-type-comparison','possibly-null-array-index','possibly-undefined-int-array-index']; }
    public function filterIssue(IssueFilterContext $context):IssueFilterDecision
    {
        $candidate=$context->issue->code==='impossible-null-type-comparison'
            ?new \Ichinya\Laramago\Analyzer\AssertedDeclaredValueWarningFilter($this->root):new \Ichinya\Laramago\Analyzer\CapturedArrayWarningFilter($this->root);
        $evaluation=$candidate->evaluate($context);
        $event=['file'=>$context->file,'sourceSha256'=>hash('sha256',$context->contents),'candidateDecision'=>$evaluation['decision']->name,
            'completeSdkIssue'=>self::value($context->issue),'completedLocalEvaluation'=>self::value($evaluation)];
        file_put_contents($this->output.'/'.$this->mode.'-issues.jsonl',json_encode($event,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n",FILE_APPEND|LOCK_EX);
        return IssueFilterDecision::Keep;
    }
    public static function value(mixed $value):mixed { if($value instanceof \UnitEnum) { return $value->name; }if(is_object($value)) { return self::value(get_object_vars($value)); }return is_array($value)?array_map(self::value(...),$value):$value; }
}
final class DeclaredCapturedWarningObserverPlugin implements Plugin
{
    public function __construct(private readonly string $root,private readonly string $output,private readonly string $mode) {}
    public function getDefinition():PluginDefinition { return new PluginDefinition('fixture/remaining-warnings','Remaining warning observation','Genuine native contexts only; every complete issue is kept.'); }
    public function register(PluginRegistry $registry):void { $registry->registerIssueFilterHook(new DeclaredCapturedWarningObserver($this->root,$this->output,$this->mode)); }
}
final class DeclaredCapturedWarningControlsPlugin implements Plugin
{
    public function __construct(private readonly string $root,private readonly string $output) {}
    public function getDefinition():PluginDefinition { return new PluginDefinition('fixture/remaining-warning-controls','Remaining warning controls','Actual native cache and envelope controls.'); }
    public function register(PluginRegistry $registry):void { $registry->registerIssueFilterHook(new DeclaredCapturedWarningNativeControls($this->root,$this->output)); }
}
$root=$argv[2];$mode=$argv[3];$output=$argv[4];
if(str_starts_with($mode,'full-')) { require $argv[5]; }
else {
    $plugins=match($mode) {
        'native'=>[],
        'guards'=>[new DeclaredCapturedWarningControlsPlugin($root,$output)],
        'compatibility'=>[new \Ichinya\Laramago\Analyzer\DeclaredValuePostconditionPlugin($root),new \Ichinya\Laramago\Analyzer\CapturedArrayPostconditionPlugin($root)],
        default=>[new DeclaredCapturedWarningObserverPlugin($root,$output,$mode)],
    };
    (new \Mago\Sdk\Worker(new \Mago\Sdk\Extension('fixture/remaining-warnings','Remaining warning observation','1',analyzerPlugins:$plugins)))->run();
}
