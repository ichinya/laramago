<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\DefensiveBoundaryGuardSource as Source;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\Metadata\{ClassLikeKind, ClassLikeMetadata, MetadataFlags};
use PhpParser\Node;

/** Current source-issued parent/trait closure; no caller name or native hash allowlist. */
final class FactoryFakerLocalCacheCallerTraits
{
    public static function current(IssueFilterContext $context,ClassLikeMetadata $caller,string $root,array $aliases):array
    {
        $r=['admitted'=>false,'stage'=>'current caller trait declaration graph','sourceHashes'=>[],
            'nativeBindings'=>[],'nativeTypesChanged'=>false];
        $memo=[];$active=[];$visited=0;$bytes=0;
        $graph=self::visit($context,$caller,$root,$aliases,$memo,$active,$visited,$bytes,$r);
        if($graph===null){return $r;}
        foreach($r['sourceHashes'] as $file=>$hash){if(@hash_file('sha256',$file)!==$hash){return $r;}}
        $r['expectedUsedTraits']=$graph['traits'];$r['expectedParentClasses']=$graph['parents'];
        $r['stage']='current complete source and native caller trait closure';$r['admitted']=true;return $r;
    }
    private static function visit(IssueFilterContext $context,ClassLikeMetadata $native,string $root,array $aliases,
        array &$memo,array &$active,int &$visited,int &$bytes,array &$r):?array
    {
        $key=strtolower($native->name);$r['selectedName']=$native->name;
        if(isset($memo[$key])){return $memo[$key];}
        if($key===''||isset($active[$key])||isset($aliases[$key])||count($active)>=32||++$visited>128
            ||$native->hasIncompleteHierarchy()||$native->flags->contains(MetadataFlags::BUILTIN)
            ||$native->templates!==[]||$native->mixins!==[]||$native->typeAliases!==[]
            ||!in_array($native->kind,[ClassLikeKind::Class_,ClassLikeKind::Trait],true)
            ||strcasecmp($native->name,$native->originalName)!==0){return null;}
        $source=self::header($native,$root,$bytes);if($source===null){return null;}
        // header() returns scalar facts only. Its complete AST has died before
        // the next codebase request, including recursive ancestry lookups.
        $r['sourceHashes'][$source['path']]=$source['hash'];
        $r['stage']='source/native caller ancestry headers';
        if($native->kind!==$source['kind']||!self::located($native->location,$source['path'],$source['span'],$source['starts'],$root)
            ||!self::located($native->nameLocation,$source['path'],$source['nameSpan'],null,$root)
            ||strcasecmp($native->directParentClass??'',$source['parent']??'')!==0
            ||!self::sameNames($native->directParentInterfaces,$source['interfaces'])
            ||$native->flags->contains(MetadataFlags::FINAL)!==$source['final']
            ||$native->flags->contains(MetadataFlags::ABSTRACT)!==$source['abstract']
            ||$native->flags->contains(MetadataFlags::READONLY)!==$source['readonly']){return null;}
        $active[$key]=true;
        try{
            $traits=[];$parents=[];
            if($source['parent']!==null){
                $parent=$context->codebase->getClassLike($source['parent']);
                if($parent===null||$parent->kind!==ClassLikeKind::Class_
                    ||strcasecmp($parent->name,$source['parent'])!==0||strcasecmp($parent->originalName,$source['parent'])!==0){return null;}
                $inherited=self::visit($context,$parent,$root,$aliases,$memo,$active,$visited,$bytes,$r);
                if($inherited===null){return null;}
                $traits=$inherited['traits'];$parents=[strtolower($source['parent']),...$inherited['parents']];
            }
            foreach($source['traits'] as $traitName){
                $trait=$context->codebase->getClassLike($traitName);
                if($trait===null||$trait->kind!==ClassLikeKind::Trait
                    ||strcasecmp($trait->name,$traitName)!==0||strcasecmp($trait->originalName,$traitName)!==0){return null;}
                $nested=self::visit($context,$trait,$root,$aliases,$memo,$active,$visited,$bytes,$r);
                if($nested===null){return null;}
                array_push($traits,strtolower($traitName),...$nested['traits']);
            }
            $traits=array_values(array_unique($traits));sort($traits);
            $r['stage']='exact source/native effective caller trait and parent lists';
            $r['selectedComparison']=['name'=>$native->name,'expectedTraits'=>$traits,'nativeTraits'=>$native->usedTraits,
                'expectedParents'=>$parents,'nativeParents'=>$native->parentClasses];
            if(!self::sameNames($traits,$native->usedTraits)||!self::sameNames($parents,$native->parentClasses)){return null;}
            $r['nativeBindings'][$key]=['name'=>$native->name,'originalName'=>$native->originalName,'kind'=>$native->kind->name,
                'location'=>$native->location,'nameLocation'=>$native->nameLocation,'directParentClass'=>$native->directParentClass,
                'directParentInterfaces'=>$native->directParentInterfaces,'usedTraits'=>$native->usedTraits,'parentClasses'=>$native->parentClasses];
            return $memo[$key]=['traits'=>$traits,'parents'=>$parents];
        }finally{unset($active[$key]);}
    }
    private static function header(ClassLikeMetadata $native,string $root,int &$bytes):?array
    {
        $file=$native->location->file;if($file===null){return null;}$path=self::path($file,$root);if($path===null){return null;}
        $size=@filesize($path);if($size===false||$size<0||$size>2_000_000||$bytes+$size>8_388_608){return null;}
        $contents=@file_get_contents($path);if($contents===false||strlen($contents)>2_000_000||$bytes+strlen($contents)>8_388_608){return null;}
        $bytes+=strlen($contents);$hash=hash('sha256',$contents);
        try{$nodes=Source::parse($contents);}catch(\PhpParser\Error){return null;}
        $pending=$nodes;$selected=[];
        while($pending!==[]){$node=array_shift($pending);
            if($node instanceof Node\Stmt\Namespace_){array_push($pending,...$node->stmts);continue;}
            if(($node instanceof Node\Stmt\Class_||$node instanceof Node\Stmt\Trait_)&&$node->namespacedName!==null
                &&strcasecmp($node->namespacedName->toString(),$native->name)===0){$selected[]=$node;}
        }
        if(count($selected)!==1){return null;}$node=$selected[0];if($node->name===null){return null;}
        $traits=[];foreach($node->stmts as $statement){if(!$statement instanceof Node\Stmt\TraitUse){continue;}
            if($statement->adaptations!==[]){return null;}foreach($statement->traits as $trait){$traits[]=$trait->toString();}}
        if(count($traits)!==count(array_unique(array_map('strtolower',$traits)))){return null;}
        $starts=[$node->getStartFilePos()];foreach($node->getComments() as $comment){$starts[]=$comment->getStartFilePos();}
        return ['path'=>$path,'hash'=>$hash,'kind'=>$node instanceof Node\Stmt\Class_?ClassLikeKind::Class_:ClassLikeKind::Trait,
            'span'=>Source::span($node),'nameSpan'=>Source::span($node->name),'starts'=>$starts,'traits'=>$traits,
            'parent'=>$node instanceof Node\Stmt\Class_?$node->extends?->toString():null,
            'interfaces'=>$node instanceof Node\Stmt\Class_?array_map(static fn(Node\Name $name):string=>$name->toString(),$node->implements):[],
            'final'=>$node instanceof Node\Stmt\Class_&&$node->isFinal(),'abstract'=>$node instanceof Node\Stmt\Class_&&$node->isAbstract(),
            'readonly'=>$node instanceof Node\Stmt\Class_&&$node->isReadonly()];
    }
    private static function sameNames(array $left,array $right):bool
    {
        foreach([...$left,...$right] as $name){if(!is_string($name)||$name===''){return false;}}
        $left=array_map('strtolower',$left);$right=array_map('strtolower',$right);sort($left);sort($right);return $left===$right;
    }
    private static function path(string $file,string $root):?string
    {
        $root=rtrim(DefensiveBoundarySourceProfile::path($root),'/');$path=DefensiveBoundarySourceProfile::path((new PhpSource($root))->path($file));
        if(preg_match('~(?:^|/)\.{1,2}(?:/|$)~',$path)){return null;}
        $inside=DIRECTORY_SEPARATOR==='\\'?str_starts_with(strtolower($path),strtolower($root).'/'):str_starts_with($path,$root.'/');
        return $inside?$path:null;
    }
    private static function located(?\Mago\Sdk\SourceLocation $location,string $path,array $span,?array $starts,string $root):bool
    {
        $native=$location?->file===null?null:self::path($location->file,$root);
        return $location!==null&&$native!==null&&(DIRECTORY_SEPARATOR==='\\'?strcasecmp($native,$path)===0:$native===$path)
            &&in_array($location->span->start,$starts??[$span[0]],true)&&$location->span->end===$span[1];
    }
}
