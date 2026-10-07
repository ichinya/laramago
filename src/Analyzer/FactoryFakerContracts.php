<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;
use Ichinya\Laramago\Analyzer\{DefensiveBoundaryGuardSource as Source,DefensiveBoundarySourceProfile as Profile,DefensiveBoundaryGuardProof as Boundary,GuardedStringCastContracts as Physical};
use Ichinya\Laramago\Analyzer\StaticAnalysis\ContainerBindings;
use Mago\Sdk\Analyzer\{InitializationContext,InitializationHook,CodebaseScanContext,CodebaseScanHook,IssueFilterContext,Type};
use Mago\Sdk\Analyzer\Metadata\{MetadataFlags,ClassLikeKind,FunctionLikeMetadata};
use Mago\Sdk\Analyzer\Type\Visibility;
use PhpParser\{Node,NodeFinder};
require_once __DIR__.'/FactoryFakerSource.php';
require_once __DIR__.'/FactoryFakerInstalledSources.php';
require_once __DIR__.'/FactoryFakerRegistrationReceivers.php';

/** Recognized default declaration mapping; arbitrary Generator instances stay outside this boundary. */
final class FactoryFakerContracts implements InitializationHook,CodebaseScanHook
{
    private bool $started=false;private bool $complete=false;private bool $failed=false;private int $bytes=0;private array $hazards=[];private array $hashes=[];
    private ?FactoryFakerInstalledSources $installedSources=null;
    private ?FactoryFakerRegistrationReceivers $registrationReceivers=null;
    private readonly array $profiles;private readonly array $sourceFiles;public function __construct(private readonly string $root){$this->profiles=FactoryFakerLibraryProfiles::methods();$this->sourceFiles=FactoryFakerLibraryProfiles::files();}
    public function initialize(InitializationContext $context):void{$this->reset();}
    private function reset():void{$this->started=$this->complete=$this->failed=false;$this->bytes=0;$this->hazards=$this->hashes=[];$this->installedSources=null;$this->registrationReceivers=new FactoryFakerRegistrationReceivers($this->root);}
    public function getTargets():array{return ['**'];}
    public function scan(CodebaseScanContext $context):void
    {
        if($context->firstBatch){$this->reset();$this->started=true;}elseif(!$this->started||$this->complete){$this->failed=true;}
        foreach($context->files as $file){$this->bytes+=strlen($file->contents);if($context->cancellation->isCancelled()||$this->bytes>64000000||isset($this->hashes[$file->path])){$this->failed=true;break;}$this->hashes[$file->path]=hash('sha256',$file->contents);
            try{$nodes=Source::parse($file->contents);$finder=new NodeFinder;
                $this->registrationReceivers?->observeSource($nodes,$file->path);
                $library=str_replace('\\','/',$file->path);$vendorDeclaration=str_starts_with($library,'vendor/')||str_contains($library,'/vendor/');$defaultCreate=str_ends_with($library,'/vendor/fakerphp/faker/src/Faker/Factory.php')||$library==='vendor/fakerphp/faker/src/Faker/Factory.php';$defaultRegister=str_ends_with($library,'/vendor/laravel/framework/src/Illuminate/Database/DatabaseServiceProvider.php')||$library==='vendor/laravel/framework/src/Illuminate/Database/DatabaseServiceProvider.php';
                foreach($finder->findInstanceOf($nodes,Node\Stmt\ClassLike::class) as $class){$name=$class->namespacedName?->toString()??'';if(!str_starts_with(strtolower($name),'faker\\provider\\')){continue;}foreach($class->getMethods() as $method){if(in_array(strtolower($method->name->name),['words','randomelement','randomelements'],true)&&!in_array(strtolower($name).'::'.strtolower($method->name->name),['faker\\provider\\lorem::words','faker\\provider\\base::randomelement','faker\\provider\\base::randomelements'],true)){$this->hazards['custom-formatter-declaration']=true;}}}
                foreach($finder->find($nodes,static fn(Node $n):bool=>$n instanceof Node\Expr\MethodCall||$n instanceof Node\Expr\StaticCall) as $call){if(!$call->name instanceof Node\Identifier){continue;}$name=strtolower($call->name->name);
                    if($call->isFirstClassCallable()){continue;}
                    if($name==='addprovider'&&!$defaultCreate){$this->hazards['provider-registration']=true;}
                    if(!in_array($name,['bind','singleton','scoped','instance','extend','alias','bindif','singletonif','swap'],true)||$defaultRegister){continue;}
                    if($this->registrationReceivers?->unrelated($call,$nodes,$file->path)){continue;}
                    $keys=$this->registrationReceivers?->binding($call,$file->path,$nodes);
                    if($keys!==null){foreach($keys as $key){if(strcasecmp($key,'Faker\\Generator')===0){$this->hazards['generator-binding']=true;}}continue;}
                    $targets=$name==='alias'?[$call->args[0]??null,$call->args[1]??null]:[$call->args[0]??null];
                    foreach($targets as $arg){if(!$arg instanceof Node\Arg||$arg->byRef||$arg->unpack||$arg->name!==null){if(!$vendorDeclaration||$name==='alias'){$this->hazards['unknown-binding-target']=true;}continue;}$target=$arg->value;
                        if($target instanceof Node\Expr\ClassConstFetch&&$target->class instanceof Node\Name&&$target->name instanceof Node\Identifier&&strtolower($target->name->name)==='class'){if(strcasecmp($target->class->toString(),'Faker\\Generator')===0){$this->hazards['generator-binding']=true;}}
                        elseif($target instanceof Node\Scalar\String_){if(strcasecmp(ltrim($target->value,'\\'),'Faker\\Generator')===0){$this->hazards['generator-binding']=true;}}
                        else{if(!$vendorDeclaration||$name==='alias'){$this->hazards['unknown-binding-target']=true;}}
                    }
                }unset($nodes,$finder,$class,$method,$call,$arg,$target,$keys,$key);
            }catch(\Throwable){$this->failed=true;break;}
        }$this->complete=$context->lastBatch&&!$this->failed;
    }
    public function current(IssueFilterContext $context,array $p):array
    {
        $r=['admitted'=>false,'stage'=>'complete-default-source-catalog','policy'=>'recognized-default-Factory-and-Faker-declarations','arbitraryGeneratorClaimed'=>false,'nativeTypesChanged'=>false,'catalog'=>['started'=>$this->started,'complete'=>$this->complete,'failed'=>$this->failed,'hazards'=>array_keys($this->hazards)]];
        if(!$this->started||!$this->complete||$this->failed||$this->hazards!==[]||$context->cancellation->isCancelled()||($this->hashes[$context->file]??null)!==hash('sha256',$context->contents)){return $r;}
        $r['stage']='complete-current-registered-provider-source-catalog';
        if($this->installedSources===null){$this->installedSources=new FactoryFakerInstalledSources($this->root,$this->registrationReceivers);$this->installedSources->discover();}
        $registered=$this->installedSources->certificate();$r['installedSources']=$registered;
        $r['catalog']['hazards']=array_values(array_unique([...$r['catalog']['hazards'],...($registered['hazards']??[])]));
        if($registered===null||!$registered['complete']||!$registered['current']||$registered['hazards']!==[]){return $r;}
        $r['stage']='current-non-registration-receiver-contracts';$receiver=$this->registrationReceivers?->current($context);$r['registrationReceivers']=$receiver;
        if($receiver===null||!$receiver['admitted']){return $r;}
        $r['stage']='configured-binding-priority';if((new ContainerBindings($this->root))->configured('Faker\\Generator')){return $r;}
        $physical=new Physical($this->root);$file=$physical->read($context->file,$context->contents);if($file===null){return $r;}$finder=new NodeFinder;$class=$finder->findFirst($file['nodes'],static fn(Node $n):bool=>$n instanceof Node\Stmt\Class_&&strcasecmp($n->namespacedName?->toString()??'',$p['class'])===0);$method=$class?->getMethod($p['scope']['name']);
        if($class===null||$method===null){return $r;}$classSource=['span'=>Source::span($class),'name'=>Source::span($class->name),'starts'=>self::starts($class),'final'=>$class->isFinal(),'ownTraitUses'=>count(array_filter($class->stmts,static fn(Node $statement):bool=>$statement instanceof Node\Stmt\TraitUse))];$methodSource=['span'=>Source::span($method),'name'=>Source::span($method->name),'starts'=>self::starts($method)];unset($file,$finder,$class,$method);
        $r['stage']='physical-direct-default-Factory-caller';$nativeClass=$context->codebase->getClass($p['class']);$caller=$context->codebase->getDeclaringMethod($p['class'],$p['scope']['name']);
        $baseClass=$context->codebase->getClass('Illuminate\Database\Eloquent\Factories\Factory');
        $traitCertificate=$this->defaultInheritedTraits($physical,$nativeClass,$baseClass);
        $r['defaultInheritedTraits']=$traitCertificate;
        $guards=[
            'ordinaryClass'=>$nativeClass!==null&&$nativeClass->kind===ClassLikeKind::Class_,
            'completeHierarchy'=>$nativeClass!==null&&!$nativeClass->hasIncompleteHierarchy(),
            'noMixins'=>$nativeClass!==null&&$nativeClass->mixins===[],
            'noAliases'=>$nativeClass!==null&&$nativeClass->typeAliases===[],
            'noOwnSourceTraits'=>$classSource['ownTraitUses']===0,
            'currentDefaultInheritedTraits'=>$traitCertificate['admitted'],
            'sourceFinalFlag'=>$nativeClass!==null&&$nativeClass->flags->contains(MetadataFlags::FINAL)===$classSource['final'],
            'directDefaultParent'=>$nativeClass!==null&&strcasecmp($nativeClass->directParentClass??'','Illuminate\\Database\\Eloquent\\Factories\\Factory')===0,
            'physicalClassLocation'=>$nativeClass!==null&&self::located($physical,$nativeClass->location,$classSource['span'],$context->file,$classSource['starts']),
            'physicalClassNameLocation'=>$nativeClass!==null&&self::located($physical,$nativeClass->nameLocation,$classSource['name'],$context->file),
            'ordinaryOwnCaller'=>$caller!==null&&!$caller->static&&!$caller->abstract&&$caller->parameters===[]&&!$caller->flags->contains(MetadataFlags::BY_REFERENCE)&&strcasecmp($caller->identifier->class??'',$p['class'])===0,
            'physicalCallerLocation'=>$caller!==null&&self::located($physical,$caller->location,$methodSource['span'],$context->file,$methodSource['starts']),
            'physicalCallerNameLocation'=>$caller!==null&&self::located($physical,$caller->nameLocation,$methodSource['name'],$context->file),
        ];
        $r['callerGuards']=$guards;$r['selectedCallerNative']=$caller===null?null:Boundary::compact($caller);
        $r['selectedClassNative']=$nativeClass===null?null:['name'=>$nativeClass->name,'originalName'=>$nativeClass->originalName,'location'=>$nativeClass->location,'nameLocation'=>$nativeClass->nameLocation,'flags'=>$nativeClass->flags->bits,'directParentClass'=>$nativeClass->directParentClass,'usedTraits'=>$nativeClass->usedTraits,'templateCount'=>count($nativeClass->templates),'mixinCount'=>count($nativeClass->mixins),'incompleteHierarchy'=>$nativeClass->hasIncompleteHierarchy()];
        $r['selectedParentNative']=$baseClass===null?null:['name'=>$baseClass->name,'usedTraits'=>$baseClass->usedTraits,'incompleteHierarchy'=>$baseClass->hasIncompleteHierarchy()];
        if(in_array(false,$guards,true)){return $r;}
        $r['stage']='physical-default-faker-field';$field=$context->codebase->getDeclaringProperty($p['class'],'$faker');$base=$context->codebase->getProperty('Illuminate\\Database\\Eloquent\\Factories\\Factory','$faker');
        if($field===null||$base===null||$field!=$base||$field->name!=='$faker'||$field->declaredType!==null||$field->type===null||!$field->type->fromDocblock||$field->type->inferred||!$context->types->equals($field->type->type,Type::namedObject('Faker\\Generator'))||$field->readVisibility!==Visibility::Protected||$field->writeVisibility!==Visibility::Protected||$field->hooks!==[]||$field->writeType!==null||$field->flags->contains(MetadataFlags::STATIC)||$field->flags->contains(MetadataFlags::VIRTUAL_PROPERTY)||$field->flags->contains(MetadataFlags::BY_REFERENCE)){return $r;}
        $factoryFile=$physical->read('vendor/laravel/framework/src/Illuminate/Database/Eloquent/Factories/Factory.php');$factory=$factoryFile===null?null:(new NodeFinder)->findFirst($factoryFile['nodes'],static fn(Node $n):bool=>$n instanceof Node\Stmt\Class_&&strcasecmp($n->namespacedName?->toString()??'','Illuminate\\Database\\Eloquent\\Factories\\Factory')===0);$fieldSyntax=null;$item=null;
        foreach($factory?->getProperties()??[] as $property){foreach($property->props as $candidate){if($candidate->name->name==='faker'){$fieldSyntax=$property;$item=$candidate;}}}
        if($factoryFile===null||$fieldSyntax===null||$item===null||!$fieldSyntax->isProtected()||$fieldSyntax->isStatic()||$fieldSyntax->type!==null||count($fieldSyntax->props)!==1||!$physical->located($field->nameLocation,$item->name,$factoryFile)||preg_match('/@var\s+\\\\Faker\\\\Generator\s/',$fieldSyntax->getDocComment()?->getText()??'')!==1||$field->type->location===null||$physical->path($field->type->location->file)!==$factoryFile['path']||$field->type->location->span->start<$fieldSyntax->getDocComment()->getStartFilePos()||$field->type->location->span->end>$fieldSyntax->getDocComment()->getEndFilePos()+1){return $r;}unset($factoryFile,$factory,$fieldSyntax,$item,$property,$candidate);
        $r['stage']='current-default-library-source-fields';foreach($this->sourceFiles as $path=>$profile){$disk=$physical->path($path);if($disk===''||hash_file('sha256',$disk)!==$profile['sourceSha256']){return $r;}}
        $r['stage']='selected-default-library-bodies';$methods=[];foreach($this->profiles as $symbol=>$profile){$native=$context->codebase->getDeclaringMethod($profile['class'],$profile['name']);if(!$this->method($physical,$native,$profile)){return $r;}$methods[$symbol]=Boundary::compact($native);}
        foreach(['__construct','withFaker'] as $name){$selected=$context->codebase->getDeclaringMethod($p['class'],$name);if($selected===null||strcasecmp($selected->identifier->class??'','Illuminate\\Database\\Eloquent\\Factories\\Factory')!==0||$selected->flags->contains(MetadataFlags::BY_REFERENCE)||$selected->flags->contains(MetadataFlags::MAGIC_METHOD)){return $r;}}
        $r['stage']='genuine-default-generator-pseudomethod';$generator=$context->codebase->getClass('Faker\\Generator');$name=$p['kind']==='default-faker-words-text'?'words':'randomElement';$pseudo=$context->codebase->getDeclaringMethod('Faker\\Generator',$name);
        if($generator===null||$generator->hasIncompleteHierarchy()||$generator->kind!==ClassLikeKind::Class_||$generator->mixins!==[]||$generator->typeAliases!==[]||$pseudo===null||!$pseudo->flags->contains(MetadataFlags::MAGIC_METHOD)||$pseudo->flags->contains(MetadataFlags::BY_REFERENCE)||$pseudo->static||$pseudo->abstract||strcasecmp($pseudo->identifier->class??'','Faker\\Generator')!==0||$physical->path($pseudo->location->file)!==$physical->path('vendor/fakerphp/faker/src/Faker/Generator.php')){return $r;}
        $expected=$name==='words'?2:1;if(count($pseudo->parameters)!==$expected||$pseudo->returnType===null){return $r;}foreach($pseudo->parameters as $formal){if($formal->flags->contains(MetadataFlags::BY_REFERENCE)||$formal->flags->contains(MetadataFlags::VARIADIC)||$formal->outType!==null||$formal->closureThisType!==null){return $r;}}
        $methods['Faker\\Generator::'.$name]=Boundary::compact($pseudo);$r['nativeProfiles']=$methods;$r['stage']='current-default-formatter-domain';$r['admitted']=true;return $r;
    }
    /** SDK usedTraits includes inherited traits; bind that set to the physically declared default parent. */
    private function defaultInheritedTraits(Physical $physical,?\Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata $child,?\Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata $parent):array
    {
        $r=['admitted'=>false,'stage'=>'physical-default-Factory-traits','nativeTypesChanged'=>false];
        $path='vendor/laravel/framework/src/Illuminate/Database/Eloquent/Factories/Factory.php';
        $file=$physical->read($path);$syntax=$file===null?null:(new NodeFinder)->findFirst($file['nodes'],static fn(Node $node):bool=>$node instanceof Node\Stmt\Class_&&strcasecmp($node->namespacedName?->toString()??'','Illuminate\Database\Eloquent\Factories\Factory')===0);
        $disk=$physical->path($path);$expected=$this->sourceFiles[$path]['sourceSha256']??null;
        if($file===null||$syntax===null||$expected===null||hash_file('sha256',$disk)!==$expected||$syntax->extends!==null||$syntax->implements!==[]||!$syntax->isAbstract()){return $r;}
        $traits=[];foreach($syntax->stmts as $statement){if(!$statement instanceof Node\Stmt\TraitUse){continue;}foreach($statement->traits as $name){$traits[]=strtolower($name->toString());}}
        sort($traits);if(count($traits)!==3||count(array_unique($traits))!==3){return $r;}
        $physicalParent=$parent!==null&&$parent->kind===ClassLikeKind::Class_&&!$parent->hasIncompleteHierarchy()&&!$parent->flags->contains(MetadataFlags::BUILTIN)&&$parent->flags->contains(MetadataFlags::ABSTRACT)&&$parent->directParentClass===null&&$parent->mixins===[]&&$parent->typeAliases===[]
            &&strcasecmp($parent->name,'Illuminate\Database\Eloquent\Factories\Factory')===0
            &&self::located($physical,$parent->location,Source::span($syntax),$path,self::starts($syntax))&&self::located($physical,$parent->nameLocation,Source::span($syntax->name),$path);
        unset($file,$syntax,$statement,$name);
        $childTraits=$child?->usedTraits??[];$parentTraits=$parent?->usedTraits??[];sort($childTraits);sort($parentTraits);
        $r['sourceTraits']=$traits;$r['parentTraits']=$parentTraits;$r['childTraits']=$childTraits;$r['physicalParent']=$physicalParent;
        if(!$physicalParent||$parentTraits!==$traits||$childTraits!==$traits){return $r;}
        $r['admitted']=true;$r['stage']='current-physical-default-parent-inheritance';return $r;
    }
    private function method(Physical $physical,?FunctionLikeMetadata $native,array $profile):bool
    {
        if($native===null||$native->flags->contains(MetadataFlags::BUILTIN)||$native->flags->contains(MetadataFlags::BY_REFERENCE)||$native->flags->contains(MetadataFlags::MAGIC_METHOD)||$native->abstract||$native->static!==$profile['static']||strcasecmp(($native->identifier->class??'').'::'.$native->identifier->name,$profile['class'].'::'.$profile['name'])!==0||$physical->path($native->location->file)!==$physical->path($profile['path'])||count($native->parameters)!==count($profile['parameterNames'])){return false;}
        $file=$physical->read($profile['path']);if($file===null){return false;}$node=Profile::selected($file['nodes'],$profile['class'],$profile['name']);if(!$node instanceof Node\Stmt\ClassMethod||Profile::fingerprint($node)!==$profile['fingerprint']||!$physical->located($native->nameLocation,$node->name,$file)||!self::located($physical,$native->location,Source::span($node),$profile['path'],self::starts($node))){return false;}
        foreach($node->params as $i=>$source){$formal=$native->parameters[$i];if($source->byRef||$source->variadic||$formal->name!==$profile['parameterNames'][$i]||$formal->flags->contains(MetadataFlags::BY_REFERENCE)||$formal->flags->contains(MetadataFlags::VARIADIC)||$formal->outType!==null||$formal->closureThisType!==null||!$physical->located($formal->location,$source,$file)||!$physical->located($formal->nameLocation,$source->var,$file)){return false;}}return true;
    }
    private static function located(Physical $physical,?\Mago\Sdk\SourceLocation $location,array $span,string $file,?array $starts=null):bool{return $location!==null&&$physical->path($location->file)===$physical->path($file)&&in_array($location->span->start,$starts??[$span[0]],true)&&$location->span->end===$span[1];}
    private static function starts(Node $node):array{$r=[$node->getStartFilePos()];foreach($node->getComments() as $c){$r[]=$c->getStartFilePos();}foreach($node->attrGroups??[] as $a){$r[]=$a->getStartFilePos();}return $r;}
}
