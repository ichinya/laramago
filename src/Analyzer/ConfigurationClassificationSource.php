<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;
use PhpParser\{Node,NodeFinder};

/** A configuration classification branch contains only another certified defensive guard. */
final class ConfigurationClassificationSource
{
    public static function promote(array $nodes,array $proofs):array
    {
        foreach((new NodeFinder)->findInstanceOf($nodes,Node\Stmt\If_::class) as $outer){
            if($outer->else!==null || $outer->elseifs!==[] || count($outer->stmts)!==2 || !$outer->cond instanceof Node\Expr\BinaryOp\Identical
                || !$outer->cond->left instanceof Node\Expr\BinaryOp\Coalesce || !$outer->cond->left->left instanceof Node\Expr\ArrayDimFetch
                || !$outer->cond->left->left->var instanceof Node\Expr\Variable || !is_string($outer->cond->left->left->var->name)
                || !$outer->cond->left->left->dim instanceof Node\Scalar\String_ || !$outer->cond->left->right instanceof Node\Scalar\String_ || $outer->cond->left->right->value!==''
                || !$outer->cond->right instanceof Node\Scalar\String_ || $outer->cond->right->value==='' || strlen($outer->cond->right->value)>128){continue;}
            $assign=$outer->stmts[0] instanceof Node\Stmt\Expression?$outer->stmts[0]->expr:null;$inner=$outer->stmts[1];
            if(!$assign instanceof Node\Expr\Assign || !$assign->var instanceof Node\Expr\Variable || !is_string($assign->var->name)
                || !$assign->expr instanceof Node\Expr\BinaryOp\Coalesce || !$assign->expr->left instanceof Node\Expr\ArrayDimFetch
                || !$assign->expr->left->var instanceof Node\Expr\Variable || $assign->expr->left->var->name!==$outer->cond->left->left->var->name
                || !$assign->expr->left->dim instanceof Node\Scalar\String_ || $assign->expr->left->dim->value===$outer->cond->left->left->dim->value
                || !$assign->expr->right instanceof Node\Expr\ConstFetch || strtolower($assign->expr->right->name->toString())!=='null' || !$inner instanceof Node\Stmt\If_){continue;}
            $proof=null;foreach($proofs as $candidate){if($candidate['guard']!==DefensiveBoundaryGuardSource::span($inner)){continue;}foreach($candidate['predicates'] as $predicate){
                if($predicate['name']==='is_array'
                    && $predicate['origin']['family']==='configuration' && $predicate['argument']===self::selectedVariable($inner,$assign->var->name)){$proof=$candidate;break 2;}
            }}
            if($proof===null || !in_array($proof['rejection']['kind'],['append-diagnostic','throw-builtin-constructor'],true)){continue;}
            $proof['guard']=DefensiveBoundaryGuardSource::span($outer);$proof['condition']=DefensiveBoundaryGuardSource::span($outer->cond);$proof['sites']=[['kind'=>'condition','span'=>$proof['condition']]];
            unset($proof['selectedSite']);$proof['classification']=['record'=>$outer->cond->left->left->var->name,'field'=>$outer->cond->left->left->dim->value,'literal'=>$outer->cond->right->value,'nestedGuard'=>DefensiveBoundaryGuardSource::span($inner),'policy'=>'configuration classification used only to validate a dependent field'];
            $proofs[DefensiveBoundaryGuardSource::key($proof['condition'])]=$proof+['selectedSite'=>$proof['sites'][0]];
        }return $proofs;
    }
    private static function selectedVariable(Node\Stmt\If_ $inner,string $name):?array
    {
        $call=(new NodeFinder)->findFirst([$inner->cond],static fn(Node $n):bool=>$n instanceof Node\Expr\FuncCall && !$n->isFirstClassCallable() && $n->name instanceof Node\Name
            && strcasecmp($n->name->toString(),'is_array')===0 && count($n->args)===1 && $n->args[0] instanceof Node\Arg && $n->args[0]->value instanceof Node\Expr\Variable && $n->args[0]->value->name===$name);
        return $call===null?null:DefensiveBoundaryGuardSource::span($call->args[0]->value);
    }
}
