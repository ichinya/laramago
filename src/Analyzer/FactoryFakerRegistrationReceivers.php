<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\DefensiveBoundaryGuardSource as Source;
use Ichinya\Laramago\Analyzer\StaticAnalysis\{ContainerBindings, FrameworkContainerAliases, PhpSource};
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\Metadata\{ClassLikeKind, FunctionLikeKind, MetadataFlags};
use PhpParser\{Node, NodeFinder};
require_once __DIR__.'/FactoryFakerRegistrationReceiverProfiles.php';
require_once __DIR__.'/FactoryFakerClosureBindingSource.php';
require_once __DIR__.'/FactoryFakerSecondaryReceiverSource.php';
require_once __DIR__.'/FactoryFakerLocalCacheReceiverSource.php';
require_once __DIR__.'/FactoryFakerLocalCacheMethodContracts.php';
require_once __DIR__.'/FactoryFakerLocalCacheMarkerContracts.php';
require_once __DIR__.'/FactoryFakerLocalCacheCallerTraits.php';
require_once __DIR__.'/FactoryFakerCacheFacadeRegistrationContracts.php';

/** Compact physical receiver classification, shared by both default-source catalogues. */
final class FactoryFakerRegistrationReceivers
{
    private const APPLICATION='Illuminate\\Foundation\\Application';
    private const BUILDER='Illuminate\\Foundation\\Configuration\\ApplicationBuilder';
    private const MIDDLEWARE='Illuminate\\Foundation\\Configuration\\Middleware';
    private array $files=[];private array $classes=[];private array $methods=[];private array $roles=[];
    private array $bindingKeys=[];private array $hazards=[];private ?string $vendor=null;
    private array $closureCallers=[];private array $classAliasKeys=[];
    private array $localCacheCallers=[];private array $localCacheMethodDomains=[];
    private array $cacheMarkerDeclarations=[];
    private ?array $cacheFacadeSource=null;private array $cacheFacadeFacts=[];
    public function __construct(private readonly string $root){$this->cacheFacadeSource=FactoryFakerCacheFacadeRegistrationContracts::source($root);}

    /** Does not visit or consume descendants: the caller must still inspect every nested call. */
    public function unrelated(Node\Expr\CallLike $call,array $nodes,?string $path=null):bool
    {
        if(!self::plain($call)||!$call instanceof Node\Expr\MethodCall&&!$call instanceof Node\Expr\StaticCall||!$call->name instanceof Node\Identifier){return false;}
        $name=strtolower($call->name->name);$role=null;
        if($call instanceof Node\Expr\StaticCall&&$call->class instanceof Node\Name){
            $class=strtolower($call->class->toString());
            if($class==='carbon\\carbonimmutable'&&$name==='instance'&&count($call->args)===1
                &&$this->classSource('vendor/nesbot/carbon/src/Carbon/CarbonImmutable.php','Carbon\\CarbonImmutable')
                &&$this->classSource('vendor/nesbot/carbon/src/Carbon/Traits/Date.php','Carbon\\Traits\\Date',false)
                &&$this->methodSource('vendor/nesbot/carbon/src/Carbon/Traits/Creator.php','Carbon\\Traits\\Creator','instance','Carbon\\CarbonImmutable')){$role='carbon-date-conversion';}
            elseif($class==='illuminate\\support\\facades\\cache'&&$name==='extend'&&count($call->args)===2
                &&$this->cacheFacadeRecipe()){$role='cache-facade-driver-extension';}
            elseif($class==='illuminate\\support\\facades\\db'&&$name==='extend'&&count($call->args)===2
                &&$this->classSource('vendor/laravel/framework/src/Illuminate/Support/Facades/DB.php','Illuminate\\Support\\Facades\\DB')
                &&$this->methodSource('vendor/laravel/framework/src/Illuminate/Support/Facades/DB.php','Illuminate\\Support\\Facades\\DB','getFacadeAccessor')
                &&$this->methodSource('vendor/laravel/framework/src/Illuminate/Database/DatabaseManager.php','Illuminate\\Database\\DatabaseManager','extend')
                &&$this->methodSource('vendor/laravel/framework/src/Illuminate/Database/DatabaseServiceProvider.php','Illuminate\\Database\\DatabaseServiceProvider','registerConnectionServices')){$role='database-driver-extension';}
        }
        elseif($call instanceof Node\Expr\MethodCall&&$name==='extend'&&count($call->args)===2
            &&$call->var instanceof Node\Expr\FuncCall&&self::plain($call->var)&&self::globalFunction($call->var,'app')
            &&count($call->var->args)===1&&$call->var->args[0]->value instanceof Node\Scalar\String_&&$call->var->args[0]->value->value==='cache'
            &&$this->methodSource('vendor/laravel/framework/src/Illuminate/Foundation/helpers.php',null,'app')
            &&$this->methodSource('vendor/laravel/framework/src/Illuminate/Cache/CacheManager.php','Illuminate\\Cache\\CacheManager','extend')
            &&$this->methodSource('vendor/laravel/framework/src/Illuminate/Cache/CacheServiceProvider.php','Illuminate\\Cache\\CacheServiceProvider','register')){$role='cache-driver-extension';}
        elseif($call instanceof Node\Expr\MethodCall&&$name==='alias'&&count($call->args)===1&&$this->middlewareCallback($call,$nodes)){$role='middleware-alias-configuration';}
        elseif($path!==null&&$call instanceof Node\Expr\MethodCall&&$name==='extend'){
            $recipe=FactoryFakerLocalCacheReceiverSource::classify($call,$nodes);
            if($recipe!==null&&$this->localCacheRecipe($recipe,$path)){$role=$recipe['role'];}
        }
        if($role===null){
            $recipe=FactoryFakerSecondaryReceiverSource::classify($call,$nodes);
            if($recipe!==null&&$this->secondaryRecipe($recipe)){$role=$recipe['role'];}
        }
        if($role===null){return false;}$this->roles[$role]=($this->roles[$role]??0)+1;return true;
    }

