<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Tests\Support;

use Ichinya\Laramago\Analyzer\OrdinaryMixedAssignmentFilter;
use Ichinya\Laramago\Analyzer\StaticAnalysis\OrdinaryMixedBindings;
use Mago\Sdk\Analyzer\{InitializationContext,InitializationHook,IssueFilterContext,IssueFilterDecision,IssueFilterHook,Plugin,PluginDefinition,PluginRegistry};
use Mago\Sdk\Reporting\{AnnotationKind,Level,ReportedIssue};

/** Delegate unchanged genuine contexts to the production filter; copied contexts are negative controls only. */
final class OrdinaryMixedControlObserver implements Plugin,InitializationHook,IssueFilterHook
{
    private readonly OrdinaryMixedAssignmentFilter $filter;
    public function __construct(private readonly string $output,private readonly bool $observeOnly=false){$this->filter=new OrdinaryMixedAssignmentFilter();}
    public function getDefinition():PluginDefinition{return new PluginDefinition('fixture/ordinary-mixed-controls','Ordinary mixed controls','Genuine native source bindings, complete envelopes and unsafe-use preservation.');}
    public function register(PluginRegistry $registry):void{$registry->registerInitializationHook($this);$registry->registerIssueFilterHook($this);}
    public function initialize(InitializationContext $context):void{$this->filter->initialize($context);}
    public function getCodes():array{return ['mixed-assignment','mixed-argument','mixed-array-access','mixed-method-access','mixed-property-access','mixed-return-statement','invalid-assignment','possibly-invalid-argument','incompatible-property-type'];}
    public function filterIssue(IssueFilterContext $context):IssueFilterDecision
    {
        $actual=$this->filter->filterIssue($context);$eligible=$actual===IssueFilterDecision::Remove;$proof=null;
        foreach($context->issue->annotations as$annotation){if($annotation->kind===AnnotationKind::Primary){$proof=(new OrdinaryMixedBindings())->inspect($context->contents)[$annotation->span->start.':'.$annotation->span->end]??null;}}
        $checks=$eligible&&!$this->observeOnly?$this->controls($context):null;
        $returned=$this->observeOnly?IssueFilterDecision::Keep:$actual;
        file_put_contents($this->output.'/issues.jsonl',json_encode(['file'=>$context->file,'sourceSha256'=>hash('sha256',$context->contents),
            'wholeNativeEnvelope'=>self::snapshot($context->issue),'sourceProof'=>$proof,'eligible'=>$eligible,'decision'=>$returned->name,
            'actualNativeContext'=>true,'nativeTypeChanged'=>false,'runtimePurityGuarantee'=>false,'controls'=>$checks],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
        return$returned;
    }
    private function controls(IssueFilterContext $context):array
    {
        $base=$context->issue;$checks=0;
        foreach(['level','code','message','notes','help','link','annotations','edits','source']as$gate){
            $values=['level'=>$base->level,'code'=>$base->code,'message'=>$base->message,'notes'=>$base->notes,'help'=>$base->help,'link'=>$base->link,'annotations'=>$base->annotations,'edits'=>$base->edits];
            if($gate!=='source'){$values[$gate]=match($gate){'level'=>Level::Error,'code'=>'independent-warning','message'=>'Independent message','notes'=>[],'help'=>null,'link'=>'https://example.invalid/','annotations'=>[],'edits'=>[null]};}
            $negative=new IssueFilterContext($context->phpVersion,$context->codebase,$context->types,$context->cancellation,$context->file,$gate==='source'?'':$context->contents,new ReportedIssue(...$values));
            if($this->filter->filterIssue($negative)!==IssueFilterDecision::Keep){throw new \RuntimeException('Genuine envelope/source negative accepted: '.$gate);}$checks++;
        }
        if($this->filter->filterIssue($context)!==IssueFilterDecision::Remove){throw new \RuntimeException('Negative controls changed the genuine native decision.');}
        return['checks'=>$checks,'positiveAuthority'=>'unchanged genuine native context','negativeOnlyCopies'=>true,'nativeContextOrCacheMutated'=>false];
    }
    private static function snapshot(mixed$value):mixed
    {
        if($value instanceof \UnitEnum){return['enum'=>$value::class,'name'=>$value->name];}
        if(is_object($value)){$value=['objectClass'=>$value::class]+get_object_vars($value);}
        if(is_array($value)){foreach($value as$key=>$item){$value[$key]=self::snapshot($item);}}return$value;
    }
}
