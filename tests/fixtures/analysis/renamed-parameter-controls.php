<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Tests;

use Ichinya\Laramago\Analyzer\RenamedParameterAdvisoryFilter;
use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterDecision,IssueFilterHook,Type};
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Reporting\{AnnotationKind,Level,ReportedIssue,TextEdit};
use Mago\Sdk\Span;

/** Original native positives only; all negative envelope/cache mutations restore their inputs. */
final class RenamedParameterNativeControls implements IssueFilterHook
{
    private bool $checked=false;
    public function __construct(private readonly RenamedParameterAdvisoryFilter $filter,private readonly string $root) {}
    public function getCodes():array { return $this->filter->getCodes(); }
    public function filterIssue(IssueFilterContext $context):IssueFilterDecision {
        if($this->checked||!str_contains($context->issue->message,'ParameterNameExamples\\TypedChild::convert()')) { return IssueFilterDecision::Keep; }
        $this->checked=true;$checks=[];
        $expect=function(string $label,IssueFilterContext $input,bool $remove)use(&$checks):void {
            $actual=$this->filter->filterIssue($input)===IssueFilterDecision::Remove;
            if($actual!==$remove) {
                file_put_contents($this->root.'/control-first-failure.json',json_encode(['label'=>$label,'expectedRemove'=>$remove,'actualRemove'=>$actual,'passed'=>$checks],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
                throw new \RuntimeException('Genuine renamed parameter control failed: '.$label);
            }
            $checks[$label]=true;
        };
        $copy=static function(object $value,array $changes):object { $class=$value::class;return new $class(...array_replace(get_object_vars($value),$changes)); };
        $with=static fn(ReportedIssue $issue,?string $contents=null,?string $file=null):IssueFilterContext=>new IssueFilterContext($context->phpVersion,$context->codebase,$context->types,$context->cancellation,$file??$context->file,$contents??$context->contents,$issue);
        $expect('genuine native positive before any mutation',$context,true);
        $proof=(new \ReflectionProperty($this->filter,'lastProof'))->getValue($this->filter);
        if($proof===null) { throw new \RuntimeException('The genuine native positive did not certify current declarations.'); }
        $issue=$context->issue;[$primary,$parentAnnotation,$childAnnotation]=$issue->annotations;
        foreach(['Error severity'=>['level'=>Level::Error],'other code'=>['code'=>'invalid-named-argument'],
            'changed message'=>['message'=>$issue->message.' Changed.'],'missing note'=>['notes'=>[]],'extra note'=>['notes'=>[...$issue->notes,'Extra.']],
            'missing help'=>['help'=>null],'changed help'=>['help'=>$issue->help.' Changed.'],'extra link'=>['link'=>'https://example.invalid'],
            'extra edit'=>['edits'=>[TextEdit::insert(0,' ')]],'missing annotations'=>['annotations'=>[]],
            'extra annotation'=>['annotations'=>[$primary,$parentAnnotation,$childAnnotation,$primary]],
            'reversed secondaries'=>['annotations'=>[$primary,$childAnnotation,$parentAnnotation]],
            'Primary kind mismatch'=>['annotations'=>[$copy($primary,['kind'=>AnnotationKind::Secondary]),$parentAnnotation,$childAnnotation]],
            'Parent kind mismatch'=>['annotations'=>[$primary,$copy($parentAnnotation,['kind'=>AnnotationKind::Primary]),$childAnnotation]],
            'Child kind mismatch'=>['annotations'=>[$primary,$parentAnnotation,$copy($childAnnotation,['kind'=>AnnotationKind::Primary])]],
            'Primary foreign file'=>['annotations'=>[$copy($primary,['file'=>'foreign.php']),$parentAnnotation,$childAnnotation]],
            'Parent foreign file'=>['annotations'=>[$primary,$copy($parentAnnotation,['file'=>'foreign.php']),$childAnnotation]],
            'Child foreign file'=>['annotations'=>[$primary,$parentAnnotation,$copy($childAnnotation,['file'=>'foreign.php'])]],
            'Primary message mismatch'=>['annotations'=>[$copy($primary,['message'=>'Changed.']),$parentAnnotation,$childAnnotation]],
            'Parent message mismatch'=>['annotations'=>[$primary,$copy($parentAnnotation,['message'=>'Changed.']),$childAnnotation]],
            'Child message mismatch'=>['annotations'=>[$primary,$parentAnnotation,$copy($childAnnotation,['message'=>'Changed.'])]],
            'nearby method-name span'=>['annotations'=>[$copy($primary,['span'=>new Span($primary->span->start+1,$primary->span->end)]),$parentAnnotation,$childAnnotation]],
            'nearby parent-class span'=>['annotations'=>[$primary,$copy($parentAnnotation,['span'=>new Span($parentAnnotation->span->start+1,$parentAnnotation->span->end)]),$childAnnotation]],
            'nearby child-class span'=>['annotations'=>[$primary,$parentAnnotation,$copy($childAnnotation,['span'=>new Span($childAnnotation->span->start+1,$childAnnotation->span->end)])]]] as $label=>$change) { $expect($label,$with($copy($issue,$change)),false); }
        $expect('foreign issue caller file',$with($issue,file:'foreign.php'),false);
        $expect('stale analyzed bytes',$with($issue,contents:$context->contents.' '),false);
        $expect('malformed analyzed bytes',$with($issue,contents:'<?php invalid {'),false);
        foreach(['incomplete before-issue named-call index'=>['scanComplete',false],'failed before-issue named-call index'=>['scanFailed',true],'missing current analyzed file hashes'=>['scanned',[]]] as $label=>[$field,$replacement]) {
            $property=new \ReflectionProperty($this->filter,$field);$original=$property->getValue($this->filter);
            try { $property->setValue($this->filter,$replacement);$expect($label,$context,false); }
            finally { $property->setValue($this->filter,$original); }
        }
        $sources=new \ReflectionProperty($this->filter,'sources');$originalSources=$sources->getValue($this->filter);
        if($originalSources===[]) { throw new \RuntimeException('The actual source cache was not populated.'); }
        $missing=$originalSources;foreach($missing as $key=>$entry) { $missing[$key]=[]; }
        try { $sources->setValue($this->filter,$missing);$expect('missing actual source declarations',$context,false); }
        finally { $sources->setValue($this->filter,$originalSources); }
        $childClass=$proof['childClass'];$parentClass=$proof['parentClass'];$child=$proof['childMethod'];$parent=$proof['parentMethod'];
        $formal=$child->parameters[0];$parentFormal=$parent->parameters[0];
        $mutations=['native child class missing'=>[$childClass,null],'native parent class missing'=>[$parentClass,null],
            'native child class name mismatch'=>[$childClass,$copy($childClass,['name'=>'Unknown\\Child'])],
            'native parent class name mismatch'=>[$parentClass,$copy($parentClass,['name'=>'Unknown\\Parent'])],
            'native ancestry missing'=>[$childClass,$copy($childClass,['parentClasses'=>[],'parentInterfaces'=>[]])],
            'native ancestry unresolved'=>[$childClass,$copy($childClass,['unresolvedHierarchyDependencies'=>['Unknown\\Dependency']])],
            'native child method missing'=>[$child,null],'native parent method missing'=>[$parent,null],
            'native child method owner mismatch'=>[$child,$copy($child,['identifier'=>$copy($child->identifier,['class'=>'Unknown\\Child'])])],
            'native parent method owner mismatch'=>[$parent,$copy($parent,['identifier'=>$copy($parent->identifier,['class'=>'Unknown\\Parent'])])],
            'native child method foreign file'=>[$child,$copy($child,['location'=>$copy($child->location,['file'=>'foreign.php'])])],
            'native parent method foreign file'=>[$parent,$copy($parent,['location'=>$copy($parent->location,['file'=>'foreign.php'])])],
            'native child arity mismatch'=>[$child,$copy($child,['parameters'=>[]])],
            'native parent arity mismatch'=>[$parent,$copy($parent,['parameters'=>[]])],
            'native child parameter name mismatch'=>[$child,$copy($child,['parameters'=>[$copy($formal,['name'=>'$other'])]])],
            'native parent parameter name mismatch'=>[$parent,$copy($parent,['parameters'=>[$copy($parentFormal,['name'=>'$other'])]])],
            'native child parameter by-reference'=>[$child,$copy($child,['parameters'=>[$copy($formal,['flags'=>new MetadataFlags($formal->flags->bits|MetadataFlags::BY_REFERENCE)])]])],
            'native child parameter variadic'=>[$child,$copy($child,['parameters'=>[$copy($formal,['flags'=>new MetadataFlags($formal->flags->bits|MetadataFlags::VARIADIC)])]])],
            'native child parameter-out'=>[$child,$copy($child,['parameters'=>[$copy($formal,['outType'=>$formal->type])]])],
            'native child type missing'=>[$child,$copy($child,['parameters'=>[$copy($formal,['type'=>null])]])],
            'native child type incompatible'=>[$child,$copy($child,['parameters'=>[$copy($formal,['type'=>$copy($formal->type,['type'=>Type::int()])])]])],
            'native static contract changed'=>[$child,$copy($child,['static'=>!$child->static])],
            'native physical return incompatible'=>[$child,$copy($child,['declaredReturnType'=>$copy($child->declaredReturnType,['type'=>Type::int()])])]];
        $cache=(new \ReflectionProperty($context->codebase,'cache'))->getValue($context->codebase);$values=$cache->values;$relations=$cache->relations;
        foreach($mutations as $label=>[$observed,$replacement]) {
            $changed=0;
            foreach($values as $operation=>$entries) { foreach($entries as $key=>$value) {
                if($value===$observed||is_object($value)&&$value==$observed) { $cache->values[$operation][$key]=$replacement;$changed++; }
            } }
            try { if($changed===0) { throw new \RuntimeException('Vacuous genuine native cache mutation: '.$label); }$expect($label,$context,false); }
            finally { $cache->values=$values;$cache->relations=$relations; }
        }
        $expect('all native/source state restored',$context,true);
        file_put_contents($this->root.'/native-controls.json',json_encode(['genuineSdkIssue'=>RenamedParameterTestObserver::value($issue),'sourceSha256'=>hash('sha256',$context->contents),'checks'=>$checks],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
        return IssueFilterDecision::Keep;
    }
}
