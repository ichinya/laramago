<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\{IssueFilterContext,Type};
use Mago\Sdk\Analyzer\Metadata\{ClassLikeKind,FunctionLikeMetadata,MetadataFlags};
use Mago\Sdk\Analyzer\Type\{CallableType,GenericParameterType,NamedObjectType,Visibility};
use PhpParser\{Node,NodeFinder,NodeTraverser,NodeVisitorAbstract};
use PhpParser\PrettyPrinter\Standard;

/** One lexical typed chunk input under the standard Eloquent generic contract. */
final class SelectedChunkCollectionContracts
{
    private const MODEL='Illuminate\\Database\\Eloquent\\Model';
    private const BUILDER='Illuminate\\Database\\Eloquent\\Builder';
    private const COLLECTION='Illuminate\\Database\\Eloquent\\Collection';
    private const SUPPORT='Illuminate\\Support\\Collection';
    private const QUERIES='Illuminate\\Database\\Concerns\\BuildsQueries';
    private const FACTORY='Illuminate\\Database\\Eloquent\\HasCollection';
    private const BODIES=[
        'query'=>'873ddbbd2a60ee81542cf33087a8cf9834c64a869a0dc0ea34c0ee5c84e854ee',
        'chunkById'=>'876803540cb0ffe94c3392825ea1152209f9890baa5b13fa5ea22e01895a2b58',
        'orderedChunkById'=>'18c57c5f83dba163a72568bd074640c5a32dd6e8aea5fdc8573dc1d58788fa93',
        'get'=>'eb5237d06208e8cd6bfc98b3209a63b217d75c5059009d1d753ec1cfaff210b1',
        'newCollection'=>'0c0e15344390a0a55fc6c87883842c7bf27600d42e25e6349e50a9d42b1b6571',
        'resolveCollectionFromAttribute'=>'c50b3b9b7a59f77e25ef98665e4faaa7176f9681126a05fbbf61dab8fc361e84',
        'where'=>'db13d6d9d280085969dbea12f805302e1a910da3a43c341e13c3e79d8a7e8bea',
        'with'=>'0daa94ee9af48fa35cd3f865abb96d90ae80bec55f2c2ada1b43f83d9910b24a',
    ];
    public array $stages=[];public array $dependencies=[];public array $certificate=[];
    private array $held=[];
    public function __construct(private readonly string $root) {}
    private function stage(string $name,bool $passed):bool { $this->stages[]=['stage'=>$name,'passed'=>$passed];return $passed; }

