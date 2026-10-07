<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\{IssueFilterContext,Type};
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use PhpParser\{Node,NodeFinder};

/** A source guard supplies a local nominal binding; no application loader is executed. */
final class CapturedThisLocalBinding
{
    public static function source(Node\Stmt\ClassMethod $scope,Node\Expr\MethodCall $registration):?array
    {
        if(!$registration->var instanceof Node\Expr\Variable || !is_string($registration->var->name)){return null;}
        $name=$registration->var->name;$statements=$scope->stmts??[];$declaration=null;$guard=null;$guardIndex=null;
        foreach($statements as $index=>$statement){
            if($statement->getStartFilePos()>=$registration->getStartFilePos()){break;}
            if(!$statement instanceof Node\Stmt\If_ || $statement->else!==null || $statement->elseifs!==[] || count($statement->stmts)!==1
                || !$statement->cond instanceof Node\Expr\BooleanNot || !$statement->cond->expr instanceof Node\Expr\Instanceof_){continue;}
            $instance=$statement->cond->expr;
            if(!$instance->expr instanceof Node\Expr\Variable || $instance->expr->name!==$name || !$instance->class instanceof Node\Name\FullyQualified){continue;}
            $throw=$statement->stmts[0] instanceof Node\Stmt\Expression?$statement->stmts[0]->expr:null;
            if(!$throw instanceof Node\Expr\Throw_ || !$throw->expr instanceof Node\Expr\New_ || !$throw->expr->class instanceof Node\Name\FullyQualified
                || !in_array(strtolower($throw->expr->class->toString()),['runtimeexception','logicexception','unexpectedvalueexception'],true)
                || count($throw->expr->args)!==1 || !$throw->expr->args[0] instanceof Node\Arg || !$throw->expr->args[0]->value instanceof Node\Scalar\String_){continue;}
            $previous=$statements[$index-1]??null;$assign=$previous instanceof Node\Stmt\Expression?$previous->expr:null;
            if(!$assign instanceof Node\Expr\Assign || !$assign->var instanceof Node\Expr\Variable || $assign->var->name!==$name
                || (!$assign->expr instanceof Node\Expr\Include_ && !$assign->expr instanceof Node\Expr\New_)
                || $assign->expr instanceof Node\Expr\Include_ && $assign->expr->type!==Node\Expr\Include_::TYPE_REQUIRE){continue;}
            if($assign->expr instanceof Node\Expr\New_ && (!$assign->expr->class instanceof Node\Name\FullyQualified || strcasecmp($assign->expr->class->toString(),$instance->class->toString())!==0)){continue;}
            $declaration=$previous;$guard=$statement;$guardIndex=$index;break;
        }
        if($guard===null || !$scope->returnType instanceof Node\Name\FullyQualified || strcasecmp($scope->returnType->toString(),$guard->cond->expr->class->toString())!==0){return null;}
        $finder=new NodeFinder;
        if($finder->findFirst($statements,static fn(Node $node):bool=>$node instanceof Node\Expr\Variable && $node->name===$name && $node->getStartFilePos()<$declaration->getStartFilePos())!==null){return null;}
        if($finder->findFirst($statements,static fn(Node $node):bool=>$node->getStartFilePos()<$registration->getStartFilePos()
            && ($node instanceof Node\Expr\Variable && !is_string($node->name) || $node instanceof Node\Expr\Eval_
                || $node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name && in_array(strtolower($node->name->toString()),['extract','parse_str'],true)))!==null){return null;}
        $uses=[];
        foreach(array_slice($statements,$guardIndex+1) as $statement){
            if($statement->getStartFilePos()>=$registration->getStartFilePos()){break;}
            if($statement->getEndFilePos()>=$registration->getEndFilePos()){break;}
            if(!(new NodeFinder)->findFirst([$statement],static fn(Node $node):bool=>$node instanceof Node\Expr\Variable && $node->name===$name)){continue;}
            $expression=$statement instanceof Node\Stmt\Expression?$statement->expr:null;
            if($expression instanceof Node\Expr\MethodCall && !$expression->isFirstClassCallable() && $expression->var instanceof Node\Expr\Variable && $expression->var->name===$name
                && $expression->name instanceof Node\Identifier && strcasecmp($expression->name->name,'booting')===0 && count($expression->args)===1 && $expression->args[0] instanceof Node\Arg
                && !$expression->args[0]->byRef && !$expression->args[0]->unpack && $expression->args[0]->name===null && $expression->args[0]->value instanceof Node\Expr\Closure
                && !$expression->args[0]->value->byRef && !$expression->args[0]->value->static){
                $closure=$expression->args[0]->value;$bad=false;
                foreach($closure->uses as $use){if($use->var->name===$name && $use->byRef){$bad=true;}}
                foreach($closure->params as $param){if($param->var->name===$name){$bad=true;}}
                if($bad){return null;}$uses[]=['kind'=>'append-only-callback-storage','call'=>$expression];continue;
            }
            if($statement instanceof Node\Stmt\If_ && $statement->else===null && $statement->elseifs===[] && count($statement->stmts)===1){
                $condition=$statement->cond;if($condition instanceof Node\Expr\BinaryOp\BooleanAnd && $condition->left instanceof Node\Expr\Isset_){$condition=$condition->right;}
                $call=$statement->stmts[0] instanceof Node\Stmt\Expression?$statement->stmts[0]->expr:null;
                if(self::hasMethodGuard($condition,$call,$name)){$uses[]=['kind'=>'phpstan-has-method-argument-effects','guard'=>$condition,'call'=>$call];continue;}
            }
            return null;
        }
        return ['name'=>$name,'class'=>$guard->cond->expr->class->toString(),'guard'=>$guard,'declaration'=>$declaration,'uses'=>$uses,'exception'=>$guard->stmts[0]->expr->expr->class->toString()];
    }
    private static function hasMethodGuard(Node\Expr $condition,?Node\Expr $call,string $name):bool
    {
        if(!$condition instanceof Node\Expr\FuncCall || $condition->isFirstClassCallable() || !$condition->name instanceof Node\Name || strcasecmp($condition->name->toString(),'method_exists')!==0
            || count($condition->args)!==2 || !$condition->args[0] instanceof Node\Arg || !$condition->args[1] instanceof Node\Arg
            || !$condition->args[0]->value instanceof Node\Expr\Variable || $condition->args[0]->value->name!=='this' || !$condition->args[1]->value instanceof Node\Scalar\String_
            || !$call instanceof Node\Expr\MethodCall || $call->isFirstClassCallable() || !$call->var instanceof Node\Expr\Variable || $call->var->name!=='this' || !$call->name instanceof Node\Identifier
            || strcasecmp($call->name->name,$condition->args[1]->value->value)!==0 || count($call->args)!==1 || !$call->args[0] instanceof Node\Arg
            || !$call->args[0]->value instanceof Node\Expr\Variable || $call->args[0]->value->name!==$name){return false;}
        foreach([...$condition->args,...$call->args] as $arg){if($arg->byRef || $arg->unpack || $arg->name!==null){return false;}}return true;
    }
    public static function native(IssueFilterContext $context,Node\Stmt\ClassMethod $scope,object $caller,array $binding):array
    {
        $receipt=['admitted'=>false,'stage'=>'local-guard-native-class','nativeTypesChanged'=>false,'runtimePurityProven'=>false,'guardClass'=>$binding['class']];
        $expected=Type::namedObject($binding['class']);$class=$context->codebase->getClass($binding['class']);
        if($class===null || $class->hasIncompleteHierarchy() || $class->templates!==[] || $class->mixins!==[] || $caller->declaredReturnType===null || $caller->returnType===null
            || $caller->declaredReturnType->fromDocblock || $caller->declaredReturnType->inferred || !$context->types->equals($caller->declaredReturnType->type,$expected)
            || !$context->types->equals($caller->returnType->type,$expected)){return $receipt;}
        $exception=$context->codebase->getClass($binding['exception']);
        if($exception===null || !$exception->flags->contains(MetadataFlags::BUILTIN) || $exception->flags->contains(MetadataFlags::USER_DEFINED) || $exception->hasIncompleteHierarchy()){return $receipt;}
        foreach($binding['uses'] as $use){
            if($use['kind']==='append-only-callback-storage'){continue;}
            $function=$context->codebase->getFunction('method_exists');$method=$use['call']->name->name;
            if($function===null || !$function->flags->contains(MetadataFlags::BUILTIN) || $function->flags->contains(MetadataFlags::USER_DEFINED) || $function->flags->contains(MetadataFlags::BY_REFERENCE)
                || $context->codebase->methodExists($caller->identifier->class,$method)){return $receipt;}
            $original=$use['guard']->name->getAttribute('originalName');
            if(!$original instanceof Node\Name\FullyQualified){$separator=strrpos($caller->identifier->class,'\\');$namespace=$separator===false?'':substr($caller->identifier->class,0,$separator);
                if($namespace!=='' && $context->codebase->getFunction($namespace.'\\method_exists')!==null){return $receipt;}}
        }
        $receipt['stage']='dominating-local-guard-admitted';$receipt['admitted']=true;$receipt['guardSpan']=[$binding['guard']->getStartFilePos(),$binding['guard']->getEndFilePos()+1];
        $receipt['producerExecuted']=false;$receipt['hasMethodPassingPolicy']='PHPStan default HasMethod dummy variants have no reference parameters or invalidated expressions; side effects remain uncertain';return $receipt;
    }
}
