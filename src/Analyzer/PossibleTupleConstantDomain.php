<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\{IssueFilterContext,Type};
use Mago\Sdk\Analyzer\Metadata\{ClassLikeKind,MetadataFlags};
use Mago\Sdk\Analyzer\Type\{ReferenceType,ReferenceTypeKind,ReferenceSelectorKind,Visibility};
use PhpParser\Node;

/** Current own literal constant PHPDoc domains; no general unresolved-type fallback. */
final class PossibleTupleConstantDomain
{
    public array $stages=[];
    public array $dependencies=[];
    private function stage(string $stage,bool $passed):bool { $this->stages[]=compact('stage','passed');return $passed; }

    /** Source-only scalar projection, released syntax never authorizes a native decision. */
    public static function plan(GuardedStringCastContracts $source,array $file,Node\Stmt\ClassMethod $scope,Node\Param $parameter):?array
    {
        if(!$parameter->type instanceof Node\Identifier || strtolower($parameter->type->name)!=='string'
            ||!$parameter->var instanceof Node\Expr\Variable ||!is_string($parameter->var->name)
            ||$parameter->byRef ||$parameter->variadic ||$parameter->default!==null) { return null; }
        $doc=$scope->getDocComment();if($doc===null) { return null; }
        $name=$parameter->var->name;$text=$doc->getText();$selected=[];
        preg_match_all('~@(?:phpstan-|psalm-)?param(?:-out)?\b[^\r\n]*~',$text,$tags,PREG_OFFSET_CAPTURE);
        foreach($tags[0] as [$tag,$offset]) {
            if(preg_match('~\$'.preg_quote($name,'~').'(?![A-Za-z0-9_])~',$tag)!==1) { continue; }
            $selected[]=[$tag,$offset];
        }
        if(count($selected)!==1) { return null; }[$tag,$offset]=$selected[0];
        if(preg_match('~^@param[\t ]+(self::([A-Za-z_][A-Za-z0-9_]*)\*)[\t ]+\$'.preg_quote($name,'~').'(?:[\t ].*)?$~D',$tag,$match,PREG_OFFSET_CAPTURE)!==1) { return null; }
        $token=$match[1][0];$prefix=$match[2][0];$start=$doc->getStartFilePos()+$offset+$match[1][1];
        if(substr($file['contents'],$start,strlen($token))!==$token) { return null; }
        $class=null;
        foreach($source->ancestors($scope,$file) as $parent) { if($parent instanceof Node\Stmt\ClassLike) { $class=$parent;break; } }
        if(!$class instanceof Node\Stmt\Class_ ||$class->name===null ||$class->namespacedName===null
            ||$class->extends!==null ||$class->implements!==[]) { return null; }
        $constants=[];$allNames=[];
        foreach($class->stmts as $statement) {
            if($statement instanceof Node\Stmt\TraitUse) { return null; }
            if(!$statement instanceof Node\Stmt\ClassConst) { continue; }
            foreach($statement->consts as $constant) {
                $constantName=$constant->name->name;
                if(in_array($constantName,$allNames,true)) { return null; }$allNames[]=$constantName;
                if(!str_starts_with($constantName,$prefix)) { continue; }
                if($statement->type!==null ||$statement->getDocComment()!==null ||$statement->attrGroups!==[]
                    ||!$statement->isPublic() ||$statement->isFinal() ||!$constant->value instanceof Node\Scalar\String_) { return null; }
                $constants[]=['name'=>$constantName,'value'=>$constant->value->value,
                    'span'=>[$constant->getStartFilePos(),$constant->getEndFilePos()+1]];
            }
        }
        if($constants===[] ||count($constants)>16) { return null; }sort($allNames);
        return ['file'=>$file['path'],'sourceSha256'=>$file['hash'],'class'=>$class->namespacedName->toString(),
            'classSpan'=>[$class->getStartFilePos(),$class->getEndFilePos()+1],
            'classNameSpan'=>[$class->name->getStartFilePos(),$class->name->getEndFilePos()+1],
            'typeToken'=>$token,'typeSpan'=>[$start,$start+strlen($token)],'prefix'=>$prefix,
            'constants'=>$constants,'allConstantNames'=>$allNames];
    }