    /** Source syntax only; this is not a native admission certificate. */
    public function lexical(GuardedStringCastContracts $source,array $file,Node $point,Node\Expr\Variable $value):?array
    {
        $closure=null;$scope=null;
        foreach ($source->ancestors($point,$file) as $ancestor) {
            if ($closure===null && $ancestor instanceof Node\Expr\Closure) { $closure=$ancestor; }
            elseif ($ancestor instanceof Node\Expr\Closure || $ancestor instanceof Node\Expr\ArrowFunction || $ancestor instanceof Node\Stmt\Function_) { return null; }
            if ($ancestor instanceof Node\Stmt\ClassMethod) { $scope=$ancestor;break; }
        }
        $formal=$closure?->params[0]??null;$arg=$closure===null?null:($file['parents'][spl_object_id($closure)]??null);
        $chunk=$arg instanceof Node\Arg?($file['parents'][spl_object_id($arg)]??null):null;
        if (!$this->stage('direct literal callback and physical collection formal',$scope!==null && $closure!==null && !$closure->byRef
            && $closure->getDocComment()===null && count($closure->params)===1 && $closure->attrGroups===[]
            && $formal instanceof Node\Param && !$formal->byRef && !$formal->variadic && $formal->default===null && $formal->flags===0 && $formal->attrGroups===[]
            && $formal->var instanceof Node\Expr\Variable && is_string($value->name) && $formal->var->name===$value->name
            && $formal->type instanceof Node\Name && $formal->type->toString()===self::COLLECTION
            && $arg instanceof Node\Arg && $arg->getDocComment()===null && $formal->getDocComment()===null && $chunk instanceof Node\Expr\MethodCall && SourceArgumentDeclarationContracts::plain($chunk)
            && $chunk->name instanceof Node\Identifier && strtolower($chunk->name->name)==='chunkbyid' && count($chunk->args)===2 && $chunk->args[1]===$arg
            && $chunk->args[0]->value instanceof Node\Scalar\Int_ && $chunk->args[0]->value->value>0)) { return null; }
        $query=$chunk->var;$operations=[];
        while ($query instanceof Node\Expr\MethodCall) {
            if (!$query->name instanceof Node\Identifier || strtolower($query->name->name)!=='with' || !$this->eagerLoad($query)) { return null; }
            $operations['with']=true;$query=$query->var;
        }
        if (!$this->stage('one local query root',$query instanceof Node\Expr\Variable && is_string($query->name))) { return null; }
        $finder=new NodeFinder;$origins=[];
        foreach ($finder->findInstanceOf($scope->stmts??[],Node\Expr\Assign::class) as $assign) {
            if ($assign->var instanceof Node\Expr\Variable && $assign->var->name===$query->name && $assign->getEndFilePos()<$chunk->getStartFilePos()) { $origins[]=$assign; }
        }
        $origin=count($origins)===1?$origins[0]:null;$start=$origin?->expr;$statement=$origin===null?null:($file['parents'][spl_object_id($origin)]??null);
        if (!$this->stage('unconditional fresh concrete model query',$start instanceof Node\Expr\StaticCall && $start->class instanceof Node\Name
            && $start->name instanceof Node\Identifier && strtolower($start->name->name)==='query' && $start->args===[]
            && $statement instanceof Node\Stmt\Expression && ($file['parents'][spl_object_id($statement)]??null)===$scope)) { return null; }
        foreach ($finder->find($scope->stmts??[],static fn(Node $node):bool=>$node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\StaticPropertyFetch) as $effect) {
            if ($effect===$start || $effect->getStartFilePos()>=$point->getStartFilePos() || !$effect->class instanceof Node\Name
                || !in_array(strtolower($effect->class->toString()),[strtolower($start->class->toString()),strtolower(self::MODEL)],true)) { continue; }
            $this->stage('no source-visible selected model dispatch or collection configuration',false);return null;
        }
        foreach ($finder->findInstanceOf($scope->stmts??[],Node\Expr\Variable::class) as $use) {
            if ($use->getStartFilePos()>=$chunk->getEndFilePos() || $use->getEndFilePos()<=$origin->getStartFilePos() || $use===$origin->var) { continue; }
            if (!is_string($use->name)) { $this->stage('no dynamic local access',false);return null; }
            if ($use->name!==$query->name) { continue; }
            $parent=$file['parents'][spl_object_id($use)]??null;
            if ($parent instanceof Node\Expr\MethodCall && $parent->var===$use && $parent->name instanceof Node\Identifier) {
                $name=strtolower($parent->name->name);
                if ($name==='where' && $this->scalarWhere($parent) || $name==='with' && $this->eagerLoad($parent)) { $operations[$name]=true;continue; }
                if ($parent===$chunk) { continue; }
            }
            if ($parent instanceof Node\Expr\Clone_ && $parent->expr===$use) {
                $read=$file['parents'][spl_object_id($parent)]??null;
                if ($read instanceof Node\Expr\MethodCall && $read->var===$parent && $read->name instanceof Node\Identifier && strtolower($read->name->name)==='count' && $read->args===[]) { continue; }
            }
            $this->stage('no selected query alias reference rebind escape or model change',false);return null;
        }
        foreach ($finder->find($scope->stmts??[],static fn(Node $node):bool=>$node instanceof Node\Expr\Eval_ || $node instanceof Node\Expr\Include_
            || $node instanceof Node\Stmt\Global_ || $node instanceof Node\Expr\FuncCall && (!$node->name instanceof Node\Name || in_array(strtolower($node->name->getLast()),['extract','parse_str'],true))) as $effect) {
            if ($effect->getStartFilePos()>$origin->getStartFilePos() && $effect->getStartFilePos()<$point->getStartFilePos()) { $this->stage('no unknown selected local mutation',false);return null; }
        }
        foreach ($finder->find($closure->stmts??[],static fn(Node $node):bool=>$node->getDocComment()!==null) as $documented) {
            $doc=$documented->getDocComment();
            if ($doc->getStartFilePos()<$point->getEndFilePos() && preg_match('/@(?:phpstan-|psalm-)?var\\b/i',$doc->getText())===1) {
                $this->stage('selected callback input has no earlier inline type replacement',false);return null;
            }
        }
        foreach ($finder->findInstanceOf($closure->stmts??[],Node\Expr\Variable::class) as $use) {
            if ($use===$value || $use->getStartFilePos()>=$value->getStartFilePos()) { continue; }
            if (!is_string($use->name) || $use->name===$value->name) { $this->stage('collection formal has no prior use alias reference write or escape',false);return null; }
        }
        $this->stage('closed query and selected callback collection lifetimes',true);
        return compact('closure','scope','formal','chunk','query','origin','start','operations')+['model'=>$start->class->toString()];
    }

