<?php
declare(strict_types=1);
namespace Example\ModelPropertyArgumentTests;

use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;

/** Bounded actual SDK projections for an AlwaysKeep observer; never admission authority. */
final class SelectedModelMetadataObservation
{
    public static function collect(IssueFilterContext $context,string $model,string $root):array
    {
        $frames=[];$queue=[$model];$seen=[];$budget=64;
        while($queue!==[]&&--$budget>=0){
            $name=array_shift($queue);$key=strtolower($name);if(isset($seen[$key])){continue;}$seen[$key]=true;
            $native=$context->codebase->getClassLike($name);
            if($native===null){$frames[$key]=['query'=>$name,'native'=>null];continue;}
            $frames[$key]=['query'=>$name,'name'=>$native->name,'originalName'=>$native->originalName,'kind'=>$native->kind->name,'location'=>self::location($native->location),
                'nameLocation'=>self::location($native->nameLocation),'directParentClass'=>$native->directParentClass,'usedTraits'=>$native->usedTraits,
                'incompleteHierarchy'=>$native->hasIncompleteHierarchy(),'sourceSha256'=>self::sourceHash($root,$native->location->file)];
            if($native->directParentClass!==null){$queue[]=$native->directParentClass;}
            foreach($native->usedTraits as $trait){if(is_string($trait)&&$trait!==''){$queue[]=$trait;}}
        }
        $methods=[];
        foreach(['__get','getAttribute','getAttributeValue','getAttributeFromArray','getDates','usesTimestamps','getCreatedAtColumn','getUpdatedAtColumn',
            'getTable','getConnectionName','getKeyName','getKeyType','getIncrementing','getDateFormat','asDateTime','getCasts','casts','initializeSoftDeletes'] as $name){
            $methods[$name]=['appearing'=>self::method($context->codebase->getMethod($model,$name),$root),
                'declaring'=>self::method($context->codebase->getDeclaringMethod($model,$name),$root)];
        }
        $constants=[];
        foreach(['CREATED_AT','UPDATED_AT','DELETED_AT','DATE_FORMAT'] as $name){
            $native=$context->codebase->getClassConstant($model,$name);
            $constants[$name]=$native===null?null:['name'=>$native->name,'location'=>self::location($native->location),
                'declaredTypeLocation'=>self::location($native->declaredType?->location),'declaredType'=>$native->declaredType===null?null:(string)$native->declaredType->type,
                'effectiveTypeLocation'=>self::location($native->type?->location),'effectiveType'=>$native->type===null?null:(string)$native->type->type,
                'effectiveFromDocblock'=>$native->type?->fromDocblock,'effectiveInferred'=>$native->type?->inferred,
                'inferredType'=>$native->inferredType===null?null:(string)$native->inferredType,'inferredLiteralString'=>$native->inferredType?->getLiteralString(),
                'flags'=>$native->flags->bits,'sourceSha256'=>self::sourceHash($root,$native->location->file)];
        }
        return ['observationOnly'=>true,'admissionAuthority'=>false,'modelQuery'=>$model,'actualClassFrames'=>$frames,
            'frameBudgetExceeded'=>$queue!==[],'actualSelectedMethods'=>$methods,'actualSelectedConstants'=>$constants];
    }
    private static function location(?\Mago\Sdk\SourceLocation $location):?array
    {
        return $location===null?null:['file'=>$location->file,'span'=>[$location->span->start,$location->span->end]];
    }
    private static function method(?\Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata $native,string $root):?array
    {
        if($native===null){return null;}$parameters=[];
        foreach($native->parameters as $parameter){$parameters[]=['name'=>$parameter->name,'location'=>self::location($parameter->location),
            'nameLocation'=>self::location($parameter->nameLocation),'byReference'=>$parameter->flags->contains(MetadataFlags::BY_REFERENCE),
            'variadic'=>$parameter->flags->contains(MetadataFlags::VARIADIC),'outTypePresent'=>$parameter->outType!==null,
            'declaredTypeLocation'=>self::location($parameter->declaredType?->location),'effectiveType'=>$parameter->type===null?null:(string)$parameter->type->type,
            'effectiveTypeLocation'=>self::location($parameter->type?->location),'effectiveFromDocblock'=>$parameter->type?->fromDocblock,'effectiveInferred'=>$parameter->type?->inferred];}
        return ['identifierClass'=>$native->identifier->class,'identifierName'=>$native->identifier->name,'originalName'=>$native->originalName,
            'location'=>self::location($native->location),'nameLocation'=>self::location($native->nameLocation),'parameters'=>$parameters,
            'static'=>$native->static,'byReference'=>$native->flags->contains(MetadataFlags::BY_REFERENCE),
            'declaredReturnType'=>$native->declaredReturnType===null?null:(string)$native->declaredReturnType->type,
            'effectiveReturnType'=>$native->returnType===null?null:(string)$native->returnType->type,
            'effectiveReturnLocation'=>self::location($native->returnType?->location),'effectiveReturnFromDocblock'=>$native->returnType?->fromDocblock,
            'effectiveReturnInferred'=>$native->returnType?->inferred,
            'sourceSha256'=>self::sourceHash($root,$native->location->file)];
    }
    private static function sourceHash(string $root,?string $file):?string
    {
        if($file===null||str_starts_with($file,'@')){return null;}
        $file=str_replace('\\','/',$file);if(str_starts_with($file,'//?/')){$file=substr($file,4);}
        if(!str_starts_with($file,'/')&&preg_match('~^[A-Za-z]:/~',$file)!==1){$file=rtrim(str_replace('\\','/',$root),'/').'/'.$file;}
        $hash=@hash_file('sha256',$file);return $hash===false?null:$hash;
    }
}