    public function observeSource(array $nodes,string $path):void
    {
        $finder=new NodeFinder;
        if($this->cacheFacadeSource!==null){$this->cacheFacadeFacts[]=FactoryFakerCacheFacadeRegistrationContracts::observeSource($nodes,$this->nativePath($path),$this->cacheFacadeSource);}
        foreach($finder->findInstanceOf($nodes,Node\Stmt\ClassLike::class) as $class){
            foreach($class->getMethods() as $method){
                if(in_array(strtolower($method->name->name),['markconfigcached','markroutescached'],true)){
                    $this->cacheMarkerDeclarations[]=['class'=>$class->namespacedName?->toString(),
                        'name'=>$method->name->name,'path'=>$path,'span'=>Source::span($method)];
                }
            }
            foreach($finder->findInstanceOf([$class],Node\Stmt\TraitUseAdaptation\Alias::class) as $alias){
                $selectedMarker=in_array(strtolower($alias->method->name),['markconfigcached','markroutescached'],true)
                    ?$alias->method->name:($alias->newName!==null&&in_array(strtolower($alias->newName->name),['markconfigcached','markroutescached'],true)
                        ?$alias->newName->name:null);
                if($selectedMarker!==null){
                    $this->cacheMarkerDeclarations[]=['class'=>$class->namespacedName?->toString(),
                        'name'=>$selectedMarker,'path'=>$path,'span'=>Source::span($alias),'alias'=>true];
                }
            }
        }
        foreach($finder->findInstanceOf($nodes,Node\Stmt\Function_::class) as $function){
            if(in_array(strtolower($function->name->name),['class_uses_recursive','trait_uses_recursive'],true)
                &&!$this->standardFile($path,'vendor/laravel/framework/src/Illuminate/Support/helpers.php')){$this->hazards['trait-index-helper-shadow']=true;}
            if(strcasecmp($function->name->name,'app')===0&&!$this->standardFile($path,'vendor/laravel/framework/src/Illuminate/Foundation/helpers.php')){$this->hazards['app-helper-shadow']=true;}
        }
        foreach($finder->findInstanceOf($nodes,Node\Expr\Assign::class) as $assignment){
            if($assignment->var instanceof Node\Expr\StaticPropertyFetch&&$assignment->var->name instanceof Node\VarLikeIdentifier
                &&strtolower($assignment->var->name->name)==='applicationbuilder'){$this->hazards['application-builder-write']=true;}
        }
        foreach($finder->find($nodes,static fn(Node $node):bool=>$node instanceof Node\Expr\MethodCall||$node instanceof Node\Expr\StaticCall) as $dynamic){
            if($dynamic->name instanceof Node\Identifier){continue;}
            $selected=$dynamic instanceof Node\Expr\MethodCall&&($dynamic->var instanceof Node\Expr\PropertyFetch
                &&$dynamic->var->var instanceof Node\Expr\Variable&&$dynamic->var->var->name==='this'
                &&$dynamic->var->name instanceof Node\Identifier&&$dynamic->var->name->name==='app'
                ||$dynamic->var instanceof Node\Expr\FuncCall&&self::globalFunction($dynamic->var,'app'));
            if($selected){$this->hazards['dynamic-container-api']=true;}
        }
        foreach($finder->findInstanceOf($nodes,Node\Expr\FuncCall::class) as $call){
            if(!self::globalFunction($call,'class_alias')||$call->isFirstClassCallable()){continue;}
            $target=$call->args[1]->value??null;$literal=self::literalKey($target);
            if($literal!==null){$this->classAliasKeys[strtolower($literal)]=true;}
            if($literal===null||in_array(strtolower($literal),['carbon\\carbonimmutable',strtolower(self::APPLICATION),strtolower(self::BUILDER),strtolower(self::MIDDLEWARE),
                'illuminate\\support\\facades\\db','illuminate\\support\\facades\\cache','illuminate\\support\\facades\\facade',
                'illuminate\\database\\databasemanager','illuminate\\cache\\cachemanager',
                'illuminate\\support\\once','illuminate\\support\\serviceprovider','illuminate\\container\\container',
                'symfony\\component\\console\\input\\argvinput','symfony\\component\\console\\input\\input',
                'symfony\\component\\console\\input\\inputdefinition','faker\\generator','faker\\factory','faker\\provider\\base','faker\\provider\\lorem',
                'illuminate\\database\\eloquent\\factories\\factory','illuminate\\contracts\\foundation\\application',
                'illuminate\\contracts\\container\\container'],true)){$this->hazards['selected-receiver-class-alias']=true;}
        }
    }

    /** Both alias operands participate in related receiver-root precedence. */
    public function binding(Node\Expr\CallLike $call,string $path,array $nodes=[]):?array
    {
        if(!$call->name instanceof Node\Identifier){return null;}$name=strtolower($call->name->name);
        if(!in_array($name,['bind','singleton','scoped','instance','extend','alias','bindif','singletonif','swap'],true)){return null;}
        if($this->standardFile($path,'vendor/laravel/framework/src/Illuminate/Database/DatabaseServiceProvider.php')
            ||$this->standardFile($path,'vendor/laravel/framework/src/Illuminate/Cache/CacheServiceProvider.php')){return null;}
        // The scanners already excluded only physically certified unrelated
        // receivers. A remaining extend call has no receiver contract; its
        // literal driver/key cannot establish a Container abstract argument.
        if($name==='extend'){$this->hazards['unknown-extension-receiver']=true;return null;}
        if(!self::plain($call)){$this->hazards['unknown-related-container-key']=true;return null;}
        $keys=[];$unknown=false;
        foreach($name==='alias'?[0,1]:[0] as $index){$key=self::literalKey($call->args[$index]->value??null);
            if($key===null&&$index===0&&$nodes!==[]){$recipe=FactoryFakerClosureBindingSource::classify($call,$nodes);
                if($recipe!==null&&$this->closureRecipe($recipe,$nodes,$path)){$key=$recipe['key'];}}
            if($key===null){$this->hazards['unknown-related-container-key']=true;$unknown=true;continue;}
            $keys[]=$key;$this->bindingKeys[strtolower($key)]=true;
        }
        return $unknown?null:$keys;
    }
    public function declaredBinding(?Node $key):void{$literal=self::literalKey($key);if($literal!==null){$this->bindingKeys[strtolower($literal)]=true;}}