    public function domain(IssueFilterContext $context,GuardedStringCastContracts $source,array $file,array $lexical):?Type
    {
        $this->held[$file['path']]=$file['hash'];$model=$lexical['model'];$owner=$source->owner($context,$lexical['chunk'],$file);$bindings=[];
        $this->dependencies['owner']=$owner;
        if (!$this->stage('genuine physical caller and staticness',$owner!==null && $owner->static===$lexical['scope']->isStatic())) { return null; }
        $this->dependencies['callerClass']=$context->codebase->getClass($owner->identifier->class);
        $bindings['owner']=['kind'=>'method','class'=>$owner->identifier->class,'name'=>$owner->identifier->name];
        $bindings['callerClass']=['kind'=>'class','name'=>$owner->identifier->class];$bindings['selectedModel']=['kind'=>'class','name'=>$model];
        $nativeModel=$context->codebase->getClass($model);$this->dependencies['selectedModel']=$nativeModel;
        $modelSource=$nativeModel===null?null:$source->read($nativeModel->location->file);
        $modelNodes=$modelSource===null?[]:(new NodeFinder)->findInstanceOf($modelSource['nodes'],Node\Stmt\Class_::class);
        $modelNode=array_values(array_filter($modelNodes,static fn(Node\Stmt\Class_ $node):bool=>$node->namespacedName?->toString()===$model))[0]??null;
        if (!$this->stage('concrete current model declaration',$nativeModel!==null && $nativeModel->kind===ClassLikeKind::Class_ && !$nativeModel->hasIncompleteHierarchy()
            && !$nativeModel->flags->contains(MetadataFlags::ABSTRACT) && $nativeModel->templates===[] && $modelNode!==null
            && $source->located($nativeModel->location,$modelNode,$modelSource) && $source->located($nativeModel->nameLocation,$modelNode->name,$modelSource))) { return null; }
        $this->held[$modelSource['path']]=$modelSource['hash'];$dispatch=new EloquentModelDispatch;
        if (!$this->stage('standard declared model builder and hydration',$dispatch->supportsModel($context->codebase,$model,'chunkById')
            && !$dispatch->overrides($context->codebase,$model,'query') && !$dispatch->overrides($context->codebase,$model,'newInstance')
            && !$dispatch->overrides($context->codebase,$model,'newFromBuilder') && !$dispatch->overrides($context->codebase,$model,'hydrate'))) { return null; }
        foreach ([['query',$model,self::MODEL,0,true],['chunkById',self::BUILDER,self::QUERIES,4,false],
            ['orderedChunkById',self::BUILDER,self::QUERIES,5,false],['get',self::BUILDER,self::BUILDER,1,false],
            ['newCollection',$model,self::FACTORY,1,false],['resolveCollectionFromAttribute',$model,self::FACTORY,0,false]] as [$name,$target,$declaring,$count,$static]) {
            $native=$context->codebase->getMethod($target,$name)??$context->codebase->getDeclaringMethod($target,$name);
            $this->dependencies[$name]=$native;
            $bindings[$name]=['kind'=>'method','class'=>$target,'name'=>$name];
            if (!$this->nativeMethod($context,$source,$native,$name,$declaring,$count,$static)) { return null; }
        }
        foreach (array_keys($lexical['operations']) as $name) {
            $native=$context->codebase->getMethod(self::BUILDER,$name)??$context->codebase->getDeclaringMethod(self::BUILDER,$name);$this->dependencies[$name]=$native;
            $bindings[$name]=['kind'=>'method','class'=>self::BUILDER,'name'=>$name];
            if (!$this->nativeMethod($context,$source,$native,$name,self::BUILDER,$name==='where'?4:2,false)) { return null; }
        }
        foreach (['$builder'=>self::BUILDER,'$collectionClass'=>self::COLLECTION] as $name=>$expected) {
            if (!$this->defaultClassField($context,$source,$model,$name,$expected)) { return null; }
            $bindings[$name]=['kind'=>'property','class'=>$model,'name'=>$name];
        }
        $domain=(new EloquentCollectionType)->resolve($context->codebase,Type::namedObject($model));
        if (!$this->stage('standard native collection factory preserves integer model keys',$domain!==null
            && $context->types->equals($domain,Type::namedObject(self::COLLECTION,Type::int(),Type::namedObject($model))))) { return null; }
        $this->certificate=['model'=>$model,'domain'=>(string)$domain,'sourceSha256'=>$file['hash'],'callbackSpan'=>SourceArgumentDeclarationContracts::span($lexical['closure']),
            'queryInitializationSpan'=>SourceArgumentDeclarationContracts::span($lexical['origin']),'formalSpan'=>SourceArgumentDeclarationContracts::span($lexical['formal']),
            'owner'=>$owner->identifier->class.'::'.$owner->identifier->name,'operations'=>array_keys($lexical['operations']),'bindings'=>$bindings,
            'afterFileAuthority'=>false,'opaqueClosureIdentifierClaimed'=>false,'nativeTypesReplaced'=>false,'policy'=>'standard declared Eloquent hydrated collection generic'];
        return $domain;
    }

