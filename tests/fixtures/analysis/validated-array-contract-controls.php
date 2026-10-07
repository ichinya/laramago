<?php
declare(strict_types=1);

namespace Ichinya\Laramago\Tests;

use Ichinya\Laramago\Analyzer\ValidatedArrayContractPlugin;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ValidatedSortedArrayProofs;
use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterDecision,IssueFilterHook,Type};
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Reporting\{AnnotationKind,Level,ReportedIssue,TextEdit};
use Mago\Sdk\Span;

/** Mutate only originally observed native positives; retain every real diagnostic. */
final class ValidatedArrayNativeControls implements IssueFilterHook
{
    private array $completed=[];
    public function __construct(private readonly ValidatedArrayContractPlugin $plugin,private readonly string $root) {}
    public function getCodes():array { return $this->plugin->getCodes(); }
    public function filterIssue(IssueFilterContext $context):IssueFilterDecision
    {
        $primary=$context->issue->annotations[0]??null;
        if($primary===null) { return IssueFilterDecision::Keep; }
        $proof=null;$family=null;
        if(basename($context->file)==='fixture-cases.php') {
            foreach((new ValidatedSortedArrayProofs)->inspect($context->contents)['candidates']??[] as $candidate) {
                if(($candidate['sourceStructureAccepted']??false)!==true) { continue; }
                if(str_ends_with($candidate['name'],'\\validatedBookkeeping')&&in_array([$primary->span->start,$primary->span->end],$candidate['comparatorReadSpans'],true)) { $proof=$candidate;$family='sorted';break; }
                if(str_ends_with($candidate['name'],'\\sortedValidated')&&[$primary->span->start,$primary->span->end]===$candidate['returnExpressionSpan']) { $proof=$candidate;$family='return';break; }
            }
        } elseif(basename($context->file)==='top-positive.php') { $family='script'; }
        if($family===null||isset($this->completed[$family])) { return IssueFilterDecision::Keep; }
        $this->completed[$family]=true;$checks=[];
        $expect=function(string $label,IssueFilterContext $input,bool $remove)use(&$checks,$family):void {
            $actual=$this->plugin->filterIssue($input)===IssueFilterDecision::Remove;
            if($actual!==$remove) {
                file_put_contents($this->root.'/control-first-failure-'.getmypid().'.json',json_encode(['family'=>$family,'label'=>$label,'expectedRemove'=>$remove,'actualRemove'=>$actual,'passed'=>$checks,'trace'=>ValidatedArrayTestObserver::value($this->plugin->lastTrace)],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
                throw new \RuntimeException('Genuine validated array control failed: '.$family.' / '.$label);
            }
            $checks[$label]=true;
        };
        $copy=static function(object $value,array $changes):object { $class=$value::class;return new $class(...array_replace(get_object_vars($value),$changes)); };
        $with=static fn(ReportedIssue $issue,?string $contents=null,?string $file=null):IssueFilterContext=>new IssueFilterContext($context->phpVersion,$context->codebase,$context->types,$context->cancellation,$file??$context->file,$contents??$context->contents,$issue);
        $expect('genuine native positive before any mutation',$context,true);
        $issue=$context->issue;
        foreach(['Warning severity'=>['level'=>Level::Warning],'other code'=>['code'=>'mixed-property-access'],
            'changed diagnostic message'=>['message'=>$issue->message.' Changed.'],'missing notes'=>['notes'=>[]],
            'extra note'=>['notes'=>[...$issue->notes,'Extra.']],'missing help'=>['help'=>null],
            'changed help'=>['help'=>$issue->help.' Changed.'],'extra link'=>['link'=>'https://example.invalid'],
            'extra edit'=>['edits'=>[TextEdit::insert(0,' ')]],'missing annotation'=>['annotations'=>[]],
            'extra annotation'=>['annotations'=>[$primary,$primary]],
            'nonprimary annotation'=>['annotations'=>[$copy($primary,['kind'=>AnnotationKind::Secondary])]],
            'foreign annotation file'=>['annotations'=>[$copy($primary,['file'=>'foreign.php'])]],
            'changed annotation text'=>['annotations'=>[$copy($primary,['message'=>'Changed.'])]],
            'nearby source span'=>['annotations'=>[$copy($primary,['span'=>new Span($primary->span->start+1,$primary->span->end)])]]] as $label=>$changes) {
            $expect($label,$with($copy($issue,$changes)),false);
        }
        $expect('foreign current caller file',$with($issue,file:'foreign.php'),false);
        $expect('stale analyzed bytes',$with($issue,contents:$context->contents.' '),false);
        $expect('malformed analyzed bytes',$with($issue,contents:'<?php invalid {'),false);
        $expect('oversized analyzed bytes',$with($issue,contents:str_repeat(' ',1024*1024+1)),false);
        if($proof!==null) {
            $fresh = new ValidatedArrayContractPlugin($this->root);
            if ((new \ReflectionProperty($fresh,'callbacks'))->getValue($fresh)!==[]) { throw new \RuntimeException('Fresh worker unexpectedly has provider state.'); }
            if ($fresh->filterIssue($context)!==IssueFilterDecision::Remove || ($fresh->lastProof['providerCacheUsed']??true)!==false) { throw new \RuntimeException('Fresh worker without callback provider cannot prove the genuine positive.'); }
            $checks['fresh issue worker with no callback provider state']=true;
            $callbacks=new \ReflectionProperty($this->plugin,'callbacks');$original=$callbacks->getValue($this->plugin);
            try {
                $callbacks->setValue($this->plugin,[]);
                $expect('empty callback observation map preserves genuine lexical positive',$context,true);
            } finally { $callbacks->setValue($this->plugin,$original); }
        }
        $codebase=$context->codebase;$cache=(new \ReflectionProperty($codebase,'cache'))->getValue($codebase);
        $values=$cache->values;$relations=$cache->relations;$mutations=[];
        if($proof!==null) {
            $caller=$codebase->getFunction($proof['name']);$sort=$codebase->getFunction('usort');$arrayCheck=$codebase->getFunction('is_array');
            if($caller===null||$sort===null||$arrayCheck===null||count($caller->parameters)!==1) { throw new \RuntimeException('Missing genuine native caller/sort/guard metadata.'); }
            $formal=$caller->parameters[0];
            $mutations=['native caller missing'=>[$caller,null],
                'native caller foreign source'=>[$caller,$copy($caller,['location'=>$copy($caller->location,['file'=>'foreign.php'])])],
                'native caller name mismatch'=>[$caller,$copy($caller,['identifier'=>$copy($caller->identifier,['name'=>'other'])])],
                'native caller formal count'=>[$caller,$copy($caller,['parameters'=>[]])],
                'native caller by-reference'=>[$caller,$copy($caller,['flags'=>new MetadataFlags($caller->flags->bits|MetadataFlags::BY_REFERENCE)])],
                'native formal by-reference'=>[$caller,$copy($caller,['parameters'=>[$copy($formal,['flags'=>new MetadataFlags($formal->flags->bits|MetadataFlags::BY_REFERENCE)])]])],
                'native formal variadic'=>[$caller,$copy($caller,['parameters'=>[$copy($formal,['flags'=>new MetadataFlags($formal->flags->bits|MetadataFlags::VARIADIC)])]])],
                'native formal parameter-out'=>[$caller,$copy($caller,['parameters'=>[$copy($formal,['outType'=>$formal->type])]])],
                'native formal loses documented list'=>[$caller,$copy($caller,['parameters'=>[$copy($formal,['type'=>$copy($formal->type,['fromDocblock'=>false])])]])],
                'native formal unknown list type'=>[$caller,$copy($caller,['parameters'=>[$copy($formal,['type'=>$copy($formal->type,['type'=>Type::mixed()])])]])],
                'native documented return missing'=>[$caller,$copy($caller,['returnType'=>null])],
                'native physical return missing'=>[$caller,$copy($caller,['declaredReturnType'=>null])],
                'native documented return incompatible'=>[$caller,$copy($caller,['returnType'=>$copy($caller->returnType,['type'=>Type::list(Type::string())])])],
                'native sort missing'=>[$sort,null],
                'native sort not builtin'=>[$sort,$copy($sort,['flags'=>new MetadataFlags($sort->flags->bits&~MetadataFlags::BUILTIN)])],
                'native sort formal count'=>[$sort,$copy($sort,['parameters'=>[]])],
                'native sort loses array reference'=>[$sort,$copy($sort,['parameters'=>[$copy($sort->parameters[0],['flags'=>new MetadataFlags($sort->parameters[0]->flags->bits&~MetadataFlags::BY_REFERENCE)]),$sort->parameters[1]]])],
                'native sort callback by-reference'=>[$sort,$copy($sort,['parameters'=>[$sort->parameters[0],$copy($sort->parameters[1],['flags'=>new MetadataFlags($sort->parameters[1]->flags->bits|MetadataFlags::BY_REFERENCE)])]])],
                'native guard missing'=>[$arrayCheck,null],
                'native guard not builtin'=>[$arrayCheck,$copy($arrayCheck,['flags'=>new MetadataFlags($arrayCheck->flags->bits&~MetadataFlags::BUILTIN)])]];
            if($family==='sorted') {
                $helper=$codebase->getDeclaringMethod('ValidatedArrayExamples\\ScalarValidator','label');
                if($helper===null||count($helper->parameters)!==1) { throw new \RuntimeException('Missing actual scalar helper declaration.'); }
                $parameter=$helper->parameters[0];
                $mutations+=['native scalar helper missing'=>[$helper,null],
                    'native scalar helper nonstatic'=>[$helper,$copy($helper,['static'=>false])],
                    'native scalar helper foreign source'=>[$helper,$copy($helper,['location'=>$copy($helper->location,['file'=>'foreign.php'])])],
                    'native scalar helper by-reference'=>[$helper,$copy($helper,['parameters'=>[$copy($parameter,['flags'=>new MetadataFlags($parameter->flags->bits|MetadataFlags::BY_REFERENCE)])]])],
                    'native scalar helper parameter-out'=>[$helper,$copy($helper,['parameters'=>[$copy($parameter,['outType'=>$parameter->type])]])],
                    'native scalar helper incompatible primitive'=>[$helper,$copy($helper,['parameters'=>[$copy($parameter,['declaredType'=>$copy($parameter->declaredType,['type'=>Type::int()])])]])]];
            }
        } else {
            $getopt=$codebase->getFunction('getopt');$json=$codebase->getFunction('json_decode');
            if($getopt===null||$json===null||count($getopt->parameters)<2) { throw new \RuntimeException('Missing genuine script builtin metadata.'); }
            $parameters=$getopt->parameters;$byRef=$parameters;$byRef[0]=$copy($parameters[0],['flags'=>new MetadataFlags($parameters[0]->flags->bits|MetadataFlags::BY_REFERENCE)]);
            $out=$parameters;$out[1]=$copy($parameters[1],['outType'=>$parameters[1]->type]);
            $mutations=['native getopt missing'=>[$getopt,null],'native getopt not builtin'=>[$getopt,$copy($getopt,['flags'=>new MetadataFlags($getopt->flags->bits&~MetadataFlags::BUILTIN)])],
                'native getopt formal count'=>[$getopt,$copy($getopt,['parameters'=>[]])],
                'native getopt supplied formal by-reference'=>[$getopt,$copy($getopt,['parameters'=>$byRef])],
                'native getopt supplied formal parameter-out'=>[$getopt,$copy($getopt,['parameters'=>$out])],
                'native json builtin missing'=>[$json,null],'native json not builtin'=>[$json,$copy($json,['flags'=>new MetadataFlags($json->flags->bits&~MetadataFlags::BUILTIN)])]];
        }
        $cacheReceipts=[];
        foreach($mutations as $label=>[$observed,$replacement]) {
            if($replacement!==null&&$replacement==$observed) { throw new \RuntimeException('No-op native metadata mutation: '.$label); }
            // A nested SDK query can replace a bucket. Re-query this genuine declaration immediately before selecting its real slots.
            $identifier=$observed->identifier;
            $fresh=$identifier->class===null?$codebase->getFunction($identifier->name):$codebase->getDeclaringMethod($identifier->class,$identifier->name);
            if($fresh===null||$fresh!=$observed) { throw new \RuntimeException('Selected genuine metadata changed before control: '.$label); }
            $values=$cache->values;$relations=$cache->relations;$changed=0;$slots=[];
            foreach($values as $operation=>$entries) { foreach($entries as $key=>$value) {
                if($value===$fresh) { $cache->values[$operation][$key]=$replacement;$changed++;$slots[]=['operation'=>$operation,'key'=>$key]; }
            } }
            try {
                if($changed===0) { throw new \RuntimeException('Vacuous native cache control: '.$label); }$expect($label,$context,false);
                $cacheReceipts[$label]=['touched'=>$changed,'actualSlots'=>$slots,'changedMetadata'=>true];
            }
            finally { $cache->values=$values;$cache->relations=$relations; }
        }
        $expect('all native source and callback state restored',$context,true);
        file_put_contents($this->root.'/native-controls-'.$family.'-'.getmypid().'.json',json_encode(['genuineSdkIssue'=>ValidatedArrayTestObserver::value($issue),'sourceSha256'=>hash('sha256',$context->contents),'checks'=>$checks,'cacheMutations'=>$cacheReceipts,'lexicalProof'=>ValidatedArrayTestObserver::value($this->plugin->lastProof)],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
        return IssueFilterDecision::Keep;
    }
}