    public function current(IssueFilterContext $context):array
    {
        $r=['admitted'=>false,'stage'=>'current-unrelated-registration-receiver-sources','recognizedRoles'=>$this->roles,
            'sourceInputs'=>$this->files,'relatedBindingKeys'=>array_keys($this->bindingKeys),'hazards'=>array_keys($this->hazards),'nativeTypesChanged'=>false];
        foreach($this->files as $path=>$hash){if(@hash_file('sha256',$path)!==$hash){return $r;}}
        if(isset($this->hazards['unknown-extension-receiver'])){return $r;}
        if(isset($this->hazards['dynamic-container-api'])||isset($this->hazards['selected-receiver-class-alias'])){return $r;}
        foreach($this->closureCallers as $caller){if(isset($this->classAliasKeys[strtolower($caller['class'])])){return $r;}}
        foreach($this->localCacheCallers as $caller){if(isset($this->classAliasKeys[strtolower($caller['callerClass'])])){return $r;}}
        if($this->localCacheCallers!==[]&&isset($this->hazards['trait-index-helper-shadow'])){return $r;}
        if(isset($this->roles['cache-driver-extension'])&&isset($this->hazards['app-helper-shadow'])
            ||isset($this->roles['middleware-alias-configuration'])&&isset($this->hazards['application-builder-write'])
            ||$this->roles!==[]&&isset($this->hazards['selected-receiver-class-alias'])){return $r;}
        $r['stage']='current-related-container-root-precedence';
        if((isset($this->roles['database-driver-extension'])||isset($this->roles['cache-driver-extension'])||isset($this->roles['cache-facade-driver-extension'])||$this->closureCallers!==[]||$this->localCacheCallers!==[])
            &&isset($this->hazards['unknown-related-container-key'])){return $r;}
        if($this->closureCallers!==[]||$this->localCacheCallers!==[]||isset($this->roles['cache-facade-driver-extension'])){
            $configured=new ContainerBindings($this->root);
            foreach(['app',self::APPLICATION,'Illuminate\\Contracts\\Foundation\\Application','Illuminate\\Container\\Container',
                'Illuminate\\Contracts\\Container\\Container'] as $key){if(isset($this->bindingKeys[strtolower($key)])||$configured->configured($key)){return $r;}}
        }
        if($this->localCacheCallers!==[]||isset($this->roles['cache-facade-driver-extension'])){
            $appKeys=$this->serviceKeys('app',self::APPLICATION);if($appKeys===null){return $r;}
            $configured=new ContainerBindings($this->root);
            foreach($appKeys as $key){if(isset($this->bindingKeys[strtolower($key)])||$configured->configured($key)){return $r;}}
            if(strcasecmp((new FrameworkContainerAliases(new PhpSource($this->root)))->concrete($context->codebase,'app')??'',self::APPLICATION)!==0){return $r;}
        }
        foreach(['database-driver-extension'=>['db','Illuminate\\Database\\DatabaseManager'],'cache-driver-extension'=>['cache','Illuminate\\Cache\\CacheManager'],
            'cache-facade-driver-extension'=>['cache','Illuminate\\Cache\\CacheManager']] as $role=>[$key,$expected]){
            if(!isset($this->roles[$role])){continue;}
            $alias=$this->serviceKeys($key,$expected);if($alias===null){return $r;}
            $configured=new ContainerBindings($this->root);
            foreach($alias as $name){if(isset($this->bindingKeys[strtolower($name)])||$configured->configured($name)){return $r;}}
            if(strcasecmp((new FrameworkContainerAliases(new PhpSource($this->root)))->concrete($context->codebase,$key)??'',$expected)!==0){return $r;}
        }
        if(isset($this->roles['cache-facade-driver-extension'])){
            $r['cacheFacadePriority']=FactoryFakerCacheFacadeRegistrationContracts::priority($this->cacheFacadeFacts);
            if(!$r['cacheFacadePriority']['admitted']){return $r;}
        }
        $r['stage']='genuine-current-unrelated-receiver-declarations';$profiles=[];
        foreach($this->classes as $class){
            if(!$class['nativeRequired']){continue;}$native=$context->codebase->getClass($class['name']);
            $r['selectedDependency']=['kind'=>'class','name'=>$class['name'],'expectedSource'=>$class];
            $r['selectedNativeProfile']=$native===null?null:self::publicMetadata($native);
            if($native===null||$native->kind!==ClassLikeKind::Class_||$native->hasIncompleteHierarchy()||$native->flags->contains(MetadataFlags::BUILTIN)
                ||strcasecmp($native->name,$class['name'])!==0||strcasecmp($native->originalName,$class['name'])!==0
                ||!$this->located($native->location,$class['path'],$class['span'],$class['starts'])||!$this->located($native->nameLocation,$class['path'],$class['nameSpan'])
                ||$native->flags->contains(MetadataFlags::FINAL)!==$class['final']||strcasecmp($native->directParentClass??'',$class['parent']??'')!==0
                ||count($native->mixins)!==$class['mixins']||count($native->typeAliases)!==$class['aliases']){return $r;}
            $profiles['class:'.$class['name']]=['name'=>$native->name,'originalName'=>$native->originalName,'location'=>$native->location,'nameLocation'=>$native->nameLocation,'flags'=>$native->flags->bits];
        }
        foreach($this->methods as $method){
            $native=$method['queryClass']===null?$context->codebase->getFunction($method['name']):$context->codebase->getDeclaringMethod($method['queryClass'],$method['name']);
            $r['selectedDependency']=['kind'=>'method','queryClass'=>$method['queryClass'],'name'=>$method['name'],'expectedSource'=>$method];
            $r['selectedNativeProfile']=$native===null?null:DefensiveBoundaryGuardProof::compact($native);
            if($native===null||$native->kind!==($method['queryClass']===null?FunctionLikeKind::Function_:FunctionLikeKind::Method)
                ||$native->static!==$method['static']||$native->abstract||$native->flags->contains(MetadataFlags::BUILTIN)||$native->flags->contains(MetadataFlags::MAGIC_METHOD)
                ||$native->flags->contains(MetadataFlags::BY_REFERENCE)||strcasecmp($native->identifier->name,$method['name'])!==0
                ||($method['queryClass']===null?$native->identifier->class!==null:!in_array(strtolower($native->identifier->class??''),[strtolower($method['queryClass']),strtolower($method['sourceClass'])],true))
                ||$method['visibility']!==null&&$native->visibility?->name!==$method['visibility']
                ||!$this->located($native->location,$method['path'],$method['span'],$method['starts'])||!$this->located($native->nameLocation,$method['path'],$method['nameSpan'])
                ||count($native->parameters)!==count($method['formals'])){return $r;}
            $domain=null;$domainKey=($method['queryClass']??'').'::'.strtolower($method['name']);
            if(isset($this->localCacheMethodDomains[$domainKey])){
                $domain=FactoryFakerLocalCacheMethodContracts::current($context,$native,$this->localCacheMethodDomains[$domainKey],$method['path'],$this->root);
                $r['selectedMethodDomain']=$domain;if(!$domain['admitted']){return $r;}
                $profiles['localCacheMethodDomain:'.$domainKey]=$domain;
            }
            foreach($method['formals'] as $index=>$formal){$parameter=$native->parameters[$index];
                if($parameter->name!==$formal['name']||$parameter->flags->contains(MetadataFlags::BY_REFERENCE)||$parameter->flags->contains(MetadataFlags::VARIADIC)
                    ||$parameter->flags->contains(MetadataFlags::HAS_DEFAULT)!==$formal['optional']
                    ||$parameter->outType!==null||!$this->parameterClosureThisCurrent($parameter->closureThisType,$formal['closureThis']??null,$method)
                    ||!$this->located($parameter->location,$method['path'],$formal['span'])
                    ||!$this->located($parameter->nameLocation,$method['path'],$formal['nameSpan'])){return $r;}
                foreach([$parameter->type,$parameter->declaredType] as $type){if($type?->location!==null&&!$this->within($type->location,$method)&&!($domain['admitted']??false)){return $r;}}
            }
            foreach([$native->returnType,$native->declaredReturnType] as $type){if($type?->location!==null&&!$this->within($type->location,$method)){return $r;}}
            $profiles['method:'.($method['queryClass']??'').'::'.$method['name']]=DefensiveBoundaryGuardProof::compact($native);
        }
        $r['stage']='current-physical-provider-closure-key-receivers';
        foreach($this->closureCallers as $caller){
            $r['selectedDependency']=['kind'=>'closure-key-receiver','expectedSource'=>$caller];
            if(!$this->closureCallerCurrent($context,$caller,$profiles,$r)){return $r;}
        }
        $r['stage']='current-local-cache-application-caller-and-effects';
        foreach($this->localCacheCallers as $caller){
            $r['selectedDependency']=['kind'=>'local-cache-caller','source'=>$caller];
            if(!$this->localCacheCallerCurrent($context,$caller,$profiles,$r)){return $r;}
            $markers=FactoryFakerLocalCacheMarkerContracts::current($context,$caller,$this->cacheMarkerDeclarations,$this->root,$this->classAliasKeys);
            $r['selectedMarkerContract']=$markers;if(!$markers['admitted']){return $r;}
            $profiles['localCacheMarkers:'.$caller['callerClass'].'::'.$caller['callerMethod']]=$markers;
        }
        if(isset($this->roles['cache-facade-driver-extension'])){
            $r['stage']='current physical cache facade forwarding and roots';
            if($this->cacheFacadeSource===null){return $r;}
            $facade=FactoryFakerCacheFacadeRegistrationContracts::current($context,$this->cacheFacadeSource,$this->root);
            $r['cacheFacadeContract']=['kind'=>'cache-facade-driver-extension']+$facade;
            if(!$facade['admitted']||!$this->cacheFacadeConstructorCurrent($context,$r)){return $r;}
            $profiles['cacheFacadeDriverExtension']=$r['cacheFacadeContract'];
        }
        if(isset($this->roles['middleware-alias-configuration'])){
            $builder=$context->codebase->getDeclaringProperty(self::APPLICATION,'$applicationBuilder');
            if($builder===null||$builder->defaultType===null||$builder->defaultType->fromDocblock||!$builder->defaultType->inferred
                ||(new \Ichinya\Laramago\Analyzer\StaticAnalysis\ModelReflection($context->codebase,new PhpSource($this->root)))->default(self::APPLICATION,'applicationBuilder')!==self::BUILDER){return $r;}
            $profiles['applicationBuilder']=$builder;
        }
        $r['nativeProfiles']=$profiles;$r['stage']='current-unrelated-registration-api-contracts';$r['admitted']=true;return $r;
    }

