<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\{IssueFilterContext,Type};
use Mago\Sdk\Analyzer\Metadata\{ClassLikeKind,FunctionLikeKind,FunctionLikeMetadata,MetadataFlags,TemplateMetadata};
use Mago\Sdk\Analyzer\Type\{CallableType,GenericParameterType,GenericParentKind,KeyedArrayType,NamedObjectType,ScalarType,ScalarTypeKind,SimpleAtomicType,SimpleAtomicTypeKind,Variance,Visibility};
use PhpParser\{Node,NodeFinder,NodeTraverser,NodeVisitorAbstract};
use PhpParser\PrettyPrinter\Standard;

/** The current standard Builder trait maps its TValue result to its declared TModel. */
final class StandardEloquentFirstDeclarationContracts
{
    private const MODEL='Illuminate\\Database\\Eloquent\\Model';
    private const BUILDER='Illuminate\\Database\\Eloquent\\Builder';
    private const QUERIES='Illuminate\\Database\\Concerns\\BuildsQueries';
    private const COLLECTION='Illuminate\\Database\\Eloquent\\Collection';
    private const SUPPORT='Illuminate\\Support\\Collection';
    private const FACTORY='Illuminate\\Database\\Eloquent\\HasCollection';
    private const BODIES=[
        'query'=>'873ddbbd2a60ee81542cf33087a8cf9834c64a869a0dc0ea34c0ee5c84e854ee',
        'get'=>'eb5237d06208e8cd6bfc98b3209a63b217d75c5059009d1d753ec1cfaff210b1',
        'newCollection'=>'0c0e15344390a0a55fc6c87883842c7bf27600d42e25e6349e50a9d42b1b6571',
        'resolveCollectionFromAttribute'=>'c50b3b9b7a59f77e25ef98665e4faaa7176f9681126a05fbbf61dab8fc361e84',
    ];
    public array $stages=[];public array $dependencies=[];public array $certificate=[];
    private array $held=[];private array $lookupBindings=[];
    public function __construct(private readonly string $root) {}
    private function stage(string $label,bool $passed):bool { $this->stages[]=['stage'=>$label,'passed'=>$passed];return $passed; }

    /** Independent current declaration projection for an already source-bound literal query root. */
    public function forLiteralQuery(IssueFilterContext $context,GuardedStringCastContracts $source,array $file,Node\Expr\StaticCall $query):?Type
    {
        if(!$this->stage('current literal zero-argument model query',$query->class instanceof Node\Name && !$query->class->isSpecialClassName()
            && $query->name instanceof Node\Identifier && strtolower($query->name->name)==='query' && $query->args===[]
            && SourceArgumentDeclarationContracts::plain($query) && $file['contents']===$context->contents
            && $file['path']===$source->path($context->file) && hash_file('sha256',$file['path'])===$file['hash'])) { return null; }
        $this->held[$file['path']]=$file['hash'];
        return $this->modelDeclaration($context,Type::namedObject($query->class->toString()),null);
    }

    private function modelDeclaration(IssueFilterContext $context,Type $model,?Type $receiver):?Type
    {
        $atom=$model->atomicTypes[0]??null;
        if(!$this->stage('one genuinely declared concrete model parameter',count($model->atomicTypes)===1 && !$model->flags->byReference && !$model->flags->possiblyUndefined
            && $atom instanceof NamedObjectType && ($atom->parameters??[])===[] && !$atom->static && !$atom->isThis && ($atom->intersections??[])===[] && !$atom->remappedParameters
            && $context->types->isContainedBy($model,Type::namedObject(self::MODEL)))) { return null; }
        $source=new GuardedStringCastContracts($this->root);$bindings=[];
        foreach([['builder',self::BUILDER,ClassLikeKind::Class_],['queries',self::QUERIES,ClassLikeKind::Trait],
            ['collection',self::COLLECTION,ClassLikeKind::Class_],['support',self::SUPPORT,ClassLikeKind::Class_],
            ['modelRoot',self::MODEL,ClassLikeKind::Class_],['factory',self::FACTORY,ClassLikeKind::Trait],
            ['model',$atom->name,ClassLikeKind::Class_]] as [$role,$name,$kind]) {
            $native=$context->codebase->getClassLike($name);$this->dependencies[$role]=$native;$this->lookupBindings[$role]=['kind'=>'class','name'=>$name];
            $physical=$this->classDeclaration($source,$native,$name,$kind);
            if($physical===null) { return null; }$bindings[$role]=$physical;
        }
        $nativeModel=$this->dependencies['model'];
        if(!$this->stage('standard builder and collection declarations are concrete',!$this->dependencies['builder']->flags->contains(MetadataFlags::ABSTRACT)
            && !$this->dependencies['collection']->flags->contains(MetadataFlags::ABSTRACT) && !$this->dependencies['support']->flags->contains(MetadataFlags::ABSTRACT))) { return null; }
        if(!$this->stage('concrete model hierarchy without unresolved dependencies',!$nativeModel->hasIncompleteHierarchy()
            && !$nativeModel->flags->contains(MetadataFlags::ABSTRACT) && $nativeModel->templates===[])) { return null; }
        $builderNode=$bindings['builder']['node'];$builderDoc=$builderNode->getDocComment()?->getText()??'';
        $traitUses=array_values(array_filter((new NodeFinder)->findInstanceOf($builderNode->stmts,Node\Stmt\TraitUse::class),
            static fn(Node\Stmt\TraitUse $use):bool=>count(array_filter($use->traits,static fn(Node\Name $name):bool=>$name->toString()===self::QUERIES))===1));
        $builderTemplates=$this->dependencies['builder']->templates;$traitTemplates=$this->dependencies['queries']->templates;
        $mixinDoc=self::declaredFirstMixins($builderNode,self::BUILDER);
        $queryMixinDoc=self::declaredFirstMixins($bindings['queries']['node'],self::QUERIES);
        if($mixinDoc===null||$queryMixinDoc===null){return null;}
        $builderExpected=['template'=>['TModel of \\Illuminate\\Database\\Eloquent\\Model']];
        $queriesExpected=['template'=>['TValue']];
        if($mixinDoc!==[]){$builderExpected['mixin']=['\\'.$mixinDoc[0]];}
        if($queryMixinDoc!==[]){$queriesExpected['mixin']=['\\'.$queryMixinDoc[0]];}
        if(!$this->stage('current physical and native Builder to trait generic binding',count($traitUses)===1
            && self::supportedClassDocumentation($traitUses[0],['use'=>['\\Illuminate\\Database\\Concerns\\BuildsQueries<TModel>']])
            && self::supportedClassDocumentation($builderNode,$builderExpected)
            && count($builderTemplates)===1 && $builderTemplates[0]->name==='TModel' && $builderTemplates[0]->default===null
            && $builderTemplates[0]->definingEntity->kind===GenericParentKind::ClassLike && strcasecmp($builderTemplates[0]->definingEntity->name,self::BUILDER)===0
            && $builderTemplates[0]->readonly===true && $builderTemplates[0]->variance===Variance::Invariant
            && $context->types->isContainedBy($model,$builderTemplates[0]->constraint)
            && count($traitTemplates)===1 && $traitTemplates[0]->name==='TValue' && $traitTemplates[0]->default===null
            && $traitTemplates[0]->definingEntity->kind===GenericParentKind::ClassLike && strcasecmp($traitTemplates[0]->definingEntity->name,self::QUERIES)===0
            && $traitTemplates[0]->readonly===true && $traitTemplates[0]->variance===Variance::Invariant
            && $context->types->equals($traitTemplates[0]->constraint,Type::mixed())
            && self::supportedClassDocumentation($bindings['queries']['node'],$queriesExpected))) { return null; }
        if(!$this->currentModelHierarchy($context,$source,$bindings,$atom->name)) { return null; }
        $firstTraitGraphs=[];
        foreach(['builder','support','collection'] as $role){
            $graph=[];$parentGraph=$role==='collection'?($firstTraitGraphs['support']??null):null;
            if(!$this->currentFirstTraits($context,$source,$bindings[$role],$this->dependencies[$role],$role,$parentGraph,$graph)){return null;}
            $firstTraitGraphs[$role]=$graph;
        }
        $dispatch=new EloquentModelDispatch;
        if(!$this->stage('standard model construction and collection dispatch',$dispatch->supportsModel($context->codebase,$atom->name,'first')
            && !$dispatch->overrides($context->codebase,$atom->name,'query') && !$dispatch->overrides($context->codebase,$atom->name,'newInstance')
            && !$dispatch->overrides($context->codebase,$atom->name,'newFromBuilder') && !$dispatch->overrides($context->codebase,$atom->name,'hydrate'))) { return null; }
        foreach(['$builder'=>self::BUILDER,'$collectionClass'=>self::COLLECTION] as $name=>$expected) {
            if(!$this->defaultField($context,$source,$atom->name,$name,$expected)) { return null; }
        }
        $first=$this->selectedMethod($context,'first',self::BUILDER,'first');
        $physical=$this->method($source,$first,self::QUERIES,'first',1,false);
        if($physical===null) { return null; }[$file,$syntax]=$physical;
        $doc=$syntax->getDocComment()?->getText()??'';$return=$first->returnType?->type;$generic=[];$null=[];
        foreach($return?->atomicTypes??[] as $part) {
            if($part instanceof GenericParameterType) { $generic[]=$part; }
            if($part instanceof SimpleAtomicType && $part->kind===SimpleAtomicTypeKind::Null) { $null[]=$part; }
        }
        if(!$this->stage('unchanged first TValue or null declaration',$first->declaredReturnType===null && $first->returnType!==null
            && $first->returnType->fromDocblock && !$first->returnType->inferred && !$return->flags->byReference && !$return->flags->possiblyUndefined
            && count($return->atomicTypes)===2 && count($generic)===1 && count($null)===1 && $generic[0]->name==='TValue'
            && $generic[0]->definingEntity->kind===GenericParentKind::ClassLike && strcasecmp($generic[0]->definingEntity->name,self::QUERIES)===0
            && $context->types->equals($generic[0]->constraint,Type::mixed()) && $this->generic($context,Type::fromAtomic($generic[0]),$traitTemplates[0])
            && $this->columns($context,$source,$first,$syntax,$file) && $this->docType($source,$first->returnType,$syntax,$file,'return','TValue|null')
            && $source->tokens(substr($file['contents'],$syntax->getStartFilePos(),$syntax->getEndFilePos()-$syntax->getStartFilePos()+1))
                ===$source->tokens('public function first($columns = [\'*\']) { return $this->limit(1)->get($columns)->first(); }')
            && $syntax->params[0]->default instanceof Node\Expr\Array_ && count($syntax->params[0]->default->items)===1
            && $syntax->params[0]->default->items[0]!==null && $syntax->params[0]->default->items[0]->key===null
            && !$syntax->params[0]->default->items[0]->byRef && !$syntax->params[0]->default->items[0]->unpack
            && $syntax->params[0]->default->items[0]->value instanceof Node\Scalar\String_ && $syntax->params[0]->default->items[0]->value->value==='*'
            && $first->parameters[0]->defaultType!==null && !$first->parameters[0]->defaultType->fromDocblock && $first->parameters[0]->defaultType->inferred
            && $this->parameterDefaultLocated($source,$first->parameters[0]->defaultType->location,$syntax->params[0],$file)
            && $context->types->isContainedBy($first->parameters[0]->defaultType->type,Type::list(Type::literalString('*'))))) { return null; }
        if(!$this->getAndCollectionFirst($context,$source,$bindings) || !$this->standardCollectionFactory($context,$source,$bindings,$atom->name,$model)) { return null; }
        if(!$this->current()) { return null; }
        $result=Type::union($model,Type::null());
        $this->certificate=['model'=>$atom->name,'nativeReceiver'=>$receiver===null?null:(string)$receiver,'result'=>(string)$result,
            'policy'=>'Current Builder @use BuildsQueries<TModel> and declared first TValue|null, with standard collection defaults.',
            'sourceHashes'=>$this->held,'bindings'=>$this->lookupBindings,'callerFileAuthorityClaimed'=>false,'invocationOffsetsUsedAsAuthority'=>false,
            'currentFirstMixinSources'=>$this->certificate['currentFirstMixinSources']??[],
            'currentFirstTraitSources'=>$this->certificate['currentFirstTraitSources']??[],
            'strongerDeclaredOverridesReplaced'=>false,'nullablePreserved'=>true,'receivingSignatureReplaced'=>false];
        return $result;
    }

