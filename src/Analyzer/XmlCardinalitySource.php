<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\DefensiveBoundaryGuardSource as Source;
use PhpParser\{Node,NodeFinder};

/** A closed external XML validation grammar; this does not prove XML cardinality. */
final class XmlCardinalitySource
{
    public static function compile(array $nodes):array
    {
        $result=[];$finder=new NodeFinder;
        foreach($finder->findInstanceOf($nodes,Node\Stmt\Class_::class) as $owner){
            if(!isset($owner->namespacedName)){continue;}
            foreach($owner->getMethods() as $scope){
                if($scope->byRef||$scope->stmts===null){continue;}
                $statements=$scope->stmts;$loads=[];
                foreach($statements as $index=>$statement){
                    $candidates=$statement instanceof Node\Stmt\TryCatch && $statement->catches===[]?$statement->stmts:[$statement];
                    foreach($candidates as $candidate){$assign=self::assignment($candidate);
                        if($assign===null||!$assign->expr instanceof Node\Expr\FuncCall||!self::function($assign->expr,'simplexml_load_string',3)){continue;}
                        $call=$assign->expr;$class=$call->args[1]->value;$flags=$call->args[2]->value;
                        if(!$call->args[0]->value instanceof Node\Expr\Variable||!is_string($call->args[0]->value->name)
                            || !$class instanceof Node\Expr\ClassConstFetch || !$class->class instanceof Node\Name\FullyQualified || $class->class->toString()!=='SimpleXMLElement'
                            || !$class->name instanceof Node\Identifier || strtolower($class->name->name)!=='class'
                            || !$flags instanceof Node\Expr\ConstFetch || $flags->name->toString()!=='LIBXML_NONET'){continue;}
                        $loads[$assign->var->name]=['index'=>$index,'assign'=>Source::span($assign),'call'=>Source::span($call)];
                    }
                }
                foreach($statements as $index=>$statement){$assign=self::assignment($statement);
                    if($assign===null||!$assign->expr instanceof Node\Expr\MethodCall||$assign->expr->isFirstClassCallable()||!self::ordinaryArgs($assign->expr->args,1)
                        || !$assign->expr->name instanceof Node\Identifier || strcasecmp($assign->expr->name->name,'xpath')!==0
                        || !$assign->expr->var instanceof Node\Expr\Variable || !is_string($assign->expr->var->name)
                        || !$assign->expr->args[0]->value instanceof Node\Scalar\String_ || !preg_match('~^/[A-Za-z_][A-Za-z0-9_-]*(?:/{1,2}[A-Za-z_][A-Za-z0-9_-]*){1,6}$~D',$assign->expr->args[0]->value->value)){continue;}
                    $document=$assign->expr->var->name;$load=$loads[$document]??null;if($load===null||$load['index']>=$index){continue;}
                    // Loader binding stays unmodified before the selected XPath call.
                    if(!self::unwritten($finder,array_slice($statements,$load['index']+1,$index-$load['index']-1),$document)){continue;}
                    $arrayGuard=$statements[$index+1]??null;$loop=null;
                    for($next=$index+2;$next<min(count($statements),$index+10);$next++){
                        if($statements[$next] instanceof Node\Stmt\Foreach_){$loop=$statements[$next];break;}
                        $fresh=self::assignment($statements[$next]);if($fresh===null||!self::initialization($fresh,[$document,$assign->var->name])){break;}
                    }
                    if(!$arrayGuard instanceof Node\Stmt\If_ || self::rejection($arrayGuard)===null || !self::arrayCondition($arrayGuard->cond,$assign->var->name)
                        || !$loop instanceof Node\Stmt\Foreach_ || $loop->byRef || $loop->keyVar!==null || !$loop->expr instanceof Node\Expr\Variable || $loop->expr->name!==$assign->var->name
                        || !$loop->valueVar instanceof Node\Expr\Variable || !is_string($loop->valueVar->name)){continue;}
                    $element=$loop->valueVar->name;
                    foreach($loop->stmts as $guardIndex=>$guard){if(!$guard instanceof Node\Stmt\If_||($exception=self::rejection($guard))===null){continue;}
                        if(!self::unwritten($finder,array_slice($loop->stmts,0,$guardIndex),$element)){continue;}
                        $operands=self::disjuncts($guard->cond);$selected=[];$valid=true;
                        foreach($operands as $operand){$count=self::comparison($operand,$element);if($count!==null){$selected[]=['condition'=>Source::span($operand),'call'=>Source::span($count),'argument'=>Source::span($count->args[0]->value),'property'=>$count->args[0]->value->name->name];}
                            elseif(!self::pure($operand)){$valid=false;break;}}
                        if(!$valid||count($selected)!==1){continue;}
                        $starts=[$scope->getStartFilePos()];foreach($scope->getComments() as $c){$starts[]=$c->getStartFilePos();}foreach($scope->attrGroups as $a){$starts[]=$a->getStartFilePos();}
                        $site=$selected[0];$result[Source::key($site['condition'])]=['scope'=>['class'=>$owner->namespacedName->toString(),'name'=>$scope->name->name,'static'=>$scope->isStatic(),'span'=>Source::span($scope),'startAlternatives'=>array_values(array_unique($starts))],
                            'selectedSite'=>['kind'=>'xml-cardinality','span'=>$site['condition']],'count'=>$site,'loader'=>$load,'xpath'=>Source::span($assign->expr),'xpathLiteral'=>$assign->expr->args[0]->value->value,
                            'document'=>$document,'list'=>$assign->var->name,'element'=>$element,'exception'=>$exception,'guard'=>Source::span($guard),'arrayPredicate'=>Source::span($arrayGuard->cond->left->expr),
                            'policy'=>'intentional external XML defensive cardinality advisory policy','nativeTypesChanged'=>false];
                    }
                }
            }
        }
        return $result;
    }
    private static function assignment(Node $statement):?Node\Expr\Assign
    { $a=$statement instanceof Node\Stmt\Expression?$statement->expr:null;return $a instanceof Node\Expr\Assign && $a->var instanceof Node\Expr\Variable && is_string($a->var->name)?$a:null; }
    private static function ordinaryArgs(array $args,int $count):bool
    {if(count($args)!==$count){return false;}foreach($args as $arg){if(!$arg instanceof Node\Arg||$arg->byRef||$arg->unpack||$arg->name!==null){return false;}}return true;}
    private static function function(Node\Expr\FuncCall $call,string $name,int $arity):bool
    {return !$call->isFirstClassCallable()&&$call->name instanceof Node\Name&&strcasecmp($call->name->toString(),$name)===0&&self::ordinaryArgs($call->args,$arity);}
    private static function arrayCondition(Node $node,string $name):bool
    {return $node instanceof Node\Expr\BinaryOp\BooleanOr && $node->left instanceof Node\Expr\BooleanNot && $node->left->expr instanceof Node\Expr\FuncCall && self::function($node->left->expr,'is_array',1)
        && $node->left->expr->args[0]->value instanceof Node\Expr\Variable && $node->left->expr->args[0]->value->name===$name
        && $node->right instanceof Node\Expr\BinaryOp\Identical && $node->right->left instanceof Node\Expr\Variable && $node->right->left->name===$name
        && $node->right->right instanceof Node\Expr\Array_ && $node->right->right->items===[];}
    private static function rejection(Node\Stmt\If_ $if):?string
    {if($if->else!==null||$if->elseifs!==[]||count($if->stmts)!==1){return null;}$s=$if->stmts[0];$e=$s instanceof Node\Stmt\Expression?$s->expr:null;
        if(!$e instanceof Node\Expr\Throw_||!$e->expr instanceof Node\Expr\New_||!$e->expr->class instanceof Node\Name\FullyQualified||!in_array($e->expr->class->toString(),['RuntimeException','LogicException'],true)||!self::ordinaryArgs($e->expr->args,1)||!$e->expr->args[0]->value instanceof Node\Scalar\String_){return null;}return $e->expr->class->toString();}
    private static function disjuncts(Node $node):array
    {return $node instanceof Node\Expr\BinaryOp\BooleanOr?[...self::disjuncts($node->left),...self::disjuncts($node->right)]:[$node];}
    private static function comparison(Node $node,string $name):?Node\Expr\FuncCall
    {if(!$node instanceof Node\Expr\BinaryOp\NotIdentical || !$node->right instanceof Node\Scalar\Int_ || $node->right->value!==1 || !$node->left instanceof Node\Expr\FuncCall||!self::function($node->left,'count',1)){return null;}
        $property=$node->left->args[0]->value;if(!$property instanceof Node\Expr\PropertyFetch||!$property->var instanceof Node\Expr\Variable||$property->var->name!==$name||!$property->name instanceof Node\Identifier){return null;}return $node->left;}
    private static function pure(Node $node,int $depth=0):bool
    {if($depth>12){return false;}if($node instanceof Node\Scalar\String_||$node instanceof Node\Scalar\Int_){return true;}if($node instanceof Node\Expr\Variable){return is_string($node->name);}if($node instanceof Node\Expr\ArrayDimFetch){return self::pure($node->var,$depth+1)&&$node->dim!==null&&self::pure($node->dim,$depth+1);}if($node instanceof Node\Expr\Isset_){foreach($node->vars as $var){if(!self::pure($var,$depth+1)){return false;}}return true;}if($node instanceof Node\Expr\BinaryOp\Identical||$node instanceof Node\Expr\BinaryOp\NotIdentical){return self::pure($node->left,$depth+1)&&self::pure($node->right,$depth+1);}return false;}
    private static function initialization(Node $node,array $forbidden,int $depth=0):bool
    {if($depth>4){return false;}if($node instanceof Node\Expr\Assign){return $node->var instanceof Node\Expr\Variable&&is_string($node->var->name)&&!in_array($node->var->name,$forbidden,true)&&self::initialization($node->expr,$forbidden,$depth+1);}return $node instanceof Node\Scalar\Int_||$node instanceof Node\Scalar\String_||$node instanceof Node\Expr\Array_&&$node->items===[];}
    private static function unwritten(NodeFinder $finder,array $nodes,string $name):bool
    {foreach($finder->find($nodes,static fn(Node $n):bool=>$n instanceof Node\Expr\Assign||$n instanceof Node\Expr\AssignRef||$n instanceof Node\Expr\AssignOp||$n instanceof Node\Expr\PreInc||$n instanceof Node\Expr\PostInc||$n instanceof Node\Expr\PreDec||$n instanceof Node\Expr\PostDec||$n instanceof Node\Stmt\Unset_||$n instanceof Node\Expr\FuncCall||$n instanceof Node\Expr\MethodCall||$n instanceof Node\Expr\StaticCall||$n instanceof Node\Arg||$n instanceof Node\ClosureUse) as $n){
            $contains=$finder->findFirst([$n],static fn(Node $v):bool=>$v instanceof Node\Expr\Variable&&$v->name===$name)!==null;
            if(!$contains){continue;}if($n instanceof Node\Arg && !$n->byRef){continue;}
            if($n instanceof Node\Expr\Assign||$n instanceof Node\Expr\AssignOp){if($finder->findFirst([$n->var],static fn(Node $v):bool=>$v instanceof Node\Expr\Variable&&$v->name===$name)===null){continue;}return false;}
            if($n instanceof Node\Expr\MethodCall && !$n->isFirstClassCallable() && $n->var instanceof Node\Expr\Variable && $n->var->name===$name && $n->name instanceof Node\Identifier && strtolower($n->name->name)==='getname'&&$n->args===[]){continue;}
            if($n instanceof Node\Expr\FuncCall && !$n->isFirstClassCallable() && $n->name instanceof Node\Name && strcasecmp($n->name->toString(),'count')===0 && self::ordinaryArgs($n->args,1) && $n->args[0]->value instanceof Node\Expr\PropertyFetch){continue;}
            if(($n instanceof Node\Expr\StaticCall||$n instanceof Node\Expr\FuncCall)&&!$n->isFirstClassCallable()&&self::ordinaryArgs($n->args,1)&&$n->args[0]->value instanceof Node\Expr\Cast\String_){continue;}
            return false;
        }return true;}
}
