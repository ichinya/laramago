<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Tests;
use Ichinya\Laramago\Analyzer\GuardedNullFlowIssueFilter;
use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterDecision,IssueFilterHook,Type};
use Mago\Sdk\Analyzer\Assertion\{TypeAssertion,TypeAssertionKind};
use Mago\Sdk\Analyzer\Metadata\{FunctionLikeMetadata,MetadataFlags};
use Mago\Sdk\Reporting\{AnnotationKind,Level,ReportedIssue,TextEdit};
use Mago\Sdk\Span;

/** Mutations are negative controls of original genuine SDK inputs; every native/cache value is restored. */
final class NullFlowNativeControls implements IssueFilterHook
{
    private bool $checked=false;
    public function __construct(private readonly GuardedNullFlowIssueFilter $filter,private readonly string $projectRoot,private readonly string $outputRoot) {}
    public function getCodes():array { return $this->filter->getCodes(); }
    public function filterIssue(IssueFilterContext $context):IssueFilterDecision {
        $catalogue=json_decode(file_get_contents($this->outputRoot.'/cases.json'),true,flags:JSON_THROW_ON_ERROR);$primary=$context->issue->annotations[0]??null;
        if($this->checked||$primary===null||[$primary->span->start,$primary->span->end]!==$catalogue['positives']['P06']['span']) { return IssueFilterDecision::Keep; }
        $this->checked=true;$checks=[];
        $expect=function(string $label,IssueFilterContext $input,bool $remove,?GuardedNullFlowIssueFilter $filter=null)use(&$checks):void {
            $selected=$filter??$this->filter;$actual=$selected->filterIssue($input)===IssueFilterDecision::Remove;
            if($actual!==$remove) { file_put_contents($this->outputRoot.'/control-first-failure.json',json_encode(['label'=>$label,'expectedRemove'=>$remove,'actualRemove'=>$actual,'completedObservation'=>NullFlowTestObserver::english(NullFlowTestObserver::value($selected->observation())),'alreadyPassed'=>$checks],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));throw new \RuntimeException('Genuine null-flow control failed: '.$label); }
            $checks[$label]=true;
        };
        $copy=static function(object $value,array $changes):object { $class=$value::class;return new $class(...array_replace(get_object_vars($value),$changes)); };
        $with=static fn(ReportedIssue $issue,?string $contents=null,?string $file=null):IssueFilterContext=>new IssueFilterContext($context->phpVersion,$context->codebase,$context->types,$context->cancellation,$file??$context->file,$contents??$context->contents,$issue);
        $expect('genuine native positive before all mutations',$context,true);$certificate=$this->filter->observation()['certificate'];
        $issue=$context->issue;[$primary,$secondary]=$issue->annotations;
        foreach(['other severity'=>['level'=>Level::Warning],'other code'=>['code'=>'invalid-argument'],'changed message'=>['message'=>$issue->message.' Changed.'],
            'extra note'=>['notes'=>['Changed.']],'missing help'=>['help'=>null],'changed help'=>['help'=>$issue->help.' Changed.'],'foreign link'=>['link'=>'https://example.invalid'],
            'extra edit'=>['edits'=>[TextEdit::insert(0,' ')]],'missing annotations'=>['annotations'=>[]],'extra annotation'=>['annotations'=>[$primary,$secondary,$primary]],
            'reversed annotations'=>['annotations'=>[$secondary,$primary]],'Primary kind'=>['annotations'=>[$copy($primary,['kind'=>AnnotationKind::Secondary]),$secondary]],
            'Secondary kind'=>['annotations'=>[$primary,$copy($secondary,['kind'=>AnnotationKind::Primary])]],
            'Primary message'=>['annotations'=>[$copy($primary,['message'=>'Changed.']),$secondary]],'Secondary message'=>['annotations'=>[$primary,$copy($secondary,['message'=>'Changed.'])]],
            'Primary file'=>['annotations'=>[$copy($primary,['file'=>'foreign.php']),$secondary]],'Secondary file'=>['annotations'=>[$primary,$copy($secondary,['file'=>'foreign.php'])]],
            'Primary neighboring span'=>['annotations'=>[$copy($primary,['span'=>new Span($primary->span->start+1,$primary->span->end)]),$secondary]],
            'Secondary neighboring span'=>['annotations'=>[$primary,$copy($secondary,['span'=>new Span($secondary->span->start+1,$secondary->span->end)])]]] as $label=>$change) { $expect($label,$with($copy($issue,$change)),false); }
        $expect('foreign caller file',$with($issue,file:'foreign.php'),false);$expect('stale current bytes',$with($issue,contents:$context->contents.' '),false);
        $expect('malformed current bytes',$with($issue,contents:'<?php invalid {'),false);
        $expect('remembrance disabled',$context,false,new GuardedNullFlowIssueFilter($this->projectRoot,false,true));
        $expect('PHPDoc certainty disabled',$context,false,new GuardedNullFlowIssueFilter($this->projectRoot,true,false));
        $contracts=(new \ReflectionProperty($this->filter,'contracts'))->getValue($this->filter);$sources=new \ReflectionProperty($contracts,'sources');$originalSources=$sources->getValue($contracts);
        if($originalSources===[]) { throw new \RuntimeException('The genuine bounded source cache is missing.'); }
        try { $missing=array_map(static fn(array $entry):array=>[],$originalSources);$sources->setValue($contracts,$missing);$expect('actual source declarations missing',$context,false); }
        finally { $sources->setValue($contracts,$originalSources); }
        $caller=$certificate['caller']['native'];$target=$certificate['target']['native'];$guard=$certificate['postcondition']['native'];$receiver=$certificate['nativeReceivingCallable'];
        $carrier=array_values($certificate['classes'])[0]['native'];$formal=$receiver->parameters[0];$assertedFormal=$guard->parameters[0];
        $shift=static fn(\Mago\Sdk\SourceLocation $location):\Mago\Sdk\SourceLocation=>$copy($location,['span'=>new Span($location->span->start+1,$location->span->end)]);
        $mutations=['native caller missing'=>[$caller,null],'native target getter missing'=>[$target,null],'native postcondition missing'=>[$guard,null],'native receiving callable missing'=>[$receiver,null],
            'native carrier class missing'=>[$carrier,null],'native carrier class name'=>[$carrier,$copy($carrier,['name'=>'Unknown\\Carrier'])],
            'native carrier hierarchy incomplete'=>[$carrier,$copy($carrier,['unresolvedHierarchyDependencies'=>['Unknown\\Parent']])],
            'native carrier source name span'=>[$carrier,$copy($carrier,['nameLocation'=>$shift($carrier->nameLocation)])],
            'native caller owner'=>[$caller,$copy($caller,['identifier'=>$copy($caller->identifier,['name'=>'otherCaller'])])],
            'native caller source span'=>[$caller,$copy($caller,['location'=>$shift($caller->location)])],
            'native caller name span'=>[$caller,$copy($caller,['nameLocation'=>$shift($caller->nameLocation)])],
            'native target owner'=>[$target,$copy($target,['identifier'=>$copy($target->identifier,['class'=>'Unknown\\Carrier'])])],
            'native target source file'=>[$target,$copy($target,['location'=>$copy($target->location,['file'=>'foreign.php'])])],
            'native target name span'=>[$target,$copy($target,['nameLocation'=>$shift($target->nameLocation)])],
            'native target reference return'=>[$target,$copy($target,['flags'=>new MetadataFlags($target->flags->bits|MetadataFlags::BY_REFERENCE)])],
            'native target declared return missing'=>[$target,$copy($target,['declaredReturnType'=>null])],
            'native target return missing'=>[$target,$copy($target,['returnType'=>null])],
            'native target declared return contradiction'=>[$target,$copy($target,['declaredReturnType'=>$copy($target->declaredReturnType,['type'=>Type::int()])])],
            'native target effective return contradiction'=>[$target,$copy($target,['returnType'=>$copy($target->returnType,['type'=>Type::int()])])],
            'native postcondition assertions missing'=>[$guard,$copy($guard,['assertions'=>[]])],
            'native postcondition assertions inferred'=>[$guard,$copy($guard,['assertionsInferred'=>true])],
            'native postcondition asserts null'=>[$guard,$copy($guard,['assertions'=>[$assertedFormal->name=>[new TypeAssertion(TypeAssertionKind::IsType,Type::null())]]])],
            'native postcondition formal name'=>[$guard,$copy($guard,['parameters'=>[$copy($assertedFormal,['name'=>'$other']),$guard->parameters[1]]])],
            'native postcondition reference formal'=>[$guard,$copy($guard,['parameters'=>[$copy($assertedFormal,['flags'=>new MetadataFlags($assertedFormal->flags->bits|MetadataFlags::BY_REFERENCE)]),$guard->parameters[1]]])],
            'native postcondition parameter-out'=>[$guard,$copy($guard,['parameters'=>[$copy($assertedFormal,['outType'=>$assertedFormal->type]),$guard->parameters[1]]])],
            'native receiver arity'=>[$receiver,$copy($receiver,['parameters'=>[]])],
            'native receiver reference formal'=>[$receiver,$copy($receiver,['parameters'=>[$copy($formal,['flags'=>new MetadataFlags($formal->flags->bits|MetadataFlags::BY_REFERENCE)])]])],
            'native receiver formal type missing'=>[$receiver,$copy($receiver,['parameters'=>[$copy($formal,['type'=>null])]])],
            'native receiver formal type contradiction'=>[$receiver,$copy($receiver,['parameters'=>[$copy($formal,['type'=>$copy($formal->type,['type'=>Type::int()])])]])],
            'native receiver declared formal contradiction'=>[$receiver,$copy($receiver,['parameters'=>[$copy($formal,['declaredType'=>$copy($formal->declaredType,['type'=>Type::int()])])]])],
            'native receiver parameter-out'=>[$receiver,$copy($receiver,['parameters'=>[$copy($formal,['outType'=>$formal->type])]])],
            'native receiver variadic formal'=>[$receiver,$copy($receiver,['parameters'=>[$copy($formal,['flags'=>new MetadataFlags($formal->flags->bits|MetadataFlags::VARIADIC)])]])]];
        $cache=(new \ReflectionProperty($context->codebase,'cache'))->getValue($context->codebase);$values=$cache->values;$relations=$cache->relations;$actualChanges=[];
        foreach($mutations as $label=>[$observed,$replacement]) {
            $changed=0;foreach($values as $operation=>$entries) { foreach($entries as $key=>$value) { if($value===$observed||is_object($value)&&$value==$observed) { $cache->values[$operation][$key]=$replacement;$changed++; } } }
            try { if($changed===0) { throw new \RuntimeException('Vacuous genuine native cache control: '.$label); }$expect($label,$context,false);$actualChanges[$label]=$changed; }
            finally { $cache->values=$values;$cache->relations=$relations; }
        }
        $expect('all genuine native/source values restored',$context,true);
        file_put_contents($this->outputRoot.'/native-controls.json',json_encode(['originalGenuineSdkIssue'=>NullFlowTestObserver::value($issue),'sourceSha256'=>hash('sha256',$context->contents),'checks'=>$checks,'actualCacheVariantsChanged'=>$actualChanges,'positiveNativeDTOsFabricated'=>false],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
        return IssueFilterDecision::Keep;
    }
}