    public function current():bool
    {
        foreach ($this->held as $path=>$hash) { if (hash_file('sha256',$path)!==$hash) { return $this->stage('all selected source hashes current',false); } }
        return $this->stage('all selected source hashes current',true);
    }
    private function nativeMethod(IssueFilterContext $context,GuardedStringCastContracts $source,?FunctionLikeMetadata $native,string $name,string $declaring,int $count,bool $static):bool
    {
        $physical=$native===null?null:$source->read($native->location->file);$nodes=$physical===null?[]:(new NodeFinder)->findInstanceOf($physical['nodes'],Node\Stmt\ClassMethod::class);
        $owners=$physical===null?[]:array_values(array_filter($nodes,static fn(Node\Stmt\ClassMethod $node):bool=>$source->located($native->location,$node,$physical)));
        $syntax=$owners[0]??null;
        $declaredClass=null;
        if ($syntax!==null) { foreach ($source->ancestors($syntax,$physical) as $ancestor) { if ($ancestor instanceof Node\Stmt\ClassLike) { $declaredClass=$ancestor;break; } } }
        if (!$this->stage('current physical native '.$name,$native!==null && strcasecmp($native->identifier->class??'',$declaring)===0
            && strcasecmp($native->identifier->name,$name)===0 && $native->static===$static && !$native->abstract
            && $native->visibility===Visibility::Public && !$native->flags->contains(MetadataFlags::BY_REFERENCE) && count($native->parameters)===$count
            && count($owners)===1 && $syntax->name->name===$name && !$syntax->byRef && $syntax->isStatic()===$static && count($syntax->params)===$count
            && $declaredClass!==null && $declaredClass->namespacedName?->toString()===$declaring
            && $source->located($native->nameLocation,$syntax->name,$physical) && $this->bodyHash($syntax)===self::BODIES[$name]
            && str_ends_with(strtolower($physical['path']),'/laravel/framework/src/illuminate/database/'.strtolower($declaring===self::QUERIES?'Concerns/BuildsQueries.php':
                ($declaring===self::FACTORY?'Eloquent/HasCollection.php':($declaring===self::MODEL?'Eloquent/Model.php':'Eloquent/Builder.php')))))) { return false; }
        $this->held[$physical['path']]=$physical['hash'];
        foreach ($syntax->params as $index=>$parameter) {
            $formal=$native->parameters[$index];
            if (!$this->stage('native '.$name.' formal '.$index,$parameter->var instanceof Node\Expr\Variable && !$parameter->byRef && !$parameter->variadic
                && $formal->name==='$'.$parameter->var->name && !$formal->flags->contains(MetadataFlags::BY_REFERENCE) && !$formal->flags->contains(MetadataFlags::VARIADIC)
                && $formal->flags->contains(MetadataFlags::HAS_DEFAULT)===($parameter->default!==null) && $formal->outType===null
                && $source->located($formal->location,$parameter,$physical) && $source->located($formal->nameLocation,$parameter->var,$physical)
                && ($parameter->type===null?$formal->declaredType===null:$source->located($formal->declaredType?->location,$parameter->type,$physical)))) { return false; }
        }
        $return=$native->returnType?->type;$doc=$syntax->getDocComment()?->getText()??'';
        if (!$this->stage('no selected framework-specific doc replacement '.$name,preg_match('/@(?:phpstan|psalm)-(?:return|param|template)\b/i',$doc)!==1)) { return false; }
        if ($name==='where' || $name==='with') {
            $atom=count($return?->atomicTypes??[])===1?$return->atomicTypes[0]:null;
            return $this->stage('native query operation retains builder '.$name,$native->returnType!==null && $native->returnType->fromDocblock && !$native->returnType->inferred
                && $native->declaredReturnType===null && $atom instanceof NamedObjectType
                && ($atom->name===self::BUILDER || $atom->name==='$this' && $atom->isThis && $atom->static
                    && $atom->parameters===null && $atom->intersections===null && !$atom->remappedParameters)
                && preg_match('~@return\s+\$this\b~',$doc)===1);
        }
        if ($name==='query') {
            $atom=count($return?->atomicTypes??[])===1?$return->atomicTypes[0]:null;$item=$atom instanceof NamedObjectType?($atom->parameters[0]??null):null;
            return $this->stage('native query static model generic',$native->returnType!==null && $native->returnType->fromDocblock && !$native->returnType->inferred
                && preg_match('~@return\s+(?:\\\\Illuminate\\\\Database\\\\Eloquent\\\\)?Builder<static>~',$doc)===1
                && $atom instanceof NamedObjectType && $atom->name===self::BUILDER && count($atom->parameters??[])===1 && $item!==null
                && $context->types->isContainedBy($item,Type::namedObject(self::MODEL)));
        }
        if ($name==='chunkById' || $name==='orderedChunkById') {
            $callback=$native->parameters[1]->type?->type;$callable=count($callback?->atomicTypes??[])===1?$callback->atomicTypes[0]:null;
            $signature=$callable instanceof CallableType?$callable->signature:null;$first=$signature?->parameters[0]->type;
            $input=count($first?->atomicTypes??[])===1?$first->atomicTypes[0]:null;
            return $this->stage('native hydrated callback formals '.$name,$return!==null && $context->types->equals($return,Type::bool())
                && preg_match('~@param\s+callable\(\\\\Illuminate\\\\Support\\\\Collection<int,\s*TValue>,\s*int\):\s*mixed\s+\$callback~',$doc)===1
                && $signature!==null && count($signature->parameters)===2 && $input instanceof NamedObjectType && $input->name===self::SUPPORT
                && count($input->parameters??[])===2 && $context->types->equals($input->parameters[0],Type::int())
                && $signature->parameters[1]->type!==null && $context->types->equals($signature->parameters[1]->type,Type::int())
                && !$signature->parameters[0]->byReference && !$signature->parameters[0]->variadic && !$signature->parameters[1]->byReference
                && $signature->returnType!==null && $context->types->equals($signature->returnType,Type::mixed()));
        }
        if ($name==='get') {
            $atom=count($return?->atomicTypes??[])===1?$return->atomicTypes[0]:null;
            return $this->stage('native declared model collection result',$atom instanceof NamedObjectType && $atom->name===self::COLLECTION
                && count($atom->parameters??[])===2 && $context->types->equals($atom->parameters[0],Type::int())
                && preg_match('~@return\s+\\\\Illuminate\\\\Database\\\\Eloquent\\\\Collection<int,\s*TModel>~',$doc)===1);
        }
        return $this->stage('native declared collection factory result '.$name,$return!==null && !$return->flags->byReference && !$return->flags->possiblyUndefined
            && ($name==='newCollection'?$context->types->isContainedBy($return,Type::namedObject(self::COLLECTION)):
                $context->types->isContainedBy($return,Type::union(Type::string(),Type::null()))
                    && preg_match('~@return\s+class-string<TCollection>\|null~',$doc)===1));
    }
    private function defaultClassField(IssueFilterContext $context,GuardedStringCastContracts $source,string $model,string $name,string $expected):bool
    {
        $field=$context->codebase->getDeclaringProperty($model,$name)??$context->codebase->getProperty($model,$name);$this->dependencies[$name]=$field;
        $physical=$field===null?null:$source->read($field->nameLocation?->file);$items=$physical===null?[]:(new NodeFinder)->findInstanceOf($physical['nodes'],Node\PropertyItem::class);
        $items=$physical===null?[]:array_values(array_filter($items,static fn(Node\PropertyItem $item):bool=>$source->located($field->nameLocation,$item->name,$physical)));
        $item=$items[0]??null;$declaration=$item===null?null:($physical['parents'][spl_object_id($item)]??null);
        if (!$this->stage('physical standard default field '.$name,$field!==null && !$field->flags->contains(MetadataFlags::VIRTUAL_PROPERTY)
            && $field->flags->contains(MetadataFlags::STATIC) && $field->flags->contains(MetadataFlags::HAS_DEFAULT) && count($items)===1
            && $declaration instanceof Node\Stmt\Property && $declaration->isStatic() && $declaration->type instanceof Node\Identifier && $declaration->type->name==='string'
            && $source->located($field->declaredType?->location,$declaration->type,$physical) && $field->declaredType!==null && $field->type!==null
            && $context->types->equals($field->declaredType->type,Type::string()) && $context->types->isContainedBy($field->type->type,Type::string())
            && $item->default instanceof Node\Expr\ClassConstFetch && $item->default->class instanceof Node\Name && $item->default->class->toString()===$expected
            && $item->default->name instanceof Node\Identifier && strtolower($item->default->name->name)==='class'
            && $field->defaultType?->type->getLiteralClassString()===$expected)) { return false; }
        $this->held[$physical['path']]=$physical['hash'];return true;
    }
    private function scalarWhere(Node\Expr\MethodCall $call):bool
    {
        return SourceArgumentDeclarationContracts::plain($call) && count($call->args)===2 && $call->args[0]->value instanceof Node\Scalar\String_
            && $call->args[0]->value->value!=='' && $call->args[1]->value instanceof Node\Expr
            && (new NodeFinder)->findFirst([$call->args[1]->value],static fn(Node $node):bool=>$node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction
                || $node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignRef || $node instanceof Node\Expr\Variable && !is_string($node->name))===null;
    }
    private function eagerLoad(Node\Expr\MethodCall $call):bool
    {
        if (!SourceArgumentDeclarationContracts::plain($call) || count($call->args)!==1 || !$call->args[0]->value instanceof Node\Expr\Array_) { return false; }
        foreach ($call->args[0]->value->items as $item) { if ($item===null || $item->key!==null || $item->byRef || $item->unpack || !$item->value instanceof Node\Scalar\String_ || $item->value->value==='') { return false; } }
        return true;
    }
    private function bodyHash(Node\Stmt\ClassMethod $method):string
    {
        $clean=new NodeTraverser(new class extends NodeVisitorAbstract { public function enterNode(Node $node):null { $node->setAttribute('comments',[]);return null; } });
        return hash('sha256',(new Standard)->prettyPrint($clean->traverse($method->stmts??[])));
    }
}
