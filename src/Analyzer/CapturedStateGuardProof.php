<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\{DefensiveBoundaryGuardProof,DefensiveBoundaryGuardSource,DefensiveBoundarySourceProfile};
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\{IssueFilterContext,Type};
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Reporting\{AnnotationKind,Level};
use PhpParser\{Node,NodeFinder};

/** Reporting policy for selected captured-state guards. Native values, signatures and Errors stay intact. */
final class CapturedStateGuardProof
{
    public function __construct(private readonly string $root){}
    public function prove(IssueFilterContext $context):array
    {
        $receipt=['remove'=>false,'stage'=>'current-native-warning','policy'=>'defensive captured state reporting','nativeTypesChanged'=>false,'callbackExecutionProven'=>false,'runtimePurityProven'=>false,'source'=>null];
        $issue=$context->issue;$annotation=count($issue->annotations)===1?$issue->annotations[0]:null;
        if($context->cancellation->isCancelled() || $issue->level!==Level::Warning || $annotation===null || $annotation->kind!==AnnotationKind::Primary || ($annotation->file!==null && $annotation->file!=='') || strlen($context->contents)>2000000){return $receipt;}
        try{$nodes=DefensiveBoundaryGuardSource::parse($context->contents);$proofs=CapturedStateGuardSource::compile($nodes);}catch(\Throwable){return $receipt;}
        $proof=$proofs[CapturedStateGuardSource::key([$annotation->span->start,$annotation->span->end])]??null;if($proof===null){return $receipt;}
        $receipt['source']=$proof;$receipt['stage']='exact-native-warning-envelope';$envelope=$proof;
        if($issue->code==='redundant-condition'&&$proof['family']==='captured-written-value'&&$proof['selectedSite']['kind']==='negated-predicate'){$envelope['selectedSite']['kind']='condition';}
        if(!DefensiveBoundaryGuardProof::envelope($context,$envelope)){return $receipt;}
        $spans=array_column($proof['predicates'],'span');if($proof['objectId']!==null){$spans[]=$proof['objectId']['call'];}
        $selected=[];$finder=new NodeFinder;foreach($spans as $span){$node=$finder->findFirst($nodes,static fn(Node $node):bool=>CapturedStateGuardSource::span($node)===$span);if($node!==null){$selected[CapturedStateGuardSource::key($span)]=$node;}}
        unset($nodes,$finder,$node,$spans);$at=static fn(array $span):?Node=>$selected[CapturedStateGuardSource::key($span)]??null;
        $receipt['stage']='current-physical-caller';if(!self::caller($context,$proof['scope'])){return $receipt;}
        
        if($proof['storage']!==null){$receipt['stage']='current-physical-callback-field';$field=$this->storage($context,$proof['storage']);$receipt['storage']=$field;if(!$field['admitted']){return $receipt;}}
        if($proof['family']==='captured-written-value'){
            $receipt['stage']='native-is-int-predicate';$predicate=$at($proof['predicates'][0]['span']);
            $native=$predicate instanceof Node\Expr\FuncCall?DefensiveBoundaryGuardProof::builtin($context,$predicate,'is_int'):null;
            $receipt['predicate']=$native===null?null:DefensiveBoundaryGuardProof::compact($native);if($native===null){return $receipt;}
            $receipt['stage']='current-native-rejection';if(!self::exception($context,$proof['exception'])){return $receipt;}
        }
        if($proof['family']==='recursive-visited-set'){
            $receipt['stage']='current-native-object-id';$node=$at($proof['objectId']['call']);$native=$context->codebase->getFunction('spl_object_id');
            $receipt['objectId']=$native===null?null:DefensiveBoundaryGuardProof::compact($native);
            if(!$node instanceof Node\Expr\FuncCall || !$node->name instanceof Node\Name || strcasecmp($node->name->toString(),'spl_object_id')!==0 || $native===null
                || !$native->flags->contains(MetadataFlags::BUILTIN) || $native->flags->contains(MetadataFlags::USER_DEFINED) || $native->flags->contains(MetadataFlags::BY_REFERENCE) || $native->templates!==[]
                || count($native->parameters)!==1 || $native->parameters[0]->flags->contains(MetadataFlags::BY_REFERENCE) || $native->parameters[0]->outType!==null
                || $native->parameters[0]->declaredType===null || $native->parameters[0]->type===null || (string)$native->parameters[0]->declaredType->type!=='object'
                || !$context->types->equals($native->parameters[0]->type->type,$native->parameters[0]->declaredType->type)
                || $native->returnType===null || $native->declaredReturnType===null || !$context->types->isContainedBy($native->returnType->type,Type::int()) || !$context->types->isContainedBy($native->declaredReturnType->type,Type::int())){return $receipt;}
            if(!$node->name instanceof Node\Name\FullyQualified){$qualified=$node->name->getAttribute('namespacedName');if($qualified instanceof Node\Name && strcasecmp($qualified->toString(),'spl_object_id')!==0 && $context->codebase->getFunction($qualified->toString())!==null){return $receipt;}}
        }
        $receipt['remove']=true;$receipt['stage']='source-and-current-native-contract-admitted';return $receipt;
    }
    private static function caller(IssueFilterContext $context,?array $scope):bool
    {
        if($scope===null){return true;}
        $native=$scope['class']===null?$context->codebase->getFunction($scope['name']):$context->codebase->getDeclaringMethod($scope['class'],$scope['name']);
        if($native===null || $native->flags->contains(MetadataFlags::BUILTIN) || $native->flags->contains(MetadataFlags::BY_REFERENCE) || $native->location->file===null
            || DefensiveBoundarySourceProfile::path($native->location->file)!==DefensiveBoundarySourceProfile::path($context->file)
            || !in_array($native->location->span->start,$scope['startAlternatives'],true) || $native->location->span->end!==$scope['span'][1]){return false;}
        if($scope['class']!==null){$class=$context->codebase->getClass($scope['class']);if($class===null || $class->hasIncompleteHierarchy()){return false;}}return true;
    }
    private function storage(IssueFilterContext $context,array $storage):array
    {return CapturedStatePhysicalCallback::prove($context,$this->root,$storage);}
    private static function exception(IssueFilterContext $context,?array $exception):bool
    {
        if($exception===null){return false;}$class=$context->codebase->getClass($exception['class']);$constructor=$context->codebase->getDeclaringMethod($exception['class'],'__construct');
        return $class!==null && !$class->hasIncompleteHierarchy() && $class->flags->contains(MetadataFlags::BUILTIN) && !$class->flags->contains(MetadataFlags::USER_DEFINED) && strcasecmp($class->directParentClass??'','Exception')===0
            && $constructor!==null && $constructor->flags->contains(MetadataFlags::BUILTIN) && !$constructor->static && strcasecmp($constructor->identifier->class??'','Exception')===0 && count($constructor->parameters)===3
            && $constructor->parameters[0]->name==='$message' && $constructor->parameters[0]->type!==null && $context->types->equals($constructor->parameters[0]->type->type,Type::string()) && !$constructor->parameters[0]->flags->contains(MetadataFlags::BY_REFERENCE);
    }
}
