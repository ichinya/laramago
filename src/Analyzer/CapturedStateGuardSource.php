<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use PhpParser\{Node,NodeFinder};

/** Compact lexical producer roles for mutable captured state. No closure symbol is invented. */
final class CapturedStateGuardSource
{
    public static function compile(array $nodes):array
    {
        $proofs=[];$visits=0;
        $walk=static function(Node $node,array $ancestors,?array $scope,?string $class) use(&$walk,&$proofs,&$visits):void{
            if(++$visits>100000){throw new \RuntimeException('Source node bound exceeded.');}
            if($node instanceof Node\Stmt\ClassLike){$class=$node->namespacedName?->toString();}
            if($node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Stmt\Function_){
                $scope=['kind'=>$node->getType(),'class'=>$node instanceof Node\Stmt\ClassMethod?$class:null,'name'=>$node instanceof Node\Stmt\Function_?$node->namespacedName->toString():$node->name->name,'span'=>self::span($node),'startAlternatives'=>[$node->getStartFilePos()]];
                foreach($node->getComments() as $comment){$scope['startAlternatives'][]=$comment->getStartFilePos();}foreach($node->attrGroups as $attribute){$scope['startAlternatives'][]=$attribute->getStartFilePos();}
                $ancestors=[];
            }
            if($node instanceof Node\Stmt\If_){$proof=self::guard($node,$ancestors,$scope);if($proof!==null){foreach($proof['sites'] as $site){$proofs[self::key($site['span'])]=$proof+['selectedSite'=>$site];}}}
            $ancestors[]=$node;
            foreach($node->getSubNodeNames() as $field){$value=$node->$field;foreach(is_array($value)?$value:[$value] as $child){if($child instanceof Node){$walk($child,$ancestors,$scope,$class);}}}
        };
        foreach($nodes as $node){$walk($node,[],null,null);}return $proofs;
    }
    private static function guard(Node\Stmt\If_ $if,array $ancestors,?array $scope):?array
    {
        if($if->else!==null || $if->elseifs!==[]){return null;}
        $callables=array_values(array_filter($ancestors,static fn(Node $node):bool=>$node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Stmt\Function_ || $node instanceof Node\Expr\Closure));
        $closure=end($callables);if(!$closure instanceof Node\Expr\Closure || $closure->byRef){return null;}
        $condition=$if->cond;$family=null;$variable=null;$predicate=null;
        if(($condition instanceof Node\Expr\BinaryOp\Smaller || $condition instanceof Node\Expr\BinaryOp\Identical)
            && $condition->left instanceof Node\Expr\PreInc && self::variable($condition->left->var) && $condition->right instanceof Node\Scalar\Int_
            && $condition->right->value>=1 && $condition->right->value<=1024 && self::nextReturn($if->stmts,$closure)){
            $family='captured-counter';$variable=$condition->left->var->name;
        } elseif($condition instanceof Node\Expr\BooleanNot && $condition->expr instanceof Node\Expr\FuncCall && $condition->expr->name instanceof Node\Name
            && strcasecmp($condition->expr->name->toString(),'is_int')===0 && self::ordinary($condition->expr->args) && count($condition->expr->args)===1
            && self::variable($condition->expr->args[0]->value) && self::exception($if->stmts)!==null){
            $family='captured-written-value';$variable=$condition->expr->args[0]->value->name;$predicate=$condition->expr;
        } elseif($condition instanceof Node\Expr\Isset_ && count($condition->vars)===1 && $condition->vars[0] instanceof Node\Expr\ArrayDimFetch
            && self::variable($condition->vars[0]->var) && self::variable($condition->vars[0]->dim) && self::falseReturn($if->stmts)){
            $family='recursive-visited-set';$variable=$condition->vars[0]->var->name;
        } else{return null;}
        $initial=self::initializer($callables,$variable,$if->getStartFilePos(),$family);if($initial===null){return null;}
        $bindingIndex=$initial['callableIndex'];
        for($index=$bindingIndex+1;$index<count($callables);$index++){if(!$callables[$index] instanceof Node\Expr\Closure || !self::captures($callables[$index],$variable)){return null;}}
        $storage=null;$writer=null;$objectId=null;
        if($family==='recursive-visited-set'){
            $objectId=self::visited($closure,$if,$condition->vars[0]);if($objectId===null){return null;}
        } else {
            $storage=self::storage($closure,$ancestors);if($storage===null || !$closure->static){return null;}
            if($family==='captured-written-value'){$writer=self::writer($callables[$bindingIndex],$variable,$closure);if($writer===null){return null;}}
        }
        $sites=[['kind'=>'condition','span'=>self::span($condition)]];$predicates=[];
        if($predicate!==null){$sites[]=['kind'=>'predicate','name'=>'is_int','span'=>self::span($predicate)];$sites[]=['kind'=>'negated-predicate','name'=>'is_int','span'=>self::span($condition)];
            $predicates[]=['name'=>'is_int','span'=>self::span($predicate),'argument'=>self::span($predicate->args[0]->value)];}
        return ['family'=>$family,'scope'=>$scope,'guard'=>self::span($if),'condition'=>self::span($condition),'sites'=>$sites,'predicates'=>$predicates,
            'variable'=>$variable,'initializer'=>$initial['assignment'],'initializerValue'=>$initial['value'],'captures'=>array_map(static fn(Node $node):array=>self::span($node),array_slice($callables,$bindingIndex+1)),
            'storage'=>$storage,'writer'=>$writer,'objectId'=>$objectId,'exception'=>self::exception($if->stmts),
            'authority'=>'explicit reporting policy for source-declared mutable captured state','callbackExecutionProven'=>false,'runtimePurityProven'=>false,'nativeTypesChanged'=>false];
    }
    private static function initializer(array $callables,string $variable,int $before,string $family):?array
    {
        for($index=count($callables)-1;$index>=0;$index--){
            foreach($callables[$index]->stmts??[] as $statement){$assign=$statement instanceof Node\Stmt\Expression?$statement->expr:null;
                if(!$assign instanceof Node\Expr\Assign || !self::variable($assign->var) || $assign->var->name!==$variable || $assign->getEndFilePos()>=$before){continue;}
                $value=match(true){$assign->expr instanceof Node\Scalar\Int_ && $assign->expr->value===0=>'zero',$assign->expr instanceof Node\Expr\ConstFetch && strtolower($assign->expr->name->toString())==='null'=>'null',$assign->expr instanceof Node\Expr\Array_ && $assign->expr->items===[]=>'empty-array',default=>null};
                $expected=match($family){'captured-counter'=>'zero','captured-written-value'=>'null','recursive-visited-set'=>'empty-array'};
                if($value!==$expected){return null;}
                $same=(new NodeFinder)->find($callables[$index]->stmts??[],static fn(Node $node):bool=>$node instanceof Node\Expr\Assign && self::variable($node->var) && $node->var->name===$variable && $node->getStartFilePos()<$before);
                // The written-value sibling transport may write later. Before the selected guard only the declaration establishes its binding.
                if(count($same)!==1){return null;}return ['callableIndex'=>$index,'assignment'=>self::span($assign),'value'=>$value];
            }
        }return null;
    }
    private static function storage(Node\Expr\Closure $closure,array $ancestors):?array
    {
        $assign=null;foreach(array_reverse($ancestors) as $parent){if($parent instanceof Node\Expr\Assign && $parent->expr===$closure){$assign=$parent;break;}}
        if(!$assign?->var instanceof Node\Expr\PropertyFetch || !self::variable($assign->var->var) || !$assign->var->name instanceof Node\Identifier){return null;}
        $receiver=$assign->var->var->name;
        foreach(array_reverse($ancestors) as $parent){if(!$parent instanceof Node\Expr\Closure){continue;}foreach($parent->params as $parameter){if(self::variable($parameter->var) && $parameter->var->name===$receiver && !$parameter->byRef && !$parameter->variadic && $parameter->type instanceof Node\Name){
            return ['class'=>$parameter->type->toString(),'property'=>$assign->var->name->name,'assignment'=>self::span($assign),'formal'=>self::span($parameter),'sourceDeclaredFormalOnly'=>true,'nativeClosureIdentifierInvented'=>false];
        }}}return null;
    }
    private static function writer(Node $host,string $variable,Node\Expr\Closure $reader):?array
    {
        foreach((new NodeFinder)->findInstanceOf($host->stmts??[],Node\Expr\Closure::class) as $closure){if($closure===$reader || !self::captures($closure,$variable) || !$closure->static || $closure->byRef){continue;}
            foreach($closure->stmts as $statement){$assign=$statement instanceof Node\Stmt\Expression?$statement->expr:null;if(!$assign instanceof Node\Expr\Assign || !self::variable($assign->var) || $assign->var->name!==$variable || !self::variable($assign->expr)){continue;}
                foreach($closure->params as $parameter){if(self::variable($parameter->var) && $parameter->var->name===$assign->expr->name && !$parameter->byRef && !$parameter->variadic && $parameter->type instanceof Node\Identifier && $parameter->type->name==='mixed'){
                    return ['closure'=>self::span($closure),'assignment'=>self::span($assign),'formal'=>self::span($parameter),'declaredInput'=>'mixed','nativeClosureParameterAssumed'=>false];
                }}
            }
        }return null;
    }
    private static function visited(Node\Expr\Closure $closure,Node\Stmt\If_ $if,Node\Expr\ArrayDimFetch $member):?array
    {
        $selfReference=false;foreach($closure->uses as $use){if($use->byRef && $use->var->name!==$member->var->name){$selfReference=true;}}if(!$selfReference){return null;}
        $objectId=null;$write=false;foreach($closure->stmts as $statement){$assign=$statement instanceof Node\Stmt\Expression?$statement->expr:null;
            if($assign instanceof Node\Expr\Assign && self::variable($assign->var) && $assign->var->name===$member->dim->name && $assign->getStartFilePos()<$if->getStartFilePos()
                && $assign->expr instanceof Node\Expr\FuncCall && $assign->expr->name instanceof Node\Name && strcasecmp($assign->expr->name->toString(),'spl_object_id')===0
                && count($assign->expr->args)===1 && self::ordinary($assign->expr->args) && self::variable($assign->expr->args[0]->value)){$objectId=['call'=>self::span($assign->expr),'argument'=>self::span($assign->expr->args[0]->value)];}
            if($assign instanceof Node\Expr\Assign && $assign->getStartFilePos()>$if->getEndFilePos() && $assign->var instanceof Node\Expr\ArrayDimFetch && self::variable($assign->var->var) && $assign->var->var->name===$member->var->name
                && self::variable($assign->var->dim) && $assign->var->dim->name===$member->dim->name && $assign->expr instanceof Node\Expr\ConstFetch && strtolower($assign->expr->name->toString())==='true'){$write=true;}
        }return $write?$objectId:null;
    }
    private static function nextReturn(array $statements,Node\Expr\Closure $closure):bool
    {
        if(count($statements)!==1 || !$statements[0] instanceof Node\Stmt\Return_ || !$statements[0]->expr instanceof Node\Expr\FuncCall || !self::variable($statements[0]->expr->name) || $statements[0]->expr->args!==[]){return false;}
        foreach($closure->params as $parameter){if(self::variable($parameter->var) && $parameter->var->name===$statements[0]->expr->name->name && !$parameter->byRef && !$parameter->variadic && $parameter->type instanceof Node\Name && strcasecmp($parameter->type->toString(),'Closure')===0){return true;}}return false;
    }
    private static function falseReturn(array $statements):bool{return count($statements)===1 && $statements[0] instanceof Node\Stmt\Return_ && $statements[0]->expr instanceof Node\Expr\ConstFetch && strtolower($statements[0]->expr->name->toString())==='false';}
    private static function exception(array $statements):?array
    {
        $expr=count($statements)===1 && $statements[0] instanceof Node\Stmt\Expression?$statements[0]->expr:null;
        if(!$expr instanceof Node\Expr\Throw_ || !$expr->expr instanceof Node\Expr\New_ || !$expr->expr->class instanceof Node\Name || !in_array($expr->expr->class->toString(),['RuntimeException','LogicException'],true)
            || count($expr->expr->args)!==1 || !self::ordinary($expr->expr->args) || !$expr->expr->args[0]->value instanceof Node\Scalar\String_){return null;}return ['class'=>$expr->expr->class->toString(),'span'=>self::span($expr->expr)];
    }
    private static function captures(Node\Expr\Closure $closure,string $variable):bool{foreach($closure->uses as $use){if($use->var->name===$variable){return $use->byRef;}}return false;}
    private static function ordinary(array $arguments):bool{foreach($arguments as $argument){if(!$argument instanceof Node\Arg || $argument->byRef || $argument->unpack || $argument->name!==null){return false;}}return true;}
    private static function variable(?Node $node):bool{return $node instanceof Node\Expr\Variable && is_string($node->name);}
    public static function span(Node $node):array{return [$node->getStartFilePos(),$node->getEndFilePos()+1];}
    public static function key(array $span):string{return $span[0].':'.$span[1];}
}