    private function cacheFacadeRecipe():bool
    {
        if($this->cacheFacadeSource===null){return false;}
        foreach($this->cacheFacadeSource['sourceHashes'] as $path=>$hash){$this->files[$path]=$hash;}
        foreach([
            ['vendor/laravel/framework/src/Illuminate/Cache/CacheManager.php','Illuminate\Cache\CacheManager','extend'],
            ['vendor/laravel/framework/src/Illuminate/Cache/CacheServiceProvider.php','Illuminate\Cache\CacheServiceProvider','register'],
        ] as [$file,$class,$name]){
            if(!$this->methodSource($file,$class,$name)){return false;}
            $nodes=$this->nodes($file);if($nodes===null){return false;}
            $method=DefensiveBoundarySourceProfile::selected($nodes,$class,$name);
            $domain=$method instanceof Node\Stmt\ClassMethod?FactoryFakerLocalCacheMethodContracts::source($method,$class):null;
            unset($nodes,$method);if($domain===null){return false;}
            $key=$class.'::'.strtolower($name);
            if(isset($this->localCacheMethodDomains[$key])&&$this->localCacheMethodDomains[$key]!==$domain){return false;}
            $this->localCacheMethodDomains[$key]=$domain;
        }
        return $this->methodSource('vendor/laravel/framework/src/Illuminate/Cache/CacheManager.php','Illuminate\Cache\CacheManager','__construct');
    }
    private function cacheFacadeConstructorCurrent(IssueFilterContext $context,array &$r):bool
    {
        $declaring=$context->codebase->getDeclaringMethod('Illuminate\Cache\CacheManager','__construct');
        $effective=$context->codebase->getMethod('Illuminate\Cache\CacheManager','__construct');
        $source=$this->methods['Illuminate\Cache\CacheManager::__construct']??null;
        $r['cacheFacadeConstructor']=['declaring'=>$declaring===null?null:DefensiveBoundaryGuardProof::compact($declaring),
            'effective'=>$effective===null?null:DefensiveBoundaryGuardProof::compact($effective)];
        if($declaring===null||$source===null||$effective!==null&&$declaring!=$effective||count($declaring->parameters)!==1
            ||$declaring->declaredReturnType!==null||$declaring->returnType!==null){return false;}
        $parameter=$declaring->parameters[0];$type=$parameter->type;
        return $parameter->declaredType===null&&$parameter->defaultType===null&&$parameter->outType===null&&$parameter->closureThisType===null
            &&$type!==null&&$type->fromDocblock&&!$type->inferred&&$type->location!==null&&$this->within($type->location,$source)
            &&self::plainNamedObject($type->type,'Illuminate\Contracts\Foundation\Application');
    }

    private function secondaryRecipe(array $recipe):bool
    {
        foreach($recipe['requiredClasses'] as $class){if(!$this->classSource($class['file'],$class['class'])){return false;}}
        foreach($recipe['requiredMethods'] as $method){
            if(!$this->methodSource($method['file'],$method['sourceClass'],$method['name'],$method['queryClass'])){return false;}
        }
        return true;
    }

    private function localCacheRecipe(array $recipe,string $path):bool
    {
        $path=$this->nativePath($path);$hash=@hash_file('sha256',$path);
        $root=rtrim(DefensiveBoundarySourceProfile::path($this->root),'/');
        if($hash===false||!str_starts_with(strtolower($path),strtolower($root).'/')){return false;}
        foreach($recipe['requiredClasses'] as $class){if(!$this->classSource($class['file'],$class['class'])){return false;}}
        foreach($recipe['requiredMethods'] as $method){
            if(!$this->methodSource($method['file'],$method['sourceClass'],$method['name'],$method['queryClass'])){return false;}
            $nodes=$this->nodes($method['file']);if($nodes===null){return false;}
            $physical=DefensiveBoundarySourceProfile::selected($nodes,$method['sourceClass'],$method['name']);
            $domain=$physical instanceof Node\Stmt\ClassMethod?FactoryFakerLocalCacheMethodContracts::source($physical,$method['sourceClass']):null;
            unset($nodes,$physical);if($domain===null){return false;}
            $key=$method['queryClass'].'::'.strtolower($method['name']);
            if(isset($this->localCacheMethodDomains[$key])&&$this->localCacheMethodDomains[$key]!==$domain){return false;}
            $this->localCacheMethodDomains[$key]=$domain;
        }
        // The constructor body and selected native declaration are separate
        // dependencies of the literal CacheServiceProvider singleton producer.
        if(!$this->methodSource('vendor/laravel/framework/src/Illuminate/Cache/CacheManager.php','Illuminate\\Cache\\CacheManager','__construct')){return false;}
        $markerSource=FactoryFakerLocalCacheMarkerContracts::source($recipe,$this->root);
        if($markerSource===null){return false;}
        foreach($markerSource['sourceHashes'] as $file=>$expected){$this->files[$file]=$expected;}
        $recipe+=['path'=>$path,'hash'=>$hash,'markerSource'=>$markerSource];
        $key=$recipe['callerClass'].'::'.strtolower($recipe['callerMethod']);
        if(isset($this->localCacheCallers[$key])&&$this->localCacheCallers[$key]!==$recipe){return false;}
        $this->files[$path]=$hash;$this->localCacheCallers[$key]=$recipe;return true;
    }
    private function localCacheCallerCurrent(IssueFilterContext $context,array $caller,array &$profiles,array &$receipt):bool
    {
        $class=$context->codebase->getClass($caller['callerClass']);
        $receipt['selectedNativeProfile']=['stage'=>'local-cache-physical-caller-class','profile'=>$class===null?null:self::publicMetadata($class)];
        if($class===null||$class->kind!==ClassLikeKind::Class_||$class->hasIncompleteHierarchy()
            ||$class->flags->contains(MetadataFlags::BUILTIN)||$class->flags->contains(MetadataFlags::FINAL)!==$caller['callerClassFinal']
            ||$class->flags->contains(MetadataFlags::ABSTRACT)!==$caller['callerClassAbstract']
            ||strcasecmp($class->name,$caller['callerClass'])!==0||strcasecmp($class->originalName,$caller['callerClass'])!==0
            ||strcasecmp($class->directParentClass??'',$caller['callerClassParent']??'')!==0
            ||$class->mixins!==[]||$class->typeAliases!==[]||$class->templates!==[]
            ||!$this->located($class->location,$caller['path'],$caller['callerClassSpan'],$caller['callerClassStarts'])
            ||!$this->located($class->nameLocation,$caller['path'],$caller['callerClassNameSpan'])){return false;}
        $traits=FactoryFakerLocalCacheCallerTraits::current($context,$class,$this->root,$this->classAliasKeys);
        $receipt['selectedCallerTraitContract']=$traits;if(!$traits['admitted']){return false;}
        $profiles['localCacheCallerTraits:'.$caller['callerClass']]=$traits;
        $declaring=$context->codebase->getDeclaringMethod($caller['callerClass'],$caller['callerMethod']);
        $effective=$context->codebase->getMethod($caller['callerClass'],$caller['callerMethod']);
        $receipt['selectedNativeProfile']=['stage'=>'local-cache-physical-caller-method','declaring'=>$declaring===null?null:DefensiveBoundaryGuardProof::compact($declaring),
            'effective'=>$effective===null?null:DefensiveBoundaryGuardProof::compact($effective)];
        if($declaring===null||$effective===null||$declaring!=$effective||$declaring->kind!==FunctionLikeKind::Method
            ||$declaring->identifier->kind!==\Mago\Sdk\Analyzer\Type\FunctionLikeKind::Method
            ||strcasecmp($declaring->identifier->class??'',$caller['callerClass'])!==0
            ||strcasecmp($declaring->identifier->name,$caller['callerMethod'])!==0||$declaring->static||$declaring->abstract
            ||$declaring->flags->contains(MetadataFlags::BUILTIN)||$declaring->flags->contains(MetadataFlags::MAGIC_METHOD)
            ||$declaring->flags->contains(MetadataFlags::BY_REFERENCE)||$declaring->parameters!==[]||$declaring->templates!==[]
            ||$declaring->whereConstraints!==[]||$declaring->visibility?->name!==$caller['callerVisibility']
            ||!$this->located($declaring->location,$caller['path'],$caller['callerMethodSpan'],$caller['callerMethodStarts'])
            ||!$this->located($declaring->nameLocation,$caller['path'],$caller['callerMethodNameSpan'])){return false;}
        foreach([$declaring->declaredReturnType,$declaring->returnType] as $return){
            if($return===null||$return->fromDocblock||$return->inferred
                ||!$this->located($return->location,$caller['path'],$caller['callerReturnSpan'])
                ||!self::plainNamedObject($return->type,self::APPLICATION)){return false;}
        }
        $constructor=$context->codebase->getDeclaringMethod('Illuminate\\Cache\\CacheManager','__construct');
        $effectiveConstructor=$context->codebase->getMethod('Illuminate\\Cache\\CacheManager','__construct');
        $source=$this->methods['Illuminate\\Cache\\CacheManager::__construct']??null;
        $receipt['selectedNativeProfile']=['stage'=>'local-cache-physical-manager-constructor',
            'declaring'=>$constructor===null?null:DefensiveBoundaryGuardProof::compact($constructor),
            'effective'=>$effectiveConstructor===null?null:DefensiveBoundaryGuardProof::compact($effectiveConstructor)];
        if($constructor===null||$source===null||$effectiveConstructor!==null&&$constructor!=$effectiveConstructor
            ||count($constructor->parameters)!==1||$constructor->declaredReturnType!==null||$constructor->returnType!==null){return false;}
        $parameter=$constructor->parameters[0];$type=$parameter->type;
        if($parameter->declaredType!==null||$parameter->defaultType!==null||$parameter->outType!==null||$parameter->closureThisType!==null
            ||$type===null||!$type->fromDocblock||$type->inferred||$type->location===null||!$this->within($type->location,$source)
            ||!self::plainNamedObject($type->type,'Illuminate\\Contracts\\Foundation\\Application')){return false;}
        $profiles['localCacheCaller:'.$caller['callerClass'].'::'.$caller['callerMethod']]=['class'=>self::publicMetadata($class),
            'method'=>DefensiveBoundaryGuardProof::compact($declaring),'constructor'=>DefensiveBoundaryGuardProof::compact($constructor)];
        return @hash_file('sha256',$caller['path'])===$caller['hash'];
    }
    private static function plainNamedObject(\Mago\Sdk\Analyzer\Type $type,string $name):bool
    {
        if(count($type->atomicTypes)!==1){return false;}$atom=$type->atomicTypes[0];
        if(!$atom instanceof \Mago\Sdk\Analyzer\Type\NamedObjectType||strcasecmp($atom->name,$name)!==0
            ||$atom->parameters!==null||$atom->intersections!==null||$atom->variances!==null||$atom->isThis||$atom->static||$atom->remappedParameters){return false;}
        foreach(get_object_vars($type->flags) as $flag=>$value){if(!is_bool($value)||($flag==='populated'?$value!==true:$value!==false)){return false;}}
        return true;
    }

