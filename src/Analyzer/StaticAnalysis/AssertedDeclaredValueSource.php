<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\{Node,NodeFinder,NodeTraverser,ParserFactory,PrettyPrinter\Standard};
use PhpParser\NodeVisitor\NameResolver;

/** Closed lexical origins for a contradictory null assertion; native declaration contracts are separate. */
final class AssertedDeclaredValueSource
{
    public static function inspect(string $bytes,int $start,int $end):?array
    {
        if(strlen($bytes)>2_000_000) { return null; }
        try { $nodes=(new NodeTraverser(new NameResolver))->traverse((new ParserFactory)->createForNewestSupportedVersion()->parse($bytes)??[]); }catch(\PhpParser\Error) { return null; }
        $finder=new NodeFinder;$printer=new Standard;$describe=new SourceNullFacts;
        $classes=$finder->find($nodes,static fn(Node $node):bool=>$node instanceof Node\Stmt\Class_&&isset($node->namespacedName)&&$node->name!==null&&self::contains($node,$start,$end));
        if(count($classes)!==1) { return null; }$class=$classes[0];$methods=array_values(array_filter($class->getMethods(),static fn(Node $node):bool=>self::contains($node,$start,$end)));
        if(count($methods)!==1) { return null; }$method=$methods[0];
        foreach($method->params as $param) { if($param->byRef) { return null; } }
        if($finder->findFirst($method->stmts??[],static fn(Node $node):bool=>($node instanceof Node\Expr\Closure||$node instanceof Node\Expr\ArrowFunction)&&self::contains($node,$start,$end))!==null) { return null; }
        if($method->byRef||$finder->findFirst($method->stmts??[],static fn(Node $node):bool=>$node->getStartFilePos()<$end&&(
            $node instanceof Node\Expr\AssignRef||$node instanceof Node\Stmt\Global_||$node instanceof Node\Stmt\Static_||$node instanceof Node\Expr\Eval_||$node instanceof Node\Expr\Include_
            ||$node instanceof Node\Stmt\Goto_||$node instanceof Node\Expr\Variable&&!is_string($node->name)
            ||$node instanceof Node\Expr\FuncCall&&$node->name instanceof Node\Name&&in_array(strtolower($node->name->toString()),['extract','get_defined_vars'],true)))!==null) { return null; }
        $targets=$finder->find($method->stmts??[],static fn(Node $node):bool=>$node instanceof Node\Expr\StaticCall&&self::span($node)===[$start,$end]&&self::assertion($node,'notNull'));
        if(count($targets)!==1||$targets[0]->args===[]) { return null; }$target=$targets[0];$value=$target->args[0]->value;
        $owners=[$method,...$finder->find($method->stmts??[],static fn(Node $node):bool=>property_exists($node,'stmts')&&is_array($node->stmts))];$previous=null;
        foreach($owners as $owner) { foreach($owner->stmts??[] as $index=>$stmt) { if($stmt instanceof Node\Stmt\Expression&&$stmt->expr===$target&&$index>0) {
            if($previous!==null) { return null; }$previous=$owner->stmts[$index-1];
        } } }
        if($previous===null||!$previous instanceof Node\Stmt\Expression) { return null; }
        $types=$origins=$assignments=[];
        foreach($method->params as $param) { $type=$param->type instanceof Node\NullableType?$param->type->type:$param->type;if($type instanceof Node\Name&&$param->var instanceof Node\Expr\Variable&&is_string($param->var->name)) { $types[$param->var->name]=$type->toString(); } }
        foreach($finder->findInstanceOf($method->stmts??[],Node\Expr\Assign::class) as $assignment) {
            if($assignment->getEndFilePos()>=$start||!$assignment->var instanceof Node\Expr\Variable||!is_string($assignment->var->name)) { continue; }
            $name=$assignment->var->name;$assignments[$name][]=$assignment;$origin=$describe->describe($assignment->expr);if($origin!==null) { $origins[$name]=$origin; }
            if($assignment->expr instanceof Node\Expr\New_&&$assignment->expr->class instanceof Node\Name) { $types[$name]=$assignment->expr->class->toString(); }
        }
        $scope=['owner'=>$class->namespacedName->toString(),'name'=>$method->name->name,'span'=>self::span($method),'nameSpan'=>self::span($method->name),'topLevel'=>false,'types'=>$types,'origins'=>$origins];
        $proof=['sourceSha256'=>hash('sha256',$bytes),'scope'=>$scope,'targetAssertion'=>$describe->describe($target),'targetExpression'=>$describe->describe($value),
            'primarySpan'=>[$start,$end],'nativeAdmissionClaimed'=>false];
        if($value instanceof Node\Expr\PropertyFetch&&$value->name instanceof Node\Identifier&&$previous->expr instanceof Node\Expr\StaticCall&&self::assertion($previous->expr,'same')
            &&count($previous->expr->args)>=2&&$printer->prettyPrintExpr($previous->expr->args[0]->value)===$printer->prettyPrintExpr($value)) {
            $expected=$previous->expr->args[1]->value;
            if(!$expected instanceof Node\Expr\Variable||!is_string($expected->name)||count($assignments[$expected->name]??[])!==1) { return null; }
            $binding=$assignments[$expected->name][0];
            if(!$binding->expr instanceof Node\Expr\FuncCall||!$binding->expr->name instanceof Node\Name||strtolower($binding->expr->name->toString())!=='basename'
                ||count($binding->expr->args)<1||count($binding->expr->args)>2||!self::ordinaryArgs($binding->expr)||$binding->getEndFilePos()>=$previous->getStartFilePos()) { return null; }
            if(!self::dominates($method,$target,$binding,$finder)) { return null; }
            $expectedUses=$finder->find($method->stmts??[],static fn(Node $node):bool=>$node instanceof Node\Expr\Variable&&$node->name===$expected->name&&$node->getStartFilePos()<$end);
            if(count($expectedUses)!==2||$expectedUses[0]!==$binding->var||$expectedUses[1]!==$expected) { return null; }
            // The exact same assertion executes immediately before the notNull call; no getter or mutation is inserted between them.
            $proof+=['kind'=>'generic-equality-string-postcondition','equalityAssertion'=>$describe->describe($previous->expr),'expectedOrigin'=>$describe->describe($binding->expr),
                'expectedLocal'=>$expected->name,'expectedAssignmentSpan'=>self::span($binding),'adjacentSuccessfulAssertion'=>true];
        } elseif($value instanceof Node\Expr\Variable&&is_string($value->name)&&$previous->expr instanceof Node\Expr\Assign&&$previous->expr->var instanceof Node\Expr\Variable
            &&$previous->expr->var->name===$value->name&&$previous->expr->expr instanceof Node\Expr\ArrayDimFetch&&$previous->expr->expr->var instanceof Node\Expr\Variable
            &&is_string($previous->expr->expr->var->name)&&$previous->expr->expr->dim instanceof Node\Scalar\Int_) {
            $collection=$previous->expr->expr->var->name;if(count($assignments[$collection]??[])!==1||count($assignments[$value->name]??[])!==1) { return null; }
            $binding=$assignments[$collection][0];$create=$binding->expr;
            if(!self::dominates($method,$target,$binding,$finder)) { return null; }
            if(!$create instanceof Node\Expr\MethodCall||!$create->name instanceof Node\Identifier||strtolower($create->name->name)!=='create'||count($create->args)>1||!self::ordinaryArgs($create)
                ||!$create->var instanceof Node\Expr\MethodCall||!$create->var->name instanceof Node\Identifier||strtolower($create->var->name->name)!=='count'||count($create->var->args)!==1
                ||!self::ordinaryArgs($create->var)||!$create->var->args[0]->value instanceof Node\Scalar\Int_||$create->var->args[0]->value->value<0
                ||!$create->var->var instanceof Node\Expr\StaticCall||!$create->var->var->class instanceof Node\Name||!$create->var->var->name instanceof Node\Identifier
                ||strtolower($create->var->var->name->name)!=='factory'||$create->var->var->args!==[]||$create->var->var->isFirstClassCallable()) { return null; }
            $writes=$finder->find($method->stmts??[],static function(Node $node)use($collection,$start):bool {
                if($node->getStartFilePos()>=$start) { return false; }
                if(!$node instanceof Node\Expr\Assign&&!$node instanceof Node\Expr\AssignOp&&!$node instanceof Node\Stmt\Unset_&&!$node instanceof Node\ClosureUse&&!$node instanceof Node\Param) { return false; }
                $targets=$node instanceof Node\Stmt\Unset_?$node->vars:[$node->var];foreach($targets as $target) { while($target instanceof Node\Expr\ArrayDimFetch||$target instanceof Node\Expr\PropertyFetch) { $target=$target->var; }
                    if($target instanceof Node\Expr\Variable&&$target->name===$collection) { return true; } }return false;
            });
            if(count($writes)!==1||$writes[0]!==$binding) { return null; }
            $count=$create->var->args[0]->value->value;$key=$previous->expr->expr->dim->value;
            // A concrete empty/out-of-range input is a contradiction, not a declared-element refinement.
            if($count===0||$key<0||$key>=$count) { return null; }
            $proof+=['kind'=>'declared-factory-collection-element','collectionLocal'=>$collection,'selectedLocal'=>$value->name,'literalOffset'=>$previous->expr->expr->dim->value,
                'literalFactoryCount'=>$create->var->args[0]->value->value,'collectionProducer'=>$describe->describe($create),'modelClass'=>$create->var->var->class->toString(),
                'selectedOffsetSpan'=>self::span($previous->expr->expr),'elementReadThroughDeclaredOffsetGet'=>true,'keyPresenceClaimed'=>false];
        } else { return null; }
        // Receiver/expected/selected locals cannot hide a second lexical assignment in another callback scope.
        foreach($assignments as $name=>$writes) { if(count($writes)>1) { unset($proof['scope']['origins'][$name],$proof['scope']['types'][$name]); } }
        return $proof;
    }
    private static function assertion(Node\Expr\StaticCall $call,string $name):bool {
        $required=$name==='same'?2:1;
        // An optional message is evaluated after the asserted value. A call or
        // interpolation there may change a live field before the postcondition.
        return $call->class instanceof Node\Name&&strcasecmp($call->class->toString(),'Testo\\Assert')===0
            &&$call->name instanceof Node\Identifier&&strcasecmp($call->name->name,$name)===0&&!$call->isFirstClassCallable()&&self::ordinaryArgs($call)
            &&count($call->args)>=$required&&count($call->args)<=$required+1
            &&(count($call->args)===$required||$call->args[$required]->value instanceof Node\Scalar\String_);
    }
    private static function ordinaryArgs(Node\Expr\CallLike $call):bool { foreach($call->args as $arg) { if(!$arg instanceof Node\Arg||$arg->byRef||$arg->unpack||$arg->name!==null) { return false; } }return true; }
    private static function dominates(Node\Stmt\ClassMethod $method,Node $target,Node\Expr\Assign $binding,NodeFinder $finder):bool
    {
        foreach([$method,...$finder->find($method->stmts??[],static fn(Node $node):bool=>property_exists($node,'stmts')&&is_array($node->stmts))] as $owner) {
            foreach($owner->stmts??[] as $index=>$stmt) {
                if(!$stmt instanceof Node\Stmt\Expression||$stmt->expr!==$binding) { continue; }
                foreach(array_slice($owner->stmts,$index+1) as $later) { if(self::contains($later,$target->getStartFilePos(),$target->getEndFilePos()+1)) { return true; } }
            }
        }return false;
    }
    private static function contains(Node $node,int $start,int $end):bool { return $node->getStartFilePos()<=$start&&$node->getEndFilePos()+1>=$end; }
    private static function span(Node $node):array { return [$node->getStartFilePos(),$node->getEndFilePos()+1]; }
}
