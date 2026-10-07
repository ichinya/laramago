<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterDecision,IssueFilterHook,Type};
use PhpParser\Node;
use PhpParser\NodeFinder;

/** PHPStan's declared benevolent array-key policy for a closed Session::all -> put copy. */
final class SessionArrayKeyArgumentFilter implements IssueFilterHook
{
    private const STORE='Illuminate\\Session\\Store';
    private const SESSION='Illuminate\\Contracts\\Session\\Session';
    public array $stages=[];public array $dependencies=[];public array $certificate=[];
    public function __construct(private readonly string $root) {}
    public function getCodes(): array { return ['less-specific-argument']; }
    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        $proof=new self($this->root);$decision=$proof->evaluate($context);$this->stages=$proof->stages;$this->dependencies=$proof->dependencies;$this->certificate=$proof->certificate;return $decision;
    }
    private function evaluate(IssueFilterContext $context): IssueFilterDecision
    {
        $contracts=new SourceArgumentDeclarationContracts($this->root);
        try {
            $receiving=$contracts->receiving($context,['less-specific-argument']);if ($receiving===null) { return IssueFilterDecision::Keep; }
            ['source'=>$source,'file'=>$file,'call'=>$call,'argument'=>$argument,'native'=>$native]=$receiving;
            if (! $contracts->stage('selected array-key copy receiving put',$receiving['index']===0 && $call instanceof Node\Expr\MethodCall && count($call->args)===2
                && $call->name instanceof Node\Identifier && strtolower($call->name->name)==='put'
                && str_contains($context->issue->message,'provided type `array-key` is less specific.')
                && $argument->value instanceof Node\Expr\Variable && is_string($argument->value->name))) { return IssueFilterDecision::Keep; }
            $name=$argument->value->name;$foreach=null;$scope=null;
            foreach ($source->ancestors($call,$file) as $ancestor) {
                if ($foreach===null && $ancestor instanceof Node\Stmt\Foreach_) { $foreach=$ancestor; }
                if ($ancestor instanceof Node\Stmt\ClassMethod) { $scope=$ancestor;break; }
            }
            if (! $contracts->stage('literal closed Session all key/value foreach',$scope!==null && $foreach!==null && ! $foreach->byRef
                && $foreach->keyVar instanceof Node\Expr\Variable && $foreach->keyVar->name===$name && $foreach->valueVar instanceof Node\Expr\Variable
                && is_string($foreach->valueVar->name) && $foreach->valueVar->name!==$name
                && $call->args[1]->value instanceof Node\Expr\Variable && $call->args[1]->value->name===$foreach->valueVar->name
                && $foreach->expr instanceof Node\Expr\MethodCall && $foreach->expr->name instanceof Node\Identifier && strtolower($foreach->expr->name->name)==='all'
                && $foreach->expr->args===[] && $foreach->expr->var instanceof Node\Expr\Variable && is_string($foreach->expr->var->name)
                && count($foreach->stmts)===1 && $foreach->stmts[0] instanceof Node\Stmt\Expression && $foreach->stmts[0]->expr===$call)) { return IssueFilterDecision::Keep; }
            $owner=$source->owner($context,$call,$file);$contracts->dependencies['owner']=$owner;
            if (! $contracts->stage('genuine physical named Session copy owner',$owner!==null)) { return IssueFilterDecision::Keep; }
            if(!$this->bound($context,$contracts,$source,'owner',$owner,$owner->identifier->class,$owner->identifier->name,count($scope->params),$scope->isStatic(),'')) { return IssueFilterDecision::Keep; }
            if(!$this->closedScope($scope->stmts,$foreach->getStartFilePos())) { return IssueFilterDecision::Keep; }
            $producer=$foreach->expr->var->name;$finder=new NodeFinder;$producerType=null;$origin=null;
            foreach ($scope->params as $index=>$parameter) {
                if ($parameter->var->name===$producer && ! $parameter->byRef && $parameter->type instanceof Node\Name && $parameter->type->toString()===self::SESSION) {
                    $producerType=$owner->parameters[$index]->declaredType?->type;
                }
            }
            if ($producerType===null) {
                $origins=[];foreach ($finder->findInstanceOf($scope->stmts,Node\Expr\Assign::class) as $assign) {
                    if ($assign->var instanceof Node\Expr\Variable && $assign->var->name===$producer && $assign->getEndFilePos()<$foreach->getStartFilePos()) { $origins[]=$assign; }
                }
                $origin=count($origins)===1?$origins[0]:null;$start=$origin?->expr;
                if($origin!==null && !$this->rootStatement($origin,$scope,$file)) { return IssueFilterDecision::Keep; }
                if ($start instanceof Node\Expr\MethodCall && $start->name instanceof Node\Identifier && strtolower($start->name->name)==='session' && $start->args===[]
                    && $start->var instanceof Node\Expr\Variable) {
                    $formals=array_filter($scope->params,static fn(Node\Param $parameter):bool=>$parameter->var->name===$start->var->name && ! $parameter->byRef
                        && $parameter->type instanceof Node\Name && $parameter->type->toString()==='Illuminate\\Http\\Request');
                    $accessor=$context->codebase->getMethod('Illuminate\\Http\\Request','session')??$context->codebase->getDeclaringMethod('Illuminate\\Http\\Request','session');$contracts->dependencies['sessionAccessor']=$accessor;
                    $requestName=$start->var->name;$requestSafe=is_string($requestName);
                    foreach($finder->findInstanceOf($scope->stmts,Node\Expr\Variable::class) as $requestUse) {
                        if($requestUse->name!==$requestName || $requestUse->getStartFilePos()>=$origin->getStartFilePos()) { continue; }
                        $requestSafe=false;break;
                    }
                    $requestIndex=array_key_first($formals);
                    if(count($formals)===1 && $requestSafe && $contracts->same($context,$owner->parameters[$requestIndex]->declaredType?->type,Type::namedObject('Illuminate\\Http\\Request'))
                        && $this->bound($context,$contracts,$source,'sessionAccessor',$accessor,'Illuminate\\Http\\Request','session',0,false,
                        "public function session() { if (! \$this->hasSession()) { throw new RuntimeException('Session store not set on request.'); } return \$this->session->store; }")) { $producerType=$accessor?->returnType?->type; }
                }
            }
            if (! $contracts->stage('native declared Session source',$contracts->same($context,$producerType,Type::namedObject(self::SESSION)))) { return IssueFilterDecision::Keep; }
            foreach($finder->findInstanceOf($scope->stmts,Node\Expr\Variable::class) as $variable) {
                if($variable->name!==$producer || $variable->getStartFilePos()>$foreach->getStartFilePos()) { continue; }
                $parent=$file['parents'][spl_object_id($variable)]??null;
                if($variable===$foreach->expr->var || $origin!==null && $variable===$origin->var) { continue; }
                if($parent instanceof Node\Expr\MethodCall && $parent->var===$variable && $parent->name instanceof Node\Identifier && SourceArgumentDeclarationContracts::plain($parent)) { continue; }
                $contracts->stage('no producer alias rebind reference or unknown escape',false);return IssueFilterDecision::Keep;
            }
            $all=$context->codebase->getMethod(self::SESSION,'all')??$context->codebase->getDeclaringMethod(self::SESSION,'all');$contracts->dependencies['all']=$all;
            if(!$this->bound($context,$contracts,$source,'all',$all,self::SESSION,'all',0,false,null)) { return IssueFilterDecision::Keep; }
            $array=$all?->returnType?->type;$atoms=$array?->atomicTypes??[];$fallback=count($atoms)===1?$atoms[0]:null;
            $keyAtoms=$fallback instanceof \Mago\Sdk\Analyzer\Type\KeyedArrayType?$fallback->keyType?->atomicTypes??[]:[];
            $key=count($keyAtoms)===1?$keyAtoms[0]:null;
            if(!$contracts->stage('genuine documented bare array with semantic array-key fallback',$all->returnType!==null
                && $all->returnType->fromDocblock && !$all->returnType->inferred && $all->declaredReturnType===null
                && !$array->flags->byReference && !$array->flags->possiblyUndefined
                && $fallback instanceof \Mago\Sdk\Analyzer\Type\KeyedArrayType && $fallback->knownItems===null && !$fallback->nonEmpty
                && $key instanceof \Mago\Sdk\Analyzer\Type\ScalarType && $key->kind===\Mago\Sdk\Analyzer\Type\ScalarTypeKind::ArrayKey && $key->refinement===null
                && $contracts->same($context,$fallback->valueType,Type::mixed()))) { return IssueFilterDecision::Keep; }
            [$targetClass]=explode('::',$receiving['target'],2);
            if(!$contracts->stage('source-bound concrete put receiver',$call->var instanceof Node\Expr\Variable && is_string($call->var->name)
                && $this->receiver($context,$contracts,$scope,$owner,$file,$foreach,$call->var,$targetClass))) { return IssueFilterDecision::Keep; }
            if (! $contracts->stage('native Store receiver hierarchy',strcasecmp($targetClass,self::STORE)===0
                || in_array(strtolower(self::STORE),array_map('strtolower',$context->codebase->getClassAncestors($targetClass)),true))) { return IssueFilterDecision::Keep; }
            $attributes=$context->codebase->getDeclaringProperty($targetClass,'$attributes')??$context->codebase->getProperty($targetClass,'$attributes');
            $contracts->dependencies['attributes']=$attributes;$attributeFile=$attributes===null?null:$source->read($attributes->nameLocation?->file);
            $attributeNodes=$attributeFile===null?[]:$finder->findInstanceOf($attributeFile['nodes'],Node\PropertyItem::class);
            $attributeNodes=array_values(array_filter($attributeNodes,static fn(Node\PropertyItem $node):bool=>$source->located($attributes->nameLocation,$node->name,$attributeFile)));
            $attributeItem=$attributeNodes[0]??null;$attributeDeclaration=$attributeItem===null?null:($attributeFile['parents'][spl_object_id($attributeItem)]??null);
            $attributeOwner=$attributeDeclaration===null?null:($attributeFile['parents'][spl_object_id($attributeDeclaration)]??null);
            if(!$contracts->stage('physical standard array attribute field',$attributes!==null && !$attributes->flags->contains(\Mago\Sdk\Analyzer\Metadata\MetadataFlags::VIRTUAL_PROPERTY)
                && !$attributes->flags->contains(\Mago\Sdk\Analyzer\Metadata\MetadataFlags::STATIC) && $attributes->hooks===[] && $attributes->declaredType===null
                && count($attributeNodes)===1 && $attributeDeclaration instanceof Node\Stmt\Property && !$attributeDeclaration->isStatic() && $attributeDeclaration->type===null
                && $attributeDeclaration->hooks===[] && $attributeOwner instanceof Node\Stmt\Class_ && $attributeOwner->namespacedName?->toString()===self::STORE
                && $attributes->type!==null && $attributes->type->fromDocblock && !$attributes->type->inferred && $this->bareArray($context,$contracts,$attributes->type->type)
                && $attributeItem->default instanceof Node\Expr\Array_ && $attributeItem->default->items===[]
                && preg_match('~@var[ \t]+array[ \t]*(?:\r?\n|\*/)~',$attributeDeclaration->getDocComment()?->getText()??'')===1)) { return IssueFilterDecision::Keep; }
            $physical=$source->read($native->location->file);$methods=$physical===null?[]:$finder->findInstanceOf($physical['nodes'],Node\Stmt\ClassMethod::class);
            $methods=array_values(array_filter($methods,static fn(Node\Stmt\ClassMethod $method):bool=>$source->located($native->location,$method,$physical)));
            if (! $contracts->stage('physical untyped by-value key receiving formal',count($methods)===1 && count($methods[0]->params)===2
                && $methods[0]->params[0]->type===null && ! $methods[0]->params[0]->byRef && ! $methods[0]->params[1]->byRef
                && $this->keyInput($context,$contracts,$native->parameters[0]->type))) { return IssueFilterDecision::Keep; }
            if(!$this->bound($context,$contracts,$source,'selectedPut',$native,$native->identifier->class,'put',2,false,'')) { return IssueFilterDecision::Keep; }
            if (strcasecmp($targetClass,self::STORE)!==0) {
                $selected=$methods[0];$calls=$finder->findInstanceOf($selected->stmts,Node\Expr\StaticCall::class);
                $parentCalls=array_values(array_filter($calls,static fn(Node\Expr\StaticCall $call):bool=>$call->class instanceof Node\Name && strtolower($call->class->toString())==='parent'
                    && $call->name instanceof Node\Identifier && strtolower($call->name->name)==='put' && SourceArgumentDeclarationContracts::plain($call) && count($call->args)===2
                    && $call->args[0]->value instanceof Node\Expr\Variable && $call->args[0]->value->name===$selected->params[0]->var->name
                    && $call->args[1]->value instanceof Node\Expr\Variable && $call->args[1]->value->name===$selected->params[1]->var->name));
                if (! $contracts->stage('exact nonrewriting parent put forwarder',count($parentCalls)===1 && $this->unmodifiedFormals($selected,$physical,$finder))) { return IssueFilterDecision::Keep; }
            }
            $base=$context->codebase->getMethod(self::STORE,'put')??$context->codebase->getDeclaringMethod(self::STORE,'put');$contracts->dependencies['basePut']=$base;
            $baseFile=$base===null?null:$source->read($base->location->file);$methods=$baseFile===null?[]:$finder->findInstanceOf($baseFile['nodes'],Node\Stmt\ClassMethod::class);
            $methods=array_values(array_filter($methods,static fn(Node\Stmt\ClassMethod $node):bool=>$source->located($base->location,$node,$baseFile)));
            if (count($methods)!==1) { return IssueFilterDecision::Keep; }
            $body=substr($baseFile['contents'],$methods[0]->getStartFilePos(),$methods[0]->getEndFilePos()-$methods[0]->getStartFilePos()+1);
            $expected='public function put($key, $value = null) { if (! is_array($key)) { $key = [enum_value($key) => $value]; } foreach ($key as $arrayKey => $arrayValue) { Arr::set($this->attributes, enum_value($arrayKey), $arrayValue); } }';
            if(!$this->bound($context,$contracts,$source,'basePut',$base,self::STORE,'put',2,false,$expected)
                || !$this->weakFile($baseFile) || !$contracts->stage('base untyped scalar key boundary',$methods[0]->params[0]->type===null
                    && $base->parameters[0]->declaredType===null && $this->keyInput($context,$contracts,$base->parameters[0]->type))) { return IssueFilterDecision::Keep; }
            $enumCalls=$finder->findInstanceOf($methods[0]->stmts,Node\Expr\FuncCall::class);
            $enumCalls=array_values(array_filter($enumCalls,static fn(Node\Expr\FuncCall $call):bool=>$call->name instanceof Node\Name && $call->name->toString()==='Illuminate\Support\enum_value'));
            if(!$contracts->stage('both physical imported enum normalizations',count($enumCalls)===2)) { return IssueFilterDecision::Keep; }
            $enum=$context->codebase->getFunction('Illuminate\Support\enum_value');$contracts->dependencies['enumValue']=$enum;
            if(!$this->bound($context,$contracts,$source,'enumValue',$enum,null,'Illuminate\Support\enum_value',2,false,
                'function enum_value($value, $default = null) { return match (true) { $value instanceof \\BackedEnum => $value->value, $value instanceof \\UnitEnum => $value->name, default => $value ?? value($default), }; }')) { return IssueFilterDecision::Keep; }
            $enumInput=$enum->parameters[0]->type;$enumAtoms=$enumInput?->type->atomicTypes??[];$enumTemplate=count($enumAtoms)===1?$enumAtoms[0]:null;
            if(!$contracts->stage('genuine enum input template declaration',$enumInput!==null && $enumInput->fromDocblock && !$enumInput->inferred
                && $enumTemplate instanceof \Mago\Sdk\Analyzer\Type\GenericParameterType && $enumTemplate->name==='TValue' && $enumTemplate->intersections===null
                && $enumTemplate->definingEntity->kind===\Mago\Sdk\Analyzer\Type\GenericParentKind::FunctionLike && $enumTemplate->definingEntity->name===''
                && strcasecmp($enumTemplate->definingEntity->member??'','Illuminate\Support\enum_value')===0 && $contracts->same($context,$enumTemplate->constraint,Type::mixed()))) { return IssueFilterDecision::Keep; }
            // Session keys are int|string, so enum branches and the null/default branch cannot run.
            $set=$context->codebase->getMethod('Illuminate\Support\Arr','set')??$context->codebase->getDeclaringMethod('Illuminate\Support\Arr','set');$contracts->dependencies['arraySet']=$set;
            $setFile=$set===null?null:$source->read($set->location->file);
            $setBody='public static function set(&$array, $key, $value) { if (is_null($key)) { return $array = $value; } $keys = explode(\'.\', $key); foreach ($keys as $i => $key) { if (count($keys) === 1) { break; } unset($keys[$i]); if (! isset($array[$key]) || ! is_array($array[$key])) { $array[$key] = []; } $array = &$array[$key]; } $array[array_shift($keys)] = $value; return $array; }';
            if(!$this->bound($context,$contracts,$source,'arraySet',$set,'Illuminate\Support\Arr','set',3,true,$setBody,[0])
                || !$this->weakFile($setFile) || !$contracts->stage('declared integer and string array setter key domain',$set->parameters[1]->type!==null
                    && $set->parameters[1]->declaredType===null && $context->types->isContainedBy(Type::int(),$set->parameters[1]->type->type)
                    && $context->types->isContainedBy(Type::string(),$set->parameters[1]->type->type))) { return IssueFilterDecision::Keep; }

            $this->certificate=['file'=>$context->file,'sourceSha256'=>$file['hash'],'argumentSpan'=>SourceArgumentDeclarationContracts::span($argument->value),
                'foreachSpan'=>SourceArgumentDeclarationContracts::span($foreach),'selectedReceiver'=>$targetClass,'domain'=>'array-key','policy'=>'PHPStan array-key BenevolentUnionType with strict-benevolent checking disabled',
                'producerKeyAtomic'=>get_debug_type($key),'integerBranchErased'=>false,'nativeTypesReplaced'=>false,'afterFileAuthority'=>false];return IssueFilterDecision::Remove;
        } finally { $this->stages=$contracts->stages;$this->dependencies=$contracts->dependencies; }
    }
    private function unmodifiedFormals(Node\Stmt\ClassMethod $method,array $file,NodeFinder $finder): bool
    {
        if(!$this->closedScope($method->stmts,PHP_INT_MAX)) { return false; }
        $names=array_map(static fn(Node\Param $parameter):string=>$parameter->var->name,$method->params);
        foreach ($finder->findInstanceOf($method->stmts,Node\Expr\Variable::class) as $variable) {
            if (! in_array($variable->name,$names,true)) { continue; }$parent=$file['parents'][spl_object_id($variable)]??null;
            if ($parent instanceof Node\Expr\Assign && $parent->var===$variable || $parent instanceof Node\Expr\AssignRef || $parent instanceof Node\ClosureUse
                || $parent instanceof Node\Stmt\Unset_ || $parent instanceof Node\Arg && $parent->byRef) { return false; }
            if ($parent instanceof Node\Arg) {
                $call=$file['parents'][spl_object_id($parent)]??null;
                if (! $call instanceof Node\Expr\StaticCall || ! $call->class instanceof Node\Name || strtolower($call->class->toString())!=='parent'
                    || ! $call->name instanceof Node\Identifier || strtolower($call->name->name)!=='put') { return false; }
            }
        }return true;
    }
    private function closedScope(array $nodes,int $before):bool
    {
        foreach((new NodeFinder)->findInstanceOf($nodes,Node::class) as $node) {
            if($node->getStartFilePos()>=$before) { continue; }
            if($node instanceof Node\Expr\Eval_ || $node instanceof Node\Expr\Include_ || $node instanceof Node\Expr\Variable && !is_string($node->name)
                || $node instanceof Node\Expr\FuncCall && (!$node->name instanceof Node\Name || in_array(strtolower($node->name->getLast()),['extract','parse_str'],true))) { return false; }
        }return true;
    }
    private function rootStatement(Node $node,Node\Stmt\ClassMethod $scope,array $file):bool
    {
        $parent=$file['parents'][spl_object_id($node)]??null;
        return $parent instanceof Node\Stmt\Expression && ($file['parents'][spl_object_id($parent)]??null)===$scope;
    }
    private function receiver(IssueFilterContext $context,SourceArgumentDeclarationContracts $contracts,Node\Stmt\ClassMethod $scope,object $owner,array $file,
        Node\Stmt\Foreach_ $foreach,Node\Expr\Variable $selected,string $target):bool
    {
        $name=$selected->name;$origin=null;$typed=false;$finder=new NodeFinder;
        foreach($scope->params as $index=>$parameter) {
            if($parameter->var->name===$name && !$parameter->byRef && !$parameter->variadic && $parameter->type instanceof Node\Name
                && strcasecmp($parameter->type->toString(),$target)===0 && $contracts->same($context,$owner->parameters[$index]->declaredType?->type,Type::namedObject($target))) { $typed=true; }
        }
        foreach($finder->findInstanceOf($scope->stmts,Node\Expr\Variable::class) as $variable) {
            if($variable->name!==$name || $variable->getStartFilePos()>=$foreach->getStartFilePos()) { continue; }
            $parent=$file['parents'][spl_object_id($variable)]??null;
            if(!$typed && $origin===null && $parent instanceof Node\Expr\Assign && $parent->var===$variable && $this->rootStatement($parent,$scope,$file)
                && $parent->expr instanceof Node\Expr\New_ && $parent->expr->class instanceof Node\Name && strcasecmp($parent->expr->class->toString(),$target)===0
                && SourceArgumentDeclarationContracts::plain($parent->expr)) { $origin=$parent;continue; }
            if($parent instanceof Node\Expr\MethodCall && $parent->var===$variable && $parent->name instanceof Node\Identifier && SourceArgumentDeclarationContracts::plain($parent)) { continue; }
            return false;
        }return $typed || $origin!==null;
    }
    private function weakFile(?array $file):bool
    {
        if($file===null) { return false; }
        foreach((new NodeFinder)->findInstanceOf($file['nodes'],Node\Stmt\Declare_::class) as $declare) {
            foreach($declare->declares as $item) { if(strtolower($item->key->name)==='strict_types' && !($item->value instanceof Node\Scalar\Int_ && $item->value->value===0)) { return false; } }
        }return true;
    }
    private function bareArray(IssueFilterContext $context,SourceArgumentDeclarationContracts $contracts,Type $type):bool
    {
        $atoms=$type->atomicTypes;$array=count($atoms)===1?$atoms[0]:null;
        $keys=$array instanceof \Mago\Sdk\Analyzer\Type\KeyedArrayType?$array->keyType?->atomicTypes??[]:[];$key=count($keys)===1?$keys[0]:null;
        return !$type->flags->byReference && !$type->flags->possiblyUndefined && $array instanceof \Mago\Sdk\Analyzer\Type\KeyedArrayType
            && $array->knownItems===null && !$array->nonEmpty && $key instanceof \Mago\Sdk\Analyzer\Type\ScalarType
            && $key->kind===\Mago\Sdk\Analyzer\Type\ScalarTypeKind::ArrayKey && $key->refinement===null && $contracts->same($context,$array->valueType,Type::mixed());
    }
    /** The SDK represents a plain native string with null or an explicit General refinement. */
    private function plainString(\Mago\Sdk\Analyzer\Type\ScalarType $atom):bool
    {
        $refinement=$atom->refinement;
        return $refinement===null || ($refinement instanceof \Mago\Sdk\Analyzer\Type\StringType
            && $refinement->literalKind===\Mago\Sdk\Analyzer\Type\StringLiteralKind::General && $refinement->literalValue===null
            && !$refinement->numeric && !$refinement->truthy && !$refinement->nonEmpty && !$refinement->callable
            && $refinement->casing===\Mago\Sdk\Analyzer\Type\StringCasing::Unspecified);
    }
    private function keyInput(IssueFilterContext $context,SourceArgumentDeclarationContracts $contracts,?object $metadata):bool
    {
        if($metadata===null || !$metadata->fromDocblock || $metadata->inferred || $metadata->type->flags->byReference || $metadata->type->flags->possiblyUndefined) { return false; }
        $atoms=$metadata->type->atomicTypes;$strings=[];$arrays=[];$enums=[];
        foreach($atoms as $atom) {
            if($atom instanceof \Mago\Sdk\Analyzer\Type\ScalarType && $atom->kind===\Mago\Sdk\Analyzer\Type\ScalarTypeKind::String && $this->plainString($atom)) { $strings[]=$atom; }
            if($atom instanceof \Mago\Sdk\Analyzer\Type\KeyedArrayType && $this->bareArray($context,$contracts,Type::fromAtomic($atom))) { $arrays[]=$atom; }
            if($atom instanceof \Mago\Sdk\Analyzer\Type\NamedObjectType && strcasecmp($atom->name,'UnitEnum')===0 && $atom->parameters===null
                && !$atom->static && !$atom->isThis && $atom->intersections===null && !$atom->remappedParameters) { $enums[]=$atom; }
        }return count($atoms)===3 && count($strings)===1 && count($arrays)===1 && count($enums)===1;
    }
    private function bound(IssueFilterContext $context,SourceArgumentDeclarationContracts $contracts,GuardedStringCastContracts $source,
        string $dependency,?object $native,?string $class,string $name,int $count,bool $static,?string $expected,array $referenceFormals=[]):bool
    {
        $file=$native===null?null:$source->read($native->location?->file);
        $kind=$class===null?Node\Stmt\Function_::class:Node\Stmt\ClassMethod::class;
        $nodes=$file===null?[]:(new NodeFinder)->findInstanceOf($file['nodes'],$kind);
        $nodes=array_values(array_filter($nodes,static fn(Node $node):bool=>$source->located($native->location,$node,$file)));
        $node=$nodes[0]??null;$owner=$node===null?null:($file['parents'][spl_object_id($node)]??null);
        if(!$contracts->stage('physical native contract '.$dependency,count($nodes)===1 && $native!==null && !$native->flags->contains(\Mago\Sdk\Analyzer\Metadata\MetadataFlags::BY_REFERENCE)
            && !$node->byRef && $native->identifier->name===$name
            && $native->kind===($class===null?\Mago\Sdk\Analyzer\Metadata\FunctionLikeKind::Function_:\Mago\Sdk\Analyzer\Metadata\FunctionLikeKind::Method)
            && ($class===null?$native->identifier->class===null && $node->namespacedName?->toString()===$name:
                $native->identifier->class===$class && $node->name->name===$name && $owner instanceof Node\Stmt\ClassLike && $owner->namespacedName?->toString()===$class && $node->isStatic()===$static && $native->static===$static)
            && $source->located($native->nameLocation,$node->name,$file) && count($node->params)===$count && count($native->parameters)===$count)) { return false; }
        foreach($node->params as $index=>$parameter) {
            $formal=$native->parameters[$index];$reference=in_array($index,$referenceFormals,true);
            if(!$contracts->stage('physical native formal '.$dependency.' #'.($index+1),$parameter->var instanceof Node\Expr\Variable && is_string($parameter->var->name)
                && $formal->name==='$'.$parameter->var->name && $parameter->byRef===$reference
                && $formal->flags->contains(\Mago\Sdk\Analyzer\Metadata\MetadataFlags::BY_REFERENCE)===$reference
                && !$parameter->variadic && !$formal->flags->contains(\Mago\Sdk\Analyzer\Metadata\MetadataFlags::VARIADIC) && $formal->outType===null
                && $formal->flags->contains(\Mago\Sdk\Analyzer\Metadata\MetadataFlags::HAS_DEFAULT)===($parameter->default!==null)
                && $source->located($formal->location,$parameter,$file) && $source->located($formal->nameLocation,$parameter->var,$file)
                && ($parameter->type===null?$formal->declaredType===null:$source->located($formal->declaredType?->location,$parameter->type,$file)))) { return false; }
        }
        if($expected==='') { return true; }
        if($expected===null) {
            return $contracts->stage('physical bare array producer declaration',$node->stmts===null && $node->returnType===null
                && preg_match('~@return[ \t]+array[ \t]*(?:\r?\n|\*/)~',$node->getDocComment()?->getText()??'')===1
                && preg_match('~@(phpstan|psalm)-return\b~',$node->getDocComment()?->getText()??'')!==1);
        }
        $body=substr($file['contents'],$node->getStartFilePos(),$node->getEndFilePos()-$node->getStartFilePos()+1);
        return $contracts->stage('exact current physical body '.$dependency,$source->tokens($body)===$source->tokens($expected));
    }

}
