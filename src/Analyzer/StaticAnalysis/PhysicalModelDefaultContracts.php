<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use PhpParser\Node;
use PhpParser\NodeFinder;

/** Current physical defaults used by the existing model resolver agree with genuine storage metadata. */
final class PhysicalModelDefaultContracts
{
    private const FIELDS=['connection','table','casts','primaryKey','keyType','incrementing','timestamps'];

    public static function admits(IssueFilterContext $context,PhpSource $source,string $model):bool
    {
        $classes=self::currentGraph($context,$source,$model);
        if($classes===null){return false;}
        $reflection=new ModelReflection($context->codebase,$source);
        foreach(self::FIELDS as $field){
            if(!self::field($context,$source,$model,$field,$classes,$reflection)){return false;}
        }
        return $source->isCurrent()&&$source->warnings===[];
    }

    /** Compact current physical frames; no all-class AST or copied SDK cache. */
    public static function currentGraph(IssueFilterContext $context,PhpSource $source,string $model):?array
    {
        $classes=[];$budget=64;
        if(!self::declarations($context,$source,$model,$classes,$budget)){return null;}
        // Compare the union over the complete physical parent/trait graph. This
        // handles direct or flattened per-class SDK lists without accepting an
        // unknown extra trait, and retains no class AST/duplicate SDK cache.
        $sourceTraits=[];$nativeTraits=[];
        foreach($classes as $class){
            foreach($class['traits'] as $trait){$sourceTraits[strtolower($trait)]=true;}
            foreach($class['nativeUsedTraits'] as $trait){
                if(!is_string($trait)||$trait===''){return null;}
                $nativeTraits[strtolower($trait)]=true;
            }
        }
        $sourceNames=array_keys($sourceTraits);$nativeNames=array_keys($nativeTraits);
        sort($sourceNames);sort($nativeNames);
        return $sourceNames===$nativeNames?$classes:null;
    }

    private static function field(IssueFilterContext $context,PhpSource $source,string $model,string $field,array $classes,ModelReflection $reflection):bool
    {
            $native=$context->codebase->getDeclaringProperty($model,'$'.$field)??$context->codebase->getProperty($model,'$'.$field);
            if($native===null||$native->name!=='$'.$field||$native->nameLocation?->file===null){return false;}
            $selected=[];
            foreach($classes as $class){
                foreach($class['properties'] as $property){
                    if($property['name']===$field&&self::sameFile($source,$native->nameLocation->file,$class['file'])
                        &&[$native->nameLocation->span->start,$native->nameLocation->span->end]===$property['nameSpan']){
                        $selected[]=[$class,$property];
                    }
                }
            }
            if(count($selected)!==1){return false;}[$class,$property]=$selected[0];
            if(!$property['ordinary']||$property['typed']||$native->declaredType!==null||$native->hooks!==[]
                ||$native->attributes!==[]||$native->location!==null||$native->writeType!==null
                ||$native->readVisibility->name!==$property['visibility']||$native->writeVisibility!==$native->readVisibility
                ||($native->flags->bits&(MetadataFlags::STATIC|MetadataFlags::VIRTUAL_PROPERTY|MetadataFlags::PROMOTED_PROPERTY|MetadataFlags::READONLY|MetadataFlags::BY_REFERENCE|MetadataFlags::ASYMMETRIC_PROPERTY))!==0
                ||$native->flags->contains(MetadataFlags::HAS_DEFAULT)!==$property['hasDefault']){return false;}
            if(!$property['hasDefault']){
                if($native->defaultType!==null||$reflection->default($model,$field)!==null){return false;}
                return true;
            }
            $default=$native->defaultType;
            if($default===null||$default->fromDocblock||!$default->inferred||$default->type->flags->byReference||$default->type->flags->possiblyUndefined
                ||!self::sameFile($source,$default->location->file,$class['file'])
                ||[$default->location->span->start,$default->location->span->end]!==$property['defaultSpan']
                ||$property['value'] instanceof UnknownValue||!self::sameLiteralDefault($field,$reflection->default($model,$field),$property['value'])){return false;}
        return true;
    }