    private function closureRecipe(array $recipe,array $nodes,string $path):bool
    {
        $files=[
            'Illuminate\\Support\\ServiceProvider'=>'vendor/laravel/framework/src/Illuminate/Support/ServiceProvider.php',
            self::APPLICATION=>'vendor/laravel/framework/src/Illuminate/Foundation/Application.php',
            'Illuminate\\Container\\Container'=>'vendor/laravel/framework/src/Illuminate/Container/Container.php',
            'Illuminate\\Support\\Traits\\ReflectsClosures'=>'vendor/laravel/framework/src/Illuminate/Reflection/Traits/ReflectsClosures.php',
        ];
        foreach($recipe['requiredClasses'] as $class){if(!$this->classSource($files[$class],$class)){return false;}}
        foreach($recipe['requiredMethods'] as [$query,$source,$method]){if(!$this->methodSource($files[$source],$source,$method,$query)){return false;}}
        $path=DefensiveBoundarySourceProfile::path($path);
        if(!str_starts_with($path,'/')&&!preg_match('~^[A-Za-z]:/~',$path)){$path=str_replace('\\','/',$this->root).'/'.$path;}
        $hash=@hash_file('sha256',$path);if($hash===false){return false;}
        $classes=(new NodeFinder)->find($nodes,static fn(Node $node):bool=>$node instanceof Node\Stmt\Class_
            &&strcasecmp($node->namespacedName?->toString()??'',$recipe['callerClass'])===0);
        if(count($classes)!==1||Source::span($classes[0])!==$recipe['callerClassSpan']){return false;}
        $class=$classes[0];$methods=$class->getMethods();$selected=array_values(array_filter($methods,static fn(Node\Stmt\ClassMethod $node):bool=>Source::span($node)===$recipe['callerMethodSpan']));
        if(count($selected)!==1){return false;}$scope=$selected[0];
        $this->files[$path]=$hash;
        $this->closureCallers[$recipe['callerClass']]=['class'=>$recipe['callerClass'],'path'=>$path,'hash'=>$hash,
            'classSpan'=>$recipe['callerClassSpan'],'classNameSpan'=>$recipe['callerClassNameSpan'],'classStarts'=>self::starts($class),
            'method'=>$recipe['callerMethod'],'methodSpan'=>$recipe['callerMethodSpan'],'methodNameSpan'=>$recipe['callerMethodNameSpan'],
            'methodStarts'=>self::starts($scope),'declaredReturnSpan'=>$scope->returnType===null?null:Source::span($scope->returnType),
            'declaredReturnName'=>$scope->returnType instanceof Node\Identifier?$scope->returnType->name:null];
        return true;
    }