    private function classDeclaration(GuardedStringCastContracts $source,?object $native,string $name,ClassLikeKind $kind):?array
    {
        $file=$native===null?null:$source->read($native->nameLocation?->file);
        $nodes=$file===null?[]:array_values(array_filter((new NodeFinder)->findInstanceOf($file['nodes'],Node\Stmt\ClassLike::class),
            static fn(Node\Stmt\ClassLike $node):bool=>$name!=='' && $node->namespacedName instanceof Node\Name && strcasecmp($node->namespacedName->toString(),$name)===0));
        $node=count($nodes)===1?$nodes[0]:null;
        $physicalKind=match(true){$node instanceof Node\Stmt\Class_=>ClassLikeKind::Class_,$node instanceof Node\Stmt\Trait_=>ClassLikeKind::Trait,
            $node instanceof Node\Stmt\Interface_=>ClassLikeKind::Interface,$node instanceof Node\Stmt\Enum_=>ClassLikeKind::Enum,default=>null};
        if(!$this->stage('current physical classlike '.$name,$native!==null && strcasecmp($native->name,$name)===0 && strcasecmp($native->originalName,$name)===0
            && $native->kind===$kind && $physicalKind===$kind
            && !$native->hasIncompleteHierarchy() && $node!==null && $source->located($native->nameLocation,$node->name,$file)
            && ($native->location===null || $source->located($native->location,$node,$file)))) { return null; }
        $this->held[$file['path']]=$file['hash'];
        // Retain selected declaration shells, not complete framework trees, across RPC queries.
        $shell=clone $node;$shell->stmts=array_values(array_filter($node->stmts,static fn(Node $member):bool=>$member instanceof Node\Stmt\TraitUse));
        $methods=[];$properties=[];
        foreach($node->stmts as $member) {
            if($member instanceof Node\Stmt\ClassMethod) { $methods[]=strtolower($member->name->name); }
            if($member instanceof Node\Stmt\Property) { foreach($member->props as $item) { $properties['$'.$item->name->name]=[$item->name->getStartFilePos(),$item->name->getEndFilePos()+1]; } }
        }
        return ['file'=>['path'=>$file['path'],'hash'=>$file['hash'],'contents'=>$file['contents']],'node'=>$shell,'methods'=>$methods,'properties'=>$properties];
    }
    private function method(GuardedStringCastContracts $source,?FunctionLikeMetadata $native,string $class,string $name,int $count,bool $static,bool $templates=false):?array
    {
        $file=$native===null?null:$source->read($native->location?->file);
        $nodes=$file===null?[]:array_values(array_filter((new NodeFinder)->findInstanceOf($file['nodes'],Node\Stmt\ClassMethod::class),
            static fn(Node\Stmt\ClassMethod $node):bool=>$source->located($native->location,$node,$file)));
        $node=count($nodes)===1?$nodes[0]:null;$owner=null;
        if($node!==null) { foreach($source->ancestors($node,$file) as $parent) { if($parent instanceof Node\Stmt\ClassLike) { $owner=$parent;break; } } }
        if(!$this->stage('current physically declared '.$class.'::'.$name,$native!==null && strcasecmp($native->identifier->class??'',$class)===0
            && strcasecmp($native->identifier->name,$name)===0 && $native->kind===FunctionLikeKind::Method && $native->visibility===Visibility::Public && !$native->abstract && $native->static===$static
            && !$native->flags->contains(MetadataFlags::BY_REFERENCE) && ($templates || $native->templates===[]) && count($native->parameters)===$count
            && $native->whereConstraints===[] && $native->attributes===[]
            && $node!==null && $owner?->namespacedName?->toString()===$class && $node->name->name===$name && $node->isStatic()===$static
            && !$node->byRef && count($node->params)===$count && $source->located($native->nameLocation,$node->name,$file)
            && preg_match('~@(?:phpstan|psalm)-(?:return|param|template)\b~',$node->getDocComment()?->getText()??'')!==1)) { return null; }
        foreach($node->params as $index=>$formal) {
            $parameter=$native->parameters[$index];
            if(!$this->stage('unchanged physical '.$name.' formal '.$index,$formal->var instanceof Node\Expr\Variable && !$formal->byRef && !$formal->variadic
                && $parameter->name==='$'.$formal->var->name && !$parameter->flags->contains(MetadataFlags::BY_REFERENCE)
                && !$parameter->flags->contains(MetadataFlags::VARIADIC) && $parameter->outType===null
                && $parameter->flags->contains(MetadataFlags::HAS_DEFAULT)===($formal->default!==null)
                && $source->located($parameter->location,$formal,$file) && $source->located($parameter->nameLocation,$formal->var,$file)
                && ($formal->type===null?$parameter->declaredType===null:$source->located($parameter->declaredType?->location,$formal->type,$file)))) { return null; }
        }
        $this->held[$file['path']]=$file['hash'];return [['path'=>$file['path'],'hash'=>$file['hash'],'contents'=>$file['contents']],$node];
    }
    private function defaultField(IssueFilterContext $context,GuardedStringCastContracts $source,string $model,string $name,string $expected):bool
    {
        $native=$context->codebase->getDeclaringProperty($model,$name)??$context->codebase->getProperty($model,$name);$this->dependencies[$name]=$native;
        $this->lookupBindings[$name]=['kind'=>'property','class'=>$model,'name'=>$name];
        $file=$native===null?null:$source->read($native->nameLocation?->file);
        $items=$file===null?[]:array_values(array_filter((new NodeFinder)->findInstanceOf($file['nodes'],Node\PropertyItem::class),
            static fn(Node\PropertyItem $item):bool=>$source->located($native->nameLocation,$item->name,$file)));
        $item=count($items)===1?$items[0]:null;$property=$item===null?null:($file['parents'][spl_object_id($item)]??null);
        $expectedSource=$this->certificate['currentModelProperties'][$name]??null;
        if(!$this->stage('current physical default '.$name,$native!==null && $native->name===$name && $native->hooks===[] && !$native->flags->contains(MetadataFlags::VIRTUAL_PROPERTY)
            && $native->flags->contains(MetadataFlags::STATIC) && $native->flags->contains(MetadataFlags::HAS_DEFAULT)
            && $expectedSource!==null && $file!==null && $file['path']===$expectedSource['file']
            && [$native->nameLocation->span->start,$native->nameLocation->span->end]===$expectedSource['span']
            && $property instanceof Node\Stmt\Property && $property->isStatic() && $property->type instanceof Node\Identifier && $property->type->name==='string'
            && $this->docType($source,$native->type,$property,$file,'var',
                $name==='$builder'?'class-string<\\Illuminate\\Database\\Eloquent\\Builder<*>>':'class-string<\\Illuminate\\Database\\Eloquent\\Collection<*,*>>')
            && $source->located($native->declaredType?->location,$property->type,$file) && $native->declaredType!==null
            && !$native->declaredType->fromDocblock && !$native->declaredType->inferred
            && $context->types->equals($native->declaredType->type,Type::string()) && $native->type!==null
            && $context->types->isContainedBy($native->type->type,Type::string()) && $item->default instanceof Node\Expr\ClassConstFetch
            && $item->default->class instanceof Node\Name && $item->default->class->toString()===$expected
            && $item->default->name instanceof Node\Identifier && strtolower($item->default->name->name)==='class'
            && $native->defaultType!==null && !$native->defaultType->fromDocblock && $native->defaultType->inferred
            && $source->located($native->defaultType->location,$item->default,$file)
            && $native->defaultType->type->getLiteralClassString()===$expected)) { return false; }
        $this->held[$file['path']]=$file['hash'];return true;
    }
    private function currentModelHierarchy(IssueFilterContext $context,GuardedStringCastContracts $source,array $bindings,string $model):bool
    {
        $selected=['first','query','newquery','newmodelquery','newquerywithoutscopes','newquerywithoutrelationships',
            'neweloquentbuilder','resolvecustombuilderclass','newcollection','resolvecollectionfromattribute','newinstance','newfrombuilder','hydrate','__call','__callstatic',
            'hasnamedscope','callnamedscope','isscopemethodwithattribute','scopelockforupdate'];
        $current=$model;$seen=[];$properties=[];$reachedRoot=false;$traitFrames=[];$nativeTraits=[];$sourceTraits=[];$classTraitFrames=[];$budget=64;
        for($depth=0;$depth<16 && $current!==null;$depth++) {
            if(isset($seen[strtolower($current)])) { return $this->stage('closed model ancestor declarations',false); }$seen[strtolower($current)]=true;
            $native=$context->codebase->getClassLike($current);$this->dependencies['modelHierarchy:'.$current]=$native;
            $this->lookupBindings['modelHierarchy:'.$current]=['kind'=>'class','name'=>$current];
            $physical=$this->classDeclaration($source,$native,$current,ClassLikeKind::Class_);if($physical===null) { return false; }
            $node=$physical['node'];$root=strcasecmp($current,self::MODEL)===0;
            if(!$this->stage('current model ancestor and source dispatch '.$current,($node instanceof Node\Stmt\Class_)
                && ($node->extends===null?$native->directParentClass===null:($node->extends instanceof Node\Name
                    && strcasecmp($node->extends->toString(),$native->directParentClass??'')===0))
                && ($root || array_intersect($physical['methods'],$selected)===[])
                && (!$root || $node->extends===null)
                && preg_match('~@(?:phpstan-|psalm-)?method\b[^\r\n]*(?:first|query|newCollection)\s*\(~i',$node->getDocComment()?->getText()??'')!==1
                && preg_match('~@(?:phpstan|psalm)-(?:extends|template|mixin)\b|@mixin\b~i',$node->getDocComment()?->getText()??'')!==1
                && $native->mixins===[] && self::noOrdinaryModelTemplate($node))) { return false; }
            foreach($node->attrGroups as $group) { foreach($group->attrs as $attribute) {
                if(in_array(strtolower($attribute->name->toString()),['illuminate\\database\\eloquent\\attributes\\collectedby','illuminate\\database\\eloquent\\attributes\\useeloquentbuilder'],true)) {
                    return $this->stage('no current model factory or collection attribute',false);
                }
            } }
            foreach(['$builder','$collectionClass'] as $property) {
                if(!isset($properties[$property]) && isset($physical['properties'][$property])) { $properties[$property]=['file'=>$physical['file']['path'],'span'=>$physical['properties'][$property]]; }
            }
            $selectedClassTraits=$native->usedTraits;$ownSourceTraits=[];
            foreach($native->usedTraits as $trait){if(!is_string($trait)||$trait===''){return false;}$nativeTraits[strtolower($trait)]=true;}
            foreach($node->stmts as $use) { if(!$use instanceof Node\Stmt\TraitUse) { continue; }
                foreach($use->traits as $trait) { if(!in_array(strtolower($trait->toString()),array_map(strtolower(...),$native->usedTraits),true)) {
                    return $this->stage('source trait use matches current native model hierarchy',false);
                }
                    $sourceTraits[strtolower($trait->toString())]=true;
                    if(!$this->traitFrame($context,$source,$trait->toString(),$selected,$traitFrames,$nativeTraits,$sourceTraits,$budget,true)){return false;}
                    $ownSourceTraits[strtolower($trait->toString())]=true;
                    foreach($traitFrames[strtolower($trait->toString())]['allTraits'] as $nested){$ownSourceTraits[$nested]=true;}
                }
                foreach($use->adaptations as $adaptation) { if($adaptation instanceof Node\Stmt\TraitUseAdaptation\Alias
                    && $adaptation->newName!==null && in_array(strtolower($adaptation->newName->name),$selected,true)) {
                    return $this->stage('no selected source trait alias',false);
                } }
            }
            $classTraitFrames[]=['name'=>$current,'ownSourceTraits'=>array_keys($ownSourceTraits),'nativeTraits'=>$selectedClassTraits];
            if($root) { $reachedRoot=true;break; }$current=$native->directParentClass;
        }
        $parentTraits=[];
        foreach(array_reverse($classTraitFrames) as $frame){
            $expected=$parentTraits;
            foreach($frame['ownSourceTraits'] as $trait){$expected[$trait]=true;}
            if(!$this->stage('exact current selected model ancestor trait graph '.$frame['name'],self::sameTraitNames(array_keys($expected),$frame['nativeTraits']))){return false;}
            $parentTraits=$expected;
        }
        $this->certificate['currentModelTraitSources']=$classTraitFrames;
        $sourceNames=array_keys($sourceTraits);$nativeNames=array_keys($nativeTraits);sort($sourceNames);sort($nativeNames);
        if(!$this->stage('exact current merged model and parent trait set',$sourceNames===$nativeNames)){return false;}
        $this->certificate['currentModelProperties']=$properties;
        return $this->stage('bounded complete current model hierarchy',$reachedRoot && count($properties)===2);
    }
    /** Compare one selected native list with its independently proven physical closure. */
    public static function sameTraitNames(array $physical,array $native):bool
    {
        $sets=[];
        foreach([$physical,$native] as $names){
            $set=[];
            foreach($names as $name){
                if(!is_string($name)||$name===''){return false;}
                $key=strtolower($name);if(isset($set[$key])){return false;}$set[$key]=true;
            }
            $keys=array_keys($set);sort($keys);$sets[]=$keys;
        }
        return $sets[0]===$sets[1];
    }
    /** Current selected trait methods and flattened native sets; retain compact frames only. */
    private function currentFirstTraits(IssueFilterContext $context,GuardedStringCastContracts $source,array $physical,object $native,string $role,
        ?array $parentGraph,array &$graph):bool
    {
        if($role==='builder'&&in_array('first',$physical['methods'],true)){return $this->stage('no current direct Builder first override',false);}
        if($role!=='builder'&&($native->mixins!==[]||self::tagPayloads($physical['node'],'mixin')!==[])){return $this->stage('no selected collection mixin first override',false);}
        $frames=[];$nativeTraits=[];$sourceTraits=[];$budget=64;
        $mergedMixins=self::declaredFirstMixins($physical['node'],$role==='builder'?self::BUILDER:'');
        if($mergedMixins===null){return $this->stage('closed current '.$role.' own mixin declaration',false);}
        if($role==='builder'&&($native->directParentClass!==null||!$physical['node'] instanceof Node\Stmt\Class_||$physical['node']->extends!==null)){
            return $this->stage('current Builder has no inherited class mixin authority',false);
        }
        if($role==='support'&&($native->directParentClass!==null||!$physical['node'] instanceof Node\Stmt\Class_||$physical['node']->extends!==null)){
            return $this->stage('current Support Collection has no inherited class trait authority',false);
        }
        if($role==='collection'&&!$this->stage('current Collection inherits only the independently bound Support trait graph',
            $physical['node'] instanceof Node\Stmt\Class_&&$physical['node']->extends instanceof Node\Name
            &&strcasecmp($physical['node']->extends->toString(),self::SUPPORT)===0
            &&strcasecmp($native->directParentClass??'',self::SUPPORT)===0
            &&$parentGraph!==null&&$parentGraph['role']==='support'&&strcasecmp($parentGraph['className'],self::SUPPORT)===0
            &&$parentGraph['parent']===null&&$parentGraph['mixins']===[]
            &&hash_file('sha256',$parentGraph['file'])===$parentGraph['hash'])){return false;}
        // A descendant DTO may not repair a missing entry in this selected class.
        $selectedNativeTraits=$native->usedTraits;
        foreach($selectedNativeTraits as $trait){if(!is_string($trait)||$trait===''){return false;}$nativeTraits[strtolower($trait)]=true;}
        foreach($physical['node']->stmts as $use){
            if(!$use instanceof Node\Stmt\TraitUse){continue;}
            foreach($use->adaptations as $adaptation){
                if(strtolower($adaptation->method->name)==='first'||$adaptation instanceof Node\Stmt\TraitUseAdaptation\Alias&&strtolower($adaptation->newName?->name??'')==='first'){
                    return $this->stage('no selected current first trait adaptation',false);
                }
            }
            foreach($use->traits as $trait){
                $sourceTraits[strtolower($trait->toString())]=true;
                if(!$this->traitFrame($context,$source,$trait->toString(),['first'],$frames,$nativeTraits,$sourceTraits,$budget,false)){return false;}
                $mergedMixins=[...$mergedMixins,...$frames[strtolower($trait->toString())]['mixins']];
            }
        }
        $ownNames=array_keys($sourceTraits);sort($ownNames);
        if($role==='collection'){
            // The selected native class flattens traits from its physically bound
            // Support parent. The private local parent frame is independently
            // checked before this class; unrelated native extras are never added.
            foreach($parentGraph['traits'] as $trait){$sourceTraits[$trait]=true;}
            $mergedMixins=[...$mergedMixins,...$parentGraph['mixins']];
        }
        $sourceNames=array_keys($sourceTraits);sort($sourceNames);
        if(!$this->stage('exact current '.$role.' trait graph',self::sameTraitNames($sourceNames,$selectedNativeTraits))){return false;}
        if(!$this->stage('exact current '.$role.' hierarchical native mixin multiset',self::nativeFirstMixins($native->mixins,$mergedMixins))){return false;}
        $this->certificate['currentFirstMixinSources'][$role]=['own'=>self::declaredFirstMixins($physical['node'],$role==='builder'?self::BUILDER:''),
            'merged'=>$mergedMixins,'frames'=>array_map(static fn(array $frame):array=>['name'=>$frame['name'],'file'=>$frame['file'],'hash'=>$frame['hash'],
                'directTraits'=>$frame['traits'],'ownMixins'=>$frame['ownMixins'],'mergedMixins'=>$frame['mixins']],$frames)];
        $parent=$role==='collection'?['className'=>$parentGraph['className'],'file'=>$parentGraph['file'],'hash'=>$parentGraph['hash'],
            'traits'=>$parentGraph['traits']]:null;
        $graph=['role'=>$role,'className'=>$native->name,'file'=>$physical['file']['path'],'hash'=>$physical['file']['hash'],
            'ownTraits'=>$ownNames,'traits'=>$sourceNames,'nativeTraits'=>$selectedNativeTraits,'mixins'=>$mergedMixins,'parent'=>$parent];
        $this->certificate['currentFirstTraitSources'][$role]=$graph;
        return true;
    }
    private function traitFrame(IssueFilterContext $context,GuardedStringCastContracts $source,string $name,array $selected,
        array &$frames,array &$nativeTraits,array &$sourceTraits,int &$budget,bool $modelGraph):bool
    {
        $key=strtolower($name);
        if(isset($frames[$key])){return ($frames[$key]['complete']??false)===true;}
        if(--$budget<0){return $this->stage('bounded current trait graph',false);}
        $native=$context->codebase->getClassLike($name);$role='trait:'.$key;$this->dependencies[$role]=$native;$this->lookupBindings[$role]=['kind'=>'class','name'=>$name];
        $physical=$this->classDeclaration($source,$native,$name,ClassLikeKind::Trait);if($physical===null){return false;}
        if($native->directParentClass!==null){return false;}
        foreach($physical['methods'] as $method){
            if(!in_array($method,$selected,true)){continue;}
            $allowed=$modelGraph?($name===self::FACTORY&&in_array($method,['newcollection','resolvecollectionfromattribute'],true))
                :($name===self::QUERIES&&$method==='first');
            if(!$allowed){return $this->stage('no current selected custom trait dispatch '.$name.'::'.$method,false);}
        }
        if(array_intersect(array_keys($physical['properties']),['$builder','$collectionClass'])!==[]){return $this->stage('no selected factory configuration in an unbound trait',false);}
        $doc=$physical['node']->getDocComment()?->getText()??'';
        $ownMixins=self::declaredFirstMixins($physical['node'],$modelGraph?'':$name);
        if($ownMixins===null||preg_match('~@(?:phpstan-|psalm-)?method\b[^\r\n]*(?:first|query|newCollection)\s*\(|@(?:phpstan|psalm)-(?:extends|template|mixin)\b~i',$doc)===1){return false;}
        $traits=[];$ownNativeTraits=$native->usedTraits;
        // Validate this DTO independently; never add it to an ancestor's native set.
        foreach($ownNativeTraits as $trait){if(!is_string($trait)||$trait===''){return false;}}
        foreach($physical['node']->stmts as $use){
            if(!$use instanceof Node\Stmt\TraitUse){continue;}
            if($use->adaptations!==[]){return $this->stage('closed current nested trait adaptations',false);}
            foreach($use->traits as $trait){$traits[]=$trait->toString();$sourceTraits[strtolower($trait->toString())]=true;}
        }
        $frames[$key]=['name'=>$name,'file'=>$physical['file']['path'],'hash'=>$physical['file']['hash'],'traits'=>$traits,
            'ownMixins'=>$ownMixins,'mixins'=>[],'complete'=>false];
        unset($physical);
        $mergedMixins=$ownMixins;$nestedSourceTraits=[];
        foreach($traits as $trait){
            if(!$this->traitFrame($context,$source,$trait,$selected,$frames,$nativeTraits,$sourceTraits,$budget,$modelGraph)){return false;}
            $mergedMixins=[...$mergedMixins,...$frames[strtolower($trait)]['mixins']];
            $nestedSourceTraits[strtolower($trait)]=true;
            foreach($frames[strtolower($trait)]['allTraits'] as $nested){$nestedSourceTraits[$nested]=true;}
        }
        $allTraits=array_keys($nestedSourceTraits);sort($allTraits);
        if(!$this->stage('exact current selected trait graph '.$name,self::sameTraitNames($allTraits,$ownNativeTraits))){return false;}
        if(!$this->stage('exact current trait hierarchical native mixin multiset '.$name,self::nativeFirstMixins($native->mixins,$mergedMixins))){return false;}
        $frames[$key]['allTraits']=$allTraits;$frames[$key]['nativeTraits']=$ownNativeTraits;
        $frames[$key]['mixins']=$mergedMixins;$frames[$key]['complete']=true;
        return true;
    }
    /** The selected literal chain contains only unchanged model-preserving operations. */
    public function preservesLiteralOperations(IssueFilterContext $context,GuardedStringCastContracts $source,array $file,array $local):bool
    {
        if(isset($local['operations']['where'])){
            $native=$this->selectedMethod($context,'localWhere',self::BUILDER,'where');
            $physical=$this->method($source,$native,self::BUILDER,'where',4,false);if($physical===null){return false;}
            [$declared,$syntax]=$physical;
            $expected='public function where($column, $operator = null, $value = null, $boolean = \'and\') {
                if ($column instanceof Closure && is_null($operator)) {
                    $column($query = $this->model->newQueryWithoutRelationships());
                    $this->eagerLoad = array_merge($this->eagerLoad, $query->getEagerLoads());
                    $this->withoutGlobalScopes($query->removedScopes());
                    $this->query->addNestedWhereQuery($query->getQuery(), $boolean);
                } else {$this->query->where(...func_get_args());} return $this;
            }';
            if(!$this->stage('current literal where keeps the same builder',$this->currentThisReturn($source,$native,$syntax,$declared,self::BUILDER)
                &&$source->tokens(substr($declared['contents'],$syntax->getStartFilePos(),$syntax->getEndFilePos()-$syntax->getStartFilePos()+1))===$source->tokens($expected))){return false;}
        }
        if(!isset($local['operations']['lockForUpdate'])){return $this->current();}
        $query='Illuminate\\Database\\Query\\Builder';
        $queryClass=$context->codebase->getClassLike($query);$this->dependencies['forwardedQueryClass']=$queryClass;
        if($this->classDeclaration($source,$queryClass,$query,ClassLikeKind::Class_)===null){return false;}
        foreach([['lockForUpdate',0,'public function lockForUpdate() { return $this->lock(true); }'],
            ['lock',1,'public function lock($value = true) { $this->lock = $value; if (! is_null($this->lock)) { $this->useWritePdo(); } return $this; }']] as [$name,$count,$body]){
            $native=$this->selectedMethod($context,'forwarded:'.$name,$query,$name);$physical=$this->method($source,$native,$query,$name,$count,false);
            if($physical===null){return false;}[$declared,$syntax]=$physical;
            if(!$this->stage('unchanged current forwarded '.$name,$this->currentThisReturn($source,$native,$syntax,$declared,$query)
                &&$source->tokens(substr($declared['contents'],$syntax->getStartFilePos(),$syntax->getEndFilePos()-$syntax->getStartFilePos()+1))===$source->tokens($body))){return false;}
        }
        $native=$this->selectedMethod($context,'forwardedBuilderCall',self::BUILDER,'__call');$physical=$this->method($source,$native,self::BUILDER,'__call',2,false);
        if($physical===null){return false;}[$declared,$syntax]=$physical;
        $expected='public function __call($method, $parameters) {
            if ($method === \'macro\') { $this->localMacros[$parameters[0]] = $parameters[1]; return; }
            if ($this->hasMacro($method)) { array_unshift($parameters, $this); return $this->localMacros[$method](...$parameters); }
            if (static::hasGlobalMacro($method)) { $callable = static::$macros[$method]; if ($callable instanceof Closure) { $callable = $callable->bindTo($this, static::class); } return $callable(...$parameters); }
            if ($this->hasNamedScope($method)) { return $this->callNamedScope($method, $parameters); }
            if (in_array(strtolower($method), $this->passthru)) { return $this->toBase()->{$method}(...$parameters); }
            $this->forwardCallTo($this->query, $method, $parameters); return $this;
        }';
        if(!$this->stage('current standard Eloquent forwarding body',$source->tokens(substr($declared['contents'],$syntax->getStartFilePos(),$syntax->getEndFilePos()-$syntax->getStartFilePos()+1))===$source->tokens($expected)
            &&$native->returnType!==null&&$native->returnType->fromDocblock&&!$native->returnType->inferred
            &&$context->types->equals($native->returnType->type,Type::mixed())&&$this->docType($source,$native->returnType,$syntax,$declared,'return','mixed'))){return false;}
        // Configured macros are source metadata. No bootstrap or provider body is run.
        $composer=$source->path('composer.json');$json=$composer===''?false:file_get_contents($composer);$configuration=$json===false?null:json_decode($json,true);
        if(!is_array($configuration)){return $this->stage('current explicit macro configuration',false);}
        $this->held[$composer]=hash('sha256',$json);
        if(isset($configuration['extra']['laramago']['macro-files'])||isset($configuration['extra']['laramago']['macro-service-providers'])){
            // A separate current catalog certificate is needed for opted-in sources.
            return $this->stage('configured forwarding catalogs remain unproved',false);
        }
        foreach((new NodeFinder)->findInstanceOf($file['nodes'],Node\Expr\StaticCall::class) as $call){
            if($call->class instanceof Node\Name&&in_array(strtolower($call->class->toString()),array_map(strtolower(...),[self::BUILDER,$query,$local['model']]),true)
                &&(!$call->name instanceof Node\Identifier||in_array(strtolower($call->name->name),['macro','mixin','flushmacros'],true))){
                return $this->stage('no selected caller macro mutation or registration',false);
            }
        }
        $metadata=$context->codebase->getClassLike(self::BUILDER);$this->dependencies['forwardedBuilderClass']=$metadata;
        if($metadata===null||in_array('lockforupdate',array_map(strtolower(...),[...$metadata->pseudoMethods,...$metadata->staticPseudoMethods]),true)){
            return $this->stage('no stronger declared forwarded lock method',false);
        }
        foreach([self::BUILDER,$local['model']] as $class){
            foreach(['lockForUpdate','scopeLockForUpdate'] as $method){
                $bound=$context->codebase->getMethod($class,$method);$declaring=$context->codebase->getDeclaringMethod($class,$method);
                $this->dependencies['forwardingAbsent:'.$class.'::'.$method]=[$bound,$declaring];
                foreach([$bound,$declaring] as $selected){
                    if($selected===null){continue;}
                    // A real mixin lookup may expose the same current physical
                    // Query Builder lock declaration. It must not be a scope,
                    // pseudo method, replacement or separately inferred result.
                    $default=$method==='lockForUpdate'?($this->dependencies['forwarded:lockForUpdate']??null):null;
                    if($default===null||!$this->equivalent($context,$selected,$default)){
                        return $this->stage('no selected native scope or lock override',false);
                    }
                }
            }
        }
        return $this->current();
    }
    private function currentThisReturn(GuardedStringCastContracts $source,FunctionLikeMetadata $native,Node\Stmt\ClassMethod $syntax,array $file,string $owner):bool
    {
        $type=$native->returnType?->type;$atom=count($type?->atomicTypes??[])===1?$type->atomicTypes[0]:null;
        return $native->declaredReturnType===null&&$type!==null&&!$type->flags->byReference&&!$type->flags->possiblyUndefined
            &&strcasecmp($native->identifier->class??'',$owner)===0
            &&$atom instanceof NamedObjectType&&$atom->name==='$this'&&$atom->static&&$atom->isThis
            &&($atom->parameters??[])===[]&&($atom->variances??[])===[]&&($atom->intersections??[])===[]&&!$atom->remappedParameters
            &&$this->docType($source,$native->returnType,$syntax,$file,'return','$this');
    }
    /** Both real public lookup APIs are primed; selected aliases must describe the same declaration. */
    private function selectedMethod(IssueFilterContext $context,string $role,string $class,string $name):?FunctionLikeMetadata
    {
        $bound=$context->codebase->getMethod($class,$name);$declaring=$context->codebase->getDeclaringMethod($class,$name);
        $this->dependencies[$role.':bound']=$bound;$this->dependencies[$role.':declaring']=$declaring;
        $this->lookupBindings[$role]=['kind'=>'method','class'=>$class,'name'=>$name];
        $selected=$bound??$declaring;$this->dependencies[$role]=$selected;
        if(!$this->stage('genuine selected method APIs '.$role,$selected!==null && ($bound===null || $declaring===null || $this->equivalent($context,$bound,$declaring)))) { return null; }
        return $selected;
    }
    private function equivalent(IssueFilterContext $context,FunctionLikeMetadata $left,FunctionLikeMetadata $right):bool
    {
        foreach(['identifier','kind','name','originalName','location','nameLocation','flags','static','abstract','final','visibility','constructor',
            'hasDocblock','attributes','thrownTypes','assertions','ifTrueAssertions','ifFalseAssertions','assertionsInferred','globalsAccessed','whereConstraints','availableVersions'] as $field) {
            if($left->$field!=$right->$field) { return false; }
        }
        if(count($left->parameters)!==count($right->parameters) || count($left->templates)!==count($right->templates)) { return false; }
        foreach(['returnType','declaredReturnType'] as $field) { if(!$this->equivalentTypeMetadata($context,$left->$field,$right->$field)) { return false; } }
        foreach($left->parameters as $index=>$parameter) {
            $other=$right->parameters[$index];
            foreach(['name','location','nameLocation','flags','attributes'] as $field) { if($parameter->$field!=$other->$field) { return false; } }
            foreach(['type','declaredType','defaultType','outType','closureThisType'] as $field) { if(!$this->equivalentTypeMetadata($context,$parameter->$field,$other->$field)) { return false; } }
        }
        foreach($left->templates as $index=>$template) {
            $other=$right->templates[$index];
            foreach(['name','definingEntity','variance','readonly'] as $field) { if($template->$field!=$other->$field) { return false; } }
            if(!$context->types->equals($template->constraint,$other->constraint) || ($template->default===null)!==($other->default===null)
                || $template->default!==null && !$context->types->equals($template->default,$other->default)) { return false; }
        }
        return true;
    }
    private function equivalentTypeMetadata(IssueFilterContext $context,?object $left,?object $right):bool
    {
        return $left===null || $right===null?$left===$right:($left->location==$right->location && $left->fromDocblock===$right->fromDocblock
            && $left->inferred===$right->inferred && $left->type->flags==$right->type->flags && $context->types->equals($left->type,$right->type));
    }
    private function generic(IssueFilterContext $context,Type $type,TemplateMetadata $template):bool
    {
        $atom=count($type->atomicTypes)===1?$type->atomicTypes[0]:null;
        return !$type->flags->byReference && !$type->flags->possiblyUndefined && $atom instanceof GenericParameterType
            && $atom->name===$template->name && $atom->definingEntity==$template->definingEntity
            && ($atom->intersections??[])===[] && $context->types->equals($atom->constraint,$template->constraint);
    }
    private function genericUnion(IssueFilterContext $context,Type $type,array $templates):bool
    {
        if($type->flags->byReference || $type->flags->possiblyUndefined || count($type->atomicTypes)!==count($templates)) { return false; }
        $remaining=$templates;
        foreach($type->atomicTypes as $atom) {
            $matched=null;foreach($remaining as $index=>$template) { if($this->generic($context,Type::fromAtomic($atom),$template)) { $matched=$index;break; } }
            if($matched===null) { return false; }unset($remaining[$matched]);
        }
        return $remaining===[];
    }
    private function collectionTemplates(IssueFilterContext $context,array $bindings):bool
    {
        $collection=$this->dependencies['collection']->templates;$support=$this->dependencies['support']->templates;
        if(count($collection)!==2 || count($support)!==2 || $collection[0]->name!=='TKey' || $collection[1]->name!=='TModel'
            || $support[0]->name!=='TKey' || $support[1]->name!=='TValue') { return false; }
        foreach([[self::COLLECTION,$collection],[self::SUPPORT,$support]] as [$owner,$templates]) { foreach($templates as $index=>$template) {
            if($template->definingEntity->kind!==GenericParentKind::ClassLike || strcasecmp($template->definingEntity->name,$owner)!==0
                || $template->default!==null || $template->readonly!==true || $template->variance!==($owner===self::SUPPORT && $index===1?Variance::Covariant:Variance::Invariant)) { return false; }
        } }
        return self::supportedClassDocumentation($bindings['collection']['node'],[
                'template'=>['TKey of array-key','TModel of \\Illuminate\\Database\\Eloquent\\Model'],
                'extends'=>['\\Illuminate\\Support\\Collection<TKey,TModel>']])
            && self::supportedClassDocumentation($bindings['support']['node'],['template'=>['TKey of array-key'],'template-covariant'=>['TValue']])
            && $context->types->equals($collection[0]->constraint,Type::fromAtomic(new ScalarType(ScalarTypeKind::ArrayKey)))
            && $context->types->equals($support[0]->constraint,$collection[0]->constraint)
            && $context->types->equals($collection[1]->constraint,$this->dependencies['builder']->templates[0]->constraint)
            && $context->types->equals($support[1]->constraint,Type::mixed());
    }
    private function columns(IssueFilterContext $context,GuardedStringCastContracts $source,FunctionLikeMetadata $method,Node\Stmt\ClassMethod $syntax,array $file):bool
    {
        $formal=$method->parameters[0];$type=$formal->type?->type;
        if($formal->declaredType!==null || $formal->type===null || !$formal->type->fromDocblock || $formal->type->inferred || $formal->closureThisType!==null
            || $type->flags->byReference || $type->flags->possiblyUndefined || count($type->atomicTypes)!==2
            || !$this->docType($source,$formal->type,$syntax,$file,'param','array|string','$columns')) { return false; }
        $strings=0;$arrays=0;
        foreach($type->atomicTypes as $atom) {
            if($atom instanceof ScalarType && $context->types->equals(Type::fromAtomic($atom),Type::string())) { $strings++;continue; }
            if($atom instanceof KeyedArrayType && $atom->knownItems===null && !$atom->nonEmpty && $atom->keyType!==null && $atom->valueType!==null
                && $context->types->equals($atom->keyType,Type::fromAtomic(new ScalarType(ScalarTypeKind::ArrayKey)))
                && $context->types->equals($atom->valueType,Type::mixed())) { $arrays++;continue; }
            return false;
        }
        $default=$syntax->params[0]->default;
        return $strings===1 && $arrays===1 && $default instanceof Node\Expr\Array_ && count($default->items)===1 && $default->items[0]!==null
            && $default->items[0]->key===null && !$default->items[0]->byRef && !$default->items[0]->unpack
            && $default->items[0]->value instanceof Node\Scalar\String_ && $default->items[0]->value->value==='*'
            && $formal->defaultType!==null && !$formal->defaultType->fromDocblock && $formal->defaultType->inferred
            && $this->parameterDefaultLocated($source,$formal->defaultType->location,$syntax->params[0],$file)
            && $context->types->isContainedBy($formal->defaultType->type,Type::list(Type::literalString('*')));
    }
    private function collectionCallback(IssueFilterContext $context,Type $type,array $templates):bool
    {
        if($type->flags->byReference || $type->flags->possiblyUndefined || count($type->atomicTypes)!==2) { return false; }$callable=null;$nulls=0;
        foreach($type->atomicTypes as $atom) { if($atom instanceof CallableType) { $callable=$atom; }elseif($atom instanceof SimpleAtomicType && $atom->kind===SimpleAtomicTypeKind::Null) { $nulls++; }else { return false; } }
        $signature=$callable?->signature;
        if($nulls!==1 || $callable?->alias!==null || $signature===null || $signature->pure || $signature->closure || $signature->source!==null
            || $signature->constraints!==[] || count($signature->parameters)!==2 || $signature->returnType===null
            || !$context->types->equals($signature->returnType,Type::bool())) { return false; }
        foreach($signature->parameters as $index=>$formal) {
            if($formal->name!==null || $formal->type===null || $formal->byReference || $formal->variadic || $formal->hasDefault || $formal->closureThisType!==null
                || !$this->generic($context,$formal->type,$templates[$index===0?1:0])) { return false; }
        }
        return true;
    }
    private function generalNullableCallable(IssueFilterContext $context,Type $type):bool
    {
        if($type->flags->byReference || $type->flags->possiblyUndefined || count($type->atomicTypes)!==2) { return false; }$callable=null;$nulls=0;
        foreach($type->atomicTypes as $atom) { if($atom instanceof CallableType) { $callable=$atom; }elseif($atom instanceof SimpleAtomicType && $atom->kind===SimpleAtomicTypeKind::Null) { $nulls++; }else { return false; } }
        // The zero-argument call selects the physical null default; no callback signature is broadened.
        return $nulls===1 && $callable?->alias===null && $callable?->signature!==null;
    }
    private function collectionDefault(IssueFilterContext $context,Type $type,TemplateMetadata $template):bool
    {
        if($type->flags->byReference || $type->flags->possiblyUndefined || count($type->atomicTypes)!==2) { return false; }$generics=0;$closures=0;
        foreach($type->atomicTypes as $atom) {
            if($this->generic($context,Type::fromAtomic($atom),$template)) { $generics++;continue; }
            $signature=$atom instanceof CallableType?$atom->signature:null;
            if(!$atom instanceof CallableType || $atom->alias!==null || $signature===null || !$signature->closure || $signature->pure
                || $signature->source!==null || $signature->constraints!==[] || $signature->parameters!==[] || $signature->returnType===null
                || !$this->generic($context,$signature->returnType,$template)) { return false; }$closures++;
        }
        return $generics===1 && $closures===1;
    }
    private function standardCollectionFactory(IssueFilterContext $context,GuardedStringCastContracts $source,array $bindings,string $model,Type $modelType):bool
    {
        $template=$this->dependencies['factory']->templates;
        if(!$this->stage('current collection factory declaration',count($template)===1 && $template[0]->name==='TCollection'
            && $template[0]->definingEntity->kind===GenericParentKind::ClassLike && strcasecmp($template[0]->definingEntity->name,self::FACTORY)===0
            && $template[0]->default===null && $context->types->equals($template[0]->constraint,Type::namedObject(self::COLLECTION))
            && self::supportedClassDocumentation($bindings['factory']['node'],['template'=>['TCollection of \\Illuminate\\Database\\Eloquent\\Collection']]))) { return false; }
        foreach([['query',self::MODEL,self::MODEL,0,true],['newCollection',$model,self::FACTORY,1,false],
            ['resolveCollectionFromAttribute',$model,self::FACTORY,0,false]] as [$name,$target,$owner,$count,$static]) {
            $method=$this->selectedMethod($context,$name,$target,$name);$physical=$this->method($source,$method,$owner,$name,$count,$static);
            if($physical===null) { return false; }[$file,$syntax]=$physical;$return=$method->returnType?->type;
            if(!$this->stage('unchanged physical standard factory '.$name,$this->bodyHash($syntax)===self::BODIES[$name]
                && $method->declaredReturnType===null && $method->returnType!==null && $method->returnType->fromDocblock && !$method->returnType->inferred
                && !$return->flags->byReference && !$return->flags->possiblyUndefined)) { return false; }
            if($name==='query') {
                $atom=count($return->atomicTypes)===1?$return->atomicTypes[0]:null;$parameter=$atom instanceof NamedObjectType?($atom->parameters[0]??null):null;
                if(!$this->stage('declared query model bound',$atom instanceof NamedObjectType && $atom->name===self::BUILDER && count($atom->parameters??[])===1
                    && $parameter!==null && $context->types->isContainedBy($parameter,Type::namedObject(self::MODEL))
                    && $this->docType($source,$method->returnType,$syntax,$file,'return','\\Illuminate\\Database\\Eloquent\\Builder<static>'))) { return false; }
            } elseif($name==='newCollection') {
                if(!$this->stage('declared current collection factory value',$this->generic($context,$return,$template[0])
                    && $this->docType($source,$method->returnType,$syntax,$file,'return','TCollection'))) { return false; }
            } else {
                if(!$this->stage('declared current collection resolver domain',$context->types->isContainedBy($return,Type::union(Type::string(),Type::null()))
                    && $this->docType($source,$method->returnType,$syntax,$file,'return','class-string<TCollection>|null'))) { return false; }
            }
        }
        $resolved=(new EloquentCollectionType)->resolve($context->codebase,$modelType);
        return $this->stage('standard native collection factory projects the same model',$resolved!==null
            && $context->types->equals($resolved,Type::namedObject(self::COLLECTION,Type::int(),$modelType)));
    }
    private function bodyHash(Node\Stmt\ClassMethod $method):string
    {
        $clean=new NodeTraverser(new class extends NodeVisitorAbstract { public function enterNode(Node $node):null { $node->setAttribute('comments',[]);return null; } });
        return hash('sha256',(new Standard)->prettyPrint($clean->traverse($method->stmts??[])));
    }
    private function getAndCollectionFirst(IssueFilterContext $context,GuardedStringCastContracts $source,array $bindings):bool
    {
        $get=$this->selectedMethod($context,'get',self::BUILDER,'get');
        $physical=$this->method($source,$get,self::BUILDER,'get',1,false);if($physical===null) { return false; }
        [$file,$syntax]=$physical;$return=$get->returnType?->type;$atom=$return?->atomicTypes[0]??null;
        if(!$this->stage('get declares the model collection projection',$get->declaredReturnType===null && $get->returnType!==null
            && $get->returnType->fromDocblock && !$get->returnType->inferred && count($return->atomicTypes)===1 && !$return->flags->byReference
            && !$return->flags->possiblyUndefined && $atom instanceof NamedObjectType && $atom->name===self::COLLECTION && count($atom->parameters??[])===2
            && $context->types->equals($atom->parameters[0],Type::int()) && count($atom->parameters[1]->atomicTypes)===1
            && $atom->parameters[1]->atomicTypes[0] instanceof GenericParameterType && $atom->parameters[1]->atomicTypes[0]->name==='TModel'
            && $atom->parameters[1]->atomicTypes[0]->definingEntity->kind===GenericParentKind::ClassLike
            && strcasecmp($atom->parameters[1]->atomicTypes[0]->definingEntity->name,self::BUILDER)===0
            && $this->generic($context,$atom->parameters[1],$this->dependencies['builder']->templates[0])
            && !$atom->static && !$atom->isThis && $atom->intersections===null && !$atom->remappedParameters
            && $this->bodyHash($syntax)===self::BODIES['get'] && $this->columns($context,$source,$get,$syntax,$file)
            && $this->docType($source,$get->returnType,$syntax,$file,'return','\\Illuminate\\Database\\Eloquent\\Collection<int,TModel>'))) { return false; }
        $collection=$bindings['collection']['node'];
        if(!$this->stage('current model collection to support value binding',$collection->extends instanceof Node\Name && $collection->extends->toString()===self::SUPPORT
            && self::supportedClassDocumentation($collection,['template'=>['TKey of array-key','TModel of \\Illuminate\\Database\\Eloquent\\Model'],
                'extends'=>['\\Illuminate\\Support\\Collection<TKey,TModel>']])
            && !in_array('first',$bindings['collection']['methods'],true)
            && strcasecmp($this->dependencies['collection']->directParentClass??'',self::SUPPORT)===0
            && $this->collectionTemplates($context,$bindings))) { return false; }
        $first=$this->selectedMethod($context,'collectionFirst',self::COLLECTION,'first');
        $physical=$this->method($source,$first,self::SUPPORT,'first',2,false,true);if($physical===null) { return false; }
        [$file,$syntax]=$physical;
        if(!$this->stage('physical standard collection first defaults',$first->declaredReturnType===null && $first->returnType!==null
            && $first->returnType->fromDocblock && !$first->returnType->inferred
            && $source->tokens(substr($file['contents'],$syntax->getStartFilePos(),$syntax->getEndFilePos()-$syntax->getStartFilePos()+1))
                ===$source->tokens('public function first(?callable $callback = null, $default = null) { return Arr::first($this->items, $callback, $default); }')
            && preg_match('~@template[ \t]+TFirstDefault\b~',$syntax->getDocComment()?->getText()??'')===1
            && $this->docType($source,$first->returnType,$syntax,$file,'return','TValue|TFirstDefault')
            && count($first->templates)===1 && $first->templates[0]->name==='TFirstDefault'
            && $first->templates[0]->definingEntity->kind===GenericParentKind::FunctionLike
            && strcasecmp($first->templates[0]->definingEntity->name,$first->identifier->class)===0
            && $first->templates[0]->definingEntity->member===$first->identifier->name
            && $first->templates[0]->default===null && !$first->templates[0]->readonly && $first->templates[0]->variance===Variance::Invariant
            && $context->types->equals($first->templates[0]->constraint,Type::mixed())
            && $this->genericUnion($context,$first->returnType->type,[$this->dependencies['support']->templates[1],$first->templates[0]]))) { return false; }
        foreach($syntax->params as $index=>$parameter) {
            $native=$first->parameters[$index];
            if(!$this->stage('current collection first null default '.$index,$parameter->default instanceof Node\Expr\ConstFetch
                && strtolower($parameter->default->name->toString())==='null' && !$parameter->byRef && !$parameter->variadic
                && $native->name==='$'.$parameter->var->name && $native->outType===null && !$native->flags->contains(MetadataFlags::BY_REFERENCE)
                && !$native->flags->contains(MetadataFlags::VARIADIC) && $native->flags->contains(MetadataFlags::HAS_DEFAULT)
                && $source->located($native->location,$parameter,$file) && $source->located($native->nameLocation,$parameter->var,$file)
                && $native->defaultType!==null && !$native->defaultType->fromDocblock && $native->defaultType->inferred
                && $this->parameterDefaultLocated($source,$native->defaultType->location,$parameter,$file) && $context->types->equals($native->defaultType->type,Type::null()))) { return false; }
        }
        $callback=$first->parameters[0];$default=$first->parameters[1];
        if(!$this->stage('native collection callback and default declarations',$callback->declaredType!==null
            && !$callback->declaredType->fromDocblock && !$callback->declaredType->inferred && $callback->closureThisType===null
            && $callback->type!==null && $callback->type->fromDocblock && !$callback->type->inferred
            && $default->declaredType===null && $default->type!==null && $default->type->fromDocblock && !$default->type->inferred && $default->closureThisType===null
            && $this->docType($source,$callback->type,$syntax,$file,'param','(callable(TValue,TKey):bool)|null','$callback')
            && $this->docType($source,$default->type,$syntax,$file,'param','TFirstDefault|(\\Closure():TFirstDefault)','$default')
            && $this->collectionCallback($context,$callback->type->type,$this->dependencies['support']->templates)
            && $this->generalNullableCallable($context,$callback->declaredType->type)
            && $this->collectionDefault($context,$default->type->type,$first->templates[0]))) { return false; }
        $this->held[$file['path']]=$file['hash'];return true;
    }
    /** Whole supported documentation payload, including its genuine byte span. */
    private function docType(GuardedStringCastContracts $source,?object $native,Node $syntax,array $file,string $tag,string $expected,?string $parameter=null):bool
    {
        $payload=self::docPayload($syntax,$tag,$parameter);
        return $native!==null && $payload!==null && $native->fromDocblock && !$native->inferred
            && preg_replace('/\s+/','',$payload['type'])===$expected && $native->location!==null
            && $source->path($native->location->file??'')===$file['path']
            && [$native->location->span->start,$native->location->span->end]===$payload['span'];
    }
    /** Exact observed SDK parameter initializer span, including its equals token. */
    private function parameterDefaultLocated(GuardedStringCastContracts $source,?object $native,Node\Param $syntax,array $file):bool
    {
        $span=self::parameterDefaultSpan($syntax,$file['contents']);
        return $native!==null&&$span!==null&&$source->path($native->file??'')===$file['path']
            &&[$native->span->start,$native->span->end]===$span&&hash_file('sha256',$file['path'])===$file['hash'];
    }
    /** Parser-only span; deriving it never creates a native type or context. */
    public static function parameterDefaultSpan(Node\Param $syntax,string $contents):?array
    {
        if(!$syntax->var instanceof Node\Expr\Variable||!is_string($syntax->var->name)||$syntax->default===null){return null;}
        $prefixStart=$syntax->var->getEndFilePos()+1;$valueStart=$syntax->default->getStartFilePos();$valueEnd=$syntax->default->getEndFilePos()+1;
        if($prefixStart<0||$valueStart<$prefixStart||$valueEnd<$valueStart||$valueEnd>strlen($contents)){return null;}
        $prefix=substr($contents,$prefixStart,$valueStart-$prefixStart);
        if(preg_match('~\A[ \t\r\n]*(=)[ \t\r\n]*\z~',$prefix,$matches,PREG_OFFSET_CAPTURE)!==1){return null;}
        return [$prefixStart+$matches[1][1],$valueEnd];
    }
    /** Current source veto only; the native concrete declaration remains mandatory. */
    public static function noOrdinaryModelTemplate(Node $syntax):bool
    {
        return preg_match('~@template(?:-[a-z-]+)?\b~i',$syntax->getDocComment()?->getText()??'')!==1;
    }
    /** Parser-only helper; its result does not authorize a native diagnostic. */
    public static function docPayload(Node $syntax,string $tag,?string $parameter=null):?array
    {
        $comment=$syntax->getDocComment();if($comment===null || !in_array($tag,['return','param','var'],true)) { return null; }
        $text=$comment->getText();
        if(preg_match('~@(?:phpstan|psalm)-(?:return|param|var|template|type|import-type)\b|@param-out\b~',$text)===1) { return null; }
        $pattern=$tag!=='param'?'~@'.preg_quote($tag,'~').'[ \t]+([^\r\n]*?)[ \t]*(?:\*/|\r?\n|$)~'
            :'~@param[ \t]+([^\r\n]*?)[ \t]+'.preg_quote($parameter??'','~').'\b~';
        if(preg_match_all($pattern,$text,$matches,PREG_OFFSET_CAPTURE)!==1) { return null; }
        [$type,$offset]=$matches[1][0];if($type==='') { return null; }
        return ['type'=>$type,'span'=>[$comment->getStartFilePos()+$offset,$comment->getStartFilePos()+$offset+strlen($type)]];
    }
    /** Closed class/trait generic tags. Parser agreement alone is not a native certificate. */
    public static function supportedClassDocumentation(Node $syntax,array $expected):bool
    {
        $text=$syntax->getDocComment()?->getText()??'';
        if(preg_match('~@(?:phpstan|psalm)-(?:template|extends|implements|use|mixin|type|import-type)\b~i',$text)===1){return false;}
        // Interface mappings are not used to derive this concrete first value;
        // the selected class templates and collection parent mapping are closed.
        if(preg_match_all('~@(template(?:-[a-z-]+)?|extends|use|mixin)[ \t]+([^\r\n]*?)[ \t]*(?:\*/|\r?\n|$)~',$text,$matches,PREG_SET_ORDER)===false){return false;}
        $actual=[];
        foreach($matches as $match){$actual[$match[1]][]=preg_replace('/\s+/','',$match[2]);}
        $closed=[];foreach($expected as $tag=>$payloads){foreach($payloads as $payload){$closed[$tag][]=preg_replace('/\s+/','',$payload);}}
        foreach($actual as &$payloads){sort($payloads);}unset($payloads);
        foreach($closed as &$payloads){sort($payloads);}unset($payloads);ksort($actual);ksort($closed);
        return $actual===$closed;
    }
    /** Closed ordinary source mixins for the selected standard first trait graph. */
    public static function declaredFirstMixins(Node $syntax,string $owner):?array
    {
        $text=$syntax->getDocComment()?->getText()??'';
        if(preg_match('~@(?:phpstan|psalm)-(?:mixin|type|import-type)\b~i',$text)===1){return null;}
        $allowed=match(strtolower($owner)){
            'illuminate\\database\\eloquent\\builder','illuminate\\database\\concerns\\buildsqueries'=>'Illuminate\\Database\\Query\\Builder',
            'illuminate\\database\\eloquent\\concerns\\queriesrelationships'=>'Illuminate\\Database\\Eloquent\\Builder',
            default=>null,
        };
        $payloads=self::tagPayloads($syntax,'mixin');
        // An unrecognized or unclosed tag must not disappear from the multiset.
        if(preg_match_all('~@mixin\b~i',$text)!==count($payloads)){return null;}
        if($payloads===[]){return [];}
        if($allowed===null||count($payloads)!==1||strcasecmp($payloads[0],'\\'.$allowed)!==0){return null;}
        return [$allowed];
    }
    /** Exact native multiset; duplicates and every selected trait snapshot remain significant. */
    public static function nativeFirstMixins(array $mixins,array $expected):bool
    {
        $actual=[];
        foreach($mixins as $mixin){
            if(!$mixin instanceof Type||count($mixin->atomicTypes)!==1||$mixin->flags->byReference||$mixin->flags->possiblyUndefined){return false;}
            $atomic=$mixin->atomicTypes[0];
            if(!$atomic instanceof NamedObjectType||$atomic->isThis||$atomic->static||($atomic->parameters??[])!==[]
                ||($atomic->variances??[])!==[]||($atomic->intersections??[])!==[]||$atomic->remappedParameters){return false;}
            $actual[]=strtolower($atomic->name);
        }
        $closed=[];foreach($expected as $name){
            if(!is_string($name)||!in_array($name,['Illuminate\\Database\\Query\\Builder','Illuminate\\Database\\Eloquent\\Builder'],true)){return false;}
            $closed[]=strtolower($name);
        }
        sort($actual);sort($closed);return $actual===$closed;
    }
    private static function tagPayloads(Node $syntax,string $tag):array
    {
        $text=$syntax->getDocComment()?->getText()??'';
        preg_match_all('~@'.preg_quote($tag,'~').'[ \t]+([^\r\n]*?)[ \t]*(?:\*/|\r?\n|$)~',$text,$matches);
        return array_map(static fn(string $value):string=>preg_replace('/\s+/','',$value),$matches[1]??[]);
    }
    /** Selected source receipts only; this accessor performs no SDK query. */
    public function sourceHashes():array{return $this->held;}
    public function current():bool
    {
        foreach($this->held as $file=>$hash) { if(hash_file('sha256',$file)!==$hash) { return $this->stage('all selected physical sources current',false); } }
        return $this->stage('all selected physical sources current',true);
    }
}
