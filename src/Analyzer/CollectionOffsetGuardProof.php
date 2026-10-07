<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\{DefensiveBoundaryGuardSource as Source,DefensiveBoundaryGuardProof as Boundary,DefensiveBoundarySourceProfile as Profile,EloquentCollectionType,EloquentModelDispatch,NullableCollectionOffsetContract};
use Ichinya\Laramago\Analyzer\CollectionOffsetGuardSource;
use Mago\Sdk\Analyzer\{IssueFilterContext,Type};
use Mago\Sdk\Analyzer\Metadata\{ClassLikeKind,FunctionLikeMetadata,MetadataFlags};
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Reporting\{AnnotationKind,Level};
use PhpParser\{Node,NodeFinder};


/** Explicit defensive Collection reporting policy; never an index-presence or non-null theorem. */
final class CollectionOffsetGuardProof
{
    private const SOURCE_PROFILES=array (
  'Illuminate\\Database\\Eloquent\\Model::query' => 
  array (
    'class' => 'Illuminate\\Database\\Eloquent\\Model',
    'name' => 'query',
    'path' => 'vendor/laravel/framework/src/Illuminate/Database/Eloquent/Model.php',
    'fingerprint' => '6e1e4be56f6f6c7b7601c35b4c7c1f833ed339dfef229dec80f60770eb4f48b5',
    'span' => 
    array (
      0 => 52759,
      1 => 52842,
    ),
    'static' => true,
    'parameterNames' => 
    array (
    ),
  ),
  'Illuminate\\Database\\Eloquent\\Builder::get' => 
  array (
    'class' => 'Illuminate\\Database\\Eloquent\\Builder',
    'name' => 'get',
    'path' => 'vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php',
    'fingerprint' => 'e5a8c854400b0fa5a93ee1c459c9f822617d6e4994ed35e4bd2d08d94988168b',
    'span' => 
    array (
      0 => 25489,
      1 => 26084,
    ),
    'static' => false,
    'parameterNames' => 
    array (
      0 => '$columns',
    ),
  ),
  'Illuminate\\Support\\Collection::keyBy' => 
  array (
    'class' => 'Illuminate\\Support\\Collection',
    'name' => 'keyBy',
    'path' => 'vendor/laravel/framework/src/Illuminate/Collections/Collection.php',
    'fingerprint' => '260f9b04401b0214c15633d34605dbec9900dc236490d21c710c579e8f64f933',
    'span' => 
    array (
      0 => 15787,
      1 => 16464,
    ),
    'static' => false,
    'parameterNames' => 
    array (
      0 => '$keyBy',
    ),
  ),
  'Illuminate\\Database\\Eloquent\\HasCollection::newCollection' => 
  array (
    'class' => 'Illuminate\\Database\\Eloquent\\HasCollection',
    'name' => 'newCollection',
    'path' => 'vendor/laravel/framework/src/Illuminate/Database/Eloquent/HasCollection.php',
    'fingerprint' => '11e213faf4198de272adb6ecaa533621931ffd603c5a1cf05c192de8f17f89ff',
    'span' => 
    array (
      0 => 612,
      1 => 1054,
    ),
    'static' => false,
    'parameterNames' => 
    array (
      0 => '$models',
    ),
  ),
);
    private const NATIVE_HASHES=array (
  'Illuminate\\Database\\Eloquent\\Model::query' => '0509f97207b7350c809263793b6d709dd6458b2c058ac7b46e6531f3e1f40518',
  'Illuminate\\Database\\Eloquent\\Builder::get' => '8240851f9189dd78bf7905ac91dbba4201f2ce915163c18ee8f0c5c04cd0bb81',
  'Illuminate\\Database\\Eloquent\\HasCollection::newCollection' => 'cf078763485542485c3cbd66bf6b1d5afe6335a52ddc87ec3adf9d4a5b33f2de',
  'Illuminate\\Support\\Collection::keyBy' => 'e85f86c67f18ce9f3efdae9fb9aba0de6dcc34a0dfce3614e3b0f0db883d5926',
);
    private readonly array $profiles;
    private readonly array $observedNativeHashes;
    public readonly NullableCollectionOffsetContract $arrayStorage;
    public function __construct(private readonly string $root){$this->profiles=self::SOURCE_PROFILES;$this->observedNativeHashes=self::NATIVE_HASHES;$this->arrayStorage=new NullableCollectionOffsetContract($root,'Illuminate\\Support\\Collection');}
    public function prove(IssueFilterContext $context):array
    {
        $r=['remove'=>false,'stage'=>'warning-envelope','nativeTypesChanged'=>false,'nativeControlFlowChanged'=>false,'indexPresenceClaimed'=>false,'policy'=>'defensive-standard-collection-boundary'];
        $issue=$context->issue;$a=count($issue->annotations)===1?$issue->annotations[0]:null;
        if($context->cancellation->isCancelled()||$issue->level!==Level::Warning||!in_array($issue->code,['possibly-null-array-index','impossible-condition'],true)||$issue->link!==null||$issue->edits!==[]||$a===null||$a->kind!==AnnotationKind::Primary||$a->file!==null&&$a->file!==''||strlen($context->contents)>1024*1024){return $r;}
        $r['stage']='fresh-current-source';$physical=$this->path($context->file);if($physical===''||@file_get_contents($physical)!==$context->contents){return $r;}
        try{$nodes=Source::parse($context->contents);$proofs=CollectionOffsetGuardSource::compile($nodes);}catch(\Throwable){return $r;}
        $p=$proofs[Source::key([$a->span->start,$a->span->end])]??null;if($p===null){return $r;}$r['source']=$p;
        if(!self::envelope($context,$p)){return $r;}
        $owner=(new NodeFinder)->findFirst($nodes,static fn(Node $n):bool=>$n instanceof Node\Stmt\Class_&&strcasecmp($n->namespacedName?->toString()??'',$p['scope']['class']??'')===0);
        $classSpan=$owner===null?null:Source::span($owner);$classStartAlternatives=$owner===null?[]:self::starts($owner);$classNameSpan=$owner?->name===null?null:Source::span($owner->name);$classFinal=$owner?->isFinal();unset($nodes,$proofs,$owner);
        $r['stage']='physical-current-named-caller';$scope=$p['scope'];$nativeClass=$context->codebase->getClass($scope['class']??'');$caller=$context->codebase->getDeclaringMethod($scope['class']??'',$scope['name']);
        if($nativeClass===null||$nativeClass->kind!==ClassLikeKind::Class_||$nativeClass->hasIncompleteHierarchy()||$nativeClass->mixins!==[]||$nativeClass->typeAliases!==[]||$nativeClass->flags->contains(MetadataFlags::FINAL)!==$classFinal||!$this->location($nativeClass->location,$classSpan,$physical,$classStartAlternatives)||!$this->location($nativeClass->nameLocation,$classNameSpan,$physical)||$caller===null||$caller->static!==$scope['static']||$caller->abstract||$caller->flags->contains(MetadataFlags::BY_REFERENCE)||strcasecmp($caller->identifier->class??'',$scope['class'])!==0||!$this->location($caller->location,$scope['span'],$physical,$scope['startAlternatives'])||count($caller->parameters)!==count($scope['parameters'])){return $r;}
        foreach($scope['parameters'] as $i=>$formal){$native=$caller->parameters[$i];if($native->name!==$formal['name']||$native->flags->contains(MetadataFlags::BY_REFERENCE)||$native->flags->contains(MetadataFlags::VARIADIC)||$native->outType!==null||!$this->location($native->location,$formal['span'],$physical)){return $r;}}
        $r['stage']='current-concrete-default-model-query';$model=$p['query']['model'];$nativeModel=$context->codebase->getClass($model);$dispatch=new EloquentModelDispatch;$mixin=new CollectionOffsetModelMixin($this->root);
        if($nativeModel===null||$nativeModel->kind!==ClassLikeKind::Class_||$nativeModel->hasIncompleteHierarchy()||!$mixin->current($context,$nativeModel)||$nativeModel->typeAliases!==[]||!in_array('illuminate\\database\\eloquent\\model',array_map('strtolower',$context->codebase->getClassAncestors($model)),true)||!$dispatch->supportsModel($context->codebase,$model,'query')||$dispatch->overrides($context->codebase,$model,'query')){$r['modelMixinStages']=$mixin->stages;return $r;}
        $r['modelMixinStages']=$mixin->stages;
        $query=$context->codebase->getDeclaringMethod($model,'query');if(!$this->library($context,$query,'Illuminate\\Database\\Eloquent\\Model::query')){return $r;}
        $get=$context->codebase->getDeclaringMethod('Illuminate\\Database\\Eloquent\\Builder','get');if(!$this->library($context,$get,'Illuminate\\Database\\Eloquent\\Builder::get')){return $r;}
        $r['stage']='current-model-collection-mapping';$domain=(new EloquentCollectionType)->resolve($context->codebase,Type::namedObject($model));$atom=$domain?->atomicTypes[0]??null;
        if($domain===null||count($domain->atomicTypes)!==1||$domain->flags->byReference||$domain->flags->possiblyUndefined||!$atom instanceof NamedObjectType||strcasecmp($atom->name,'Illuminate\\Database\\Eloquent\\Collection')!==0||$atom->static||$atom->isThis||($atom->intersections??[])!==[]||count($atom->parameters??[])!==2||!$context->types->equals($atom->parameters[0],Type::int())||!$context->types->equals($atom->parameters[1],Type::namedObject($model))){return $r;}
        $factory=$context->codebase->getDeclaringMethod($model,'newCollection');if(!$this->library($context,$factory,'Illuminate\\Database\\Eloquent\\HasCollection::newCollection')){return $r;}$r['collectionDomain']=(string)$domain;
        $r['stage']='current-literal-key-by-contract';$keyBy=$context->codebase->getDeclaringMethod($atom->name,'keyBy');if(!$this->library($context,$keyBy,'Illuminate\\Support\\Collection::keyBy')){return $r;}
        $r['stage']='current-physical-native-array-storage';if(!$this->arrayStorage->current($context,$atom->name)){$r['storageStages']=$this->arrayStorage->stages;return $r;}
        if($p['kind']==='defensive-indexed-model'){$r['stage']='native-built-in-exception';$exception=$context->codebase->getClass($p['exception']);if($exception===null||$exception->hasIncompleteHierarchy()||!$exception->flags->contains(MetadataFlags::BUILTIN)||$exception->flags->contains(MetadataFlags::USER_DEFINED)){return $r;}}
        $r['stage']='genuine-current-library-metadata-profiles';foreach([$query,$get,$factory,$keyBy] as $method){$symbol=($method->identifier->class??'').'::'.$method->identifier->name;$compact=Boundary::compact($method);$r['observedLibraryNativeProfiles'][$symbol]=['sha256'=>self::hash($compact),'compact'=>$compact];}
        foreach($r['observedLibraryNativeProfiles'] as $symbol=>$profile){
            if($symbol==='Illuminate\\Support\\Collection::keyBy'){$r['selectedSemanticKeyByProfile']=CollectionOffsetNativeProfile::current($profile['compact']);if(!$r['selectedSemanticKeyByProfile']){return $r;}}
            elseif(($this->observedNativeHashes[$symbol]??null)!==$profile['sha256']){return $r;}
        }
        $r['stage']='current-standard-collection-defensive-reporting-policy';$r['remove']=true;return $r;
    }
    private function library(IssueFilterContext $context,?FunctionLikeMetadata $native,string $symbol):bool
    {
        $profile=$this->profiles[$symbol]??null;if($native===null||$profile===null||$native->flags->contains(MetadataFlags::BUILTIN)||$native->flags->contains(MetadataFlags::BY_REFERENCE)||$native->abstract||strcasecmp(($native->identifier->class??'').'::'.$native->identifier->name,$symbol)!==0||$native->static!==$profile['static']||count($native->parameters)!==count($profile['parameterNames'])){return false;}
        $path=$this->path($profile['path']);if($path===''||$this->path($native->location->file??'')!==$path){return false;}$contents=@file_get_contents($path);if($contents===false||strlen($contents)>1024*1024){return false;}
        try{$nodes=Source::parse($contents);$node=Profile::selected($nodes,$profile['class'],$profile['name']);if(!$node instanceof Node\Stmt\ClassMethod){return false;}$span=Source::span($node);$starts=self::starts($node);$nameSpan=Source::span($node->name);$fingerprint=Profile::fingerprint($node);$parameters=array_map(static fn(Node\Param $p):array=>['name'=>'$'.$p->var->name,'span'=>Source::span($p),'nameSpan'=>Source::span($p->var),'byRef'=>$p->byRef,'variadic'=>$p->variadic],$node->params);unset($nodes,$node);}catch(\Throwable){return false;}
        if($fingerprint!==$profile['fingerprint']||!$this->location($native->location,$span,$path,$starts)||!$this->location($native->nameLocation,$nameSpan,$path)){return false;}
        foreach($parameters as $i=>$source){$formal=$native->parameters[$i];if($formal->name!==$profile['parameterNames'][$i]||$formal->name!==$source['name']||$source['byRef']||$source['variadic']||$formal->flags->contains(MetadataFlags::BY_REFERENCE)||$formal->flags->contains(MetadataFlags::VARIADIC)||$formal->outType!==null||$formal->closureThisType!==null||!$this->location($formal->location,$source['span'],$path)||!$this->location($formal->nameLocation,$source['nameSpan'],$path)){return false;}}return true;
    }
    private static function envelope(IssueFilterContext $context,array $p):bool
    {
        $i=$context->issue;$a=$i->annotations[0];
        if($p['kind']==='nullable-collection-offset'){return $i->code==='possibly-null-array-index'&&$i->message==='Possibly using `null` as an array index to access elementof variable $'.$p['receiver'].'.'&&$i->notes===["Using `null` as an array key is equivalent to using an empty string `''`.",'The analysis indicates this index could be `null` at runtime.']&&$i->help==='Ensure the index is always an integer or a string, potentially using checks or assertions before access.'&&$a->message==='Index might be `null` here.';}
        return $p['kind']==='defensive-indexed-model'&&$i->code==='impossible-condition'&&$i->message==='This condition (type `false`) will always evaluate to false.'&&$i->notes===['Because this condition is always false, the code block it controls will never be executed.']&&$i->help==='Check the logic of this expression. If the code block is intended to be unreachable, consider removing it. Otherwise, revise the condition.'&&$a->message==='Expression of type `false` is always falsy';
    }
    private static function starts(Node $n):array{$r=[$n->getStartFilePos()];foreach($n->getComments() as $c){$r[]=$c->getStartFilePos();}foreach($n->attrGroups??[] as $g){$r[]=$g->getStartFilePos();}return $r;}
    private static function hash(array $value):string{$sort=static function(mixed $v)use(&$sort):mixed{if(is_array($v)){foreach($v as $k=>$item){$v[$k]=$sort($item);}if(!array_is_list($v)){ksort($v);}}return $v;};return hash('sha256',json_encode($sort($value),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));}
    private function location(?\Mago\Sdk\SourceLocation $location,?array $span,string $path,?array $starts=null):bool{return $location!==null&&$span!==null&&$this->path($location->file??'')===$path&&in_array($location->span->start,$starts??[$span[0]],true)&&$location->span->end===$span[1];}
    private function path(string $name):string{$name=str_replace('\\','/',str_replace('\\\\?\\','',$name));if(!str_starts_with($name,'/')&&preg_match('~^[a-z]:/~i',$name)!==1){$name=$this->root.'/'.$name;}$real=realpath($name);$root=realpath($this->root);if($real===false||$root===false){return '';}$real=str_replace('\\','/',$real);$root=str_replace('\\','/',$root);if(PHP_OS_FAMILY==='Windows'){$real=strtolower($real);$root=strtolower($root);}return str_starts_with($real,rtrim($root,'/').'/')?$real:'';}
}
