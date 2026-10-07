<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\DefensiveBoundaryGuardSource as Source;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\Metadata\{ClassLikeKind, FunctionLikeKind, MetadataFlags};
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\{KeyedArrayType, MixedType, ScalarType, ScalarTypeKind};
use PhpParser\{Node, NodeFinder};
require_once __DIR__.'/FactoryFakerLocalCacheMarkerMethodContracts.php';

/** Selected static trait-dispatch policy; no absent base method is assigned an owner. */
final class FactoryFakerLocalCacheMarkerContracts
{
    private const SPECS=[
        'markconfigcached'=>['trait'=>'Illuminate\\Foundation\\Testing\\WithCachedConfig','method'=>'markConfigCached',
            'file'=>'vendor/laravel/framework/src/Illuminate/Foundation/Testing/WithCachedConfig.php',
            'storeClass'=>'Illuminate\\Foundation\\Bootstrap\\LoadConfiguration','storeMethod'=>'alwaysUse',
            'storeFile'=>'vendor/laravel/framework/src/Illuminate/Foundation/Bootstrap/LoadConfiguration.php'],
        'markroutescached'=>['trait'=>'Illuminate\\Foundation\\Testing\\WithCachedRoutes','method'=>'markRoutesCached',
            'file'=>'vendor/laravel/framework/src/Illuminate/Foundation/Testing/WithCachedRoutes.php',
            'storeClass'=>'Illuminate\\Foundation\\Support\\Providers\\RouteServiceProvider','storeMethod'=>'loadCachedRoutesUsing',
            'storeFile'=>'vendor/laravel/framework/src/Illuminate/Foundation/Support/Providers/RouteServiceProvider.php'],
    ];
    private const FILES=[
        'vendor/laravel/framework/src/Illuminate/Foundation/Testing/WithCachedConfig.php'=>'fa2cb0fea0d08f55d6cff7ca0795aaef6bab8b6f6bfc12cdbfe336404959a51f',
        'vendor/laravel/framework/src/Illuminate/Foundation/Testing/WithCachedRoutes.php'=>'3d544cf3ca32e7c3ca6e05c3de91949fb6a6f698bea980c5794e2644e1cab2ba',
        'vendor/laravel/framework/src/Illuminate/Foundation/Bootstrap/LoadConfiguration.php'=>'83d3bafe062f219b207993da2425c94ff5d729b774b05c15904a62e706ba51a7',
        'vendor/laravel/framework/src/Illuminate/Foundation/Support/Providers/RouteServiceProvider.php'=>'7c3ad74596579080dc017635c700a956671d21a74fdd8e11220e14c275fdf401',
        'vendor/laravel/framework/src/Illuminate/Support/helpers.php'=>'cf16ec9f74ecbde2bb64a7c4a93e87372f72c9c58daa117998eff4dc42c77fe6',
        'vendor/laravel/framework/src/Illuminate/Container/Container.php'=>'8bbfbc5955205f817106077355a493952e685bc376653236a7188f20ff634ea8',
    ];
    public static function source(array $caller,string $root):?array
    {
        $result=['markers'=>[],'functions'=>[],'sourceHashes'=>[]];
        foreach($caller['frameworkMarkerExposures'] as $exposure){
            $spec=self::SPECS[strtolower($exposure['method'])]??null;
            if($spec===null||strcasecmp($exposure['trait'],$spec['trait'])!==0||!isset($exposure['traitIndex'])){return null;}
            $file=self::file($spec['file'],$root);if($file===null){return null;}
            $traits=(new NodeFinder)->find($file['nodes'],static fn(Node $node):bool=>$node instanceof Node\Stmt\Trait_
                &&strcasecmp($node->namespacedName?->toString()??'',$spec['trait'])===0);
            if(count($traits)!==1){return null;}$trait=$traits[0];$method=$trait->getMethod($spec['method']);
            if($trait->attrGroups!==[]||$trait->getDocComment()!==null||$method===null){return null;}
            $uses=array_filter($trait->stmts,static fn(Node $node):bool=>$node instanceof Node\Stmt\TraitUse);
            if($uses!==[]){return null;}
            $domain=FactoryFakerLocalCacheMarkerMethodContracts::source($method,$spec['trait'],$file['nodes']);if($domain===null){return null;}
            $marker=$spec+['path'=>$file['path'],'hash'=>$file['hash'],'traitSpan'=>Source::span($trait),
                'traitNameSpan'=>Source::span($trait->name),'traitStarts'=>self::starts($trait),'domain'=>$domain];
            $result['sourceHashes'][$file['path']]=$file['hash'];unset($file,$traits,$trait,$method,$uses);
            $marker['effects']=[];
            foreach([[$spec['storeFile'],$spec['storeClass'],$spec['storeMethod']],
                ['vendor/laravel/framework/src/Illuminate/Container/Container.php','Illuminate\\Container\\Container','instance']] as [$relative,$class,$name]){
                $file=self::file($relative,$root);if($file===null){return null;}
                $method=DefensiveBoundarySourceProfile::selected($file['nodes'],$class,$name);
                $domain=$method instanceof Node\Stmt\ClassMethod?FactoryFakerLocalCacheMarkerMethodContracts::source($method,$class,$file['nodes']):null;
                if($domain===null){return null;}
                $marker['effects'][]=['class'=>$class,'method'=>$name,'path'=>$file['path'],'domain'=>$domain];
                $result['sourceHashes'][$file['path']]=$file['hash'];unset($file,$method,$domain);
            }
            $result['markers'][strtolower($spec['method'])]=$marker;
        }
        if($result['markers']===[]){return $result;}
        $file=self::file('vendor/laravel/framework/src/Illuminate/Support/helpers.php',$root);if($file===null){return null;}
        foreach(['class_uses_recursive'=>'$class','trait_uses_recursive'=>'$trait'] as $name=>$parameter){
            $function=DefensiveBoundarySourceProfile::selected($file['nodes'],null,$name);
            if(!$function instanceof Node\Stmt\Function_||$function->byRef||$function->attrGroups!==[]||count($function->params)!==1
                ||!$function->returnType instanceof Node\Identifier||strtolower($function->returnType->name)!=='array'
                ||$function->params[0]->type!==null||$function->params[0]->default!==null||$function->params[0]->byRef
                ||$function->params[0]->variadic||!$function->params[0]->var instanceof Node\Expr\Variable
                ||'$'.$function->params[0]->var->name!==$parameter){return null;}
            $doc=$function->getDocComment();$text=$doc?->getText()??'';
            if($doc===null||preg_match('/@param[ \t]+(object\|string)[ \t]+'.preg_quote($parameter,'/').'\b/',$text,$param,PREG_OFFSET_CAPTURE)!==1
                ||preg_match('/@return[ \t]+(array<string, string>)/',$text,$return,PREG_OFFSET_CAPTURE)!==1
                ||preg_match('/@(phpstan-|psalm-|param-out|template|assert)/',$text)){return null;}
            $result['functions'][$name]=['name'=>$name,'path'=>$file['path'],'span'=>Source::span($function),'nameSpan'=>Source::span($function->name),
                'starts'=>self::starts($function),'parameter'=>$parameter,'parameterSpan'=>Source::span($function->params[0]),
                'parameterNameSpan'=>Source::span($function->params[0]->var),'returnSpan'=>Source::span($function->returnType),
                'docParameterSpan'=>[$doc->getStartFilePos()+$param[1][1],$doc->getStartFilePos()+$param[1][1]+strlen($param[1][0])],
                'docReturnSpan'=>[$doc->getStartFilePos()+$return[1][1],$doc->getStartFilePos()+$return[1][1]+strlen($return[1][0])]];
        }
        $result['sourceHashes'][$file['path']]=$file['hash'];unset($file,$function,$doc);return $result;
    }
    public static function current(IssueFilterContext $context,array $caller,array $declarations,string $root,array $classAliasKeys=[]):array
    {
        $r=['admitted'=>false,'stage'=>'current source-bound conditional marker declarations','nativeTypesChanged'=>false,
            'baseOwnerInvented'=>false,'nativeBindings'=>[]];$source=$caller['markerSource'];
        foreach($source['sourceHashes'] as $file=>$hash){if(@hash_file('sha256',$file)!==$hash){return $r;}}
        // A literal marker in another source is not assumed equivalent. This is
        // deliberately conservative over the already complete source catalogue.
        foreach($declarations as $declaration){
            $spec=self::SPECS[strtolower($declaration['name'])]??null;if($spec===null){continue;}
            $expected=self::path($spec['file'],$root);
            if(($declaration['alias']??false)||strcasecmp($declaration['class']??'',$spec['trait'])!==0
                ||$expected===null||!self::samePath(self::path($declaration['path'],$root)??'',$expected)
                ||@hash_file('sha256',$expected)!==self::FILES[$spec['file']]){return $r;}
        }
        foreach($source['markers'] as $marker){
            if(isset($classAliasKeys[strtolower($marker['trait'])])||isset($classAliasKeys[strtolower($marker['storeClass'])])){return $r;}
            $r['stage']='genuine absent base marker or selected current trait method';
            $baseDeclaring=$context->codebase->getDeclaringMethod($caller['callerClass'],$marker['method']);
            $baseEffective=$context->codebase->getMethod($caller['callerClass'],$marker['method']);
            $r['baseMarkerLookups'][$marker['method']]=['declaring'=>$baseDeclaring===null?null:DefensiveBoundaryGuardProof::compact($baseDeclaring),
                'effective'=>$baseEffective===null?null:DefensiveBoundaryGuardProof::compact($baseEffective)];
            // The supported abstract base has neither API. A newly provided
            // base method is a higher-priority callee and therefore defers.
            if($baseDeclaring!==null||$baseEffective!==null){return $r;}
            $trait=$context->codebase->getTrait($marker['trait']);
            if($trait===null||$trait->kind!==ClassLikeKind::Trait||$trait->hasIncompleteHierarchy()
                ||$trait->flags->contains(MetadataFlags::BUILTIN)||$trait->usedTraits!==[]||$trait->templates!==[]
                ||$trait->mixins!==[]||$trait->typeAliases!==[]||$trait->directParentClass!==null||$trait->directParentInterfaces!==[]
                ||strcasecmp($trait->name,$marker['trait'])!==0||strcasecmp($trait->originalName,$marker['trait'])!==0
                ||!self::located($trait->location,$marker['path'],$marker['traitSpan'],$root,$marker['traitStarts'])
                ||!self::located($trait->nameLocation,$marker['path'],$marker['traitNameSpan'],$root)){return $r;}
            $r['nativeBindings']['trait:'.$marker['trait']]=['name'=>$trait->name,'originalName'=>$trait->originalName,
                'location'=>$trait->location,'nameLocation'=>$trait->nameLocation,'kind'=>$trait->kind->name,'usedTraits'=>$trait->usedTraits];
            foreach([['class'=>$marker['trait'],'method'=>$marker['method'],'path'=>$marker['path'],'domain'=>$marker['domain']],...$marker['effects']] as $dependency){
                $declaring=$context->codebase->getDeclaringMethod($dependency['class'],$dependency['method']);
                $effective=$context->codebase->getMethod($dependency['class'],$dependency['method']);
                $r['stage']='genuine current marker effect '.$dependency['class'].'::'.$dependency['method'];
                if($declaring===null||$effective!==null&&$declaring!=$effective){return $r;}
                $domain=FactoryFakerLocalCacheMarkerMethodContracts::current($context,$declaring,$dependency['domain'],$dependency['path'],$root);
                $r['selectedDomain']=$domain;if(!$domain['admitted']){return $r;}
                $r['nativeBindings']['method:'.$dependency['class'].'::'.$dependency['method']]=DefensiveBoundaryGuardProof::compact($declaring);
            }
        }
        foreach($source['functions'] as $function){
            $r['stage']='genuine selected trait-index function '.$function['name'];$native=$context->codebase->getFunction($function['name']);
            if(!self::functionCurrent($context,$native,$function,$root)){return $r;}
            $r['nativeBindings']['function:'.$function['name']]=DefensiveBoundaryGuardProof::compact($native);
        }
        foreach($source['sourceHashes'] as $file=>$hash){if(@hash_file('sha256',$file)!==$hash){return $r;}}
        $r['stage']='current closed marker trait and source priority policy';$r['admitted']=true;return $r;
    }
    private static function functionCurrent(IssueFilterContext $context,?\Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata $native,array $source,string $root):bool
    {
        if($native===null||$native->kind!==FunctionLikeKind::Function_||$native->identifier->class!==null||$native->static||$native->abstract
            ||strcasecmp($native->identifier->name,$source['name'])!==0||$native->flags->contains(MetadataFlags::BUILTIN)
            ||$native->flags->contains(MetadataFlags::BY_REFERENCE)||$native->flags->contains(MetadataFlags::MAGIC_METHOD)
            ||$native->templates!==[]||$native->whereConstraints!==[]||count($native->parameters)!==1
            ||!self::located($native->location,$source['path'],$source['span'],$root,$source['starts'])
            ||!self::located($native->nameLocation,$source['path'],$source['nameSpan'],$root)){return false;}
        $parameter=$native->parameters[0];
        if($parameter->name!==$source['parameter']||$parameter->declaredType!==null||$parameter->defaultType!==null
            ||$parameter->outType!==null||$parameter->closureThisType!==null||$parameter->flags->contains(MetadataFlags::HAS_DEFAULT)
            ||$parameter->flags->contains(MetadataFlags::BY_REFERENCE)||$parameter->flags->contains(MetadataFlags::VARIADIC)
            ||!self::located($parameter->location,$source['path'],$source['parameterSpan'],$root)
            ||!self::located($parameter->nameLocation,$source['path'],$source['parameterNameSpan'],$root)
            ||!self::meta($parameter->type,true,$source['path'],$source['docParameterSpan'],$root)
            ||!$context->types->equals($parameter->type->type,Type::union(Type::object(),Type::string()))
            ||!self::meta($native->returnType,true,$source['path'],$source['docReturnSpan'],$root)
            ||!$context->types->equals($native->returnType->type,Type::array(Type::string(),Type::string()))
            ||!self::meta($native->declaredReturnType,false,$source['path'],$source['returnSpan'],$root)){return false;}
        $declared=$native->declaredReturnType->type;
        $atom=$declared->atomicTypes[0]??null;
        return count($declared->atomicTypes)===1&&$atom instanceof KeyedArrayType&&$atom->knownItems===null&&!$atom->nonEmpty
            &&$atom->keyType!==null&&$atom->valueType!==null&&count($atom->keyType->atomicTypes)===1
            &&$atom->keyType->atomicTypes[0] instanceof ScalarType&&$atom->keyType->atomicTypes[0]->kind===ScalarTypeKind::ArrayKey
            &&count($atom->valueType->atomicTypes)===1&&$atom->valueType->atomicTypes[0] instanceof MixedType;
    }
    private static function meta(?\Mago\Sdk\Analyzer\Metadata\TypeMetadata $type,bool $doc,string $path,array $span,string $root):bool
    {
        if($type===null||$type->fromDocblock!==$doc||$type->inferred||!self::located($type->location,$path,$span,$root)){return false;}
        foreach(get_object_vars($type->type->flags) as $flag=>$value){if(!is_bool($value)||$flag!=='populated'&&$value!==false){return false;}}return true;
    }
    private static function file(string $relative,string $root):?array
    {
        $path=self::path($relative,$root);$expected=self::FILES[$relative]??null;
        if($path===null||$expected===null||@hash_file('sha256',$path)!==$expected){return null;}
        $bytes=@file_get_contents($path);return $bytes===false?null:['path'=>$path,'hash'=>$expected,'nodes'=>Source::parse($bytes)];
    }
    private static function path(string $file,string $root):?string
    {
        $root=rtrim(DefensiveBoundarySourceProfile::path($root),'/');$path=DefensiveBoundarySourceProfile::path((new PhpSource($root))->path($file));
        if(preg_match('~(?:^|/)\.{1,2}(?:/|$)~',$path)||!str_starts_with(strtolower($path),strtolower($root).'/')){return null;}return $path;
    }
    private static function samePath(string $a,string $b):bool{return DIRECTORY_SEPARATOR==='\\'?strcasecmp($a,$b)===0:$a===$b;}
    private static function located(?\Mago\Sdk\SourceLocation $location,string $path,array $span,string $root,?array $starts=null):bool
    {
        return $location!==null&&self::samePath(self::path($location->file,$root)??'',$path)
            &&in_array($location->span->start,$starts??[$span[0]],true)&&$location->span->end===$span[1];
    }
    private static function starts(Node $node):array{$v=[$node->getStartFilePos()];foreach($node->getComments() as $comment){$v[]=$comment->getStartFilePos();}return $v;}
}
