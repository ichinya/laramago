<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Tests;
use Ichinya\Laramago\Analyzer\GuardedNullFlowIssueFilter;
use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterDecision,IssueFilterHook,Type};
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Reporting\{AnnotationKind,Level,ReportedIssue,TextEdit};
use Mago\Sdk\Span;

/** Only genuine positive native contexts are mutated; no invented positive SDK or invocation metadata. */
final class FrameworkNullFlowNativeControls implements IssueFilterHook
{
    private array $checked=[];
    public function __construct(private readonly string $root,private readonly string $output) {}
    public function getCodes():array { return (new GuardedNullFlowIssueFilter($this->root))->getCodes(); }
    public function filterIssue(IssueFilterContext $context):IssueFilterDecision {
        $file=str_replace('\\','/',$context->file);$selected=basename($file);$role=match($selected) { 'C01.php'=>'console','R01.php'=>'route','H01.php'=>'header',default=>null };
        if($role===null||isset($this->checked[$role])) { return IssueFilterDecision::Keep; }
        $filter=new GuardedNullFlowIssueFilter($this->root);$decision=$filter->filterIssue($context);
        // A role's first matching genuine removal, rather than an arbitrary diagnostic in the same file, owns the controls.
        if($decision!==IssueFilterDecision::Remove) { return IssueFilterDecision::Keep; }$this->checked[$role]=true;
        $certificate=$filter->observation()['certificate'];$checks=[];$changes=[];
        $expect=function(string $label,IssueFilterContext $input,bool $remove,?GuardedNullFlowIssueFilter $candidate=null)use(&$checks):void {
            $candidate??=new GuardedNullFlowIssueFilter($this->root);$actual=$candidate->filterIssue($input)===IssueFilterDecision::Remove;
            if($actual!==$remove) { file_put_contents($this->output.'/control-first-failure.json',json_encode(['label'=>$label,'expectedRemove'=>$remove,'actualRemove'=>$actual,'observation'=>FrameworkNullFlowObserver::value($candidate->observation()),'passed'=>$checks],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));throw new \RuntimeException('Exact input/route native control failed: '.$label); }$checks[$label]=true;
        };
        $copy=static function(object $value,array $changes):object { $class=$value::class;return new $class(...array_replace(get_object_vars($value),$changes)); };
        $issue=$context->issue;$primary=$issue->annotations[0];
        $with=static fn(ReportedIssue $report,?string $bytes=null,?string $file=null):IssueFilterContext=>new IssueFilterContext($context->phpVersion,$context->codebase,$context->types,$context->cancellation,$file??$context->file,$bytes??$context->contents,$report);
        $expect('actual native positive before mutations',$context,true);
        foreach(['foreign level'=>['level'=>Level::Warning],'foreign code'=>['code'=>'mixed-argument'],'message changed'=>['message'=>$issue->message.' Changed.'],
            'extra note'=>['notes'=>[...$issue->notes,'Changed.']],'missing help'=>['help'=>null],'foreign link'=>['link'=>'https://example.invalid'],
            'extra edit'=>['edits'=>[TextEdit::insert(0,' ')]],'missing annotations'=>['annotations'=>[]],
            'extra annotation'=>['annotations'=>[...$issue->annotations,$primary]],'foreign Primary kind'=>['annotations'=>[$copy($primary,['kind'=>AnnotationKind::Secondary]),...array_slice($issue->annotations,1)]],
            'foreign Primary file'=>['annotations'=>[$copy($primary,['file'=>'foreign.php']),...array_slice($issue->annotations,1)]],
            'shifted Primary span'=>['annotations'=>[$copy($primary,['span'=>new Span($primary->span->start+1,$primary->span->end)]),...array_slice($issue->annotations,1)]],
            'Primary message changed'=>['annotations'=>[$copy($primary,['message'=>'Changed.']),...array_slice($issue->annotations,1)]]] as $label=>$mutation) { $expect($label,$with($copy($issue,$mutation)),false); }
        $expect('stale caller bytes',$with($issue,$context->contents.' '),false);$expect('foreign caller file',$with($issue,file:'foreign.php'),false);
        $expect('remembrance policy disabled',$context,false,new GuardedNullFlowIssueFilter($this->root,false));
        $expect('PHPDoc certainty disabled',$context,false,new GuardedNullFlowIssueFilter($this->root,true,false));
        $shift=static fn(\Mago\Sdk\SourceLocation $location):\Mago\Sdk\SourceLocation=>$copy($location,['span'=>new Span($location->span->start+1,$location->span->end)]);
        $caller=$certificate['caller']['native'];$target=$certificate['target']['native'];
        $mutations=['caller missing'=>[$caller,null],'caller name span shifted'=>[$caller,$copy($caller,['nameLocation'=>$shift($caller->nameLocation)])],
            'target missing'=>[$target,null],'target reference return'=>[$target,$copy($target,['flags'=>new MetadataFlags($target->flags->bits|MetadataFlags::BY_REFERENCE)])],
            'target source span shifted'=>[$target,$copy($target,['nameLocation'=>$shift($target->nameLocation)])],'target effective return missing'=>[$target,$copy($target,['returnType'=>null])]];
        if($role==='route') {
            $route=$certificate['zeroArgumentRequestRoute']['actualNativeDeclaration']['native']??null;
            if($route===null) { throw new \RuntimeException('Actual zero-argument native Request route certificate is missing.'); }
            $mutations['Request route declaration missing']=[$route,null];
            foreach(['doc provenance lost'=>['fromDocblock'=>false],'return inferred'=>['inferred'=>true]] as $label=>$mutation) { $mutations['Request route '.$label]=[$route,$copy($route,['returnType'=>$copy($route->returnType,$mutation)])]; }
            foreach([0,1] as $index) {
                $formal=$route->parameters[$index];
                foreach(['parameter-out'=>['outType'=>$formal->type],'reference'=>['flags'=>new MetadataFlags($formal->flags->bits|MetadataFlags::BY_REFERENCE)],
                    'default missing'=>['defaultType'=>null],'default not null'=>['defaultType'=>$copy($formal->defaultType,['type'=>Type::string()])]] as $label=>$mutation) {
                    $formals=$route->parameters;$formals[$index]=$copy($formal,$mutation);$mutations['Request route formal '.$index.' '.$label]=[$route,$copy($route,['parameters'=>$formals])];
                }
            }
            $receiving=$certificate['nativeReceivingReturn'];$mutations['receiving return absent']=[$receiving,$copy($receiving,['declaredReturnType'=>null])];
            $mutations['receiving return changed to integer']=[$receiving,$copy($receiving,['declaredReturnType'=>$copy($receiving->declaredReturnType,['type'=>Type::int()])])];
        } elseif($role==='console') {
            $receiving=$certificate['nativeReceivingCallable'];$formal=$receiving->parameters[0];
            $mutations['receiving method absent']=[$receiving,null];
            foreach(['receiver reference formal'=>['flags'=>new MetadataFlags($formal->flags->bits|MetadataFlags::BY_REFERENCE)],'receiver parameter-out'=>['outType'=>$formal->type],
                'receiver integer formal'=>['type'=>$copy($formal->type,['type'=>Type::int()])]] as $label=>$mutation) { $mutations[$label]=[$receiving,$copy($receiving,['parameters'=>[$copy($formal,$mutation)]])]; }
            foreach(['parse','parameters','parseOption','extractDescription'] as $method) { $metadata=$context->codebase->getDeclaringMethod('Illuminate\\Console\\Parser',$method);if($metadata===null) { throw new \RuntimeException('Actual console parser declaration missing: '.$method); }$mutations['console parser '.$method.' absent']=[$metadata,null]; }
        } else {
            $header=$certificate['literalHeaderPrimaryContract']??null;
            if($header===null) { throw new \RuntimeException('Actual literal-header primary certificate missing.'); }
            $property=$header['nativeHeaderBagProperty'];$hook=$property->hooks['set']??null;
            if($hook===null) { throw new \RuntimeException('Genuine header fixture does not exercise a setter-only property.'); }
            $mutations['header property absent']=[$property,null];
            $mutations['header native setter lost']=[$property,$copy($property,['hooks'=>[]])];
            $mutations['header native get hook added']=[$property,$copy($property,['hooks'=>['set'=>$hook,'get'=>$copy($hook,['name'=>'get'])]])];
            $mutations['header virtual property']=[$property,$copy($property,['flags'=>new MetadataFlags($property->flags->bits|MetadataFlags::VIRTUAL_PROPERTY)])];
            $mutations['header native read type contradicted']=[$property,$copy($property,['type'=>$copy($property->type,['type'=>Type::int()])])];
            foreach(['abstract'=>['abstract'=>true],'reference'=>['returnsByReference'=>true],'nearby source location'=>['location'=>$shift($hook->location)]] as $label=>$change) {
                $mutations['header setter '.$label]=[$property,$copy($property,['hooks'=>['set'=>$copy($hook,$change)]])];
            }
            $get=$header['nativeHeaderBagGet'];$mutations['HeaderBag get missing']=[$get,null];
            $mutations['HeaderBag declared return contradicted']=[$get,$copy($get,['declaredReturnType'=>$copy($get->declaredReturnType,['type'=>Type::union(Type::int(),Type::null())])])];
            $retrieve=$header['nativeRetrieveItem'];$mutations['header retrieveItem missing']=[$retrieve,null];
        }
        $cache=(new \ReflectionProperty($context->codebase,'cache'))->getValue($context->codebase);$values=$cache->values;$relations=$cache->relations;
        foreach($mutations as $label=>[$observed,$replacement]) {
            $changed=0;foreach($values as $operation=>$entries) { foreach($entries as $key=>$entry) { if($entry===$observed||is_object($entry)&&$entry==$observed) { $cache->values[$operation][$key]=$replacement;$changed++; } } }
            try { if($changed===0) { throw new \RuntimeException('Vacuous actual native cache control: '.$label); }$expect($label,$context,false);$changes[$label]=$changed; }
            finally { $cache->values=$values;$cache->relations=$relations; }
        }
        if($role==='route') {
            $bound=$certificate['zeroArgumentRequestRoute']['actualNativeDeclaration'];$path=str_replace('\\','/',$bound['file']);if(!preg_match('~^(?:[A-Za-z]:/|/)~',$path)) { $path=$this->root.'/'.$path; }
            $bytes=file_get_contents($path);$find='is_null($route)';$replacement='is_bool($route)';
            if(substr_count($bytes,$find)!==1||strlen($find)!==strlen($replacement)) { throw new \RuntimeException('Route source mutation is vacuous or shifts positions.'); }
            try { file_put_contents($path,str_replace($find,$replacement,$bytes));$expect('current Request route body changed',$context,false); }
            finally { file_put_contents($path,$bytes); }
        } elseif($role==='header') {
            $property=$certificate['literalHeaderPrimaryContract']['nativeHeaderBagProperty'];$path=str_replace('\\','/',$property->nameLocation->file);if(!preg_match('~^(?:[A-Za-z]:/|/)~',$path)) { $path=$this->root.'/'.$path; }
            $bytes=file_get_contents($path);$find='$this->headers = $value;';$replacement='$this->foreign = $value;';
            if(substr_count($bytes,$find)!==1||strlen($find)!==strlen($replacement)) { throw new \RuntimeException('Header backing source mutation is vacuous or shifts positions.'); }
            try { file_put_contents($path,str_replace($find,$replacement,$bytes));$expect('header setter no longer backs the selected property',$context,false); }
            finally { file_put_contents($path,$bytes); }
        }
        $expect('all native/cache/source values restored',$context,true);
        file_put_contents($this->output.'/native-controls-'.$role.'.json',json_encode(['originalGenuineSdkIssue'=>FrameworkNullFlowObserver::value($issue),'sourceSha256'=>hash('sha256',$context->contents),
            'checks'=>$checks,'actualCacheVariantsChanged'=>$changes,'positiveNativeDTOsFabricated'=>false],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
        return IssueFilterDecision::Keep;
    }
}