    private function closureCallerCurrent(IssueFilterContext $context,array $caller,array &$profiles,array &$receipt):bool
    {
        $class=$context->codebase->getClass($caller['class']);
        $receipt['selectedNativeProfile']=['stage'=>'provider-class','profile'=>$class===null?null:self::publicMetadata($class)];
        if($class===null||$class->kind!==ClassLikeKind::Class_||$class->hasIncompleteHierarchy()
            ||strcasecmp($class->name,$caller['class'])!==0||strcasecmp($class->originalName,$caller['class'])!==0
            ||strcasecmp($class->directParentClass??'','Illuminate\\Support\\ServiceProvider')!==0
            ||$class->mixins!==[]||$class->typeAliases!==[]||$class->flags->contains(MetadataFlags::BUILTIN)
            ||!$this->located($class->location,$caller['path'],$caller['classSpan'],$caller['classStarts'])
            ||!$this->located($class->nameLocation,$caller['path'],$caller['classNameSpan'])){return false;}
        $method=$context->codebase->getDeclaringMethod($caller['class'],$caller['method']);
        $constructor=$context->codebase->getDeclaringMethod($caller['class'],'__construct');
        $receipt['selectedNativeProfile']=['stage'=>'provider-register-and-constructor','register'=>$method===null?null:DefensiveBoundaryGuardProof::compact($method),
            'constructor'=>$constructor===null?null:DefensiveBoundaryGuardProof::compact($constructor)];
        $constructorSource=$this->methods['Illuminate\\Support\\ServiceProvider::__construct']??null;
        if($method===null||$constructor===null||$constructorSource===null
            ||$method->kind!==FunctionLikeKind::Method||$constructor->kind!==FunctionLikeKind::Method
            ||$constructor->static||$constructor->abstract||$constructor->flags->contains(MetadataFlags::BUILTIN)
            ||$constructor->flags->contains(MetadataFlags::BY_REFERENCE)||$constructor->flags->contains(MetadataFlags::MAGIC_METHOD)
            ||strcasecmp($constructor->identifier->name,'__construct')!==0
            ||!$this->located($constructor->location,$constructorSource['path'],$constructorSource['span'],$constructorSource['starts'])
            ||!$this->located($constructor->nameLocation,$constructorSource['path'],$constructorSource['nameSpan'])
            ||strcasecmp($constructor->identifier->class??'','Illuminate\\Support\\ServiceProvider')!==0
            ||strcasecmp($method->identifier->class??'',$caller['class'])!==0||strcasecmp($method->identifier->name,$caller['method'])!==0
            ||$method->static||$method->abstract||$method->parameters!==[]||$method->flags->contains(MetadataFlags::BUILTIN)
            ||$method->flags->contains(MetadataFlags::BY_REFERENCE)||$method->flags->contains(MetadataFlags::MAGIC_METHOD)
            ||!$this->located($method->location,$caller['path'],$caller['methodSpan'],$caller['methodStarts'])
            ||!$this->located($method->nameLocation,$caller['path'],$caller['methodNameSpan'])){return false;}
        $constructorFormal=$constructor->parameters[0]??null;
        if(count($constructor->parameters)!==1||$constructorFormal===null||$constructorFormal->declaredType!==null
            ||$constructorFormal->type===null||!$constructorFormal->type->fromDocblock||$constructorFormal->type->inferred
            ||$constructorFormal->outType!==null||$constructorFormal->closureThisType!==null
            ||$constructorFormal->type->location===null||!$this->within($constructorFormal->type->location,$constructorSource)
            ||$constructorFormal->flags->contains(MetadataFlags::BY_REFERENCE)||$constructorFormal->flags->contains(MetadataFlags::VARIADIC)
            ||!$context->types->equals($constructorFormal->type->type,\Mago\Sdk\Analyzer\Type::namedObject('Illuminate\\Contracts\\Foundation\\Application'))){return false;}
        if($caller['declaredReturnSpan']!==null&&($caller['declaredReturnName']!=='void'||$method->declaredReturnType===null
            ||$method->declaredReturnType->fromDocblock||!$this->located($method->declaredReturnType->location,$caller['path'],$caller['declaredReturnSpan']))){return false;}
        $app=$context->codebase->getDeclaringProperty($caller['class'],'$app');
        $effective=$context->codebase->getProperty($caller['class'],'$app');
        $owning=$context->codebase->getProperty('Illuminate\Support\ServiceProvider','$app');
        $receipt['selectedNativeProfile']=['stage'=>'physical-provider-app-field','declaring'=>$app===null?null:self::publicMetadata($app),
            'effective'=>$effective===null?null:self::publicMetadata($effective),'owning'=>$owning===null?null:self::publicMetadata($owning)];
        $library=$this->nodes('vendor/laravel/framework/src/Illuminate/Support/ServiceProvider.php');
        $owners=$library===null?[]:(new NodeFinder)->find($library,static fn(Node $node):bool=>$node instanceof Node\Stmt\Class_
            &&strcasecmp($node->namespacedName?->toString()??'','Illuminate\\Support\\ServiceProvider')===0);
        $owner=$owners[0]??null;$fields=[];
        foreach($owner?->getProperties()??[] as $field){foreach($field->props as $item){if($item->name->name==='app'){$fields[]=[$field,$item];}}}
        if(count($owners)!==1||count($fields)!==1){return false;}[$physical,$item]=$fields[0];
        $propertySpan=Source::span($physical);$itemSpan=Source::span($item);$nameSpan=Source::span($item->name);
        $starts=[...self::starts($physical),$itemSpan[0]];$ends=[$propertySpan[1],$itemSpan[1]];
        $doc=$physical->getDocComment();$docSpan=$doc===null?null:[$doc->getStartFilePos(),$doc->getEndFilePos()+1];
        unset($library,$owners,$owner,$fields,$field,$physical,$item,$doc);
        $atom=$app?->type?->type->atomicTypes[0]??null;
        if($app===null||$owning===null||$app!=$owning||$effective!==null&&$app!=$effective||$app->name!=='$app'||$app->declaredType!==null
            ||$app->readVisibility->name!=='Protected'||$app->writeVisibility->name!=='Protected'||$app->hooks!==[]
            ||$app->attributes!==[]||$app->writeType!==null||$app->type?->inferred||$app->defaultType?->fromDocblock
            ||$app->flags->contains(MetadataFlags::BUILTIN)||$app->flags->contains(MetadataFlags::STATIC)
            ||$app->flags->contains(MetadataFlags::READONLY)||$app->flags->contains(MetadataFlags::BY_REFERENCE)
            ||$app->flags->contains(MetadataFlags::VIRTUAL_PROPERTY)||$app->flags->contains(MetadataFlags::WRITEONLY)
            ||$app->flags->contains(MetadataFlags::PROMOTED_PROPERTY)||$app->flags->contains(MetadataFlags::ASYMMETRIC_PROPERTY)
            ||$app->location!==null&&(!in_array($app->location->span->end,$ends,true)
                ||!$this->located($app->location,$this->path('vendor/laravel/framework/src/Illuminate/Support/ServiceProvider.php'),[$propertySpan[0],$app->location->span->end],$starts))
            ||!$this->located($app->nameLocation,$this->path('vendor/laravel/framework/src/Illuminate/Support/ServiceProvider.php'),$nameSpan)
            ||!$app->type?->fromDocblock||$docSpan===null||$app->type->location===null
            ||strtolower($this->nativePath($app->type->location->file))!==strtolower($this->path('vendor/laravel/framework/src/Illuminate/Support/ServiceProvider.php'))
            ||$app->type->location->span->start<$docSpan[0]||$app->type->location->span->end>$docSpan[1]
            ||count($app->type->type->atomicTypes)!==1||!$atom instanceof \Mago\Sdk\Analyzer\Type\NamedObjectType
            ||strcasecmp($atom->name,'Illuminate\\Contracts\\Foundation\\Application')!==0||$atom->parameters!==null||$atom->intersections!==null
            ||$app->type->type->flags->byReference||$app->type->type->flags->possiblyUndefined
            ||!$context->types->equals($app->type->type,\Mago\Sdk\Analyzer\Type::namedObject('Illuminate\\Contracts\\Foundation\\Application'))){return false;}
        $profiles['closureCaller:'.$caller['class']]=['class'=>self::publicMetadata($class),
            'register'=>DefensiveBoundaryGuardProof::compact($method),'constructor'=>DefensiveBoundaryGuardProof::compact($constructor),
            'app'=>self::publicMetadata($app)];
        return @hash_file('sha256',$caller['path'])===$caller['hash'];
    }

