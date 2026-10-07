<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Tests;

use Ichinya\Laramago\Analyzer\RenamedParameterAdvisoryFilter;
use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterDecision,IssueFilterHook,Plugin,PluginDefinition,PluginRegistry};

require $argv[1];

require __DIR__.'/renamed-parameter-controls.php';

final class RenamedParameterTestObserver implements IssueFilterHook
{
    public function __construct(private readonly RenamedParameterAdvisoryFilter $filter,private readonly string $root,private readonly string $mode) {}
    public function getCodes():array { return $this->filter->getCodes(); }
    public function filterIssue(IssueFilterContext $context):IssueFilterDecision {
        $decision=$this->filter->filterIssue($context);$proof=$decision===IssueFilterDecision::Remove?(new \ReflectionProperty($this->filter,'lastProof'))->getValue($this->filter):null;
        $native=[];
        if(preg_match('/^Parameter #[1-9][0-9]* of `([^`]+)::([^`()]+)\(\)` .* parent `([^`]+)::([^`()]+)\(\)`/',$context->issue->message,$names)===1) {
            $native=['childClass'=>$context->codebase->getClassLike($names[1]),'parentClass'=>$context->codebase->getClassLike($names[3]),
                'childMethod'=>$context->codebase->getDeclaringMethod($names[1],$names[2]),'parentMethod'=>$context->codebase->getDeclaringMethod($names[3],$names[4])];
        }
        $scan=[];foreach(['scanComplete','scanFailed','scanStarted'] as $field) { $scan[$field]=(new \ReflectionProperty($this->filter,$field))->getValue($this->filter); }
        $scan['currentFileIndexed']=isset((new \ReflectionProperty($this->filter,'scanned'))->getValue($this->filter)[strtolower(str_replace('\\','/',$context->file))]);
        $possible=[];
        if(isset($names[2])&&preg_match('/names it `(\$[A-Za-z_][A-Za-z0-9_]*)`$/D',$context->issue->message,$formal)===1) {
            $indexed=(new \ReflectionProperty($this->filter,'namedCalls'))->getValue($this->filter);
            foreach([strtolower($names[2]).'\0'.$formal[1],'*\0'.$formal[1],strtolower($names[2]).'\0*','*\0*'] as $key) { if(isset($indexed[$key])) { $possible[]=$indexed[$key]; } }
        }
        file_put_contents($this->root.'/'.$this->mode.'-issues.jsonl',json_encode(['file'=>$context->file,'sourceSha256'=>hash('sha256',$context->contents),'completeSdkIssue'=>self::value($context->issue),
            'candidateDecision'=>$decision->name,'actualCertificate'=>self::value($proof),'nativeBoundedInputs'=>self::value($native),'beforeIssueScanState'=>$scan,'boundedPossibleNamedCalls'=>$possible],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n",FILE_APPEND|LOCK_EX);
        return IssueFilterDecision::Keep;
    }
    public static function value(mixed $input):mixed {
        if($input instanceof \UnitEnum) { return $input->name; }
        if(is_object($input)) { return self::value(get_object_vars($input)); }
        return is_array($input)?array_map(self::value(...),$input):$input;
    }
}
final class RenamedParameterTestPlugin implements Plugin
{
    public function __construct(private readonly string $root,private readonly string $mode,private readonly ?string $outputRoot=null) {}
    public function getDefinition():PluginDefinition { return new PluginDefinition('fixture/renamed-parameter-advisories','Renamed parameter fixtures','Native declaration and named-call separation.'); }
    public function register(PluginRegistry $registry):void {
        $filter=new RenamedParameterAdvisoryFilter($this->root);$registry->registerInitializationHook($filter);$registry->registerCodebaseScanHook($filter);
        $registry->registerIssueFilterHook($this->mode==='guards'?new RenamedParameterNativeControls($filter,$this->outputRoot??$this->root):new RenamedParameterTestObserver($filter,$this->outputRoot??$this->root,$this->mode));
    }
}
$mode=$argv[3]??'native';
if(str_starts_with($mode,'full-')||$mode==='original-observe'||$mode==='original-correct') { require $argv[6]; }
else {
    $plugins=match($mode) { 'native'=>[], 'compatibility'=>[new RenamedParameterAdvisoryFilter($argv[2])],default=>[new RenamedParameterTestPlugin($argv[2],$mode)] };
    (new \Mago\Sdk\Worker(new \Mago\Sdk\Extension(identifier:'fixture/renamed-parameter-advisories',name:'Renamed parameter fixture',version:'1',analyzerPlugins:$plugins)))->run();
}
