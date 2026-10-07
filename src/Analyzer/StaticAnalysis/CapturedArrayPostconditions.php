<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\{Node,NodeFinder,NodeTraverser,ParserFactory,PrettyPrinter\Standard};
use PhpParser\NodeVisitor\NameResolver;

/** Current lexical storage relationships. Native admission is a separate, fresh step. */
final class CapturedArrayPostconditions
{
    public static function inspect(string $bytes,string $code,int $start,int $end):?array
    {
        if(strlen($bytes)>2_000_000) { return null; }
        try { $nodes=(new NodeTraverser(new NameResolver))->traverse((new ParserFactory)->createForNewestSupportedVersion()->parse($bytes)??[]); }
        catch(\PhpParser\Error) { return null; }
        $finder=new NodeFinder;$printer=new Standard;$describe=new SourceNullFacts;
        $classes=$finder->find($nodes,static fn(Node $node):bool=>$node instanceof Node\Stmt\Class_&&$node->name!==null&&isset($node->namespacedName)&&self::contains($node,$start,$end));
        if(count($classes)!==1) { return null; }$class=$classes[0];
        $methods=array_values(array_filter($class->getMethods(),static fn(Node $node):bool=>self::contains($node,$start,$end)));
        if(count($methods)!==1) { return null; }$method=$methods[0];
        if($method->byRef||$method->isStatic()) { return null; }
        $danger=$finder->findFirst($method->stmts??[],static fn(Node $node):bool=>$node->getStartFilePos()<$end&&(
            $node instanceof Node\Expr\AssignRef||$node instanceof Node\Stmt\Global_||$node instanceof Node\Stmt\Static_
            ||$node instanceof Node\Expr\Eval_||$node instanceof Node\Expr\Include_||$node instanceof Node\Stmt\Goto_
            ||$node instanceof Node\Expr\Variable&&!is_string($node->name)
            ||$node instanceof Node\Expr\FuncCall&&$node->name instanceof Node\Name&&in_array(strtolower($node->name->toString()),['extract','get_defined_vars'],true)));
        if($danger!==null) { return null; }
        if($code==='possibly-null-array-index') {
            $targets=$finder->find($method->stmts??[],static fn(Node $node):bool=>$node instanceof Node\Expr\FuncCall&&self::span($node)===[$start,$end]
                &&$node->name instanceof Node\Name&&strtolower($node->name->toString())==='array_key_last'&&count($node->args)===1&&self::ordinaryArg($node->args[0])
                &&$node->args[0]->value instanceof Node\Expr\Variable&&is_string($node->args[0]->value->name));
            if(count($targets)!==1) { return null; }$target=$targets[0];$array=$target->args[0]->value->name;$kind='flag-proves-earlier-append';$integerKey=null;
        } elseif($code==='possibly-undefined-int-array-index') {
            $targets=$finder->find($method->stmts??[],static fn(Node $node):bool=>$node instanceof Node\Expr\ArrayDimFetch&&$node->dim instanceof Node\Scalar\Int_
                &&self::span($node->dim)===[$start,$end]&&$node->var instanceof Node\Expr\Variable&&is_string($node->var->name));
            if(count($targets)!==1) { return null; }$target=$targets[0];$array=$target->var->name;$integerKey=$target->dim->value;$kind='normal-continuation-after-callback-key-write';
        } else { return null; }
        $initializers=$finder->find($method->stmts??[],static fn(Node $node):bool=>$node instanceof Node\Expr\Assign&&$node->getEndFilePos()<$start
            &&$node->var instanceof Node\Expr\Variable&&$node->var->name===$array);
        if(count($initializers)!==1||!$initializers[0]->expr instanceof Node\Expr\Array_||$initializers[0]->expr->items!==[]) { return null; }$initial=$initializers[0];
        foreach($method->params as $parameter) { if($parameter->var instanceof Node\Expr\Variable&&$parameter->var->name===$array) { return null; } }
        $callbacks=$finder->find($method->stmts??[],static fn(Node $node):bool=>$node instanceof Node\Expr\Closure&&$node->getEndFilePos()<$start&&self::captures($node,$array));
        if($callbacks===[]||count($callbacks)>8) { return null; }$witnesses=[];$matching=[];$flag=null;$assertion=null;
        foreach($callbacks as $callback) {
            if($callback->getStartFilePos()<$initial->getEndFilePos()||$callback->byRef||$callback->attrGroups!==[]||$callback->getDocComment()!==null||count($callback->params)!==1
                ||!$callback->params[0]->var instanceof Node\Expr\Variable||!is_string($callback->params[0]->var->name)||$callback->params[0]->byRef||$callback->params[0]->variadic
                ||$callback->params[0]->default!==null||$callback->params[0]->attrGroups!==[]) { return null; }
            if($finder->findFirst($callback->stmts,static fn(Node $node):bool=>$node instanceof Node\Expr\Yield_||$node instanceof Node\Expr\YieldFrom||$node instanceof Node\Expr\Exit_)!==null) { return null; }
            $parameter=$callback->params[0]->var->name;if(in_array($parameter,[$array,'GLOBALS','this'],true)) { return null; }
            if($kind==='flag-proves-earlier-append') {
                if(count($callbacks)!==1||count($callback->uses)!==3||count($callback->stmts)!==2||!$callback->params[0]->type instanceof Node\Identifier
                    ||strtolower($callback->params[0]->type->name)!=='string'||!$callback->returnType instanceof Node\Identifier||strtolower($callback->returnType->name)!=='void') { return null; }
                $append=$callback->stmts[0];$condition=$callback->stmts[1];
                if(!$append instanceof Node\Stmt\Expression||!$append->expr instanceof Node\Expr\Assign||!self::arrayWrite($append->expr->var,$array,null)
                    ||!$append->expr->expr instanceof Node\Expr\Variable||$append->expr->expr->name!==$parameter||!$condition instanceof Node\Stmt\If_
                    ||$condition->else!==null||$condition->elseifs!==[]||!$condition->cond instanceof Node\Expr\BinaryOp\BooleanAnd
                    ||!$condition->cond->left instanceof Node\Expr\BooleanNot||!$condition->cond->left->expr instanceof Node\Expr\Variable
                    ||!is_string($condition->cond->left->expr->name)||!$condition->cond->right instanceof Node\Expr\BinaryOp\Identical
                    ||!$condition->cond->right->left instanceof Node\Expr\Variable||$condition->cond->right->left->name!==$parameter
                    ||!$condition->cond->right->right instanceof Node\Expr\Variable||!is_string($condition->cond->right->right->name)||count($condition->stmts)!==2) { return null; }
                $flag=$condition->cond->left->expr->name;$selector=$condition->cond->right->right->name;
                if(count(array_unique([$array,$flag,$selector,$parameter]))!==4||!self::captures($callback,$flag)||!self::captures($callback,$selector,false)) { return null; }
                foreach($method->params as $parameterDeclaration) { if($parameterDeclaration->var instanceof Node\Expr\Variable&&$parameterDeclaration->var->name===$flag) { return null; } }
                foreach($callback->uses as $use) { if(!in_array($use->var->name,[$array,$flag,$selector],true)) { return null; } }
                $write=$condition->stmts[0];$throw=$condition->stmts[1];
                if(!$write instanceof Node\Stmt\Expression||!$write->expr instanceof Node\Expr\Assign||!$write->expr->var instanceof Node\Expr\Variable||$write->expr->var->name!==$flag
                    ||!self::constant($write->expr->expr,'true')||!$throw instanceof Node\Stmt\Expression||!$throw->expr instanceof Node\Expr\Throw_||!$throw->expr->expr instanceof Node\Expr\New_
                    ||!$throw->expr->expr->class instanceof Node\Name||count($throw->expr->expr->args)>1) { return null; }
                foreach($throw->expr->expr->args as $arg) { if(!self::ordinaryArg($arg)||$finder->findFirst($arg->value,static fn(Node $node):bool=>$node instanceof Node\Expr\CallLike
                    ||$node instanceof Node\Expr\Assign||$node instanceof Node\Expr\PropertyFetch||$node instanceof Node\Expr\StaticPropertyFetch
                    ||$node instanceof Node\Expr\Variable&&!in_array($node->name,[$parameter,$selector],true))!==null) { return null; } }
                $flags=$finder->find($method->stmts??[],static fn(Node $node):bool=>$node instanceof Node\Expr\Assign&&$node->getEndFilePos()<$start&&$node->var instanceof Node\Expr\Variable&&$node->var->name===$flag);
                if(count($flags)!==2||count(array_filter($flags,static fn(Node $node):bool=>$node->getEndFilePos()<$callback->getStartFilePos()&&self::constant($node->expr,'false')))!==1) { return null; }
                $assertions=$finder->find($method->stmts??[],static fn(Node $node):bool=>$node instanceof Node\Expr\StaticCall&&$node->getStartFilePos()>$callback->getEndFilePos()&&$node->getEndFilePos()<$start
                    &&$node->class instanceof Node\Name&&strcasecmp($node->class->toString(),'Testo\\Assert')===0&&$node->name instanceof Node\Identifier&&strtolower($node->name->name)==='true'
                    &&count($node->args)>=1&&self::ordinaryArg($node->args[0])&&$node->args[0]->value instanceof Node\Expr\Variable&&$node->args[0]->value->name===$flag);
                if(count($assertions)!==1||!self::sameSequentialBlock($method,$assertions[0],$target)) { return null; }$assertion=$assertions[0];
                $allowedFlag=[];foreach($flags as $assignment) { $allowedFlag[]=self::span($assignment->var); }
                $allowedFlag[]=self::span($condition->cond->left->expr);$allowedFlag[]=self::span($assertion->args[0]->value);
                foreach($callback->uses as $use) { if($use->var->name===$flag) { $allowedFlag[]=self::span($use->var); } }
                foreach($finder->findInstanceOf($method->stmts??[],Node\Expr\Variable::class) as $variable) { if($variable->getStartFilePos()<$end&&$variable->name===$flag&&!in_array(self::span($variable),$allowedFlag,true)) { return null; } }
                $writtenKey=null;
            } else {
                if(count($callback->uses)!==1||count($callback->stmts)!==2||!$callback->stmts[0] instanceof Node\Stmt\Expression||!$callback->stmts[0]->expr instanceof Node\Expr\Assign
                    ||!$callback->stmts[0]->expr->var instanceof Node\Expr\ArrayDimFetch||!$callback->stmts[0]->expr->var->dim instanceof Node\Scalar\Int_
                    ||!$callback->stmts[1] instanceof Node\Stmt\Return_||$callback->stmts[1]->expr===null) { return null; }
                $assignment=$callback->stmts[0]->expr;$writtenKey=$assignment->var->dim->value;
                if(!self::arrayWrite($assignment->var,$array,$writtenKey)||$finder->findFirst([$assignment->expr,$callback->stmts[1]->expr],static fn(Node $node):bool=>$node instanceof Node\Expr\Assign
                    ||$node instanceof Node\Expr\AssignRef||$node instanceof Node\Expr\Closure||$node instanceof Node\Expr\ArrowFunction||$node instanceof Node\Expr\Variable&&in_array($node->name,[$array,'GLOBALS'],true))!==null) { return null; }
            }
            $calls=$finder->find($method->stmts??[],static fn(Node $node):bool=>$node instanceof Node\Expr\CallLike&&!$node instanceof Node\Expr\New_
                &&array_filter($node->args,static fn($arg):bool=>$arg instanceof Node\Arg&&$arg->value===$callback)!==[]);
            if(count($calls)!==1||!$calls[0] instanceof Node\Expr\MethodCall||!$calls[0]->name instanceof Node\Identifier||$calls[0]->isFirstClassCallable()) { return null; }
            $call=$calls[0];$argument=null;foreach($call->args as $position=>$arg) { if(!self::ordinaryArg($arg)) { return null; }if($arg->value===$callback) { $argument=$position; } }
            if($argument===null||$kind==='normal-continuation-after-callback-key-write'&&!self::sameSequentialBlock($method,$call,$target)) { return null; }
            if(!self::initializerDominates($method,$initial,$call)) { return null; }
            $witness=['callbackSpan'=>self::span($callback),'callbackCanonicalSha256'=>hash('sha256',$printer->prettyPrintExpr($callback)),
                'parameterName'=>$parameter,'parameterType'=>$callback->params[0]->type===null?null:$printer->prettyPrint([$callback->params[0]->type]),
                'callbackReturnType'=>$callback->returnType===null?null:$printer->prettyPrint([$callback->returnType]),'writtenIntegerKey'=>$writtenKey,
                'invocation'=>$describe->describe($call),'argumentIndex'=>$argument];
            $witnesses[]=$witness;if($kind==='flag-proves-earlier-append'||$writtenKey===$integerKey) { $matching[]=$witness; }
        }
        if(count($matching)!==1) { return null; }
        $receiver=$matching[0]['invocation']['receiver']??null;
        if($receiver===null||$receiver['kind']!=='variable'||in_array($receiver['name'],['this',$array,$flag,'GLOBALS'],true)) { return null; }
        $receiverName=$receiver['name'];$receiverAssignments=$finder->find($method->stmts??[],static fn(Node $node):bool=>$node instanceof Node\Expr\Assign
            &&$node->getStartFilePos()<$end&&$node->var instanceof Node\Expr\Variable&&$node->var->name===$receiverName);
        if(count($receiverAssignments)!==1||!$receiverAssignments[0]->expr instanceof Node\Expr\New_||!$receiverAssignments[0]->expr->class instanceof Node\Name
            ||$receiverAssignments[0]->getEndFilePos()>=$matching[0]['invocation']['span'][0]) { return null; }
        foreach($method->params as $parameter) { if($parameter->var instanceof Node\Expr\Variable&&$parameter->var->name===$receiverName) { return null; } }
        foreach($finder->find($method->stmts??[],static fn(Node $node):bool=>$node instanceof Node\Expr\AssignOp||$node instanceof Node\Expr\PreInc
            ||$node instanceof Node\Expr\PostInc||$node instanceof Node\Expr\PreDec||$node instanceof Node\Expr\PostDec||$node instanceof Node\Stmt\Unset_
            ||$node instanceof Node\Param||$node instanceof Node\ClosureUse) as $mutation) {
            if($mutation->getStartFilePos()>=$end) { continue; }$targets=$mutation instanceof Node\Stmt\Unset_?$mutation->vars:[$mutation->var];
            foreach($targets as $variable) { if($variable instanceof Node\Expr\Variable&&$variable->name===$receiverName) { return null; } }
        }
        foreach($finder->find($method->stmts??[],static fn(Node $node):bool=>$node instanceof Node\Expr\Assign||$node instanceof Node\Expr\AssignOp
            ||$node instanceof Node\Expr\PreInc||$node instanceof Node\Expr\PostInc||$node instanceof Node\Expr\PreDec||$node instanceof Node\Expr\PostDec
            ||$node instanceof Node\Stmt\Unset_) as $mutation) {
            if($mutation->getStartFilePos()>=$end) { continue; }
            $targets=$mutation instanceof Node\Stmt\Unset_?$mutation->vars:[$mutation->var];
            foreach($targets as $mutated) { $root=$mutated;while($root instanceof Node\Expr\ArrayDimFetch||$root instanceof Node\Expr\PropertyFetch) { $root=$root->var; }
                if(!$root instanceof Node\Expr\Variable||$root->name!==$array) { continue; }
                if($mutation===$initial) { continue; }
                if(!$mutation instanceof Node\Expr\Assign||count(array_filter($callbacks,static fn(Node\Expr\Closure $callback):bool=>$callback->stmts[0] instanceof Node\Stmt\Expression
                    &&$callback->stmts[0]->expr===$mutation))!==1) { return null; }
            }
        }
        $allowed=[self::span($initial->var)];$builtins=[];
        foreach($callbacks as $callback) { foreach($callback->uses as $use) { if($use->var->name===$array) { $allowed[]=self::span($use->var); } } }
        foreach($finder->findInstanceOf($method->stmts??[],Node\Expr\ArrayDimFetch::class) as $access) {
            if($access->getStartFilePos()>=$end||!$access->var instanceof Node\Expr\Variable||$access->var->name!==$array) { continue; }
            $isWrite=$finder->findFirst($method->stmts??[],static fn(Node $node):bool=>($node instanceof Node\Expr\Assign||$node instanceof Node\Expr\AssignOp)&&$node->var===$access)!==null;
            if($isWrite&&count(array_filter($callbacks,static fn(Node $callback):bool=>self::contains($callback,$access->getStartFilePos(),$access->getEndFilePos()+1)))!==1) { return null; }
            $allowed[]=self::span($access->var);
        }
        foreach($finder->findInstanceOf($method->stmts??[],Node\Expr\FuncCall::class) as $call) {
            if($call->getStartFilePos()>=$end||!$call->name instanceof Node\Name) { continue; }$name=strtolower($call->name->toString());
            foreach($call->args as $position=>$arg) { if(!$arg instanceof Node\Arg||!$arg->value instanceof Node\Expr\Variable||$arg->value->name!==$array) { continue; }
                if(!self::ordinaryArg($arg)||!in_array([$name,$position],[['array_search',1],['array_key_last',0]],true)) { return null; }
                $allowed[]=self::span($arg->value);$builtins[$name][]=$position;
            }
        }
        foreach($finder->findInstanceOf($method->stmts??[],Node\Expr\Variable::class) as $variable) { if($variable->getStartFilePos()<$end&&$variable->name===$array&&!in_array(self::span($variable),$allowed,true)) { return null; } }
        return ['kind'=>$kind,'scope'=>['owner'=>$class->namespacedName->toString(),'name'=>$method->name->name,'span'=>self::span($method),'nameSpan'=>self::span($method->name),'topLevel'=>false,
                'types'=>[$receiverName=>$receiverAssignments[0]->expr->class->toString()],'origins'=>[$receiverName=>$describe->describe($receiverAssignments[0]->expr)]],
            'sourceSha256'=>hash('sha256',$bytes),'arrayLocal'=>$array,'initializerSpan'=>self::span($initial),'sourceStorageHasNoAliases'=>true,
            'targetSpan'=>[$start,$end],'selectedCallback'=>$matching[0],'allClosedArrayCallbacks'=>$witnesses,'arrayReadBuiltinFormals'=>$builtins,
            'flagLocal'=>$flag,'flagAssertion'=>$assertion===null?null:$describe->describe($assertion),'integerKey'=>$integerKey,
            'possibleCallbackDispatchSufficient'=>$kind==='flag-proves-earlier-append','guaranteedDispatchClaimed'=>false,'nativeAdmissionClaimed'=>false];
    }
    public static function directDispatcher(string $bytes,array $methodSpan,string $formalName):?array
    {
        if(strlen($bytes)>2_000_000) { return null; }
        try { $nodes=(new NodeTraverser(new NameResolver))->traverse((new ParserFactory)->createForNewestSupportedVersion()->parse($bytes)??[]); }catch(\PhpParser\Error) { return null; }
        $finder=new NodeFinder;$methods=$finder->find($nodes,static fn(Node $node):bool=>$node instanceof Node\Stmt\ClassMethod&&self::span($node)===$methodSpan);
        if(count($methods)!==1||$methods[0]->byRef||$methods[0]->isStatic()||$methods[0]->getStmts()===null) { return null; }$method=$methods[0];$parameter=null;
        foreach($method->params as $param) { if($param->var instanceof Node\Expr\Variable&&$param->var->name===$formalName) { if($parameter!==null||$param->byRef||$param->variadic||$param->default!==null
            ||!$param->type instanceof Node\Name||strcasecmp($param->type->toString(),'Closure')!==0) { return null; }$parameter=$param; } }
        if($parameter===null) { return null; }$invocations=$finder->find($method->stmts,static fn(Node $node):bool=>$node instanceof Node\Expr\FuncCall&&$node->name instanceof Node\Expr\Variable&&$node->name->name===$formalName);
        if(count($invocations)!==1||$invocations[0]->isFirstClassCallable()) { return null; }$call=$invocations[0];$index=null;
        foreach($method->stmts as $position=>$statement) { if($statement instanceof Node\Stmt\Expression&&$statement->expr instanceof Node\Expr\Assign&&$statement->expr->expr===$call
            &&$statement->expr->var instanceof Node\Expr\Variable&&$statement->expr->var->name!==$formalName) { $index=$position; } }
        if($index===null) { return null; }
        foreach(array_slice($method->stmts,0,$index) as $statement) { if($finder->findFirst($statement,static fn(Node $node):bool=>$node instanceof Node\Stmt\Return_||$node instanceof Node\Expr\Yield_
            ||$node instanceof Node\Expr\YieldFrom||$node instanceof Node\Expr\Exit_||$node instanceof Node\Stmt\Goto_||$node instanceof Node\Expr\Eval_||$node instanceof Node\Expr\Include_
            ||$node instanceof Node\Stmt\TryCatch||$node instanceof Node\Stmt\Global_||$node instanceof Node\Stmt\Static_||$node instanceof Node\Expr\AssignRef)!==null) { return null; } }
        $allowed=[self::span($parameter->var),self::span($call->name)];
        foreach($finder->findInstanceOf($method,Node\Expr\Variable::class) as $variable) { if($variable->name===$formalName&&!in_array(self::span($variable),$allowed,true)) { return null; } }
        if($finder->findFirst($method->stmts,static fn(Node $node):bool=>$node instanceof Node\Stmt\TryCatch||$node instanceof Node\Stmt\Goto_||$node instanceof Node\Expr\AssignRef
            ||$node instanceof Node\Expr\Yield_||$node instanceof Node\Expr\YieldFrom||$node instanceof Node\Expr\Variable&&!is_string($node->name))!==null) { return null; }
        foreach($call->args as $arg) { if(!self::ordinaryArg($arg)) { return null; } }
        return ['sourceSha256'=>hash('sha256',$bytes),'dispatcherSpan'=>self::span($method),'formalName'=>$formalName,'formalSpan'=>self::span($parameter),
            'formalTypeSpan'=>self::span($parameter->type),'formalNameSpan'=>self::span($parameter->var),'directInvocationSpan'=>self::span($call),
            'onlyFormalUseIsDirectInvocation'=>true,'invocationDominatesNormalReturn'=>true];
    }
    private static function sameSequentialBlock(Node\Stmt\ClassMethod $method,Node $first,Node $second):bool
    {
        $finder=new NodeFinder;$owners=[$method,...$finder->find($method->stmts??[],static fn(Node $node):bool=>property_exists($node,'stmts')&&is_array($node->stmts))];
        foreach($owners as $owner) { $a=$b=null;foreach($owner->stmts??[] as $position=>$statement) { if(self::contains($statement,$first->getStartFilePos(),$first->getEndFilePos()+1)) { $a=$position; }
            if(self::contains($statement,$second->getStartFilePos(),$second->getEndFilePos()+1)) { $b=$position; } }
            if($a===null||$b===null||$a>=$b) { continue; }
            // Both expressions must be in ordinary expressions at this level, not guarded inner branches.
            if(!$owner->stmts[$a] instanceof Node\Stmt\Expression||!$owner->stmts[$b] instanceof Node\Stmt\Expression) { continue; }
            $firstExpression=$owner->stmts[$a]->expr;
            if($firstExpression!==$first&&(!$firstExpression instanceof Node\Expr\Assign||$firstExpression->expr!==$first)) { continue; }
            return true;
        }return false;
    }
    private static function initializerDominates(Node\Stmt\ClassMethod $method,Node\Expr\Assign $initial,Node $call):bool
    {
        $finder=new NodeFinder;$owners=[$method,...$finder->find($method->stmts??[],static fn(Node $node):bool=>property_exists($node,'stmts')&&is_array($node->stmts))];
        foreach($owners as $owner) { $a=$b=null;foreach($owner->stmts??[] as $index=>$statement) {
            if($statement instanceof Node\Stmt\Expression&&$statement->expr===$initial) { $a=$index; }
            if(self::contains($statement,$call->getStartFilePos(),$call->getEndFilePos()+1)) { $b=$index; }
        }if($a!==null&&$b!==null&&$a<$b) { return true; } }return false;
    }
    private static function captures(Node\Expr\Closure $callback,string $name,bool $byRef=true):bool { return count(array_filter($callback->uses,static fn(Node\ClosureUse $use):bool=>$use->var->name===$name&&$use->byRef===$byRef))===1; }
    private static function arrayWrite(Node $node,string $array,?int $key):bool { return $node instanceof Node\Expr\ArrayDimFetch&&$node->var instanceof Node\Expr\Variable&&$node->var->name===$array
        &&($key===null?$node->dim===null:$node->dim instanceof Node\Scalar\Int_&&$node->dim->value===$key); }
    private static function ordinaryArg(mixed $arg):bool { return $arg instanceof Node\Arg&&!$arg->byRef&&!$arg->unpack&&$arg->name===null; }
    private static function constant(Node $node,string $name):bool { return $node instanceof Node\Expr\ConstFetch&&strtolower($node->name->toString())===$name; }
    private static function contains(Node $node,int $start,int $end):bool { return $node->getStartFilePos()<=$start&&$node->getEndFilePos()+1>=$end; }
    private static function span(Node $node):array { return [$node->getStartFilePos(),$node->getEndFilePos()+1]; }
}
