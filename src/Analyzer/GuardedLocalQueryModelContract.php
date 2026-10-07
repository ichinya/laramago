<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\{IssueFilterContext,Type};
use Mago\Sdk\Analyzer\Metadata\{ClassLikeKind,FunctionLikeKind,MetadataFlags};
use Mago\Sdk\Analyzer\Type\{NamedObjectType,SimpleAtomicType,SimpleAtomicTypeKind};
use PhpParser\{Node,NodeFinder};

/** One current guarded local producer. This never constructs a provider context. */
final class GuardedLocalQueryModelContract
{
    public array $stages=[];public array $dependencies=[];public array $certificate=[];
    public function __construct(private readonly string $root){}
    public function localModel(IssueFilterContext $context,GuardedStringCastContracts $source,array $file,array $tuple,
        object $owner,Node\Expr $value,Node\Expr\Assign $append):?Type
    {
        $syntax=new LiteralGuardedTupleModelProjection;$declarations=new StandardEloquentFirstDeclarationContracts($this->root);
        $certificate=[];$dependencies=[];$readStages=[];$sourceHashes=[];
        try{
            $local=$syntax->lexical($source,$file,$tuple,$value,$append);if($local===null){return null;}
            $result=$declarations->forLiteralQuery($context,$source,$file,$local['query']);
            if($result===null||!$declarations->preservesLiteralOperations($context,$source,$file,$local)){return null;}
            // Remove only the null alternative whose immediately terminating
            // branch was established from current physical source above.
            $concrete=[];$nulls=0;
            foreach($result->atomicTypes as $atomic){
                if($atomic instanceof SimpleAtomicType&&$atomic->kind===SimpleAtomicTypeKind::Null){$nulls++;continue;}
                if(!$atomic instanceof NamedObjectType||strcasecmp($atomic->name,$local['model'])!==0||$atomic->static||$atomic->isThis
                    ||($atomic->parameters??[])!==[]||($atomic->intersections??[])!==[]||$atomic->remappedParameters){return null;}
                $concrete[]=$atomic;
            }
            if(count($concrete)!==1||$nulls!==1||$result->flags->byReference||$result->flags->possiblyUndefined){return null;}
            foreach($local['reads'] as $number=>$read){
                $bound=$this->readFormal($context,$source,$file,$owner,$read['argument']);
                $readStages[]=['stage'=>'current physical by-value local read '.$number,'passed'=>$bound!==null];
                if($bound===null){return null;}$dependencies['localRead:'.$number]=$bound['native'];
                $sourceHashes[$bound['path']]=$bound['hash'];
            }
            if(!$declarations->current()||hash_file('sha256',$file['path'])!==$file['hash']){return null;}
            $domain=Type::fromAtomic($concrete[0]);
            $declarationCertificate=$declarations->certificate;$declarationCertificate['sourceHashes']=$declarations->sourceHashes();
            $certificate=['model'=>$local['model'],'domain'=>(string)$domain,'originSpan'=>$local['originSpan'],
                'nullGuardSpan'=>$local['nullGuardSpan'],'appendSpan'=>$local['appendSpan'],'sourceSha256'=>$file['hash'],
                'declaration'=>$declarationCertificate,'fakeProviderContextUsed'=>false,'nativeTypesReplaced'=>false,
                'afterFileAuthority'=>false,'sourceProducerCertificate'=>true,
                'sourceHashes'=>[$file['path']=>$file['hash']]+$declarations->sourceHashes()+$sourceHashes];
            return $domain;
        }finally{
            $this->stages=array_merge($syntax->stages,$declarations->stages,$readStages);
            $this->dependencies=$declarations->dependencies+$dependencies;$this->certificate=$certificate;
        }
    }
    /** The local variable itself cannot escape through an unproved formal. */
    private function readFormal(IssueFilterContext $context,GuardedStringCastContracts $source,array $file,object $owner,Node\Arg $argument):?array
    {
        $call=$file['parents'][spl_object_id($argument)]??null;$native=null;
        if(!$call instanceof Node\Expr\CallLike||!SourceArgumentDeclarationContracts::plain($call)){return null;}
        if($call instanceof Node\Expr\MethodCall&&$call->var instanceof Node\Expr\Variable&&$call->var->name==='this'&&$call->name instanceof Node\Identifier){
            $bound=$context->codebase->getMethod($owner->identifier->class,$call->name->name);
            $declaring=$context->codebase->getDeclaringMethod($owner->identifier->class,$call->name->name);$native=$bound??$declaring;
        }elseif($call instanceof Node\Expr\StaticCall&&$call->class instanceof Node\Name&&!$call->class->isSpecialClassName()&&$call->name instanceof Node\Identifier){
            $bound=$context->codebase->getMethod($call->class->toString(),$call->name->name);
            $declaring=$context->codebase->getDeclaringMethod($call->class->toString(),$call->name->name);$native=$bound??$declaring;
        }else{return null;}
        if($native===null||($bound!==null&&$declaring!==null&&$bound!=$declaring)||$native->kind!==FunctionLikeKind::Method
            ||$native->abstract||$native->flags->contains(MetadataFlags::BY_REFERENCE)||$native->flags->contains(MetadataFlags::BUILTIN)
            ||$native->assertions!==[]||$native->ifTrueAssertions!==[]||$native->ifFalseAssertions!==[]){return null;}
        $index=array_search($argument,$call->args,true);$formal=$index===false?null:($native->parameters[$index]??null);
        if($formal===null||$formal->outType!==null||$formal->closureThisType!==null||$formal->flags->contains(MetadataFlags::BY_REFERENCE)
            ||$formal->flags->contains(MetadataFlags::VARIADIC)){return null;}
        $physical=$source->read($native->location->file);if($physical===null){return null;}
        $methods=array_values(array_filter((new NodeFinder)->findInstanceOf($physical['nodes'],Node\Stmt\ClassMethod::class),
            static fn(Node\Stmt\ClassMethod $syntax):bool=>$source->located($native->location,$syntax,$physical)&&$source->located($native->nameLocation,$syntax->name,$physical)));
        if(count($methods)!==1){return null;}$method=$methods[0];$parameter=$method->params[$index]??null;
        $class=null;foreach($source->ancestors($method,$physical) as $ancestor){if($ancestor instanceof Node\Stmt\ClassLike){$class=$ancestor;break;}}
        $nativeClass=$class===null?null:$context->codebase->getClassLike($class->namespacedName?->toString()??'');
        if(!$class instanceof Node\Stmt\Class_||$nativeClass===null||$nativeClass->kind!==ClassLikeKind::Class_||$nativeClass->hasIncompleteHierarchy()
            ||strcasecmp($nativeClass->name,$class->namespacedName?->toString()??'')!==0
            ||!$source->located($nativeClass->nameLocation,$class->name,$physical)
            ||!$source->located($nativeClass->location,$class,$physical)
            ||$class->namespacedName?->toString()!==$native->identifier->class||$method->name->name!==$native->identifier->name||$method->byRef
            ||$method->isStatic()!==$native->static||count($method->params)!==count($native->parameters)
            ||$parameter===null||!$parameter->var instanceof Node\Expr\Variable||!is_string($parameter->var->name)||$parameter->byRef||$parameter->variadic
            ||$formal->name!=='$'.$parameter->var->name||!$source->located($formal->location,$parameter,$physical)||!$source->located($formal->nameLocation,$parameter->var,$physical)
            ||preg_match('/@(?:phpstan-|psalm-)?(?:param-out|assert(?:-if-true|-if-false)?|self-out|this-out)\b/',$method->getDocComment()?->getText()??'')===1){return null;}
        if($parameter->type===null?$formal->declaredType!==null:!$source->located($formal->declaredType?->location,$parameter->type,$physical)){return null;}
        return hash_file('sha256',$physical['path'])===$physical['hash']?['native'=>$native,'path'=>$physical['path'],'hash'=>$physical['hash']]:null;
    }
}