    /** Diagnostic projection only; FunctionLike compact cannot serialize a class/property DTO. */
    private static function publicMetadata(mixed $value,?\SplObjectStorage $seen=null,int $depth=0):mixed
    {
        if($depth>64){throw new \RuntimeException('Selected receiver metadata depth exceeded.');}$seen??=new \SplObjectStorage;
        if($value instanceof \BackedEnum){return ['enumClass'=>$value::class,'name'=>$value->name,'value'=>$value->value];}
        if($value instanceof \UnitEnum){return ['enumClass'=>$value::class,'name'=>$value->name];}
        if(is_object($value)){
            if($seen->offsetExists($value)){throw new \RuntimeException('Recursive selected receiver metadata is unsupported.');}$seen->offsetSet($value,null);
            $fields=get_object_vars($value);if($value instanceof \Mago\Sdk\Analyzer\Type){$fields=['description'=>(string)$value]+$fields;}
            $result=['objectClass'=>$value::class,'publicFields'=>self::publicMetadata($fields,$seen,$depth+1)];$seen->offsetUnset($value);return $result;
        }
        if(is_array($value)){foreach($value as $key=>$item){$value[$key]=self::publicMetadata($item,$seen,$depth+1);}if(!array_is_list($value)){ksort($value);}}return $value;
    }

    private function middlewareCallback(Node\Expr\MethodCall $selected,array $nodes):bool
    {
        if(!$selected->var instanceof Node\Expr\Variable||!is_string($selected->var->name)){return false;}$finder=new NodeFinder;$parents=[];
        foreach($finder->find($nodes,static fn(Node $node):bool=>true) as $node){foreach($node->getSubNodeNames() as $key){$value=$node->$key;foreach(is_array($value)?$value:[$value] as $child){if($child instanceof Node){$parents[spl_object_id($child)]=$node;}}}}
        $scope=$selected;
        while(isset($parents[spl_object_id($scope)])&&!$scope instanceof Node\FunctionLike){$scope=$parents[spl_object_id($scope)];}
        if(!$scope instanceof Node\Expr\Closure||$scope->static||$scope->byRef||count($scope->params)!==1){return false;}
        $parameter=$scope->params[0];
        if($parameter->byRef||$parameter->variadic||!$parameter->var instanceof Node\Expr\Variable||$parameter->var->name!==$selected->var->name
            ||!$parameter->type instanceof Node\Name||strcasecmp($parameter->type->toString(),self::MIDDLEWARE)!==0||$parameter->default!==null
            ||$parameter->attrGroups!==[]||$parameter->getDocComment()!==null||preg_match('/@(param|var)\b/',$scope->getDocComment()?->getText()??'')){return false;}
        foreach($finder->findInstanceOf([$scope],Node\Expr\Variable::class) as $use){
            if($use->name!==$selected->var->name||$use===$parameter->var){continue;}$parent=$parents[spl_object_id($use)]??null;
            if(!$parent instanceof Node\Expr\MethodCall||$parent->var!==$use||!$parent->name instanceof Node\Identifier){return false;}
        }
        $argument=$parents[spl_object_id($scope)]??null;$receiving=$argument instanceof Node\Arg?$parents[spl_object_id($argument)]??null:null;
        if(!$argument instanceof Node\Arg||!$receiving instanceof Node\Expr\MethodCall||!self::plain($receiving)||count($receiving->args)!==1
            ||!$receiving->name instanceof Node\Identifier||strcasecmp($receiving->name->name,'withMiddleware')!==0){return false;}
        $head=$receiving->var;$chain=[];
        while($head instanceof Node\Expr\MethodCall){
            if(!$head->name instanceof Node\Identifier||count($chain)>16){return false;}
            $name=$head->name->name;
            if(!in_array(strtolower($name),['withrouting','withschedule','withproviders','withevents','withcommands','withkernels'],true)
                ||!$this->builderArguments($head,self::BUILDER,$name)){return false;}
            $chain[]=$name;$head=$head->var;
        }
        if(!$head instanceof Node\Expr\StaticCall||!$head->class instanceof Node\Name||strcasecmp($head->class->toString(),self::APPLICATION)!==0
            ||!$head->name instanceof Node\Identifier||strcasecmp($head->name->name,'configure')!==0
            ||!$this->builderArguments($head,self::APPLICATION,'configure')){return false;}
        return $this->methodSource('vendor/laravel/framework/src/Illuminate/Foundation/Application.php',self::APPLICATION,'configure')
            &&$this->methodSource('vendor/laravel/framework/src/Illuminate/Foundation/Configuration/ApplicationBuilder.php',self::BUILDER,'withMiddleware')
            &&$this->methodSource('vendor/laravel/framework/src/Illuminate/Foundation/Configuration/Middleware.php',self::MIDDLEWARE,'alias');
    }

    /** Named arguments apply only to the physically bound framework builder chain. */
    private function builderArguments(Node\Expr\CallLike $call,string $class,string $name):bool
    {
        $relative=$class===self::APPLICATION?'vendor/laravel/framework/src/Illuminate/Foundation/Application.php':
            'vendor/laravel/framework/src/Illuminate/Foundation/Configuration/ApplicationBuilder.php';
        if($call->isFirstClassCallable()||!$this->methodSource($relative,$class,$name)){return false;}
        $formals=$this->methods[$class.'::'.strtolower($name)]['formals'];
        $names=array_map(static fn(array $formal):string=>substr($formal['name'],1),$formals);
        $bound=[];$position=0;$named=false;
        foreach($call->args as $argument){
            if(!$argument instanceof Node\Arg||$argument->byRef||$argument->unpack){return false;}
            if($argument->name!==null){$named=true;$index=array_search($argument->name->name,$names,true);if($index===false){return false;}}
            else{if($named){return false;}$index=$position++;}
            if(!isset($formals[$index])||isset($bound[$index])){return false;}$bound[$index]=true;
        }
        foreach($formals as $index=>$formal){if(!$formal['optional']&&!isset($bound[$index])){return false;}}
        return true;
    }

