<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\{IssueFilterContext,Type};
use Mago\Sdk\Analyzer\Metadata\{ClassLikeKind,MetadataFlags};
use PhpParser\{Node,NodeFinder};

/** Closed literal rows under PHPStan's possible by-reference argument-closure writes. */
final class PossibleCallbackTupleContracts
{
    public array $stages=[];public array $dependencies=[];public array $certificate=[];
    public function __construct(private readonly string $root) {}
    private function stage(string $stage,bool $passed):bool { $this->stages[]=compact('stage','passed');return $passed; }
    /** Source proof only. Returned syntax has no native admission authority. */
    public function lexical(GuardedStringCastContracts $source,array $file,Node $point):?array
    {
        $scope=null;$loop=null;
        if($point instanceof Node\Expr\List_ || $point instanceof Node\Expr\Array_) {
            $parent=$file['parents'][spl_object_id($point)]??null;
            if($parent instanceof Node\Stmt\Foreach_ && $parent->valueVar===$point) { $loop=$parent; }
        }
        foreach($source->ancestors($point,$file) as $ancestor) {
            if($loop===null && $ancestor instanceof Node\Stmt\Foreach_) { $loop=$ancestor; }
            if($ancestor instanceof Node\FunctionLike) { if($ancestor instanceof Node\Stmt\ClassMethod) { $scope=$ancestor; }break; }
        }
        if(!$this->stage('selected by-value literal tuple loop in a named method',$scope!==null && $loop!==null && !$loop->byRef && $loop->keyVar===null
            && $loop->expr instanceof Node\Expr\Variable && is_string($loop->expr->name)
            && ($loop->valueVar instanceof Node\Expr\List_ || $loop->valueVar instanceof Node\Expr\Array_))) { return null; }
        $names=[];
        foreach($loop->valueVar->items as $item) {
            if(!$item instanceof Node\ArrayItem || $item->key!==null || $item->byRef || $item->unpack
                || !$item->value instanceof Node\Expr\Variable || !is_string($item->value->name) || in_array($item->value->name,$names,true)) {
                $this->stage('distinct complete positional tuple projections',false);return null;
            }
            $names[]=$item->value->name;
        }
        if(!$this->stage('nonempty tuple with no container projection alias',$names!==[] && !in_array($loop->expr->name,$names,true))) { return null; }
        $container=$loop->expr->name;$finder=new NodeFinder;$initial=[];$captures=[];$appends=[];
        foreach($finder->findInstanceOf($scope->stmts??[],Node::class) as $node) {
            if($node->getStartFilePos()>$loop->getEndFilePos()) { continue; }
            if($node instanceof Node\Expr\Eval_ || $node instanceof Node\Expr\Include_ || $node instanceof Node\Expr\Variable && !is_string($node->name)
                || $node instanceof Node\Expr\FuncCall && (!$node->name instanceof Node\Name || in_array(strtolower($node->name->getLast()),['extract','parse_str'],true))) {
                $this->stage('no dynamic local scope mutation',false);return null;
            }
            if(!$node instanceof Node\Expr\Variable || $node->name!==$container) { continue; }
            $parent=$file['parents'][spl_object_id($node)]??null;
            if($node===$loop->expr) { continue; }
            if($parent instanceof Node\Expr\Assign && $parent->var===$node && $parent->expr instanceof Node\Expr\Array_ && $parent->expr->items===[]) {
                $statement=$file['parents'][spl_object_id($parent)]??null;
                if($statement instanceof Node\Stmt\Expression && in_array($statement,$scope->stmts??[],true) && $statement->getEndFilePos()<$loop->getStartFilePos()) { $initial[]=$parent;continue; }
            }
            if($parent instanceof Node\ClosureUse && $parent->var===$node && $parent->byRef) {
                $closure=$file['parents'][spl_object_id($parent)]??null;
                $arg=$closure instanceof Node\Expr\Closure?($file['parents'][spl_object_id($closure)]??null):null;
                $outer=$arg instanceof Node\Arg?($file['parents'][spl_object_id($arg)]??null):null;
                if($closure instanceof Node\Expr\Closure && !$closure->byRef && $arg instanceof Node\Arg && !$arg->byRef && !$arg->unpack && $arg->name===null
                    && $outer instanceof Node\Expr\CallLike && SourceArgumentDeclarationContracts::plain($outer) && $closure->getEndFilePos()<$loop->getStartFilePos()) {
                    $captures[spl_object_id($closure)]=[$closure,$outer];continue;
                }
            }
            if($parent instanceof Node\Expr\ArrayDimFetch && $parent->var===$node && $parent->dim===null) {
                $assign=$file['parents'][spl_object_id($parent)]??null;
                if($assign instanceof Node\Expr\Assign && $assign->var===$parent && $assign->expr instanceof Node\Expr\Array_) {
                    $function=null;
                    foreach($source->ancestors($assign,$file) as $ancestor) { if($ancestor instanceof Node\FunctionLike) { $function=$ancestor;break; } }
                    if($function instanceof Node\Expr\Closure) { $appends[]=[$assign,$function];continue; }
                }
            }
            $this->stage('no selected cell alias reference rebinding escape or foreign write',false);return null;
        }
        if(!$this->stage('one unconditional empty initializer and one direct argument closure',count($initial)===1 && count($captures)===1 && $appends!==[])) { return null; }
        [$closure,$outer]=array_values($captures)[0];
        if(!$this->stage('initializer precedes the selected closure',$initial[0]->getEndFilePos()<$closure->getStartFilePos())) { return null; }
        foreach($appends as [$append,$function]) {
            if(!$this->stage('all writes append one complete same-shape literal row',$function===$closure && count($append->expr->items)===count($names)
                && $append->getStartFilePos()>$initial[0]->getEndFilePos())) { return null; }
            foreach($append->expr->items as $item) {
                if(!$item instanceof Node\ArrayItem || $item->key!==null || $item->byRef || $item->unpack) { $this->stage('no keyed unpacked or referenced row entry',false);return null; }
            }
        }
        $this->stage('closed literal row source proof',true);
        return compact('scope','loop','names','container','closure','outer','appends')+['initial'=>$initial[0]];
    }
    public function bindOwner(IssueFilterContext $context,GuardedStringCastContracts $source,array $file,array $lexical,Node $point):?object
    {
        $owner=$source->owner($context,$point,$file);$this->dependencies['owner']=$owner;
        $callerClass=$owner===null?null:$context->codebase->getClass($owner->identifier->class);$this->dependencies['class:'.strtolower($owner?->identifier->class??'unknown')]=$callerClass;
        if(!$this->stage('genuine current named tuple owner',$owner!==null && $callerClass!==null && strcasecmp($callerClass->name,$owner->identifier->class)===0
            && strcasecmp($owner->identifier->name,$lexical['scope']->name->name)===0 && $owner->static===$lexical['scope']->isStatic()
            && ($owner->declaredReturnType===null) === ($lexical['scope']->returnType===null)
            && ($lexical['scope']->returnType===null || $source->located($owner->declaredReturnType?->location,$lexical['scope']->returnType,$file)))) { return null; }
        foreach($lexical['scope']->params as $index=>$parameter) {
            $native=$owner->parameters[$index];
            if(!$this->stage('unchanged caller formal '.$index,$native->outType===null
                && $native->flags->contains(MetadataFlags::HAS_DEFAULT)===($parameter->default!==null))) { return null; }
        }
        return $owner;
    }
    /** Native PHP declared input only; a closure body may read it but cannot rebind its value. */
    public function capturedFormal(IssueFilterContext $context,GuardedStringCastContracts $source,array $file,array $lexical,object $owner,Node\Expr $value,Node\Expr\Assign $append):?Type
    {
        if(!$value instanceof Node\Expr\Variable || !is_string($value->name)) { $this->stage('selected row entry is a captured typed formal',false);return null; }
        $name=$value->name;$closure=$lexical['closure'];$scope=$lexical['scope'];
        $captures=array_values(array_filter($closure->uses,static fn(Node\ClosureUse $use):bool=>$use->var->name===$name));
        $parameters=array_values(array_filter($scope->params,static fn(Node\Param $parameter):bool=>$parameter->var instanceof Node\Expr\Variable && $parameter->var->name===$name));
        if(!$this->stage('one by-value capture of one typed caller formal',count($captures)===1 && !$captures[0]->byRef && count($parameters)===1
            && !$parameters[0]->byRef && !$parameters[0]->variadic && $parameters[0]->type!==null)) { return null; }
        $parameter=$parameters[0];$index=array_search($parameter,$scope->params,true);$native=$owner->parameters[$index];$declared=$native->declaredType?->type;
        $domain=null;
        if($declared!==null && !$declared->flags->byReference && !$declared->flags->possiblyUndefined
            && $source->located($native->declaredType->location,$parameter->type,$file) && $native->type!==null) {
            if($context->types->isContainedBy($native->type->type,$declared)) { $domain=$declared; }
            else {
                $constants=new PossibleTupleConstantDomain;
                $domain=$constants->resolve($context,$source,$file,$scope,$parameter,$owner,$native);
                array_push($this->stages,...$constants->stages);
                $this->dependencies+=$constants->dependencies;
            }
        }
        if(!$this->stage('genuine physically declared captured value domain',$domain!==null)) { return null; }
        foreach((new NodeFinder)->findInstanceOf($scope->stmts??[],Node\Expr\Variable::class) as $use) {
            if($use->name===$name && $use->getStartFilePos()<$closure->getStartFilePos()) { $this->stage('no earlier captured input alias reference or escape',false);return null; }
        }
        foreach((new NodeFinder)->findInstanceOf($closure->stmts??[],Node\Expr\Variable::class) as $use) {
            if($use->name!==$name || $use->getStartFilePos()>$append->getEndFilePos()) { continue; }
            $parent=$file['parents'][spl_object_id($use)]??null;
            if($parent instanceof Node\Expr\AssignRef || $parent instanceof Node\ClosureUse || $parent instanceof Node\Stmt\Unset_ || $parent instanceof Node\Expr\AssignOp
                || $parent instanceof Node\Expr\Assign && $parent->var===$use || $parent instanceof Node\Expr\PreInc || $parent instanceof Node\Expr\PostInc
                || $parent instanceof Node\Expr\PreDec || $parent instanceof Node\Expr\PostDec) { $this->stage('captured input value is never rebound or referenced',false);return null; }
            if($parent instanceof Node\Arg && !$this->readFormal($context,$source,$file,$owner,$parent)) { return null; }
        }
        $this->stage('closed native captured formal projection',true);return $domain;
    }
    private function readFormal(IssueFilterContext $context,GuardedStringCastContracts $source,array $file,object $owner,Node\Arg $argument):bool
    {
        $call=$file['parents'][spl_object_id($argument)]??null;$native=null;
        if(!$call instanceof Node\Expr\CallLike || !SourceArgumentDeclarationContracts::plain($call)) { return $this->stage('plain selected value read call',false); }
        if($call instanceof Node\Expr\MethodCall && $call->var instanceof Node\Expr\Variable && $call->var->name==='this' && $call->name instanceof Node\Identifier) {
            $native=$context->codebase->getMethod($owner->identifier->class,$call->name->name)??$context->codebase->getDeclaringMethod($owner->identifier->class,$call->name->name);
        } elseif($call instanceof Node\Expr\StaticCall && $call->class instanceof Node\Name && $call->name instanceof Node\Identifier) {
            $native=$context->codebase->getMethod($call->class->toString(),$call->name->name)??$context->codebase->getDeclaringMethod($call->class->toString(),$call->name->name);
        }
        $key=($native?->identifier->class??'unknown').'::'.($native?->identifier->name??'unknown');$this->dependencies['read:'.$key]=$native;
        $physical=$native===null?null:$this->physicalMethod($context,$source,$native);
        $index=array_search($argument,$call->args,true);$formal=$native?->parameters[$index]??null;$param=$physical['syntax']->params[$index]??null;
        return $this->stage('physical by-value selected read formal '.$key,$physical!==null && $formal!==null && $param!==null
            && !$param->byRef && !$param->variadic && !$formal->flags->contains(MetadataFlags::BY_REFERENCE)
            && !$formal->flags->contains(MetadataFlags::VARIADIC) && $formal->outType===null);
    }
    public function receivingDeclaration(IssueFilterContext $context,GuardedStringCastContracts $source,array $receiving):bool
    {
        return $this->stage('entire current physical receiving method contract',$this->physicalMethod($context,$source,$receiving['native'])!==null);
    }
    /** Literal PHP declaration and native call binding; nullable read formals retain their exact declared union. */
    private function physicalMethod(IssueFilterContext $context,GuardedStringCastContracts $source,object $native):?array
    {
        $className=$native->identifier->class;
        if(!$this->stage('physical receiving declaration has a named class owner',is_string($className) && $className!=='')) { return null; }
        $file=$source->read($native->location->file);
        $methods=$file===null?[]:(new NodeFinder)->findInstanceOf($file['nodes'],Node\Stmt\ClassMethod::class);
        $methods=array_values(array_filter($methods,static fn(Node\Stmt\ClassMethod $method):bool=>$source->located($native->location,$method,$file)));
        $syntax=$methods[0]??null;$class=null;
        if($syntax!==null) { foreach($source->ancestors($syntax,$file) as $parent) { if($parent instanceof Node\Stmt\ClassLike) { $class=$parent;break; } } }
        $owner=$context->codebase->getClass($className);
        $this->dependencies['class:'.strtolower($native->identifier->class)]=$owner;
        if(!$this->stage('complete current physical method and class '.$native->identifier->name,count($methods)===1 && !$syntax->byRef
            && !$native->abstract && !$native->flags->contains(MetadataFlags::BY_REFERENCE) && !$native->flags->contains(MetadataFlags::BUILTIN)
            && $native->templates===[] && $native->static===$syntax->isStatic() && strcasecmp($native->identifier->name,$syntax->name->name)===0
            && $source->located($native->nameLocation,$syntax->name,$file) && $class instanceof Node\Stmt\Class_ && $class->name!==null
            && $class->namespacedName!==null && strcasecmp($native->identifier->class,$class->namespacedName->toString())===0
            && $owner!==null && $owner->kind===ClassLikeKind::Class_ && !$owner->hasIncompleteHierarchy()
            && strcasecmp($owner->name,$class->namespacedName->toString())===0 && $source->located($owner->location,$class,$file)
            && $source->located($owner->nameLocation,$class->name,$file) && count($native->parameters)===count($syntax->params))) { return null; }
        foreach($syntax->params as $position=>$parameter) {
            $formal=$native->parameters[$position];$expected=$parameter->type===null?null:$this->phpType($parameter->type);
            if(!$this->stage('unchanged physical PHP formal '.$native->identifier->name.' #'.$position,$parameter->var instanceof Node\Expr\Variable
                && is_string($parameter->var->name) && $formal->name==='$'.$parameter->var->name
                && $formal->flags->contains(MetadataFlags::BY_REFERENCE)===$parameter->byRef && $formal->flags->contains(MetadataFlags::VARIADIC)===$parameter->variadic
                && $formal->outType===null && $formal->flags->contains(MetadataFlags::HAS_DEFAULT)===($parameter->default!==null)
                && $source->located($formal->location,$parameter,$file) && $source->located($formal->nameLocation,$parameter->var,$file)
                && ($parameter->type===null?$formal->declaredType===null:$expected!==null && $formal->declaredType!==null
                    && !$formal->declaredType->fromDocblock && !$formal->declaredType->inferred
                    && $source->located($formal->declaredType->location,$parameter->type,$file)
                    && $context->types->isContainedBy($formal->declaredType->type,$expected) && $context->types->isContainedBy($expected,$formal->declaredType->type)))) { return null; }
            if($syntax->getDocComment()===null && $formal->declaredType!==null && (!$this->stage('unchanged effective PHP formal '.$native->identifier->name.' #'.$position,
                $formal->type!==null && $context->types->isContainedBy($formal->type->type,$formal->declaredType->type)
                && $context->types->isContainedBy($formal->declaredType->type,$formal->type->type)))) { return null; }
        }
        return compact('file','syntax');
    }
    private function phpType(Node $syntax):?Type
    {
        if($syntax instanceof Node\NullableType) { $inner=$this->phpType($syntax->type);return $inner===null?null:Type::union($inner,Type::null()); }
        if($syntax instanceof Node\UnionType) {
            $types=[];foreach($syntax->types as $part) { $type=$this->phpType($part);if($type===null) { return null; }$types[]=$type; }
            return Type::union(...$types);
        }
        if($syntax instanceof Node\Name) { return in_array(strtolower($syntax->toString()),['self','static','parent'],true)?null:Type::namedObject($syntax->toString()); }
        if(!$syntax instanceof Node\Identifier) { return null; }
        return match(strtolower($syntax->name)) { 'string'=>Type::string(),'int'=>Type::int(),'float'=>Type::float(),'bool'=>Type::bool(),
            'mixed'=>Type::mixed(),'object'=>Type::object(),'null'=>Type::null(),'true'=>Type::true(),'false'=>Type::false(),default=>null };
    }
    public function certify(array $file,array $lexical,array $extra=[]):void
    {
        $this->certificate=['sourceSha256'=>$file['hash'],'initializerSpan'=>SourceArgumentDeclarationContracts::span($lexical['initial']),
            'loopSpan'=>SourceArgumentDeclarationContracts::span($lexical['loop']),'closureSpan'=>SourceArgumentDeclarationContracts::span($lexical['closure']),
            'appendSpans'=>array_map(static fn(array $pair):array=>SourceArgumentDeclarationContracts::span($pair[0]),$lexical['appends']),
            'arity'=>count($lexical['names']),'policy'=>'PHPStan possible by-reference argument closure writes','guaranteedExecutionClaimed'=>false,
            'nativeTypesReplaced'=>false,'afterFileAuthority'=>false]+$extra;
    }
}
