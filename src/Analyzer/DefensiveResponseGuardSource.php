<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;
use Ichinya\Laramago\Analyzer\DefensiveBoundaryGuardSource as Source;
use PhpParser\{Node,NodeFinder};
final class DefensiveResponseGuardSource
{
    public static function compile(array $nodes):array
    {
        $proofs=[];$finder=new NodeFinder;
        foreach($finder->findInstanceOf($nodes,Node\Stmt\Class_::class) as $class){if(!isset($class->namespacedName)){continue;}
            foreach($class->getMethods() as $scope){$stmts=$scope->stmts??[];if($scope->isStatic()||$scope->byRef||count($stmts)<3||count($scope->params)!==1){continue;}
                $formal=$scope->params[0];if(!$formal->type instanceof Node\Name\FullyQualified || $formal->type->toString()!=='Laratesto\\Testing\\LaravelResponse' || $formal->byRef || $formal->variadic || $formal->default!==null || !$formal->var instanceof Node\Expr\Variable || !is_string($formal->var->name)){continue;}
                $first=$stmts[0] instanceof Node\Stmt\Expression?$stmts[0]->expr:null;$second=$stmts[1] instanceof Node\Stmt\Expression?$stmts[1]->expr:null;$if=$stmts[2];
                if(!$first instanceof Node\Expr\Assign || !$first->var instanceof Node\Expr\Variable || !is_string($first->var->name) || !$first->expr instanceof Node\Expr\MethodCall || $first->expr->isFirstClassCallable()
                    || !$first->expr->var instanceof Node\Expr\Variable || $first->expr->var->name!==$formal->var->name || !$first->expr->name instanceof Node\Identifier || strcasecmp($first->expr->name->name,'inertiaPage')!==0 || $first->expr->args!==[]
                    || !$second instanceof Node\Expr\Assign || !$second->var instanceof Node\Expr\Variable || !is_string($second->var->name) || !$second->expr instanceof Node\Expr\Ternary || !self::predicate($second->expr->cond,$first->var->name)
                    || !$second->expr->if instanceof Node\Expr\BinaryOp\Coalesce || !$second->expr->if->left instanceof Node\Expr\ArrayDimFetch || !$second->expr->if->left->var instanceof Node\Expr\Variable || $second->expr->if->left->var->name!==$first->var->name
                    || !$second->expr->if->left->dim instanceof Node\Scalar\String_ || $second->expr->if->left->dim->value!=='props' || !self::null($second->expr->if->right) || !self::null($second->expr->else)
                    || !$if instanceof Node\Stmt\If_ || $if->else!==null || $if->elseifs!==[] || !$if->cond instanceof Node\Expr\BooleanNot || !self::predicate($if->cond->expr,$second->var->name) || count($if->stmts)!==1
                    || count(array_unique([$formal->var->name,$first->var->name,$second->var->name]))!==3){continue;}
                $throw=$if->stmts[0] instanceof Node\Stmt\Expression?$if->stmts[0]->expr:null;if(!$throw instanceof Node\Expr\Throw_ || !$throw->expr instanceof Node\Expr\New_ || !$throw->expr->class instanceof Node\Name\FullyQualified
                    || !in_array($throw->expr->class->toString(),['RuntimeException','LogicException'],true) || count($throw->expr->args)!==1 || !$throw->expr->args[0] instanceof Node\Arg || !$throw->expr->args[0]->value instanceof Node\Scalar\String_
                    || $throw->expr->args[0]->name!==null || $throw->expr->args[0]->byRef || $throw->expr->args[0]->unpack){continue;}
                $sites=[['kind'=>'predicate','name'=>'is_array','span'=>Source::span($second->expr->cond)],['kind'=>'predicate','name'=>'is_array','span'=>Source::span($if->cond->expr)],['kind'=>'negated-predicate','name'=>'is_array','span'=>Source::span($if->cond)]];
                $predicates=[['name'=>'is_array','span'=>Source::span($second->expr->cond),'argument'=>Source::span($second->expr->cond->args[0]->value)],['name'=>'is_array','span'=>Source::span($if->cond->expr),'argument'=>Source::span($if->cond->expr->args[0]->value)]];
                $starts=[$scope->getStartFilePos()];foreach($scope->getComments() as $c){$starts[]=$c->getStartFilePos();}foreach($scope->attrGroups as $a){$starts[]=$a->getStartFilePos();}
                $proof=['scope'=>['class'=>$class->namespacedName->toString(),'name'=>$scope->name->name,'span'=>Source::span($scope),'startAlternatives'=>$starts],'formal'=>Source::span($formal),'formalName'=>$formal->var->name,
                    'producer'=>Source::span($first->expr),'class'=>'Laratesto\\Testing\\LaravelResponse','guard'=>Source::span($if),'condition'=>Source::span($if->cond),'sites'=>$sites,'predicates'=>$predicates,'exception'=>$throw->expr->class->toString()];
                foreach($sites as $site){$proofs[Source::key($site['span'])]=$proof+['selectedSite'=>$site];}
            }
        }return $proofs;
    }
    private static function predicate(?Node $node,string $name):bool{return $node instanceof Node\Expr\FuncCall && !$node->isFirstClassCallable() && $node->name instanceof Node\Name && strcasecmp($node->name->toString(),'is_array')===0 && count($node->args)===1 && $node->args[0] instanceof Node\Arg && $node->args[0]->name===null && !$node->args[0]->byRef && !$node->args[0]->unpack && $node->args[0]->value instanceof Node\Expr\Variable && $node->args[0]->value->name===$name;}
    private static function null(?Node $node):bool{return $node instanceof Node\Expr\ConstFetch && strtolower($node->name->toString())==='null';}
}
