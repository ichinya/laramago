<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\{DefensiveBoundaryGuardSource as Source,GuardedStringCastContracts};
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\Metadata\{ClassLikeKind,ClassLikeMetadata,MetadataFlags};
use PhpParser\{Node,NodeFinder,NodeTraverser,ParserFactory};
use PhpParser\NodeVisitor\NameResolver;

/** A source/native annotation of the already declared physical Model parent adds no dispatch contract. */
final class CollectionOffsetModelMixin
{
    private const MODEL='Illuminate\\Database\\Eloquent\\Model';
    public array $stages=[];
    public function __construct(private readonly string $root){}
    public function current(IssueFilterContext $context,ClassLikeMetadata $metadata):bool
    {
        if($metadata->mixins===[]){return true;}
        $source=new GuardedStringCastContracts($this->root);$mixin=$metadata->mixins[0]??null;$flags=$mixin===null?[]:get_object_vars($mixin->flags);$population=$flags['populated']??null;unset($flags['populated']);
        $plainFlags=is_bool($population)&&count($flags)===10&&count(array_filter($flags,static fn(mixed $flag):bool=>$flag!==false))===0;
        $this->stages['one-plain-native-parent-mixin']=count($metadata->mixins)===1&&$source->plainObject($mixin,self::MODEL)&&$plainFlags;
        if(!$this->stages['one-plain-native-parent-mixin']||$metadata->kind!==ClassLikeKind::Class_||$metadata->hasIncompleteHierarchy()||$metadata->templates!==[]||$metadata->typeAliases!==[]||$metadata->flags->contains(MetadataFlags::BUILTIN)||strcasecmp($metadata->directParentClass??'',self::MODEL)!==0){return false;}
        $file=$source->read($metadata->location->file);$this->stages['current-physical-model-source']=$file!==null;if($file===null){return false;}
        try{$nodes=self::parse($file['contents']);}catch(\Throwable){return false;}
        $owners=(new NodeFinder)->find($nodes,static fn(Node $n):bool=>$n instanceof Node\Stmt\Class_&&strcasecmp($n->namespacedName?->toString()??'',$metadata->originalName)===0);
        $owner=count($owners)===1?$owners[0]:null;$syntax=$owner===null?null:self::lexical($owner);$span=$owner===null?null:Source::span($owner);$nameSpan=$owner?->name===null?null:Source::span($owner->name);$starts=$owner===null?[]:self::starts($owner);$path=$file['path'];$hash=$file['hash'];unset($file,$nodes,$owners,$owner);
        $this->stages['source-mixin-is-physical-parent']=$syntax!==null;if($syntax===null){return false;}
        $this->stages['selected-native-source-location']=$metadata->location->file!==null&&$source->path($metadata->location->file)===$path&&$span!==null&&in_array($metadata->location->span->start,$starts,true)&&$metadata->location->span->end===$span[1]&&$metadata->nameLocation!==null&&$source->path($metadata->nameLocation->file??'')===$path&&$nameSpan!==null&&[$metadata->nameLocation->span->start,$metadata->nameLocation->span->end]===$nameSpan;
        if(!$this->stages['selected-native-source-location']||hash_file('sha256',$path)!==$hash){return false;}
        $base=$context->codebase->getClass(self::MODEL);$this->stages['complete-native-physical-Model-parent']=$base!==null&&$base->kind===ClassLikeKind::Class_&&!$base->hasIncompleteHierarchy()&&$base->mixins===[]&&$base->typeAliases===[]&&strcasecmp($base->name,self::MODEL)===0;
        return $this->stages['complete-native-physical-Model-parent'];
    }
    /** @return array{annotation:string,parent:string}|null */
    public static function lexical(Node\Stmt\Class_ $owner):?array
    {
        if(!$owner->extends instanceof Node\Name||strcasecmp($owner->extends->toString(),self::MODEL)!==0){return null;}
        $doc=$owner->getDocComment()?->getText()??'';
        if(preg_match_all('/@(?:(?:phpstan|psalm)-)?mixin\b([^\r\n]*)/i',$doc,$matches)!==1){return null;}
        if(preg_match('/^\s+([\\\\a-zA-Z_][\\\\a-zA-Z0-9_]*)\s*(?:\*\/)?\s*$/D',$matches[1][0],$match)!==1){return null;}
        $annotation=$match[1];$original=$owner->extends->getAttribute('originalName');
        $samePhysicalParent=$original instanceof Node\Name&&strcasecmp(ltrim($annotation,'\\'),ltrim($original->toString(),'\\'))===0;
        $fullyQualifiedParent=str_starts_with($annotation,'\\')&&strcasecmp(ltrim($annotation,'\\'),self::MODEL)===0;
        return $samePhysicalParent||$fullyQualifiedParent?['annotation'=>$annotation,'parent'=>self::MODEL]:null;
    }
    /** Resolve PHP names and retain the exact spelling used by the physical extends declaration. */
    public static function parse(string $contents):array
    {
        if(strlen($contents)>1024*1024){throw new \RuntimeException('Selected model source bound exceeded.');}
        return (new NodeTraverser(new NameResolver(null,['preserveOriginalNames'=>true])))->traverse((new ParserFactory)->createForNewestSupportedVersion()->parse($contents)??[]);
    }
    private static function starts(Node $n):array{$r=[$n->getStartFilePos()];foreach($n->getComments() as $c){$r[]=$c->getStartFilePos();}foreach($n->attrGroups??[] as $g){$r[]=$g->getStartFilePos();}return $r;}
}
