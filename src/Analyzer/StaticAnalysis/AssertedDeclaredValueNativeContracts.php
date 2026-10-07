<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Ichinya\Laramago\Analyzer\{EloquentCollectionType,NullableCollectionOffsetContract};
use Mago\Sdk\Analyzer\{IssueFilterContext,Type};
use Mago\Sdk\Analyzer\Assertion\{TypeAssertion,TypeAssertionKind};
use Mago\Sdk\Analyzer\Metadata\{FunctionLikeMetadata,MetadataFlags};
use Mago\Sdk\Analyzer\Type\{ConditionalType,GenericParameterType,GenericParentKind,NamedObjectType};

/** Current declared postconditions and element contracts; no synthetic expression or provider return. */
final class AssertedDeclaredValueNativeContracts
{
    public function __construct(private readonly string $root) {}
    public function admits(IssueFilterContext $context,array $proof,array &$receipt):bool
    {
        $reader=new NullFlowNativeContracts($this->root);$details=[];$scope=$proof['scope'];
        if($proof['sourceSha256']!==hash('sha256',$context->contents)||!$reader->current($context->file,$context->contents)
            ||!$reader->caller($context,$scope,$details)) { $receipt=$details;return false; }
        $target=$reader->expressionBound($context,$proof['targetAssertion'],$scope,$details);
        $details['targetAssertion']=$target;
        if($target===null||!$this->ordinaryAssertion($target)) { $receipt=$details;return false; }
        $targetNative=$target['native'];$formal=$targetNative->parameters[0];$nullAssertion=false;
        foreach($this->assertions($targetNative,$formal->name) as $assertion) {
            if(!$assertion instanceof TypeAssertion||$assertion->kind!==TypeAssertionKind::IsNotType||!$context->types->equals($assertion->type,Type::null())) { $receipt=$details;return false; }
            $nullAssertion=true;
        }
        if(!$nullAssertion||preg_match('/@phpstan-assert\s+!null\s+'.preg_quote($formal->name,'/').'(?:\s|$)/m',$target['source']['doc'])!==1) { $receipt=$details;return false; }
        $details['targetDeclaredNotNullPostcondition']=true;
        if($proof['kind']==='generic-equality-string-postcondition') {
            $admitted=$this->equality($context,$reader,$proof,$details);
        } elseif($proof['kind']==='declared-factory-collection-element') {
            $admitted=$this->factory($context,$reader,$proof,$details);
        } else { $admitted=false; }
        if($admitted&&!$reader->current($context->file,$context->contents)) { $admitted=false; }
        foreach($details['physicalFactoryModelDefault']['classes']??[] as $declaration) {
            $current=$reader->bytes($declaration['file']);
            if($current===null||hash('sha256',$current)!==$declaration['sourceSha256']) { $admitted=false; }
        }
        $details['currentDeclaredValueCertificate']=$admitted;$details['returnedProviderTypeChanged']=false;
        $receipt=$details;return $admitted;
    }
    private function equality(IssueFilterContext $context,NullFlowNativeContracts $reader,array $proof,array &$details):bool
    {
        $scope=$proof['scope'];$equality=$reader->expressionBound($context,$proof['equalityAssertion'],$scope,$details);
        $field=$reader->expressionBound($context,$proof['targetExpression'],$scope,$details);
        $details['genericEquality']=$equality;$details['physicalTargetField']=$field;
        if($equality===null||!$this->ordinaryAssertion($equality)||count($equality['native']->parameters)<2||$field===null
            ||$field['native']->hooks!==[]||$field['native']->declaredType===null||$field['native']->type===null
            ||!in_array($field['source']['type']??null,['?string','string|null','null|string'],true)
            ||!$context->types->equals($field['native']->declaredType->type,Type::union(Type::string(),Type::null()))) { return false; }
        $native=$equality['native'];$actual=$native->parameters[0];$expected=$native->parameters[1];$doc=$equality['source']['doc'];
        if(preg_match('/@template\s+([A-Za-z_][A-Za-z0-9_]*)(?:\s|$)/m',$doc,$match)!==1) { return false; }$name=$match[1];
        if(preg_match('/@param\s+'.preg_quote($name,'/').'\s+'.preg_quote($expected->name,'/').'(?:\s|$)/m',$doc)!==1
            ||preg_match('/@phpstan-assert\s+='.preg_quote($name,'/').'\s+'.preg_quote($actual->name,'/').'(?:\s|$)/m',$doc)!==1
            ||count($native->templates)!==1||$native->templates[0]->name!==$name||$native->templates[0]->default!==null
            ||!$this->methodTemplate($native->templates[0]->definingEntity,$native)
            ||!$context->types->equals($native->templates[0]->constraint,Type::mixed())
            ||$expected->type===null||!$expected->type->fromDocblock||$expected->type->inferred
            ||count($expected->type->type->atomicTypes)!==1) { return false; }
        $generic=$expected->type->type->atomicTypes[0];
        if(!$generic instanceof GenericParameterType||$generic->name!==$name||!$this->methodTemplate($generic->definingEntity,$native)
            ||$generic->intersections!==null&&$generic->intersections!==[]||!$context->types->equals($generic->constraint,Type::mixed())) { return false; }
        $genericAssertion=false;$details['actualGenericTypeAssertions']=$this->assertions($native,$actual->name);
        foreach($this->assertions($native,$actual->name) as $assertion) {
            if(!$assertion instanceof TypeAssertion||$assertion->kind!==TypeAssertionKind::IsIdentical||!$context->types->equals($assertion->type,$expected->type->type)) { return false; }
            $genericAssertion=true;
        }
        if(!$genericAssertion) { return false; }
        $builtin=$context->codebase->getFunction('basename');$owner=$scope['owner'];$namespace=str_contains($owner,'\\')?substr($owner,0,strrpos($owner,'\\')):'';
        $shadow=$namespace===''?null:$context->codebase->getFunction($namespace.'\\basename');
        $details['expectedBuiltin']=['actual'=>$builtin,'namespaceShadow'=>$shadow];
        if($shadow!==null||$builtin===null||!$builtin->flags->contains(MetadataFlags::BUILTIN)||$builtin->identifier->class!==null
            ||strcasecmp($builtin->identifier->name,'basename')!==0||$builtin->flags->contains(MetadataFlags::BY_REFERENCE)
            ||$builtin->returnType===null||!$this->declaredStringResult($context,$builtin->returnType->type)
            ||!$this->arguments($proof['expectedOrigin'],$builtin)) { return false; }
        $details['expectedDeclaredStringBuiltinAndTemplateBound']=true;
        $details['phpstanDefaultTreatPhpDocTypesAsCertain']=true;$details['adjacentSuccessfulGenericEquality']=true;
        return true;
    }
    private function factory(IssueFilterContext $context,NullFlowNativeContracts $reader,array $proof,array &$details):bool
    {
        $model=$proof['modelClass'];$source=new PhpSource($this->root);$reflection=new FactoryReflection($context->codebase,$source);
        $factory=$reflection->modelFactory($model);$physical=[];
        $physicalAdmitted=$factory!==null&&(new PhysicalFactoryModelDefault($this->root))->admits($context,$factory,$model,$physical);
        $details['physicalFactoryModelDefault']=$physical;
        if(!$physicalAdmitted) { return false; }
        $mapped=$reflection->factoryModel($factory);
        $details['declaredFactoryMapping']=['model'=>$model,'factory'=>$factory,'mappedModel'=>$mapped];
        if($factory===null||$mapped===null||strcasecmp($mapped,$model)!==0||!$reflection->inherits($model,ModelReflection::MODEL)
            ||!(new FactoryResultContract($this->root))->standardCore($reflection,$factory)) { return false; }
        foreach([$model,$factory] as $class) {
            $binding=[];$resolved=$reader->className($context,['kind'=>'new','class'=>$class],$proof['scope'],$binding);
            $details['producerClassDeclarations'][$class]=['resolved'=>$resolved,'actualSourceBinding'=>$binding];
            if($resolved===null||strcasecmp($resolved,$class)!==0) { return false; }
        }
        // Bind each standard counted factory declaration independently; unrefined create input is not the output theorem.
        foreach([[$model,'factory'],[$factory,'count'],[$factory,'create'],[$factory,'newInstance']] as [$owner,$name]) {
            $native=$context->codebase->getDeclaringMethod($owner,$name);$bound=$native===null?null:$reader->functionBound($context,$native);
            $details['factoryDeclarations'][$owner.'::'.$name]=['actual'=>$native,'source'=>$bound];
            if($native===null||$bound===null||$native->abstract||$native->flags->contains(MetadataFlags::BY_REFERENCE)) { return false; }
            foreach($native->parameters as $formal) { if($formal->flags->contains(MetadataFlags::BY_REFERENCE)||$formal->outType!==null||$formal->closureThisType!==null) { return false; } }
        }
        // Larastan removes the declared model alternative for a literal integer count.
        // Bind that original producer union before reading its declared collection element.
        $countDeclaration=$details['factoryDeclarations'][$factory.'::count'];
        $createDeclaration=$details['factoryDeclarations'][$factory.'::create'];
        if(!$this->countedProducer($context,$countDeclaration,$createDeclaration,$proof)) { return false; }
        $details['declaredCountedProducerContract']=true;
        $collection=(new EloquentCollectionType)->resolve($context->codebase,Type::namedObject($model));
        $atom=$collection?->atomicTypes[0]??null;$details['declaredModelCollection']=$collection;
        if($collection===null||count($collection->atomicTypes)!==1||!$atom instanceof NamedObjectType
            ||strcasecmp($atom->name,'Illuminate\\Database\\Eloquent\\Collection')!==0||count($atom->parameters??[])!==2
            ||!$context->types->equals($atom->parameters[0],Type::int())||!$context->types->equals($atom->parameters[1],Type::namedObject($model))) { return false; }
        $profile=new NullableCollectionOffsetContract($this->root,'Illuminate\\Support\\Collection');$profileAdmitted=$profile->current($context,$atom->name);
        $details['currentCollectionReadProfile']=['admitted'=>$profileAdmitted,'nativeSourceGuards'=>$profile->stages];
        if(!$profileAdmitted) { return false; }
        $offset=$context->codebase->getDeclaringMethod($atom->name,'offsetGet');$bound=$offset===null?null:$reader->functionBound($context,$offset);
        $details['declaredOffsetGet']=['actual'=>$offset,'source'=>$bound];
        $return=$offset?->returnType;$generic=$return?->type->atomicTypes[0]??null;
        if($offset===null||$bound===null||$offset->static||$offset->abstract||$offset->flags->contains(MetadataFlags::BY_REFERENCE)
            ||$return===null||!$return->fromDocblock||$return->inferred||count($return->type->atomicTypes)!==1||!$generic instanceof GenericParameterType
            ||$generic->name!=='TValue'||$generic->definingEntity->kind!==GenericParentKind::ClassLike
            ||strcasecmp($generic->definingEntity->name,'Illuminate\\Support\\Collection')!==0
            ||preg_match('/@return\s+TValue(?:\s|$)/m',$bound['source']['doc'])!==1) { return false; }
        $details['declaredNonNullElement']=$atom->parameters[1];$details['offsetPresenceClaimed']=false;
        $details['phpstanObjectTypeReadsDeclaredOffsetGetReturn']=true;
        return true;
    }
    private function countedProducer(IssueFilterContext $context,array $count,array $create,array $proof):bool
    {
        $countNative=$count['actual'];$createNative=$create['actual'];$factory=FactoryReflection::FACTORY;
        if(strcasecmp($countNative->identifier->class??'',$factory)!==0||strcasecmp($createNative->identifier->class??'',$factory)!==0
            ||count($countNative->parameters)!==1||!$this->arguments($proof['collectionProducer']['receiver'],$countNative)
            ||!$this->arguments($proof['collectionProducer'],$createNative)) { return false; }
        $countType=$countNative->parameters[0]->type?->type;$countReturn=$countNative->returnType;
        $static=$countReturn?->type->atomicTypes[0]??null;
        if($countType===null||!$context->types->equals($countType,Type::union(Type::int(),Type::null()))
            ||$countReturn===null||!$countReturn->fromDocblock||$countReturn->inferred||count($countReturn->type->atomicTypes)!==1
            ||!$static instanceof NamedObjectType||!$static->static||strcasecmp($static->name,$factory)!==0
            ||preg_match('/@return\s+static(?:\s|$)/m',$count['source']['source']['doc'])!==1) { return false; }
        $return=$createNative->returnType;
        if($return===null||!$return->fromDocblock||$return->inferred||$return->type->flags->byReference||$return->type->flags->possiblyUndefined
            ||$return->type->atomicTypes===[]||count($return->type->atomicTypes)>2) { return false; }
        $collectionSeen=false;$modelSeen=false;
        foreach($return->type->atomicTypes as $atom) {
            if($atom instanceof NamedObjectType&&strcasecmp($atom->name,'Illuminate\\Database\\Eloquent\\Collection')===0
                &&!$atom->static&&!$atom->isThis&&($atom->intersections===null||$atom->intersections===[])&&count($atom->parameters??[])===2
                &&$context->types->equals($atom->parameters[0],Type::int())&&$this->factoryModelTemplate($context,$atom->parameters[1])) {
                if($collectionSeen) { return false; }$collectionSeen=true;
            } elseif($this->factoryModelTemplate($context,Type::fromAtomic($atom))) {
                if($modelSeen) { return false; }$modelSeen=true;
            } else { return false; }
        }
        $doc=preg_replace('/\s+/','',$create['source']['source']['doc']);
        return $collectionSeen&&str_contains($doc,'@return\\Illuminate\\Database\\Eloquent\\Collection<int,TModel>'.($modelSeen?'|TModel':''));
    }
    private function factoryModelTemplate(IssueFilterContext $context,Type $type):bool
    {
        $generic=$type->atomicTypes[0]??null;
        return count($type->atomicTypes)===1&&!$type->flags->byReference&&!$type->flags->possiblyUndefined&&$generic instanceof GenericParameterType
            &&$generic->name==='TModel'&&$generic->definingEntity->kind===GenericParentKind::ClassLike
            &&strcasecmp($generic->definingEntity->name,FactoryReflection::FACTORY)===0
            &&($generic->intersections===null||$generic->intersections===[])
            &&$context->types->equals($generic->constraint,Type::namedObject(ModelReflection::MODEL));
    }
    private function ordinaryAssertion(array $bound):bool
    {
        $native=$bound['native'];return $native instanceof FunctionLikeMetadata&&$native->static&&!$native->abstract&&!$native->constructor
            &&!$native->assertionsInferred&&!$native->flags->contains(MetadataFlags::BY_REFERENCE)&&isset($native->parameters[0])
            &&array_all($native->parameters,static fn($formal):bool=>!$formal->flags->contains(MetadataFlags::BY_REFERENCE)
                &&!$formal->flags->contains(MetadataFlags::VARIADIC)&&$formal->outType===null&&$formal->closureThisType===null);
    }
    private function assertions(FunctionLikeMetadata $native,string $name):array { return $native->assertions[$name]??$native->assertions[ltrim($name,'$')]??[]; }
    private function declaredStringResult(IssueFilterContext $context,Type $type,int $depth=0):bool
    {
        if($depth>2||$type->atomicTypes===[]||$type->flags->byReference||$type->flags->possiblyUndefined) { return false; }
        foreach($type->atomicTypes as $atom) {
            if($atom instanceof ConditionalType) {
                if(!$this->declaredStringResult($context,$atom->then,$depth+1)||!$this->declaredStringResult($context,$atom->otherwise,$depth+1)) { return false; }
            } elseif(!$context->types->isContainedBy(Type::fromAtomic($atom),Type::string())) { return false; }
        }return true;
    }
    private function methodTemplate(object $parent,FunctionLikeMetadata $native):bool { return $parent->kind===GenericParentKind::FunctionLike
        &&strcasecmp($parent->name,$native->identifier->class??'')===0&&strcasecmp($parent->member??'',$native->identifier->name)===0; }
    private function arguments(array $call,FunctionLikeMetadata $native):bool
    {
        $arguments=$call['arguments']??[];if(count($arguments)>count($native->parameters)) { return false; }
        foreach($arguments as $index=>$argument) { $formal=$native->parameters[$index]??null;if(($argument['name']??null)!==null||$formal===null
            ||$formal->flags->contains(MetadataFlags::BY_REFERENCE)||$formal->flags->contains(MetadataFlags::VARIADIC)||$formal->outType!==null) { return false; } }
        foreach(array_slice($native->parameters,count($arguments)) as $formal) { if(!$formal->flags->contains(MetadataFlags::HAS_DEFAULT)) { return false; } }return true;
    }
}
