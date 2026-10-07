<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use PhpParser\{Node,NodeFinder};

/** Syntax boundary only. Native first, forwarding, and receiving proofs remain mandatory. */
final class LiteralGuardedTupleModelProjection
{
    public array $stages=[];
    private function stage(string $name,bool $passed):bool { $this->stages[]=['stage'=>$name,'passed'=>$passed];return $passed; }

    public function lexical(GuardedStringCastContracts $source,array $file,array $tuple,Node\Expr $value,Node\Expr\Assign $append):?array
    {
        if(!$this->stage('selected literal local row value',$value instanceof Node\Expr\Variable&&is_string($value->name)
            &&$tuple['closure'] instanceof Node\Expr\Closure)) { return null; }
        $name=$value->name;$closure=$tuple['closure'];$finder=new NodeFinder;
        $selected=array_keys(array_filter($append->expr->items,static fn(?Node\ArrayItem $item):bool=>$item?->value===$value));
        if(!$this->stage('one selected row evaluation position',count($selected)===1)){return null;}
        foreach(array_slice($append->expr->items,0,$selected[0]) as $item){
            $earlier=$item->value;
            if(!$this->stage('no effect before selected model row value',
                $earlier instanceof Node\Expr\Variable&&is_string($earlier->name)
                ||$earlier instanceof Node\Scalar\String_||$earlier instanceof Node\Scalar\Int_||$earlier instanceof Node\Scalar\Float_
                ||$earlier instanceof Node\Expr\ConstFetch&&in_array(strtolower($earlier->name->toString()),['true','false','null'],true))){return null;}
        }
        $origins=$finder->find($closure->stmts,static fn(Node $node):bool=>$node instanceof Node\Expr\Assign
            &&$node->var instanceof Node\Expr\Variable&&$node->var->name===$name&&$node->getEndFilePos()<$append->getStartFilePos());
        $origin=count($origins)===1?$origins[0]:null;
        $statement=$origin===null?null:($file['parents'][spl_object_id($origin)]??null);
        $position=$statement===null?false:array_search($statement,$closure->stmts,true);
        if(!$this->stage('one unconditional local producer before the selected append',$origin!==null
            &&$statement instanceof Node\Stmt\Expression&&$position!==false)) { return null; }
        $first=$origin->expr;
        if(!$this->stage('zero-argument first result',$first instanceof Node\Expr\MethodCall
            &&$first->name instanceof Node\Identifier&&strtolower($first->name->name)==='first'
            &&$first->args===[]&&SourceArgumentDeclarationContracts::plain($first))) { return null; }
        $query=$first->var;$operations=[];
        if($query instanceof Node\Expr\MethodCall&&$query->name instanceof Node\Identifier&&strtolower($query->name->name)==='lockforupdate') {
            if(!$this->stage('literal zero-argument forwarded lock',SourceArgumentDeclarationContracts::plain($query)&&$query->args===[])) { return null; }
            $operations['lockForUpdate']=$query;$query=$query->var;
        }
        if($query instanceof Node\Expr\MethodCall&&$query->name instanceof Node\Identifier&&strtolower($query->name->name)==='where') {
            if(!$this->stage('one literal scalar where operation',SourceArgumentDeclarationContracts::plain($query)&&count($query->args)===2
                &&$query->args[0]->value instanceof Node\Scalar\String_&&$query->args[0]->value->value!==''
                &&$query->args[1]->value instanceof Node\Expr\Variable&&is_string($query->args[1]->value->name))) { return null; }
            $operations['where']=$query;$query=$query->var;
        }
        if(!$this->stage('literal zero-argument concrete model query',$query instanceof Node\Expr\StaticCall
            &&$query->class instanceof Node\Name&&!$query->class->isSpecialClassName()
            &&$query->name instanceof Node\Identifier&&strtolower($query->name->name)==='query'
            &&$query->args===[]&&SourceArgumentDeclarationContracts::plain($query))) { return null; }
        $guard=$closure->stmts[$position+1]??null;$condition=$guard instanceof Node\Stmt\If_?$guard->cond:null;
        $guardVariable=null;
        if($condition instanceof Node\Expr\BinaryOp\Identical) {
            foreach([[$condition->left,$condition->right],[$condition->right,$condition->left]] as [$left,$right]) {
                if($left instanceof Node\Expr\Variable&&$left->name===$name&&$right instanceof Node\Expr\ConstFetch&&strtolower($right->name->toString())==='null') { $guardVariable=$left; }
            }
        }
        $throw=$guard instanceof Node\Stmt\If_&&count($guard->stmts)===1&&$guard->stmts[0] instanceof Node\Stmt\Expression?$guard->stmts[0]->expr:null;
        if(!$this->stage('immediate null branch terminates before every selected append',$guardVariable!==null&&$guard->else===null&&$guard->elseifs===[]
            &&$throw instanceof Node\Expr\Throw_&&$guard->getEndFilePos()<$append->getStartFilePos())) { return null; }
        $reads=[];
        foreach($finder->find($closure->stmts,static fn(Node $node):bool=>$node->getStartFilePos()<$append->getStartFilePos()) as $node) {
            if($node instanceof Node\Expr\Eval_||$node instanceof Node\Expr\Include_||$node instanceof Node\Stmt\Global_
                ||$node instanceof Node\FunctionLike||$node instanceof Node\Stmt\ClassLike
                ||$node instanceof Node\Expr\Variable&&!is_string($node->name)
                ||$node instanceof Node\Expr\FuncCall&&(!$node->name instanceof Node\Name||in_array(strtolower($node->name->getLast()),['extract','parse_str'],true))) {
                $this->stage('no unknown local scope mutation',false);return null;
            }
            if($node->getDocComment()!==null&&preg_match('/@(?:phpstan-|psalm-)?var\b/i',$node->getDocComment()->getText())===1) {
                $this->stage('no source local type replacement before selected append',false);return null;
            }
            if(!$node instanceof Node\Expr\Variable||$node->name!==$name||$node===$origin->var||$node===$guardVariable) { continue; }
            if($node->getStartFilePos()<$guard->getEndFilePos()) { $this->stage('no selected local read before completed null guard',false);return null; }
            $parent=$file['parents'][spl_object_id($node)]??null;
            if($parent instanceof Node\Expr\AssignRef||$parent instanceof Node\ClosureUse||$parent instanceof Node\Stmt\Unset_
                ||$parent instanceof Node\Expr\AssignOp||$parent instanceof Node\Expr\Assign&&$parent->var===$node
                ||$parent instanceof Node\ArrayItem&&$parent->byRef
                ||$parent instanceof Node\Stmt\Foreach_&&($parent->valueVar===$node||$parent->keyVar===$node)
                ||$parent instanceof Node\Stmt\Catch_&&$parent->var===$node
                ||$parent instanceof Node\Param&&$parent->var===$node
                ||$parent instanceof Node\Expr\PreInc||$parent instanceof Node\Expr\PostInc||$parent instanceof Node\Expr\PreDec||$parent instanceof Node\Expr\PostDec) {
                $this->stage('no selected local reference escape or rebinding',false);return null;
            }
            // A positional list/array target can rebind the local without any
            // direct Assign(var=Variable). Plain array reads stay by value.
            if($parent instanceof Node\ArrayItem){
                foreach($source->ancestors($node,$file) as $ancestor){
                    if($ancestor instanceof Node\Expr\Assign||$ancestor instanceof Node\Expr\AssignRef){
                        if($ancestor->var->getStartFilePos()<=$node->getStartFilePos()&&$ancestor->var->getEndFilePos()>=$node->getEndFilePos()){
                            $this->stage('no destructuring selected local rebinding',false);return null;
                        }
                        break;
                    }
                    if($ancestor instanceof Node\Stmt\Foreach_){
                        foreach([$ancestor->keyVar,$ancestor->valueVar] as $target){
                            if($target!==null&&$target->getStartFilePos()<=$node->getStartFilePos()&&$target->getEndFilePos()>=$node->getEndFilePos()){
                                $this->stage('no destructuring selected foreach rebinding',false);return null;
                            }
                        }
                        break;
                    }
                    if($ancestor instanceof Node\Stmt){break;}
                }
            }
            if($parent instanceof Node\Arg) {
                $call=$file['parents'][spl_object_id($parent)]??null;
                if(!$this->stage('source plain selected local read argument',$call instanceof Node\Expr\CallLike
                    &&SourceArgumentDeclarationContracts::plain($call)&&!$parent->byRef)) { return null; }
                $reads[]=['argument'=>$parent,'call'=>$call];
            }
        }
        $this->stage('closed guarded literal local model producer syntax',true);
        return ['model'=>$query->class->toString(),'query'=>$query,'first'=>$first,'operations'=>$operations,'reads'=>$reads,
            'sourceSha256'=>$file['hash'],'originSpan'=>SourceArgumentDeclarationContracts::span($origin),
            'nullGuardSpan'=>SourceArgumentDeclarationContracts::span($guard),'appendSpan'=>SourceArgumentDeclarationContracts::span($append),
            'nativeAdmissionClaimed'=>false,'requiredNativeProofs'=>['StandardEloquentFirst declarations and default builder/collection',
                'Physical where/forwarded lock contract and selected scope/macro policy','Every selected read formal is by-value with no outType',
                'Current physical receiving declaration contains the concrete non-null model']];
    }
}
