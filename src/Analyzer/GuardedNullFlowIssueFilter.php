<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\{NullFlowNativeContracts,SourceNullFacts};
use Mago\Sdk\Analyzer\{InitializationContext,InitializationHook,IssueFilterContext,IssueFilterDecision,IssueFilterHook,Plugin,PluginDefinition,PluginRegistry,Type};
use Mago\Sdk\Analyzer\Metadata\{FunctionLikeMetadata,MetadataFlags};
use Mago\Sdk\Reporting\{AnnotationKind,Safety};

/** PHPStan-default remembrance of a source-bound guarded expression. Native receiving contracts remain intact. */
final class GuardedNullFlowIssueFilter implements Plugin,InitializationHook,IssueFilterHook
{
    private NullFlowNativeContracts $contracts;
    private ?array $lastObservation=null;
    public function __construct(private readonly string $root='.',private readonly bool $rememberPossiblyImpureFunctionValues=true,private readonly bool $treatPhpDocTypesAsCertain=true) { $this->contracts=new NullFlowNativeContracts($root); }
    public function getDefinition():PluginDefinition { return new PluginDefinition('ichinya/guarded-null-flow','Guarded null flow','Bind guarded retained expressions to current native declarations.'); }
    public function register(PluginRegistry $registry):void { $registry->registerInitializationHook($this);$registry->registerIssueFilterHook($this); }
    public function initialize(InitializationContext $context):void { $this->contracts->clear();$this->lastObservation=null; }
    public function getCodes():array { return ['invalid-return-statement','nullable-return-statement','possible-method-access-on-null','possibly-null-argument','possibly-null-property-access']; }
    /** Observation only. This result never authorizes another decision. */
    public function observation():?array { return $this->lastObservation; }
    public function filterIssue(IssueFilterContext $context):IssueFilterDecision {
        // RPCs may reenter this hook. Every authorization input and result is local to this evaluation.
        $result=$this->evaluate($context);$this->lastObservation=$result;
        return $result['remove']?IssueFilterDecision::Remove:IssueFilterDecision::Keep;
    }
    private function evaluate(IssueFilterContext $context):array {
        $keep=static fn(string $stage,array $details=[]):array=>['remove'=>false,'stage'=>$stage]+$details;
        $issue=$context->issue;
        if(!$this->rememberPossiblyImpureFunctionValues||!$this->treatPhpDocTypesAsCertain) { return $keep('compatibility policy disabled'); }
        if($issue->level->name!=='Error'||!in_array($issue->code,$this->getCodes(),true)||$issue->link!==null||strlen($issue->message)>4096
            ||strlen($context->contents)>524_288||!$this->contracts->current($context->file,$context->contents)||$issue->annotations===[]) { return $keep('complete native envelope/current source'); }
        $primary=$issue->annotations[0];
        if($primary->kind!==AnnotationKind::Primary||!self::annotationFile($primary->file,$context->file)||$primary->span->start<0||$primary->span->end>strlen($context->contents)||$primary->span->start>=$primary->span->end) { return $keep('Primary identity'); }
        $span=NullFlowNativeContracts::span($primary->span);$proofs=(new SourceNullFacts)->proofs($context->contents)[$span[0].':'.$span[1]]??[];
        if(count($proofs)!==1) { return $keep('one current lexical proof',['sourceProofCount'=>count($proofs)]); }
        $proof=$proofs[0];$source=$this->contracts->source($context->file,$context->contents);$certificate=[];
        if(!$this->envelope($context,$proof,$source,$certificate)) { return $keep('exact code-specific envelope/source use',['sourceProof'=>$proof]); }
        if(!$this->contracts->verify($context,$proof,$certificate)) { return $keep('source/native null-flow contract',['sourceProof'=>$proof,'partialCertificate'=>$certificate]); }
        if(!$this->contracts->receivingContract($context,$proof,$certificate)) { return $keep('refined output receiving contract',['sourceProof'=>$proof,'partialCertificate'=>$certificate]); }
        if(!$this->contracts->current($context->file,$context->contents)) { return $keep('source changed after native requests'); }
        return ['remove'=>true,'stage'=>'complete local source/native certificate','sourceProof'=>$proof,'certificate'=>$certificate];
    }
    private function envelope(IssueFilterContext $context,array $proof,array $source,array &$certificate):bool {
        $issue=$context->issue;$primary=$issue->annotations[0];$span=NullFlowNativeContracts::span($primary->span);
        if($issue->code==='possible-method-access-on-null') {
            if($issue->message!=='Attempting to call a method on `null`.'||$issue->notes!==[]||$issue->help!=='Use the nullsafe operator (`?->`) if `null` is an expected value.'
                ||count($issue->annotations)!==1||$issue->edits!==[]||$primary->message!=='This expression can be `null`') { return false; }
            $uses=array_values(array_filter($source['calls']??[],static fn(array $call):bool=>$call['kind']==='method'&&$call['receiverSpan']===$span));
            if(count($uses)!==1) { return false; }$certificate['sourceUse']=$uses[0];return true;
        }
        if($issue->code==='possibly-null-property-access') {
            if($issue->message!=='Attempting to access a property on a possibly `null` value.'||$issue->notes!==['If this expression is `null` at runtime, PHP will raise a warning and the property access will result in `null`.']
                ||$issue->help!=='Use the nullsafe operator (`?->`) to safely access the property, or add a check to ensure the value is not `null` (e.g., `if ($obj !== null)`).'
                ||count($issue->annotations)!==1||count($issue->edits)!==1||$primary->message!=='This expression can be `null` here') { return false; }
            $edit=$issue->edits[0];if($edit->newText!=='?->'||$edit->safety!==Safety::Safe||!self::annotationFile($edit->file,$context->file)||NullFlowNativeContracts::span($edit->span)!==[$span[1],$span[1]+2]
                ||substr($context->contents,$span[1],2)!=='->') { return false; }
            $uses=array_values(array_filter($source['accesses']??[],static fn(array $access):bool=>$access['receiverSpan']===$span));
            if(count($uses)!==1) { return false; }$certificate['sourceUse']=$uses[0];$certificate['genuineExactSafeNullsafeEdit']=true;return true;
        }
        if($issue->code==='invalid-return-statement') {
            if($issue->edits!==[]||count($issue->annotations)!==1||$proof['scope']['topLevel']
                ||preg_match('/^Invalid return type for function `([^`]+)`: expected `([^`]+)`, but found `([^`]+)`\.$/D',$issue->message,$match)!==1||!in_array('null',explode('|',$match[3]),true)) { return false; }
            $expectedName=($proof['scope']['owner']===null?'':$proof['scope']['owner'].'::').$proof['scope']['name'];
            if($match[1]!==$expectedName||$primary->message!=='This has type `'.$match[3].'`'||$issue->notes!==['The type `'.$match[3].'` returned here is not compatible with the declared return type `'.$match[2].'`.']
                ||$issue->help!=='Change the return value to match `'.$match[2].'`, or update the function\'s return type declaration.') { return false; }
            $callee=$proof['scope']['owner']===null?$context->codebase->getFunction($proof['scope']['name']):$context->codebase->getDeclaringMethod($proof['scope']['owner'],$proof['scope']['name']);
            if($callee===null||$callee->declaredReturnType===null||(string)$callee->declaredReturnType->type!==$match[2]||NullFlowNativeContracts::containsNull($callee->declaredReturnType->type)) { return false; }
            $certificate['nativeReceivingReturn']=$callee;return true;
        }
        if($issue->code==='nullable-return-statement') {
            if($issue->edits!==[]||count($issue->annotations)!==2||$primary->message!=='Nullable value returned here.'||$proof['scope']['topLevel']) { return false; }
            if(preg_match('/^Function `([^`]+)` is declared to return `([^`]+)` but possibly returns a nullable value \(inferred as `([^`]+)`\)\.$/D',$issue->message,$match)!==1) { return false; }
            $expectedName=($proof['scope']['owner']===null?'':$proof['scope']['owner'].'::').$proof['scope']['name'];
            $secondary=$issue->annotations[1];
            if($match[1]!==$expectedName||!str_contains($match[3],'null')||$issue->notes!==["The declared return type does not permit null, but the analysis indicates that 'null' or a nullable type could be returned from this path."]
                ||$issue->help!=="You can either change the return type declaration of `".$expectedName."` to be nullable (e.g., '?string'), or ensure that this function path always returns a non-null value."
                ||$secondary->kind!==AnnotationKind::Secondary||$secondary->message!=='Return type declared as non-nullable `'.$match[2].'` here.'||!self::annotationFile($secondary->file,$context->file)
                ||NullFlowNativeContracts::span($secondary->span)!==$proof['scope']['span']) { return false; }
            $callee=$proof['scope']['owner']===null?$context->codebase->getFunction($proof['scope']['name']):$context->codebase->getDeclaringMethod($proof['scope']['owner'],$proof['scope']['name']);
            if($callee===null||$callee->declaredReturnType===null||(string)$callee->declaredReturnType->type!==$match[2]||NullFlowNativeContracts::containsNull($callee->declaredReturnType->type)) { return false; }
            $certificate['nativeReceivingReturn']=$callee;return true;
        }
        if($issue->code!=='possibly-null-argument'||$issue->edits!==[]||$issue->notes!==[]||count($issue->annotations)!==2
            ||$issue->help!=='Add a `null` check before this call to ensure the value is not `null`.') { return false; }
        if(preg_match('/^Argument #([1-9][0-9]*) of (function|method) `([^`]+)` is possibly `null`, but parameter type `([^`]+)` does not accept it\.$/D',$issue->message,$match)!==1
            ||preg_match('/^This argument of type `([^`]+)` might be `null`$/D',$primary->message??'',$actual)!==1||!in_array('null',explode('|',$actual[1]),true)) { return false; }
        $index=(int)$match[1]-1;$secondary=$issue->annotations[1];
        if($index<0||$index>63||$secondary->kind!==AnnotationKind::Secondary||$secondary->message!=='Arguments to this '.$match[2].' are incorrect'||!self::annotationFile($secondary->file,$context->file)) { return false; }
        $uses=[];foreach($source['calls']??[] as $call) {
            if(($call['arguments'][$index]['value']['span']??null)!==$span) { continue; }
            $function=$call['kind']==='function';if(($match[2]==='function')!==$function) { continue; }
            if(NullFlowNativeContracts::span($secondary->span)!==($function?$call['nameSpan']:$call['span'])) { continue; }$uses[]=$call;
        }
        if(count($uses)!==1) { return false; }$call=$uses[0];$scope=$proof['scope'];
        if($call['kind']==='function') {
            if(strcasecmp(ltrim($call['name'],'\\'),$match[3])!==0) { return false; }$native=$context->codebase->getFunction($call['name']);
            if($native===null||!$native->flags->contains(MetadataFlags::BUILTIN)) { return false; }
        } else {
            $bound=$this->contracts->expressionBound($context,$call,$scope,$certificate);$native=$bound['native']??null;
            if(!$native instanceof FunctionLikeMetadata||strcasecmp(($native->identifier->class??'').'::'.$native->identifier->name,$match[3])!==0) { return false; }
        }
        $formal=$native->parameters[$index]??null;
        if($formal===null||$formal->type===null||$formal->flags->contains(MetadataFlags::BY_REFERENCE)||$formal->flags->contains(MetadataFlags::VARIADIC)||$formal->outType!==null
            ||(string)$formal->type->type!==$match[4]||NullFlowNativeContracts::containsNull($formal->type->type)) { return false; }
        $certificate['sourceUse']=$call;$certificate['nativeReceivingCallable']=$native;$certificate['nativeReceivingParameterIndex']=$index;return true;
    }
    private static function annotationFile(?string $file,string $contextFile):bool { return $file===null||$file===''||NullFlowNativeContracts::sameFile($file,$contextFile); }
}
