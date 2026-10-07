<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\{GuardedStringCastContracts as Source,DefensiveBoundaryGuardProof as Boundary,SourceArgumentDeclarationContracts as Declaration,SelectedChunkCollectionContracts};
use Mago\Sdk\Analyzer\{IssueFilterContext,Type};
use Mago\Sdk\Analyzer\Metadata\{FunctionLikeMetadata,MetadataFlags};
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\Reporting\{AnnotationKind,Level};
use PhpParser\{Node,NodeFinder};


/** One standard chunk model receiver and a physically declared zero-argument method. */
final class SelectedChunkModelWarningProof
{
    public function __construct(private readonly string $root){}
    public function prove(IssueFilterContext $context):array
    {
        $r=['remove'=>false,'stage'=>'exact-model-method-Warning','nativeTypesChanged'=>false,'afterFileAuthority'=>false,'opaqueClosureIdentifierClaimed'=>false,'callbackExecuted'=>false,'callerWholeAstRetained'=>false];$issue=$context->issue;
        if($context->cancellation->isCancelled()||$issue->level!==Level::Warning||$issue->code!=='non-documented-method'||$issue->link!==null||$issue->edits!==[]||count($issue->annotations)!==2||preg_match('/^Ambiguous method call to `([a-zA-Z_][a-zA-Z0-9_]*)` on class `Illuminate\\\\Database\\\\Eloquent\\\\Model`\.$/D',$issue->message,$match)!==1){return $r;}
        [$primary,$secondary]=$issue->annotations;$method=$match[1];
        if($primary->kind!==AnnotationKind::Primary||$secondary->kind!==AnnotationKind::Secondary||$primary->file!==null&&$primary->file!==''||$secondary->file!==null&&$secondary->file!==''||$primary->message!=='This method is not explicitly defined'||$secondary->message!=='On an object of type `Illuminate\\Database\\Eloquent\\Model`'||$issue->notes!==['While this call might be handled by `__call()` or `__callStatic()`, Mago cannot verify its arguments or return type without a corresponding `@method` docblock tag.']||$issue->help!=='To enable full analysis, add a `@method` tag to the docblock of the `Illuminate\\Database\\Eloquent\\Model` class. For example: `/** @method returnType '.$method.'(argType $argName) */`'){return $r;}
        $source=new Source($this->root);$r['stage']='current-physical-selected-source';$file=$source->read($context->file,$context->contents);if($file===null){return $r;}
        $finder=new NodeFinder;$calls=$finder->find($file['nodes'],static fn(Node $n):bool=>$n instanceof Node\Expr\MethodCall&&$n->name instanceof Node\Identifier&&Declaration::span($n->name)===[$primary->span->start,$primary->span->end]&&Declaration::span($n->var)===[$secondary->span->start,$secondary->span->end]&&strcasecmp($n->name->name,$method)===0);
        if(count($calls)!==1){return $r;}$lexical=new SelectedChunkModelWarningSource($this->root);$p=$lexical->lexical($source,$file,$calls[0]);$r['sourceStages']=$lexical->stages;unset($file,$finder,$calls,$lexical);if($p===null){return $r;}
        $r['sourceCertificate']=array_diff_key($p,['file'=>true,'lexical'=>true]);$r['stage']='genuine-standard-hydrated-chunk-domain';$chunk=new SelectedChunkCollectionContracts($this->root);$domain=$chunk->domain($context,$source,$p['file'],$p['lexical']);$r['chunkStages']=$chunk->stages;$r['chunkCertificate']=$chunk->certificate;
        if($domain===null||!$chunk->current()){return $r;}$caller=$chunk->dependencies['owner'];$class=$caller->identifier->class;$r['domain']=(string)$domain;
        $r['stage']='physical-read-only-receiving-helper';$helper=$context->codebase->getDeclaringMethod($class,$p['helper']['name']);
        if(!$this->method($source,$helper,$p['helper'],$p['file']['path'])||strcasecmp($helper->identifier->class??'',$class)!==0||$helper->static||$helper->abstract||count($helper->parameters)!==1||$helper->visibility!==($p['helper']['public']?Visibility::Public:($p['helper']['protected']?Visibility::Protected:Visibility::Private))){return $r;}
        $formal=$helper->parameters[0];$fp=$p['helper']['parameters'][0];
        if($formal->name!==$fp['name']||$formal->flags->contains(MetadataFlags::BY_REFERENCE)||$formal->flags->contains(MetadataFlags::VARIADIC)||$formal->outType!==null||$formal->closureThisType!==null||!$this->location($source,$formal->location,$fp['span'],$p['file']['path'])||!$this->location($source,$formal->nameLocation,$fp['nameSpan'],$p['file']['path'])||!$this->location($source,$formal->declaredType?->location,$fp['typeSpan'],$p['file']['path'])||$formal->declaredType===null||$formal->type===null||!$context->types->isContainedBy($formal->type->type,$formal->declaredType->type)||!$context->types->isContainedBy($domain,$formal->type->type)){return $r;}
        $r['stage']='selected-concrete-declared-method';$callee=$context->codebase->getMethod($p['model'],$p['method'])??$context->codebase->getDeclaringMethod($p['model'],$p['method']);$physical=$callee===null?null:$source->read($callee->location->file);
        if($physical===null){return $r;}$methods=(new NodeFinder)->findInstanceOf($physical['nodes'],Node\Stmt\ClassMethod::class);$selected=[];
        foreach($methods as $candidate){$profile=SelectedChunkModelWarningSource::method($candidate);if(!$this->method($source,$callee,$profile,$physical['path'])){continue;}$owner=null;foreach($source->ancestors($candidate,$physical) as $parent){if($parent instanceof Node\Stmt\ClassLike){$owner=$parent;break;}}if($owner?->namespacedName?->toString()===$p['model']){$selected[]=[$profile,$candidate->returnType instanceof Node\Identifier?strtolower($candidate->returnType->name):null];}}
        $held=['path'=>$physical['path'],'hash'=>$physical['hash']];unset($physical,$methods,$candidate,$owner,$parent,$profile);
        if(count($selected)!==1||$selected[0][1]!=='void'||$selected[0][0]['parameters']!==[]||!$selected[0][0]['public']||$selected[0][0]['static']||$callee->visibility!==Visibility::Public||$callee->static||$callee->abstract||$callee->parameters!==[]||strcasecmp($callee->identifier->class??'',$p['model'])!==0||$callee->declaredReturnType===null||!$context->types->equals($callee->declaredReturnType->type,Type::void())){return $r;}
        if(hash_file('sha256',$held['path'])!==$held['hash']||hash_file('sha256',$p['file']['path'])!==$p['file']['hash']||!$chunk->current()){return $r;}
        $r['stage']='genuine-selected-chunk-model-profile-pending';foreach(['receiving-helper'=>$helper,'selected-model-method'=>$callee,'chunkById'=>$chunk->dependencies['chunkById'],'orderedChunkById'=>$chunk->dependencies['orderedChunkById']] as $symbol=>$native){$compact=Boundary::compact($native);$r['observedNativeProfiles'][$symbol]=['sha256'=>self::hash($compact),'compact'=>$compact];}
        $r['stage']='current-declared-method-on-selected-chunk-model';$r['remove']=true;return $r;
    }
    private function method(Source $source,?FunctionLikeMetadata $native,array $p,string $path):bool
    {return $native!==null&&!$native->flags->contains(MetadataFlags::BUILTIN)&&!$native->flags->contains(MetadataFlags::MAGIC_METHOD)&&!$native->flags->contains(MetadataFlags::BY_REFERENCE)&&$native->templates===[]&&strcasecmp($native->identifier->name,$p['name'])===0&&$this->location($source,$native->location,$p['span'],$path,$p['starts'])&&$this->location($source,$native->nameLocation,$p['nameSpan'],$path)&&$this->location($source,$native->declaredReturnType?->location,$p['returnSpan'],$path);}
    private function location(Source $source,?\Mago\Sdk\SourceLocation $location,?array $span,string $path,?array $starts=null):bool
    {return $location!==null&&$span!==null&&$source->path($location->file??'')===$path&&in_array($location->span->start,$starts??[$span[0]],true)&&$location->span->end===$span[1];}
    private static function hash(array $value):string{$sort=static function(mixed $v)use(&$sort):mixed{if(is_array($v)){foreach($v as $k=>$item){$v[$k]=$sort($item);}if(!array_is_list($v)){ksort($v);}}return $v;};return hash('sha256',json_encode($sort($value),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));}
}