    public function resolve(IssueFilterContext $context,GuardedStringCastContracts $source,array $file,
        Node\Stmt\ClassMethod $scope,Node\Param $parameter,object $owner,object $formal):?Type
    {
        $plan=self::plan($source,$file,$scope,$parameter);
        if(!$this->stage('current finite own string constant wildcard PHPDoc',$plan!==null)) { return null; }
        unset($file,$scope,$parameter);
        $metadata=$formal->type;$declared=$formal->declaredType;$type=$metadata?->type;
        $atomic=$type!==null&&count($type->atomicTypes)===1?$type->atomicTypes[0]:null;
        if(!$this->stage('genuine unchanged selected constant member reference',$metadata!==null&&$metadata->fromDocblock&&!$metadata->inferred
            &&$metadata->location!==null&&$metadata->location->file!==null&&$source->path($metadata->location->file)===$plan['file']
            &&[$metadata->location->span->start,$metadata->location->span->end]===$plan['typeSpan']
            &&$type!==null&&!$type->flags->byReference&&!$type->flags->possiblyUndefined&&!$type->flags->hadTemplate
            &&!$type->flags->possiblyUndefinedFromTry&&!$type->flags->fromTemplateDefault&&!$type->flags->fromUnspecifiedTemplate
            &&$atomic instanceof ReferenceType&&$atomic->kind===ReferenceTypeKind::Member
            &&is_string($atomic->name)&&strcasecmp($atomic->name,$plan['class'])===0
            &&$atomic->parameters===null&&$atomic->variances===null&&$atomic->intersections===null
            &&$atomic->selector===ReferenceSelectorKind::StartsWith&&$atomic->member===$plan['prefix']
            &&$declared!==null&&!$declared->fromDocblock&&!$declared->inferred
            &&$formal->outType===null&&!$formal->flags->contains(MetadataFlags::BY_REFERENCE)
            &&!$formal->flags->contains(MetadataFlags::VARIADIC)
            &&strcasecmp($owner->identifier->class,$plan['class'])===0)) { return null; }
        $class=$context->codebase->getClass($plan['class']);$this->dependencies['constant-owner:'.strtolower($plan['class'])]=$class;
        $nativeNames=$class?->constants??[];sort($nativeNames);
        if(!$this->stage('complete current noninherited constant owner',$class!==null&&$class->kind===ClassLikeKind::Class_
            &&strcasecmp($class->name,$plan['class'])===0&&strcasecmp($class->originalName,$plan['class'])===0
            &&!$class->hasIncompleteHierarchy()&&!$class->flags->contains(MetadataFlags::BUILTIN)
            &&$class->directParentClass===null&&$class->parentClasses===[]&&$class->directParentInterfaces===[]
            &&$class->parentInterfaces===[]&&$class->usedTraits===[]&&$class->templates===[]&&$class->mixins===[]
            &&self::located($source,$class->location,$plan['file'],$plan['classSpan'])
            &&self::located($source,$class->nameLocation,$plan['file'],$plan['classNameSpan'])
            &&$nativeNames===$plan['allConstantNames'])) { return null; }
        $literals=[];
        foreach($plan['constants'] as $physical) {
            $constant=$context->codebase->getClassConstant($plan['class'],$physical['name']);
            $this->dependencies['constant:'.$plan['class'].'::'.$physical['name']]=$constant;
            if(!$this->stage('current native literal string constant '.$physical['name'],$constant!==null
                &&$constant->name===$physical['name']&&$constant->visibility===Visibility::Public
                &&$constant->flags->bits===MetadataFlags::USER_DEFINED&&$constant->attributes===[]&&$constant->declaredType===null
                &&self::located($source,$constant->location,$plan['file'],$physical['span'])
                &&$constant->inferredType!==null&&!$constant->inferredType->flags->byReference&&!$constant->inferredType->flags->possiblyUndefined
                &&$constant->inferredType->getLiteralString()===$physical['value']
                &&($constant->type===null||!$constant->type->fromDocblock
                    &&$constant->type->type->getLiteralString()===$physical['value']))) { return null; }
            $literals[]=Type::literalString($physical['value']);
        }
        $domain=Type::union(...$literals);
        if(!$this->stage('resolved literal constant union preserves the physical PHP bound',
            hash_file('sha256',$plan['file'])===$plan['sourceSha256']&&$context->types->isContainedBy($domain,$declared->type))) { return null; }
        return $domain;
    }

    private static function located(GuardedStringCastContracts $source,?object $location,string $file,array $span):bool
    {
        return $location!==null&&$location->file!==null&&$source->path($location->file)===$file
            &&[$location->span->start,$location->span->end]===$span;
    }
}