    private static function declarations(IssueFilterContext $context,PhpSource $source,string $name,array &$classes,int &$budget):bool
    {
        $key=strtolower($name);if(isset($classes[$key])){return true;}if(--$budget<0){return false;}
        $native=$context->codebase->getClassLike($name);
        if($native===null||strcasecmp(ltrim($native->name,'\\'),ltrim($name,'\\'))!==0
            ||strcasecmp(ltrim($native->originalName,'\\'),ltrim($name,'\\'))!==0
            ||$native->hasIncompleteHierarchy()||$native->flags->contains(MetadataFlags::BUILTIN)
            ||$native->nameLocation===null||$native->location->file===null){return false;}
        $file=$native->location->file;$nodes=$source->read($file);if($nodes===null){return false;}
        $selected=[];
        foreach((new NodeFinder)->findInstanceOf($nodes,Node\Stmt\ClassLike::class) as $node){
            if(($node instanceof Node\Stmt\Class_||$node instanceof Node\Stmt\Trait_)&&$node->namespacedName!==null&&strcasecmp($node->namespacedName->toString(),$name)===0){$selected[]=$node;}
        }
        if(count($selected)!==1){return false;}$node=$selected[0];
        if($native->location->span->start!==$node->getStartFilePos()||$native->location->span->end!==$node->getEndFilePos()+1||$native->nameLocation->span->start!==$node->name->getStartFilePos()
            ||$native->nameLocation->span->end!==$node->name->getEndFilePos()+1||!self::sameFile($source,$native->nameLocation->file,$file)
            ||($node instanceof Node\Stmt\Class_?'Class_':'Trait')!==$native->kind->name){return false;}
        $parent=$node instanceof Node\Stmt\Class_?$node->extends?->toString():null;
        if(strcasecmp($parent??'',$native->directParentClass??'')!==0){return false;}
        $traits=[];$properties=[];
        foreach($node->getTraitUses() as $use){foreach($use->traits as $trait){$traits[]=$trait->toString();}}
        foreach($node->getProperties() as $declaration){foreach($declaration->props as $property){
            if(!in_array($property->name->name,self::FIELDS,true)){continue;}
            $properties[]=['name'=>$property->name->name,'nameSpan'=>[$property->name->getStartFilePos(),$property->name->getEndFilePos()+1],
                'ordinary'=>$declaration->hooks===[]&&$declaration->attrGroups===[]&&!$declaration->isStatic()&&!$declaration->isAbstract()&&!$declaration->isReadonly(),
                'typed'=>$declaration->type!==null,'visibility'=>$declaration->isPrivate()?'Private':($declaration->isProtected()?'Protected':'Public'),
                'hasDefault'=>$property->default!==null,'defaultSpan'=>$property->default===null?null:[$property->default->getStartFilePos(),$property->default->getEndFilePos()+1],
                'value'=>$property->default===null?null:PhpSource::value($property->default,$name,$name)];
        }}
        $methods=[];$constants=[];$adaptations=false;
        foreach($node->getMethods() as $method){$methods[strtolower($method->name->name)]=['name'=>$method->name->name,
            'span'=>[$method->getStartFilePos(),$method->getEndFilePos()+1],'nameSpan'=>[$method->name->getStartFilePos(),$method->name->getEndFilePos()+1]];}
        foreach($node->getTraitUses() as $use){$adaptations=$adaptations||$use->adaptations!==[];}
        foreach($node->stmts as $statement){if($statement instanceof Node\Stmt\ClassConst){foreach($statement->consts as $constant){
            $constants[$constant->name->name]=['span'=>[$constant->getStartFilePos(),$constant->getEndFilePos()+1],
                'value'=>PhpSource::value($constant->value,$name,$name),'typed'=>$statement->type!==null,
                'ordinary'=>$statement->attrGroups===[]&&$statement->isPublic()&&!$statement->isFinal()];
        }}}
        $classes[$key]=['name'=>$name,'file'=>$file,'properties'=>$properties,'traits'=>$traits,'nativeUsedTraits'=>$native->usedTraits,
            'methods'=>$methods,'constants'=>$constants,'adaptations'=>$adaptations,'doc'=>$node->getDocComment()?->getText()??''];unset($nodes,$node,$selected);
        foreach($traits as $trait){
            if(!in_array(strtolower($trait),array_map(strtolower(...),$native->usedTraits),true)
                ||!self::declarations($context,$source,$trait,$classes,$budget)){return false;}
        }
        if($parent!==null&&!self::declarations($context,$source,$parent,$classes,$budget)){return false;}

        return true;
    }

    /** Cast maps have key/value semantics; native known items may be sorted. */
    private static function sameLiteralDefault(string $field,mixed $native,mixed $physical):bool
    {
        if($field!=='casts'||!is_array($native)||!is_array($physical)){return $native===$physical;}
        if(count($native)!==count($physical)){return false;}
        foreach($physical as $key=>$value){
            if(!is_string($key)||!is_string($value)||!array_key_exists($key,$native)||$native[$key]!==$value){return false;}
        }
        return true;
    }
    private static function sameFile(PhpSource $source,?string $first,?string $second):bool
    {
        return $first!==null&&$second!==null&&strcasecmp(str_replace('\\','/',$source->path($first)),str_replace('\\','/',$source->path($second)))===0;
    }
}
