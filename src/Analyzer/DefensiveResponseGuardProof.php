<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;
use Ichinya\Laramago\Analyzer\{DefensiveBoundaryGuardSource as Source,DefensiveBoundaryGuardProof as Boundary,TestResponseCallbackProvider};
use Mago\Sdk\Analyzer\{IssueFilterContext,Type};
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Reporting\{Level,AnnotationKind};
use PhpParser\{Node,NodeFinder};

/** Uses the existing physical page contract; only defensive Warning reporting changes. */
final class DefensiveResponseGuardProof
{
    private readonly TestResponseCallbackProvider $httpTests;
    public function __construct(private readonly string $root){$this->httpTests=new TestResponseCallbackProvider($root);}
    public function prove(IssueFilterContext $context):array
    {
        $r=['remove'=>false,'stage'=>'native-warning-envelope','nativeTypesChanged'=>false,'macroExecuted'=>false];$issue=$context->issue;$a=count($issue->annotations)===1?$issue->annotations[0]:null;
        if($context->cancellation->isCancelled()||$issue->level!==Level::Warning||$a===null||$a->kind!==AnnotationKind::Primary||$a->file!==null&&$a->file!==''){return $r;}
        try{$nodes=Source::parse($context->contents);$proofs=DefensiveResponseGuardSource::compile($nodes);}catch(\Throwable){return $r;}$p=$proofs[Source::key([$a->span->start,$a->span->end])]??null;if($p===null){return $r;}$r['source']=$p;
        $envelope=$p;if($issue->code==='impossible-condition'&&$p['selectedSite']['kind']==='negated-predicate'){$envelope['selectedSite']['kind']='condition';}
        if(!Boundary::envelope($context,$envelope)){return $r;}
        $finder=new NodeFinder;$calls=[];foreach($p['predicates'] as $predicate){$calls[]=$finder->findFirst($nodes,static fn(Node $n):bool=>Source::span($n)===$predicate['span']);}unset($nodes,$finder);
        $r['stage']='current-physical-caller';$caller=$context->codebase->getDeclaringMethod($p['scope']['class'],$p['scope']['name']);$owner=$context->codebase->getClass($p['scope']['class']);
        if($caller===null||$owner===null||$owner->hasIncompleteHierarchy()||$caller->static||$caller->flags->contains(MetadataFlags::BY_REFERENCE)||strcasecmp($caller->identifier->class??'',$p['scope']['class'])!==0
            || self::path($caller->location->file)!==self::path($context->file)||!in_array($caller->location->span->start,$p['scope']['startAlternatives'],true)||$caller->location->span->end!==$p['scope']['span'][1]||count($caller->parameters)!==1){return $r;}
        $formal=$caller->parameters[0];$expected=Type::namedObject($p['class']);if($formal->name!=='$'.$p['formalName']||$formal->flags->contains(MetadataFlags::BY_REFERENCE)||$formal->flags->contains(MetadataFlags::VARIADIC)||$formal->outType!==null||$formal->closureThisType!==null
            ||$formal->declaredType===null||$formal->declaredType->fromDocblock||$formal->declaredType->inferred||$formal->type===null||[$formal->location->span->start,$formal->location->span->end]!==$p['formal']
            ||!$context->types->equals($formal->declaredType->type,$expected)||!$context->types->equals($formal->type->type,$expected)){return $r;}
        $r['stage']='current-response-forwarder';$class=$context->codebase->getClass($p['class']);$method=$context->codebase->getDeclaringMethod($p['class'],'inertiaPage');
        if($class===null||$class->hasIncompleteHierarchy()||$method===null||$method->static||$method->abstract||$method->templates!==[]||$method->flags->contains(MetadataFlags::BY_REFERENCE)||strcasecmp($method->identifier->class??'',$p['class'])!==0
            ||!str_ends_with(self::path($method->location->file),'/ichinya/laratesto/src/testing/laravelresponse.php')||$method->returnType===null||!$context->types->equals($method->returnType->type,Type::mixed())||count($method->parameters)!==1){return $r;}
        $key=$method->parameters[0];$keyType=Type::union(Type::string(),Type::null());if($key->name!=='$key'||!$key->flags->contains(MetadataFlags::HAS_DEFAULT)||$key->flags->contains(MetadataFlags::BY_REFERENCE)||$key->flags->contains(MetadataFlags::VARIADIC)||$key->outType!==null||$key->closureThisType!==null
            ||$key->declaredType===null||$key->declaredType->fromDocblock||$key->declaredType->inferred||$key->type===null||$key->defaultType===null
            ||!$context->types->equals($key->declaredType->type,$keyType)||!$context->types->equals($key->type->type,$keyType)||!$context->types->equals($key->defaultType->type,Type::null())){return $r;}
        foreach($context->codebase->getMultipleClasses([$p['class'],...$context->codebase->getClassAncestors($p['class'])]) as $ancestor){if($ancestor===null){return $r;}foreach([...$ancestor->pseudoMethods,...$ancestor->staticPseudoMethods] as $name){if(strcasecmp($name,'inertiaPage')===0){return $r;}}}
        $r['stage']='existing-native-inertia-page-source-contract';if(!$this->httpTests->supportsInertiaPage($context->codebase,$method)){return $r;}$r['pageContract']='existing TestResponseCallbackProvider::supportsInertiaPage with current forwarder metadata, source body, native page properties, package factory and macro override guards';
        $r['stage']='native-defensive-predicates';foreach($calls as $call){if(!$call instanceof Node\Expr\FuncCall||Boundary::builtin($context,$call,'is_array')===null){return $r;}}
        $exception=$context->codebase->getClass($p['exception']);if($exception===null||$exception->hasIncompleteHierarchy()||!$exception->flags->contains(MetadataFlags::BUILTIN)||$exception->flags->contains(MetadataFlags::USER_DEFINED)){return $r;}
        $r['stage']='current-native-response-defensive-reporting-policy';$r['remove']=true;return $r;
    }
    private static function path(?string $path):string{return strtolower(str_replace('\\','/',$path??''));}
}
