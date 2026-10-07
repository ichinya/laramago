<?php
declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ConditionalArrayGuards;
use PhpParser\{Node,NodeFinder,NodeTraverser,ParserFactory};
use PhpParser\NodeVisitor\NameResolver;

/** Source candidates only. No analyzer registration, type replacement, or issue removal. */
final class ValidatedSortedArrayProofs
{
    private array $helpers=[];
    private array $reads=[];
    private int $steps=0;
    public function inspect(string $bytes):array {
        if(strlen($bytes)>1024*1024) { return ['refusal'=>'source-size']; }
        $nodes=(new NodeTraverser(new NameResolver))->traverse((new ParserFactory)->createForNewestSupportedVersion()->parse($bytes)??[]);
        $finder=new NodeFinder; $results=[];
        foreach($finder->find($nodes,static fn(Node $n):bool=>$n instanceof Node\Stmt\Function_||$n instanceof Node\Stmt\ClassMethod) as $scope) {
            if($finder->findFirst($scope->stmts??[],static fn(Node $n):bool=>$n instanceof Node\Expr\FuncCall&&$n->name instanceof Node\Name&&strtolower($n->name->getLast())==='usort')===null) { continue; }
            $this->helpers=$this->reads=[];$this->steps=0;$owner=null;
            foreach($finder->findInstanceOf($nodes,Node\Stmt\Class_::class) as $class) { if(in_array($scope,$class->getMethods(),true)) { $owner=$class->namespacedName?->toString();break; } }
            try { $results[]=$this->scope($scope)+['owner'=>$owner,'name'=>$scope instanceof Node\Stmt\Function_?$scope->namespacedName->toString():$scope->name->name,'scopeSpan'=>self::span($scope),'sourceDeclarationStarts'=>array_values(array_unique([$scope->getStartFilePos(),$scope->getDocComment()?->getStartFilePos()??$scope->getStartFilePos()])),
                'nameSpan'=>self::span($scope->name),'formalSpan'=>self::span($scope->params[0]),'formalVariableSpan'=>self::span($scope->params[0]->var),'sourceSha256'=>hash('sha256',$bytes)]; }
            catch(WorkerIndependentArrayProofRefusal $error) { $results[]=['owner'=>$owner,'name'=>$scope->name->name,'scopeSpan'=>self::span($scope),'sourceSha256'=>hash('sha256',$bytes),'sourceStructureAccepted'=>false,'refusal'=>$error->getMessage()]; }
        }
        return ['sourceOnly'=>true,'policy'=>'PHPStan default lexical flow; runtime hidden alias/destructor safety is not claimed','candidates'=>$results];
    }
    private function scope(Node\FunctionLike $scope):array {
        $finder=new NodeFinder;
        if($scope->byRef||count($scope->getParams())!==1) { $this->refuse('one by-value array parameter required'); }
        $parameter=$scope->getParams()[0];$root=self::variable($parameter->var);
        if($root===null||$parameter->byRef||$parameter->variadic||$parameter->default!==null||!$parameter->type instanceof Node\Identifier||strtolower($parameter->type->name)!=='array') { $this->refuse('native array formal mismatch'); }
        if($finder->findFirst([$scope],static fn(Node $n):bool=>$n instanceof Node\Expr\AssignRef||$n instanceof Node\Stmt\Global_||$n instanceof Node\Stmt\Static_||$n instanceof Node\Expr\Eval_||$n instanceof Node\Expr\Include_||$n instanceof Node\Expr\Yield_||$n instanceof Node\Expr\YieldFrom||$n instanceof Node\Stmt\Goto_||$n instanceof Node\Stmt\TryCatch||$n instanceof Node\Stmt\Break_||$n instanceof Node\Stmt\Continue_||$n instanceof Node\Expr\Variable&&!is_string($n->name)||$n instanceof Node\Arg&&$n->byRef||$n instanceof Node\Param&&($n->byRef||$n->flags!==0)||$n instanceof Node\ArrayItem&&$n->byRef||$n instanceof Node\Expr\ClosureUse&&$n->byRef||preg_match('/@(?:phpstan-|psalm-|mago-)?var\b/i',$n->getDocComment()?->getText()??'')===1)!==null) { $this->refuse('reference or unsupported control effect'); }
        $statements=$scope->getStmts()??[];
        $return=array_pop($statements);$sorting=array_pop($statements);$loop=array_pop($statements);
        if(!$return instanceof Node\Stmt\Return_||self::variable($return->expr)!==$root) { $this->refuse('unchanged direct return required'); }
        $sort=$sorting instanceof Node\Stmt\Expression?$sorting->expr:null;
        if(!$sort instanceof Node\Expr\FuncCall||!$sort->name instanceof Node\Name||strtolower($sort->name->getLast())!=='usort'||count($sort->args)!==2||!self::plain($sort->args)||self::variable($sort->args[0]->value)!==$root) { $this->refuse('exact native sort form required'); }
        if(!$loop instanceof Node\Stmt\Foreach_||$loop->byRef||$loop->keyVar!==null||self::variable($loop->expr)!==$root||self::variable($loop->valueVar)===null||self::variable($loop->valueVar)===$root) { $this->refuse('exhaustive by-value foreach immediately before sort required'); }
        $row=self::variable($loop->valueVar); $locals=[];$sets=[];
        foreach($statements as $statement) {
            $assignment=$statement instanceof Node\Stmt\Expression?$statement->expr:null;
            $name=$assignment instanceof Node\Expr\Assign?self::variable($assignment->var):null;
            if($name===null||in_array($name,[$root,$row],true)||!$assignment->expr instanceof Node\Expr\Array_||$assignment->expr->items!==[]||isset($sets[$name])) { $this->refuse('only fresh empty bookkeeping arrays before loop'); }
            $sets[$name]=true;
        }
        $body=$loop->stmts; $guard=array_shift($body);
        if(!self::throwing($guard)) { $this->refuse('invalid row branch must throw'); }
        $checks=self::orParts($guard->cond);$array=false;$exists=[];$fields=[];
        foreach($checks as $check) {
            if(self::notCall($check,'is_array',[$row])) { $array=true;continue; }
            if(!$array) { $this->refuse('array check must dominate field reads'); }
            if($check instanceof Node\Expr\BooleanNot&&$check->expr instanceof Node\Expr\FuncCall&&self::call($check->expr,'array_key_exists')&&count($check->expr->args)===2&&self::plain($check->expr->args)
                &&$check->expr->args[0]->value instanceof Node\Scalar\String_&&self::variable($check->expr->args[1]->value)===$row) { $exists[$check->expr->args[0]->value->value]=true;continue; }
            $scalar=$this->scalarCheck($check,$row);
            if($scalar!==null) { $fields[$scalar[0]]=$scalar[1];$exists[$scalar[0]]=true;continue; }
            if($check instanceof Node\Expr\BinaryOp\BooleanAnd&&$check->left instanceof Node\Expr\BinaryOp\NotIdentical&&self::isNull($check->left->right)) {
                $key=self::field($check->left->left,$row);$right=self::orParts($check->right);$first=array_shift($right);
                if($key!==null&&isset($exists[$key])&&self::notCall($first,'is_int',[[$row,$key]])) {
                    foreach($right as $bound) { if(!$this->bound($bound,$row,[$key=>'int'])) { $this->refuse('unsupported nullable integer bound'); } }
                    $fields[$key]='int|null';continue;
                }
            }
            if($this->bound($check,$row,$fields)) { continue; }
            $this->refuse('unrecognized row discriminator');
        }
        if(!$array||$fields===[]) { $this->refuse('required scalar row fields missing'); }
        foreach($body as $statement) {
            if($statement instanceof Node\Stmt\Expression&&$statement->expr instanceof Node\Expr\StaticCall) {
                $helper=$statement->expr;
                if(!$helper->class instanceof Node\Name||!$helper->name instanceof Node\Identifier||!self::plain($helper->args)||count($helper->args)>4) { $this->refuse('unbound scalar helper'); }
                $arguments=[];
                foreach($helper->args as $argument) {
                    $field=self::field($argument->value,$row);$type=$field===null?null:($fields[$field]??null);
                    if(!in_array($type,['int','float','string','bool'],true)) { $this->refuse('helper input must be a proven nonnullable primitive'); }
                    $arguments[]=['field'=>$field,'type'=>$type,'span'=>self::span($argument->value)];
                }
                $this->helpers[]=['class'=>$helper->class->toString(),'method'=>$helper->name->name,'span'=>self::span($helper),'arguments'=>$arguments,
                    'nativeObligation'=>'exact source-bound declaration, scalar native by-value formals, no parameter-out/variadic/reference writes'];continue;
            }
            $assignment=$statement instanceof Node\Stmt\Expression?$statement->expr:null;
            if($assignment instanceof Node\Expr\Assign&&($name=self::variable($assignment->var))!==null) {
                if(in_array($name,[$root,$row],true)||isset($sets[$name])||isset($locals[$name])||!$this->scalarValue($assignment->expr,$row,$fields,$locals)) { $this->refuse('unsupported bookkeeping scalar assignment'); }
                $locals[$name]=true;continue;
            }
            if(self::throwing($statement)&&$statement->cond instanceof Node\Expr\Isset_&&count($statement->cond->vars)===1) {
                $access=$statement->cond->vars[0];
                if($access instanceof Node\Expr\ArrayDimFetch&&isset($sets[self::variable($access->var)])&&isset($locals[self::variable($access->dim)])) { continue; }
            }
            if($assignment instanceof Node\Expr\Assign&&$assignment->var instanceof Node\Expr\ArrayDimFetch
                &&isset($sets[self::variable($assignment->var->var)])&&isset($locals[self::variable($assignment->var->dim)])&&$assignment->expr instanceof Node\Expr\ConstFetch&&strtolower($assignment->expr->name->toString())==='true') { continue; }
            $this->refuse('loop continuation changes or escapes row/list capability');
        }
        $callback=$sort->args[1]->value;
        if(!$callback instanceof Node\Expr\ArrowFunction||$callback->byRef||count($callback->params)!==2) { $this->refuse('two-parameter arrow comparator required'); }
        if($sort->args[1]->getDocComment()!==null||$callback->getDocComment()!==null||$callback->attrGroups!==[]||$callback->returnType!==null) { $this->refuse('undocumented arrow without attributes or explicit return contract required'); }
        $parameters=[];
        foreach($callback->params as $param) {
            $name=self::variable($param->var);
            if($name===null||$param->byRef||$param->variadic||$param->default!==null||$param->type!==null||$param->flags!==0||$param->getDocComment()!==null||$param->attrGroups!==[]||isset($parameters[$name])||in_array($name,[$root,$row],true)) { $this->refuse('comparator parameter capability mismatch'); }
            $parameters[$name]=true;
        }
        if(!$this->comparator($callback->expr,$parameters,$fields)||!$this->returnsInt($callback->expr)) { $this->refuse('comparator must read validated fields and return integer comparisons'); }
        $guardCalls=[];
        foreach($finder->findInstanceOf([$guard],Node\Expr\FuncCall::class) as $call) { $guardCalls[]=['name'=>$call->name->toString(),'namespacedName'=>$call->name->getAttribute('namespacedName')?->toString(),'span'=>self::span($call),'arguments'=>count($call->args)]; }
        return ['kind'=>'validated-list-sort-return','sourceStructureAccepted'=>true,'root'=>$root,'row'=>$row,'fields'=>$fields,'openRow'=>true,'extraFieldType'=>'mixed',
            'lexicalCallback'=>['authority'=>'closed lexical arrow integer grammar','expressionSpan'=>self::span($callback->expr),'integerResult'=>true,
                'grammar'=>['validated parameter field reads','integer/null literals','literal integer negation','spaceship','strict equality/inequality','ternary'],
                'providerStateRequired'=>false,'nativeClosureIdentityClaimed'=>false],
            'loopSpan'=>self::span($loop),'sortSpan'=>self::span($sort),'sortNamespacedName'=>$sort->name->getAttribute('namespacedName')?->toString(),'guardCalls'=>$guardCalls,'callbackSpan'=>self::span($callback),'callbackParameters'=>array_map(static fn(Node\Param $param):array=>['name'=>'$'.$param->var->name,'span'=>self::span($param->var)],$callback->params),'returnExpressionSpan'=>self::span($return->expr),'scalarHelperCalls'=>$this->helpers,'comparatorReadSpans'=>$this->reads,
            'nativeObligations'=>['caller source/name/physical native array and documented list contract','native built-in helper identity and no shadow declaration','sort first formal by-reference and native output semantics','closed source arrow/span/two by-value formals/integer grammar within native named caller anchors','scalar helper physical source-bound by-value native formals','SDK type/structural containment of open row into exact return declaration'],
            'runtimeHiddenGlobalAliasSafetyClaimed'=>false,'diagnosticDecision'=>'Keep'];
    }
    private function scalarCheck(Node $check,string $row):?array {
        if(!$check instanceof Node\Expr\BooleanNot||!$check->expr instanceof Node\Expr\FuncCall||!$check->expr->name instanceof Node\Name||count($check->expr->args)!==1||!self::plain($check->expr->args)) { return null; }
        $type=match(strtolower($check->expr->name->getLast())) { 'is_string'=>'string','is_int'=>'int','is_bool'=>'bool','is_float'=>'float',default=>null };
        $input=$check->expr->args[0]->value;
        $key=$input instanceof Node\Expr\BinaryOp\Coalesce&&self::isNull($input->right)?self::field($input->left,$row):null;
        return $type!==null&&$key!==null?[$key,$type]:null;
    }
    private function bound(Node $node,string $row,array $fields):bool {
        return ($node instanceof Node\Expr\BinaryOp\Smaller||$node instanceof Node\Expr\BinaryOp\SmallerOrEqual||$node instanceof Node\Expr\BinaryOp\Greater||$node instanceof Node\Expr\BinaryOp\GreaterOrEqual)
            &&in_array($fields[self::field($node->left,$row)]??null,['int','float'],true)&&$node->right instanceof Node\Scalar\Int_;
    }
    private function scalarValue(Node $node,string $row,array $fields,array $locals):bool {
        if($node instanceof Node\Scalar\Int_||$node instanceof Node\Scalar\String_) { return true; }
        if($node instanceof Node\Expr\Variable) { return isset($locals[self::variable($node)]); }
        if($node instanceof Node\Expr\ArrayDimFetch) { return isset($fields[self::field($node,$row)]); }
        if($node instanceof Node\Expr\BinaryOp\Coalesce) { return $this->scalarValue($node->left,$row,$fields,$locals)&&($node->right instanceof Node\Scalar\Int_||$node->right instanceof Node\Scalar\String_); }
        return false;
    }
    private function comparator(Node $node,array $parameters,array $fields):bool {
        if(++$this->steps>256) { return false; }
        if($node instanceof Node\Scalar\Int_) { return true; }
        if($node instanceof Node\Expr\UnaryMinus) { return $node->expr instanceof Node\Scalar\Int_; }
        if($node instanceof Node\Expr\ConstFetch) { return self::isNull($node); }
        if($node instanceof Node\Expr\ArrayDimFetch&&isset($parameters[self::variable($node->var)])&&$node->dim instanceof Node\Scalar\String_&&isset($fields[$node->dim->value])) { $this->reads[]=self::span($node);return true; }
        if($node instanceof Node\Expr\BinaryOp\Spaceship||$node instanceof Node\Expr\BinaryOp\Identical||$node instanceof Node\Expr\BinaryOp\NotIdentical) { return $this->comparator($node->left,$parameters,$fields)&&$this->comparator($node->right,$parameters,$fields); }
        if($node instanceof Node\Expr\Ternary) { return $this->comparator($node->cond,$parameters,$fields)&&($node->if===null||$this->comparator($node->if,$parameters,$fields))&&$this->comparator($node->else,$parameters,$fields); }
        return false;
    }
    private function returnsInt(Node $node):bool {
        if($node instanceof Node\Scalar\Int_||$node instanceof Node\Expr\BinaryOp\Spaceship||$node instanceof Node\Expr\UnaryMinus&&$node->expr instanceof Node\Scalar\Int_) { return true; }
        return $node instanceof Node\Expr\Ternary&&$this->returnsInt($node->if??$node->cond)&&$this->returnsInt($node->else);
    }
    public static function span(Node $node):array { return [$node->getStartFilePos(),$node->getEndFilePos()+1]; }
    public static function variable(?Node $node):?string { return $node instanceof Node\Expr\Variable&&is_string($node->name)&&!in_array($node->name,['this','GLOBALS','_GET','_POST','_SERVER','_SESSION','_ENV','_COOKIE','_REQUEST','_FILES','argv','argc'],true)?$node->name:null; }
    private static function field(?Node $node,string $row):?string { return $node instanceof Node\Expr\ArrayDimFetch&&self::variable($node->var)===$row&&$node->dim instanceof Node\Scalar\String_&&preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/D',$node->dim->value)===1?$node->dim->value:null; }
    private static function call(Node\Expr\FuncCall $call,string $name):bool { return $call->name instanceof Node\Name&&strtolower($call->name->getLast())===$name; }
    private static function plain(array $args):bool { foreach($args as $arg) { if(!$arg instanceof Node\Arg||$arg->unpack||$arg->byRef||$arg->name!==null) { return false; } } return true; }
    private static function isNull(Node $node):bool { return $node instanceof Node\Expr\ConstFetch&&strtolower($node->name->toString())==='null'; }
    private static function notCall(?Node $node,string $name,array $arguments):bool {
        if(!$node instanceof Node\Expr\BooleanNot||!$node->expr instanceof Node\Expr\FuncCall||!self::call($node->expr,$name)||!self::plain($node->expr->args)||count($node->expr->args)!==count($arguments)) { return false; }
        foreach($arguments as $i=>$argument) { if(is_array($argument)?self::field($node->expr->args[$i]->value,$argument[0])!==$argument[1]:self::variable($node->expr->args[$i]->value)!==$argument) { return false; } } return true;
    }
    private static function orParts(Node $node):array { return $node instanceof Node\Expr\BinaryOp\BooleanOr?[...self::orParts($node->left),...self::orParts($node->right)]:[$node]; }
    private static function throwing(?Node $node):bool { return $node instanceof Node\Stmt\If_&&$node->else===null&&$node->elseifs===[]&&count($node->stmts)===1&&$node->stmts[0] instanceof Node\Stmt\Expression&&$node->stmts[0]->expr instanceof Node\Expr\Throw_; }
    private function refuse(string $reason):never { throw new WorkerIndependentArrayProofRefusal($reason); }
}
final class WorkerIndependentArrayProofRefusal extends \RuntimeException {}
