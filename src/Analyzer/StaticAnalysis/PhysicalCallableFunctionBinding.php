<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer\StaticAnalysis;
use Mago\Sdk\Analyzer\{IssueFilterContext,Type};
use Mago\Sdk\Analyzer\Metadata\{FunctionLikeMetadata,MetadataFlags};
use Mago\Sdk\Analyzer\Type\{CallableType,SimpleAtomicType,SimpleAtomicTypeKind};

/** Physical Closure syntax uses the SDK CallableType ABI, with every ordinary declaration anchor still checked. */
final class PhysicalCallableFunctionBinding
{
    public static function current(IssueFilterContext $context,NullFlowNativeContracts $reader,FunctionLikeMetadata $native):?array
    {
        $ordinary=$reader->functionBound($context,$native);if($ordinary!==null) { return $ordinary; }
        $file=$native->location->file;if($file===null||$native->nameLocation===null||$native->flags->contains(MetadataFlags::BUILTIN)) { return null; }
        $bytes=$reader->bytes($file);if($bytes===null) { return null; }$index=$reader->source($file,$bytes);$owner=$native->identifier->class;
        $source=$owner===null?($index['functions'][strtolower($native->identifier->name)]??null):($index['classes'][strtolower($owner)]['methods'][strtolower($native->identifier->name)]??null);
        // This fallback is limited to a physical own declaration; inherited trait projection is handled by the ordinary binder.
        if($source===null||NullFlowNativeContracts::span($native->nameLocation->span)!==$source['nameSpan']
            ||$native->location->span->start>$source['span'][0]||$native->location->span->end!==$source['span'][1]
            ||count($native->parameters)!==count($source['parameters'])||$native->static!==$source['static']
            ||$native->hasDocblock!==$source['hasDocblock']||$native->flags->contains(MetadataFlags::BY_REFERENCE)!==$source['byReference']) { return null; }
        $callableSeen=false;
        foreach($source['parameters'] as $number=>$parameter) {
            $formal=$native->parameters[$number];
            if($formal->name!==$parameter['name']||NullFlowNativeContracts::span($formal->nameLocation->span)!==$parameter['nameSpan']
                ||$formal->flags->contains(MetadataFlags::BY_REFERENCE)!==$parameter['byReference']
                ||$formal->flags->contains(MetadataFlags::VARIADIC)!==$parameter['variadic']||$formal->flags->contains(MetadataFlags::HAS_DEFAULT)!==$parameter['hasDefault']) { return null; }
            if($parameter['typeSpan']!==null&&($formal->declaredType===null||$formal->declaredType->location===null
                ||NullFlowNativeContracts::span($formal->declaredType->location->span)!==$parameter['typeSpan']
                ||!NullFlowNativeContracts::sameFile($formal->declaredType->location->file,$file))) { return null; }
            $syntax=$parameter['type'];
            if(is_string($syntax)&&self::closureSyntax($syntax)) {
                $callableSeen=true;
                if($formal->declaredType===null||$formal->declaredType->fromDocblock||$formal->declaredType->inferred||!self::closureType($context,$formal->declaredType->type,$syntax)) { return null; }
            } else {
                $physical=$syntax===null?null:NullFlowNativeContracts::physicalType($syntax);
                if($physical!==null&&($formal->declaredType===null||!$context->types->equals($formal->declaredType->type,$physical))) { return null; }
            }
        }
        if(!$callableSeen) { return null; }
        if($source['returnTypeSpan']!==null&&($native->declaredReturnType===null||$native->declaredReturnType->location===null
            ||NullFlowNativeContracts::span($native->declaredReturnType->location->span)!==$source['returnTypeSpan']
            ||!NullFlowNativeContracts::sameFile($native->declaredReturnType->location->file,$file))) { return null; }
        $physical=$source['returnType']===null?null:NullFlowNativeContracts::physicalType($source['returnType']);
        if($physical!==null&&($native->declaredReturnType===null||!$context->types->equals($native->declaredReturnType->type,$physical))) { return null; }
        if(!$reader->current($file,$bytes)) { return null; }
        return ['native'=>$native,'source'=>$source,'file'=>$file,'sourceSha256'=>hash('sha256',$bytes),'physicalClosureAbiBound'=>true];
    }
    private static function closureSyntax(string $syntax):bool { return strcasecmp(ltrim(ltrim($syntax,'?'),'\\'),'Closure')===0; }
    private static function closureType(IssueFilterContext $context,Type $type,string $syntax):bool
    {
        $optional=str_starts_with($syntax,'?');$nulls=0;$callables=[];
        foreach($type->atomicTypes as $atom) { if($atom instanceof SimpleAtomicType&&$atom->kind===SimpleAtomicTypeKind::Null) { $nulls++; }
            elseif($atom instanceof CallableType) { $callables[]=$atom; }else { return false; } }
        if($type->flags->byReference||$type->flags->possiblyUndefined||$nulls!==($optional?1:0)||count($callables)!==1
            ||$callables[0]->alias!==null||$callables[0]->signature===null) { return false; }
        $signature=$callables[0]->signature;$formal=$signature->parameters[0]??null;
        return $signature->closure&&!$signature->pure&&$signature->source===null&&$signature->constraints===[]
            &&count($signature->parameters)===1&&$formal!==null&&$formal->name===null&&$formal->type!==null
            &&!$formal->byReference&&$formal->variadic&&$formal->closureThisType===null&&$context->types->equals($formal->type,Type::mixed())
            &&$signature->returnType!==null&&$context->types->equals($signature->returnType,Type::mixed());
    }
}