    private function serviceKeys(string $service,string $expected):?array
    {
        $path='vendor/laravel/framework/src/Illuminate/Foundation/Application.php';$nodes=$this->nodes($path);if($nodes===null){return null;}
        $method=DefensiveBoundarySourceProfile::selected($nodes,self::APPLICATION,'registerCoreContainerAliases');
        if(!$method instanceof Node\Stmt\ClassMethod||count($method->stmts??[])!==1||!$method->stmts[0] instanceof Node\Stmt\Foreach_||!$method->stmts[0]->expr instanceof Node\Expr\Array_){return null;}
        foreach($method->stmts[0]->expr->items as $item){
            if(!$item?->key instanceof Node\Scalar\String_||$item->key->value!==$service||!$item->value instanceof Node\Expr\Array_){continue;}$keys=[$service];
            foreach($item->value->items as $member){$key=self::literalKey($member?->value);if($key===null){return null;}
                if($service==='app'&&strcasecmp($key,'self')===0){$key=self::APPLICATION;}$keys[]=$key;}
            return ($keys[1]??null)===$expected?$keys:null;
        }return null;
    }
    private function methodSource(string $relative,?string $class,string $name,?string $queryClass=null):bool
    {
        $key=($queryClass??$class??'').'::'.strtolower($name);if(isset($this->methods[$key])){return true;}
        $nodes=$this->nodes($relative);if($nodes===null){return false;}$method=DefensiveBoundarySourceProfile::selected($nodes,$class,$name);
        if(!$method instanceof Node\Stmt\ClassMethod&&!$method instanceof Node\Stmt\Function_||$method->byRef||$method->stmts===null||$method->attrGroups!==[]){return false;}
        if($class!==null&&!$this->classSource($relative,$class)){return false;}$formals=[];
        $doc=$method->getDocComment();$docText=$doc?->getText()??'';$closureThis=[];
        preg_match_all('/@param-closure-this[ \t]+(\$this)[ \t]+(\$[A-Za-z_][A-Za-z0-9_]*)\b/',$docText,$tags,PREG_OFFSET_CAPTURE);
        if(count($tags[0])!==preg_match_all('/@param-closure-this\b/',$docText)){return false;}
        foreach($tags[0] as $index=>$tag){$parameterName=$tags[2][$index][0];if(isset($closureThis[$parameterName])){return false;}
            $offset=$doc->getStartFilePos()+$tags[1][$index][1];$closureThis[$parameterName]=['name'=>'$this','span'=>[$offset,$offset+5]];}
        foreach($method->params as $parameter){if($parameter->byRef||$parameter->variadic||!$parameter->var instanceof Node\Expr\Variable||!is_string($parameter->var->name)){return false;}
            $formals[]=['name'=>'$'.$parameter->var->name,'span'=>Source::span($parameter),'nameSpan'=>Source::span($parameter->var),
                'optional'=>$parameter->default!==null,'closureThis'=>$closureThis['$'.$parameter->var->name]??null];
            unset($closureThis['$'.$parameter->var->name]);}
        if($closureThis!==[]){return false;}
        $this->methods[$key]=['queryClass'=>$queryClass??$class,'sourceClass'=>$class,'name'=>$name,'path'=>$this->path($relative),'span'=>Source::span($method),
            'nameSpan'=>Source::span($method->name),'starts'=>self::starts($method),'static'=>$method instanceof Node\Stmt\ClassMethod&&$method->isStatic(),
            'visibility'=>$method instanceof Node\Stmt\ClassMethod?($method->isPrivate()?'Private':($method->isProtected()?'Protected':'Public')):null,
            'formals'=>$formals,'fingerprint'=>DefensiveBoundarySourceProfile::fingerprint($method)];return true;
    }
    private function classSource(string $relative,string $name,bool $nativeRequired=true):bool
    {
        if(isset($this->classes[$name])){return true;}$nodes=$this->nodes($relative);if($nodes===null){return false;}
        $classes=(new NodeFinder)->find($nodes,static fn(Node $node):bool=>$node instanceof Node\Stmt\ClassLike&&strcasecmp($node->namespacedName?->toString()??'',$name)===0);
        if(count($classes)!==1){return false;}$class=$classes[0];
        // Trait ownership is physically bound through the real selected method;
        // getClass is only used for actual class declarations.
        if($class instanceof Node\Stmt\Trait_){$nativeRequired=false;}
        elseif(!$class instanceof Node\Stmt\Class_){return false;}
        $doc=$class->getDocComment()?->getText()??'';
        $this->classes[$name]=['name'=>$name,'path'=>$this->path($relative),'span'=>Source::span($class),'nameSpan'=>Source::span($class->name),
            'starts'=>self::starts($class),'final'=>$class instanceof Node\Stmt\Class_&&$class->isFinal(),
            'parent'=>$class instanceof Node\Stmt\Class_?$class->extends?->toString():null,'nativeRequired'=>$nativeRequired,
            'mixins'=>preg_match_all('/@mixin\s/',$doc),'aliases'=>preg_match_all('/@(?:phpstan|psalm)-(?:import-)?type\s/',$doc)];return true;
    }
    private function nodes(string $relative):?array
    {
        $expected=FactoryFakerRegistrationReceiverProfiles::files()[$relative]??null;$path=$this->path($relative);
        if($expected===null||$path===''||@hash_file('sha256',$path)!==$expected){return null;}$this->files[$path]=$expected;
        $bytes=file_get_contents($path);return Source::parse($bytes);
    }
    private function path(string $relative):string
    {
        if($this->vendor===null){$json=json_decode(@file_get_contents($this->root.'/composer.json')?:'',true);$vendor=$json['config']['vendor-dir']??'vendor';
            $this->vendor=is_string($vendor)&&preg_match('~^[A-Za-z0-9_/-]+$~D',$vendor)&&!str_contains($vendor,'..')?$vendor:'';}
        return $this->vendor===''?'':str_replace('\\','/',$this->root).'/'.$this->vendor.'/'.substr($relative,7);
    }
    private function standardFile(string $path,string $relative):bool{return strtolower(DefensiveBoundarySourceProfile::path($path))===strtolower($this->path($relative))
        &&@hash_file('sha256',$this->path($relative))===(FactoryFakerRegistrationReceiverProfiles::files()[$relative]??null);}
    /** Resolve a native logical filename only against the current Composer project. */
    private function nativePath(?string $name):string{return $name===null?'':DefensiveBoundarySourceProfile::path((new PhpSource($this->root))->path(DefensiveBoundarySourceProfile::path($name)));}
    private function located(?\Mago\Sdk\SourceLocation $location,string $path,array $span,?array $starts=null):bool{return $location!==null&&strtolower($this->nativePath($location->file))===strtolower($path)
        &&in_array($location->span->start,$starts??[$span[0]],true)&&$location->span->end===$span[1];}
    private function within(\Mago\Sdk\SourceLocation $location,array $method):bool{return strtolower($this->nativePath($location->file))===strtolower($method['path'])
        &&$location->span->start>=min($method['starts'])&&$location->span->end<=$method['span'][1];}
    private function parameterClosureThisCurrent(?\Mago\Sdk\Analyzer\Metadata\TypeMetadata $type,?array $source,array $method):bool
    {
        if($source===null){return $type===null;}
        if($source['name']!=='$this'||$type===null||!$type->fromDocblock||$type->inferred
            ||!$this->located($type->location,$method['path'],$source['span'])||count($type->type->atomicTypes)!==1
            ||$type->type->flags->byReference||$type->type->flags->possiblyUndefined||$type->type->flags->nullsafeNull){return false;}
        $atom=$type->type->atomicTypes[0];return $atom instanceof \Mago\Sdk\Analyzer\Type\NamedObjectType
            &&$atom->name==='$this'&&$atom->isThis&&$atom->static&&$atom->parameters===null&&$atom->intersections===null
            &&$atom->variances===null&&!$atom->remappedParameters;
    }
    private static function starts(Node $node):array{$starts=[$node->getStartFilePos()];foreach($node->getComments() as $comment){$starts[]=$comment->getStartFilePos();}return $starts;}
    private static function plain(Node\Expr\CallLike $call):bool{return !$call->isFirstClassCallable()&&!array_filter($call->args,static fn($arg):bool=>!$arg instanceof Node\Arg||$arg->name!==null||$arg->byRef||$arg->unpack);}
    private static function literalKey(?Node $node):?string{return $node instanceof Node\Scalar\String_?ltrim($node->value,'\\'):
        ($node instanceof Node\Expr\ClassConstFetch&&$node->class instanceof Node\Name&&$node->name instanceof Node\Identifier&&strcasecmp($node->name->name,'class')===0?$node->class->toString():null);}
    private static function globalFunction(Node\Expr\FuncCall $call,string $name):bool{return $call->name instanceof Node\Name&&!$call->name instanceof Node\Name\Relative
        &&($call->name->isUnqualified()||$call->name instanceof Node\Name\FullyQualified)&&strcasecmp($call->name->toString(),$name)===0;}
}
