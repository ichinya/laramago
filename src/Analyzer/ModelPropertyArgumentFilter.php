<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;
use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterHook,IssueFilterDecision,Type};
use Mago\Sdk\Analyzer\Metadata\{ClassLikeKind,MetadataFlags};
use Mago\Sdk\Analyzer\Type\{NamedObjectType,MixedType};
use PhpParser\{Node,NodeFinder};

/** A repeated physical model field read keeps the existing source/native property contract. */
final class ModelPropertyArgumentFilter implements IssueFilterHook
{
    public array $stages=[];
    public array $certificate=[];
    public array $dependencies=[];
    public function __construct(private readonly string $root){}
    public function getCodes():array{return ['mixed-argument'];}
    public function filterIssue(IssueFilterContext $context):IssueFilterDecision
    {
        // Invocation-local state must survive SDK reentrancy. Only the completed
        // invocation publishes debug fields, including on a Keep path.
        $certificate=[];$dependencies=[];
        $contracts=new SourceArgumentDeclarationContracts($this->root);
        try {
            $receiving=$contracts->receiving($context,['mixed-argument']);
            if($receiving===null){return IssueFilterDecision::Keep;}
            ['source'=>$source,'file'=>$file,'argument'=>$argument,'call'=>$call]=$receiving;
            $field=$argument->value;
            if(!$contracts->stage('one direct last-argument named model field',$field instanceof Node\Expr\PropertyFetch
                && $field->var instanceof Node\Expr\Variable && is_string($field->var->name)
                && $field->name instanceof Node\Identifier && $receiving['index']===count($call->args)-1)){
                return IssueFilterDecision::Keep;
            }
            $scope=null;
            foreach($source->ancestors($field,$file) as $parent){
                if(!$parent instanceof Node\FunctionLike){continue;}
                if($parent instanceof Node\Stmt\ClassMethod||$parent instanceof Node\Stmt\Function_){$scope=$parent;}
                break;
            }
            $native=$scope===null?null:$source->owner($context,$field,$file);
            if(!$contracts->stage('current named physical caller',$scope!==null&&$native!==null)){return IssueFilterDecision::Keep;}
            if(!$contracts->stage('earlier ordinary local reads and physical by-value receiving formals',
                self::earlierArguments($source,$file,$scope,$native,$call,$receiving))){return IssueFilterDecision::Keep;}
            $matches=[];
            foreach($scope->params as $index=>$parameter){
                if($parameter->var instanceof Node\Expr\Variable&&$parameter->var->name===$field->var->name&&$parameter->type instanceof Node\Name){$matches[]=[$parameter,$native->parameters[$index]??null];}
            }
            if(!$contracts->stage('one immutable by-value concrete model formal',count($matches)===1)){return IssueFilterDecision::Keep;}
            [$parameter,$formal]=$matches[0];$class=$parameter->type->toString();
            $declared=$formal?->declaredType?->type;$effective=$formal?->type?->type;
            $atom=$effective?->atomicTypes[0]??null;
            if(!$contracts->stage('unchanged concrete native model receiver',$formal!==null&&$formal->outType===null&&$formal->closureThisType===null
                && !$parameter->byRef&&!$parameter->variadic&&!$formal->flags->contains(MetadataFlags::BY_REFERENCE)&&!$formal->flags->contains(MetadataFlags::VARIADIC)
                && $effective!==null&&$declared!==null&&count($effective->atomicTypes)===1&&$atom instanceof NamedObjectType
                && strcasecmp($atom->name,$class)===0&&!$atom->static&&!$atom->isThis&&($atom->parameters??[])===[]&&($atom->intersections??[])===[]
                && $context->types->equals($declared,$effective))){return IssueFilterDecision::Keep;}
            $model=$context->codebase->getClass($class);$ancestors=$context->codebase->getClassAncestors($class);
            if(!$contracts->stage('genuine complete concrete Eloquent model',$model!==null&&$model->kind===ClassLikeKind::Class_
                && !$model->hasIncompleteHierarchy()&&in_array(strtolower('Illuminate\\Database\\Eloquent\\Model'),array_map(strtolower(...),$ancestors),true))){return IssueFilterDecision::Keep;}
            $modelFile=$source->read($model->location->file);
            $classes=$modelFile===null?[]:(new NodeFinder)->findInstanceOf($modelFile['nodes'],Node\Stmt\Class_::class);
            $classes=array_filter($classes,static fn(Node\Stmt\Class_ $syntax):bool=>$syntax->namespacedName!==null&&strcasecmp($syntax->namespacedName->toString(),$class)===0&&$source->located($model->location,$syntax,$modelFile)&&$source->located($model->nameLocation,$syntax->name,$modelFile));
            if(!$contracts->stage('current physical model identity',count($classes)===1)){return IssueFilterDecision::Keep;}
            if(!$contracts->stage('closed local receiver reads without aliases or writes',self::stable($scope,$field,$source,$file))){return IssueFilterDecision::Keep;}
            // Retain only native receiving identity/index before the resolver yields.
            $callerSnapshot=['path'=>$file['path'],'sha256'=>$file['hash']];$modelSnapshot=['path'=>$modelFile['path'],'sha256'=>$modelFile['hash']];
            $property=$field->name->name;$span=SourceArgumentDeclarationContracts::span($field);
            $receiving=array_intersect_key($receiving,['native'=>true,'index'=>true]);
            unset($file,$modelFile,$classes,$scope,$field,$parameter,$matches,$call,$argument,$parent);
            $schema=\Ichinya\Laramago\Analyzer\StaticAnalysis\BoundedPrimitiveSchemaContract::resolve($context,$this->root,$class,$property);
            foreach($schema['stages'] as $entry){$contracts->stage('primitive schema: '.$entry['stage'],$entry['passed']);}
            // An existing generic resolver cannot bypass the newly certified
            // schema path. Explicit casts retain their existing native priority.
            $contract=$schema['route']==='explicit-cast'
                ?(new EloquentPropertyProvider($this->root))->currentPropertyContract($context,$class,$property):$schema['contract'];
            $domain=$contract?->readType;
            if(!$contracts->stage('same current model property contract without an invented access',$domain!==null
                && !array_filter($domain->atomicTypes,static fn(object $atom):bool=>$atom instanceof MixedType)
                && !$domain->flags->byReference&&!$domain->flags->possiblyUndefined)){return IssueFilterDecision::Keep;}
            $dependencies=$contracts->dependencies+['caller'=>$native,'model'=>$model]+($schema['dependencies']??[]);
            if(!$contracts->admits($context,$receiving,$domain)){return IssueFilterDecision::Keep;}
            if(hash_file('sha256',$callerSnapshot['path'])!==$callerSnapshot['sha256']||hash_file('sha256',$modelSnapshot['path'])!==$modelSnapshot['sha256']){return IssueFilterDecision::Keep;}
            $certificate=['class'=>$class,'property'=>$property,'span'=>$span,'readDomain'=>(string)$domain,'nativeTypesChanged'=>false,'fakeAccessContextUsed'=>false];
            return IssueFilterDecision::Remove;
        } finally {$this->stages=$contracts->stages;$this->certificate=$certificate;$this->dependencies=$dependencies;}
    }
    private static function earlierArguments(GuardedStringCastContracts $source,array $file,Node\FunctionLike $scope,
        \Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata $caller,Node\Expr\CallLike $call,array $receiving):bool
    {
        if($receiving['index']===0){return true;}
        // The selected declaration is genuine; bind every preceding formal to the
        // same current physical declaration rather than trusting argument text.
        $native=$receiving['native'];$physical=$source->read($native->location->file);
        $owners=$physical===null?[]:(new NodeFinder)->findInstanceOf($physical['nodes'],Node\Stmt\ClassMethod::class);
        $owners=array_values(array_filter($owners,static fn(Node\Stmt\ClassMethod $owner):bool=>$source->located($native->location,$owner,$physical)
            &&$source->located($native->nameLocation,$owner->name,$physical)&&$owner->name->name===$native->identifier->name));
        if(count($owners)!==1){return false;}$owner=$owners[0];
        foreach(array_slice($call->args,0,$receiving['index']) as $index=>$argument){
            $parameter=$owner->params[$index]??null;$formal=$native->parameters[$index]??null;
            if(!$argument instanceof Node\Arg||$argument->byRef||$argument->unpack||$argument->name!==null
                ||!$parameter instanceof Node\Param||!$parameter->var instanceof Node\Expr\Variable||!is_string($parameter->var->name)
                ||$parameter->byRef||$parameter->variadic||$formal===null||$formal->name!=='$'.$parameter->var->name
                ||$formal->outType!==null||$formal->closureThisType!==null
                ||$formal->flags->contains(MetadataFlags::BY_REFERENCE)||$formal->flags->contains(MetadataFlags::VARIADIC)
                ||!$source->located($formal->location,$parameter,$physical)||!$source->located($formal->nameLocation,$parameter->var,$physical)
                ||($parameter->type===null?$formal->declaredType!==null:!$source->located($formal->declaredType?->location,$parameter->type,$physical))
                ||$formal->type===null||$formal->type->type->flags->byReference||$formal->type->type->flags->possiblyUndefined){return false;}
            if($argument->value instanceof Node\Scalar){continue;}
            if($argument->value instanceof Node\Expr\ConstFetch&&in_array(strtolower($argument->value->name->toString()),['true','false','null'],true)){continue;}
            if(!self::ordinaryEarlierLocal($scope,$argument->value)){return false;}
            $selected=[];
            foreach($scope->params as $position=>$input){if($input->var->name===$argument->value->name){$selected[]=[$input,$caller->parameters[$position]??null];}}
            if(count($selected)!==1){return false;}[$input,$bound]=$selected[0];
            if($bound===null||$input->byRef||$input->variadic||$bound->outType!==null||$bound->closureThisType!==null
                ||$bound->flags->contains(MetadataFlags::BY_REFERENCE)||$bound->flags->contains(MetadataFlags::VARIADIC)
                ||!$source->located($bound->location,$input,$file)||!$source->located($bound->nameLocation,$input->var,$file)
                ||$bound->type?->type->flags->byReference||$bound->type?->type->flags->possiblyUndefined){return false;}
        }
        return hash_file('sha256',$physical['path'])===$physical['hash'];
    }
    /** A defined ordinary local read has no evaluation effect; its type is not narrowed here. */
    private static function ordinaryEarlierLocal(Node\FunctionLike $scope,Node $expression):bool
    {
        if(!$expression instanceof Node\Expr\Variable||!is_string($expression->name)
            ||in_array($expression->name,['this','GLOBALS','_SERVER','_GET','_POST','_FILES','_COOKIE','_SESSION','_REQUEST','_ENV'],true)){return false;}
        $parameters=array_filter($scope->params,static fn(Node\Param $input):bool=>$input->var instanceof Node\Expr\Variable&&$input->var->name===$expression->name);
        return count($parameters)===1&&!array_values($parameters)[0]->byRef&&!array_values($parameters)[0]->variadic;
    }
    private static function stable(Node\FunctionLike $scope,Node\Expr\PropertyFetch $selected,GuardedStringCastContracts $source,array $file):bool
    {
        foreach((new NodeFinder)->findInstanceOf($scope->getStmts()??[],Node\Expr\Variable::class) as $use){
            if($use->name!==$selected->var->name||$use->getStartFilePos()>$selected->getEndFilePos()){continue;}
            $parent=$file['parents'][spl_object_id($use)]??null;
            if(!$parent instanceof Node\Expr\PropertyFetch||$parent->var!==$use||!$parent->name instanceof Node\Identifier){return false;}
            $consumer=$file['parents'][spl_object_id($parent)]??null;
            if($consumer instanceof Node\Expr\Assign||$consumer instanceof Node\Expr\AssignRef||$consumer instanceof Node\Expr\AssignOp
                ||$consumer instanceof Node\Stmt\Unset_||$consumer instanceof Node\Expr\PreInc||$consumer instanceof Node\Expr\PostInc
                ||$consumer instanceof Node\Expr\PreDec||$consumer instanceof Node\Expr\PostDec){return false;}
            if($consumer instanceof Node\Arg&&$parent!==$selected){return false;}
        }
        return true;
    }
}
