<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\{IssueFilterContext,Type};
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type\{CallableParameter,CallableSignature,CallableType};
use PhpParser\{Node,NodeFinder};

/** Matches native declared Closure storage and its own bounded physical PHPDoc signature. */
final class CapturedStatePhysicalCallback
{
    public static function prove(IssueFilterContext $context,string $root,array $storage):array
    {
        $receipt=['admitted'=>false,'class'=>$storage['class'],'property'=>$storage['property'],'stage'=>'current-native-physical-field','nativeClosureIdentifierInvented'=>false];
        $owner=$context->codebase->getClass($storage['class']);$property=$context->codebase->getDeclaringProperty($storage['class'],'$'.$storage['property']);
        if($owner===null || $owner->hasIncompleteHierarchy() || $owner->templates!==[] || $owner->mixins!==[] || $owner->location->file===null || $owner->flags->contains(MetadataFlags::BUILTIN)
            || $property===null || $property->hooks!==[] || $property->flags->contains(MetadataFlags::VIRTUAL_PROPERTY) || $property->flags->contains(MetadataFlags::BY_REFERENCE) || $property->flags->contains(MetadataFlags::STATIC)
            || $property->name!=='$'.$storage['property'] || $property->declaredType===null || $property->declaredType->fromDocblock || $property->declaredType->inferred || $property->type===null){return $receipt;}
        $source=new PhpSource($root);$nodes=$source->read($owner->location->file);if($nodes===null){return $receipt;}
        $class=(new NodeFinder)->findFirst($nodes,static fn(Node $node):bool=>$node instanceof Node\Stmt\Class_ && isset($node->namespacedName) && strcasecmp($node->namespacedName->toString(),$owner->name)===0
            && $node->getStartFilePos()===$owner->location->span->start && $node->getEndFilePos()+1===$owner->location->span->end);
        $physical=[];foreach($class?->getProperties()??[] as $declaration){foreach($declaration->props as $item){if($item->name->name===$storage['property']){$physical[]=$declaration;}}}
        if(count($physical)!==1 || $physical[0]->isStatic() || ($physical[0]->hooks??[])!==[] || $physical[0]->attrGroups!==[] || !$physical[0]->type instanceof Node\NullableType
            || !$physical[0]->type->type instanceof Node\Name || strcasecmp($physical[0]->type->type->toString(),'Closure')!==0){return $receipt;}
        $field=$physical[0];$doc=$field->getDocComment();$effective=self::documentedType($doc?->getText()??'');
        $receipt['physicalDeclarationSpan']=CapturedStateGuardSource::span($field);$receipt['physicalSourceSha256']=$source->contentHash($owner->location->file);
        $docSpan=$doc===null?null:[$doc->getStartFilePos(),$doc->getEndFilePos()+1];$ownerFile=$owner->location->file;
        unset($nodes,$class,$physical,$declaration,$item,$field,$doc,$source);
        $declared=Type::union(self::closureType(),Type::null());
        if(!$context->types->equals($property->declaredType->type,$declared)){return $receipt;}
        $receipt['stage']='native-physical-field-doc-contract';
        if($effective===null){return $receipt;}
        if($effective['documented']){
            if(!$property->type->fromDocblock || $property->type->inferred || $docSpan===null || $property->type->location->span->start<$docSpan[0] || $property->type->location->span->end>$docSpan[1]
                || self::path($property->type->location->file)!==self::path($ownerFile) || !$context->types->equals($property->type->type,$effective['type'])){return $receipt;}
        }elseif(!$context->types->equals($property->type->type,$declared)){return $receipt;}
        $receipt['nativeFlags']=$property->flags->bits;$receipt['nativeDeclaredType']=(string)$property->declaredType->type;$receipt['nativeEffectiveType']=(string)$property->type->type;
        $receipt['effectiveOwnPhysicalPhpDoc']=$effective['documented'];$receipt['nativeTypesChanged']=false;$receipt['admitted']=true;$receipt['stage']='physical-callback-storage-admitted';return $receipt;
    }
    public static function documentedType(string $doc):?array
    {
        if($doc===''){return ['documented'=>false,'type'=>Type::union(self::closureType(),Type::null())];}
        if(strlen($doc)>2048 || preg_match_all('/@(?:phpstan-|psalm-)?var\b/',$doc)!==1 || preg_match('/@var\s+(.+?)\s*\*\//s',$doc,$match)!==1){return null;}
        $text=preg_replace('/\s+/','',$match[1]);if(strlen($text)>256 || !preg_match('/^\((.+)\)\|null$/D',$text,$m)){return null;}
        $offset=0;$type=self::readType($m[1],$offset,0);if($type===null || $offset!==strlen($m[1]) || count($type->atomicTypes)!==1 || !$type->atomicTypes[0] instanceof CallableType){return null;}
        return ['documented'=>true,'type'=>Type::union($type,Type::null())];
    }
    private static function readType(string $text,int &$offset,int $depth):?Type
    {
        if($depth>3 || !preg_match('/\G(Closure|string|mixed|int|bool|void)(?=[:(),]|$)/A',$text,$m,0,$offset)){return null;}
        $offset+=strlen($m[1]);if($m[1]!=='Closure'){return match($m[1]){'string'=>Type::string(),'mixed'=>Type::mixed(),'int'=>Type::int(),'bool'=>Type::bool(),'void'=>Type::void()};}
        if(($text[$offset++]??null)!=='('){return null;}$params=[];
        if(($text[$offset]??null)!==')'){
            do{$type=self::readType($text,$offset,$depth+1);if($type===null || count($params)>=8){return null;}$params[]=new CallableParameter(type:$type);$separator=$text[$offset++]??null;if($separator===')'){break;}if($separator!==','){return null;}}while(true);
        }else{++$offset;}
        if(($text[$offset++]??null)!==':'){return null;}$return=self::readType($text,$offset,$depth+1);if($return===null){return null;}
        return Type::fromAtomic(new CallableType(new CallableSignature(false,true,$params,$return,null,[]),null));
    }
    private static function closureType():Type
    {
        // The native ABI represents physical Closure as this generic closure signature.
        return Type::fromAtomic(new CallableType(new CallableSignature(false,true,[new CallableParameter(type:Type::mixed(),variadic:true,hasDefault:true)],Type::mixed(),null,[]),null));
    }
    private static function path(?string $path):string{$path=str_replace('\\','/',$path??'');return PHP_OS_FAMILY==='Windows'?strtolower($path):$path;}
    
}
