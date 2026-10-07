<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer\StaticAnalysis;
use PhpParser\{Node,NodeFinder,NodeTraverser,ParserFactory,PrettyPrinter\Standard};
use PhpParser\NodeVisitor\NameResolver;

/** Current lexical expression facts. Compact results retain no parsed class tree. */
final class SourceNullFacts
{
    private array $proofs=[];
    private Standard $printer;
    public function __construct() { $this->printer=new Standard; }
    public function proofs(string $bytes):array {
        $this->proofs=[];
        try { $nodes=(new NodeTraverser(new NameResolver))->traverse((new ParserFactory)->createForNewestSupportedVersion()->parse($bytes)??[]); }
        catch(\PhpParser\Error) { return []; }
        $this->scopes($nodes,null);return $this->proofs;
    }
    private function scopes(array $nodes,?string $namespace):void {
        $top=[];
        foreach($nodes as $node) {
            if($node instanceof Node\Stmt\Namespace_) { $this->scopes($node->stmts,$node->name?->toString()); }
            elseif($node instanceof Node\Stmt\Function_) { $this->scope($node,null,$node->namespacedName->toString()); }
            elseif($node instanceof Node\Stmt\Class_&&$node->name!==null&&isset($node->namespacedName)) {
                foreach($node->getMethods() as $method) { $this->scope($method,$node->namespacedName->toString(),$method->name->name); }
            } elseif(!$node instanceof Node\Stmt\Use_&&!$node instanceof Node\Stmt\Declare_) { $top[]=$node; }
        }
        if($top!==[]) { $scope=['owner'=>null,'name'=>null,'span'=>null,'nameSpan'=>null,'parameters'=>[],'types'=>[],'origins'=>[],'references'=>$this->references($top),'topLevel'=>true];$this->block($top,[],$scope); }
    }
    private function scope(Node\Stmt\Function_|Node\Stmt\ClassMethod $node,?string $owner,string $name):void {
        if($node->byRef) { return; }
        $finder=new NodeFinder;
        if($finder->findFirst([$node],static fn(Node $item):bool=>$item instanceof Node\Stmt\Global_||$item instanceof Node\Stmt\Static_
            ||$item instanceof Node\Expr\Eval_||$item instanceof Node\Expr\Include_||$item instanceof Node\Stmt\Goto_||$item instanceof Node\Stmt\Label
            ||$item instanceof Node\Expr\Variable&&(!is_string($item->name)||$item->name==='GLOBALS'))!==null) { return; }
        $parameters=$types=[];foreach($node->params as $parameter) {
            if(!$parameter->var instanceof Node\Expr\Variable||!is_string($parameter->var->name)) { return; }
            $parameters[]=['name'=>'$'.$parameter->var->name,'nameSpan'=>self::span($parameter->var),'byReference'=>$parameter->byRef,'variadic'=>$parameter->variadic,'hasDefault'=>$parameter->default!==null];
            $type=$parameter->type instanceof Node\NullableType?$parameter->type->type:$parameter->type;
            if($type instanceof Node\Name) { $types[$parameter->var->name]=$type->toString(); }
        }
        $references=[];
        foreach($finder->find([$node],static fn(Node $item):bool=>$item instanceof Node\Expr\AssignRef||$item instanceof Node\ClosureUse&&$item->byRef
            ||$item instanceof Node\Param&&$item->byRef||$item instanceof Node\Arg&&$item->byRef||$item instanceof Node\ArrayItem&&$item->byRef||$item instanceof Node\Stmt\Foreach_&&$item->byRef) as $reference) {
            foreach($finder->findInstanceOf($reference,Node\Expr\Variable::class) as $variable) { if(is_string($variable->name)) { $references[]=$variable->name; } }
        }
        $scope=['owner'=>$owner,'name'=>$name,'span'=>self::span($node),'nameSpan'=>self::span($node->name),'parameters'=>$parameters,'types'=>$types,'origins'=>[],'references'=>array_unique($references),'topLevel'=>false];
        $this->block($node->stmts??[],[],$scope);
    }
    private function block(array $statements,array $facts,array $scope):array {
        foreach($statements as $statement) {
            if($statement instanceof Node\Stmt\Expression) {
                $expression=$statement->expr;
                if($expression instanceof Node\Expr\Assign) {
                    $this->expression($expression->expr,$facts,$scope);
                    $key=$this->key($expression->var);
                    if($key!==null) { $facts=$this->invalidate($facts,$key,$scope); }
                    $facts=$this->effects($expression->expr,$facts,$scope);
                    if($expression->var instanceof Node\Expr\Variable&&is_string($expression->var->name)) {
                        $name=$expression->var->name;unset($scope['types'][$name],$scope['origins'][$name],$scope['constants'][$name],$scope['closures'][$name],$scope['aliases'][$name]);
                        if($expression->expr instanceof Node\Expr\ConstFetch) { $scope['constants'][$name]=strtolower($expression->expr->name->toString()); }
                        if($expression->expr instanceof Node\Expr\Closure||$expression->expr instanceof Node\Expr\ArrowFunction) {
                            $closure=$expression->expr;$uses=[];
                            if($closure instanceof Node\Expr\Closure) {
                                foreach($closure->uses as $use) { $uses[]=['name'=>$use->var->name,'byReference'=>$use->byRef]; }
                                if(!$closure->static&&($scope['owner']??null)!==null) { $uses[]=['name'=>'this','byReference'=>false]; }
                            } else {
                                // Arrow functions implicitly capture free variables by value; an object carrier remains exposed.
                                $parameters=[];foreach($closure->params as $parameter) { if(is_string($parameter->var->name)) { $parameters[]=$parameter->var->name; } }
                                $captureNames=[];$unknownCapture=false;
                                foreach((new NodeFinder)->findInstanceOf($closure->expr,Node\Expr\Variable::class) as $variable) {
                                    if(!is_string($variable->name)) { $unknownCapture=true;continue; }
                                    if(!in_array($variable->name,$parameters,true)) { $captureNames[]=$variable->name; }
                                }
                                if($unknownCapture) {
                                    // A dynamic variable may denote any currently retained carrier; leave those reads unproved.
                                    $captureNames=[...$captureNames,'this',...array_keys($scope['types']),...array_keys($scope['origins']),...array_keys($scope['aliases']??[])];
                                    foreach($scope['parameters'] as $parameter) { $captureNames[]=ltrim($parameter['name'],'$'); }
                                }
                                foreach(array_unique($captureNames) as $captureName) { $uses[]=['name'=>$captureName,'byReference'=>false]; }
                            }
                            $scope['closures'][$name]=['uses'=>$uses,'byReference'=>$closure->byRef,'span'=>self::span($closure)];
                        }
                        if($expression->expr instanceof Node\Expr\New_&&$expression->expr->class instanceof Node\Name) { $scope['types'][$name]=$expression->expr->class->toString(); }
                        elseif($expression->expr instanceof Node\Expr\Variable&&is_string($expression->expr->name)) {
                            $old=$expression->expr->name;if(isset($scope['types'][$old])) { $scope['types'][$name]=$scope['types'][$old]; }
                            $scope['aliases'][$name]=$old;
                            $scope['origins'][$name]=$this->describe($expression->expr);
                        }
                        elseif($this->describe($expression->expr)!==null) { $scope['origins'][$name]=$this->describe($expression->expr); }
                    }
                } else {
                    $this->expression($expression,$facts,$scope);
                    if($expression instanceof Node\Expr\StaticCall&&$expression->class instanceof Node\Name&&$expression->name instanceof Node\Identifier
                        &&strtolower($expression->name->name)==='notnull'&&isset($expression->args[0])&&count($expression->args)<=2
                        &&(!isset($expression->args[1])||$expression->args[1] instanceof Node\Arg&&!$expression->args[1]->byRef&&!$expression->args[1]->unpack
                            &&$expression->args[1]->value instanceof Node\Scalar\String_)) {
                        $argument=$expression->args[0];$key=$argument instanceof Node\Arg&&!$argument->byRef&&!$argument->unpack?$this->key($argument->value):null;
                        if($key!==null&&$this->describe($argument->value)!==null) { $facts[$key]=['kind'=>'documented-postcondition','guard'=>$this->describe($expression),'guardSpan'=>self::span($expression),'target'=>$this->describe($argument->value),'effects'=>[]]; }
                    } else { $facts=$this->effects($expression,$facts,$scope); }
                }
            } elseif($statement instanceof Node\Stmt\If_) {
                $this->expression($statement->cond,$facts,$scope);
                $true=$this->effects($statement->cond,$this->condition($statement->cond,true,$facts),$scope);$false=$this->effects($statement->cond,$this->condition($statement->cond,false,$facts),$scope);
                $this->block($statement->stmts,$true,$scope);
                if($statement->elseifs!==[]) { $facts=[];continue; }
                if($statement->else!==null) { $this->block($statement->else->stmts,$false,$scope); }
                if($this->terminates($statement->stmts)) { $facts=$false; }
                elseif($statement->else!==null&&$this->terminates($statement->else->stmts)) { $facts=$true; }
                else { $facts=$this->branchEffects($statement,$facts,$scope); }
            } elseif($statement instanceof Node\Stmt\Return_||$statement instanceof Node\Stmt\Throw_) {
                if($statement->expr!==null) { $this->expression($statement->expr,$facts,$scope); }return [];
            } elseif($statement instanceof Node\Stmt\TryCatch) {
                $this->block($statement->stmts,$facts,$scope);
                foreach($statement->catches as $catch) { $this->block($catch->stmts,[],$scope); }
                if($statement->finally!==null) { $this->block($statement->finally->stmts,[],$scope); }
                $flag=$this->catchFlag($statement,$scope);
                if($flag!==null) { $facts[$flag['flagKey']]=['kind'=>'catch-flag','data'=>$flag,'effects'=>$flag['effects']]; }
                else { $facts=$this->branchEffects($statement,$facts,$scope); }
            } elseif($statement instanceof Node\Stmt\Unset_) {
                foreach($statement->vars as $var) { $key=$this->key($var);if($key!==null) { $facts=$this->invalidate($facts,$key,$scope); } }
            } elseif(!$statement instanceof Node\Stmt\Nop) { $facts=[]; }
        }
        return $facts;
    }
    private function expression(Node\Expr $expression,array $facts,array $scope):void {
        $key=$this->key($expression);$fact=$key===null?null:($facts[$key]??null);
        if($fact!==null&&$fact['kind']!=='catch-flag') {
            $description=$this->describe($expression);$root=$description;
            while(isset($root['receiver'])) { $root=$root['receiver']; }
            if(!isset($root['name'])||!in_array($root['name'],$scope['references']??[],true)) { $this->proofs[self::spanKey($expression)][]=['expression'=>$description,'fact'=>$fact,'scope'=>$scope]; }
        }
        if($expression instanceof Node\Expr\BinaryOp\BooleanAnd||$expression instanceof Node\Expr\BinaryOp\LogicalAnd) {
            $this->expression($expression->left,$facts,$scope);$this->expression($expression->right,$this->effects($expression->left,$this->condition($expression->left,true,$facts),$scope),$scope);return;
        }
        if($expression instanceof Node\Expr\BinaryOp\BooleanOr||$expression instanceof Node\Expr\BinaryOp\LogicalOr) {
            $this->expression($expression->left,$facts,$scope);$this->expression($expression->right,$this->effects($expression->left,$this->condition($expression->left,false,$facts),$scope),$scope);return;
        }
        if($expression instanceof Node\Expr\Ternary) {
            $this->expression($expression->cond,$facts,$scope);
            if($expression->if!==null) { $this->expression($expression->if,$this->effects($expression->cond,$this->condition($expression->cond,true,$facts),$scope),$scope); }
            $this->expression($expression->else,$this->effects($expression->cond,$this->condition($expression->cond,false,$facts),$scope),$scope);return;
        }
        if($expression instanceof Node\FunctionLike||$expression instanceof Node\Expr\New_) { return; }
        foreach($expression->getSubNodeNames() as $field) {
            $value=$expression->$field;
            if($value instanceof Node\Expr) { $this->expression($value,$facts,$scope); }
            elseif($value instanceof Node\Arg) { $this->expression($value->value,$facts,$scope); }
            elseif(is_array($value)) { foreach($value as $part) { if($part instanceof Node\Arg) { $this->expression($part->value,$facts,$scope); }elseif($part instanceof Node\Expr) { $this->expression($part,$facts,$scope); } } }
        }
    }
    private function condition(Node\Expr $expression,bool $truth,array $facts):array {
        if($expression instanceof Node\Expr\BooleanNot) { return $this->condition($expression->expr,!$truth,$facts); }
        $and=$expression instanceof Node\Expr\BinaryOp\BooleanAnd||$expression instanceof Node\Expr\BinaryOp\LogicalAnd;
        $or=$expression instanceof Node\Expr\BinaryOp\BooleanOr||$expression instanceof Node\Expr\BinaryOp\LogicalOr;
        if($and&&$truth||$or&&!$truth) { return $this->condition($expression->right,$truth,$this->condition($expression->left,$truth,$facts)); }
        if($and||$or) { return $facts; }
        $target=null;
        if($expression instanceof Node\Expr\BinaryOp\Identical||$expression instanceof Node\Expr\BinaryOp\NotIdentical) {
            $nonnull=$expression instanceof Node\Expr\BinaryOp\NotIdentical?$truth:!$truth;
            if($nonnull) { if(self::isNull($expression->left)) { $target=$expression->right; }elseif(self::isNull($expression->right)) { $target=$expression->left; } }
        } elseif($truth) { $target=$expression; }
        $key=$target===null?null:$this->key($target);
        if($key!==null&&isset($facts[$key])&&$facts[$key]['kind']==='catch-flag') {
            // A false catch flag is handled below, rather than mistaking the flag itself for a value proof.
        } elseif($key!==null&&$this->describe($target)!==null) { $facts[$key]=['kind'=>'condition','guard'=>$this->describe($target),'guardSpan'=>self::span($expression),'target'=>$this->describe($target),'effects'=>[]]; }
        if(!$truth&&$expression instanceof Node\Expr\Variable&&is_string($expression->name)) {
            $flagKey=$this->key($expression);$flag=$facts[$flagKey]??null;
            if($flag!==null&&$flag['kind']==='catch-flag') { $data=$flag['data'];$facts[$data['targetKey']]=['kind'=>'nonnullable-producer-catch-flag','guardSpan'=>self::span($expression),'target'=>$data['target'],'producer'=>$data['producer'],'effects'=>$flag['effects']]; }
        }
        if(!$truth&&$expression instanceof Node\Expr\MethodCall&&$expression->name instanceof Node\Identifier&&strtolower($expression->name->name)==='isempty'&&$expression->args===[]) {
            $receiver=$this->describe($expression->var);
            if($receiver!==null) { foreach(['first','last'] as $member) { $call=new Node\Expr\MethodCall($expression->var,$member);$callKey=$this->key($call);$facts[$callKey]=['kind'=>'conditional-method-postcondition','guard'=>$this->describe($expression),'guardSpan'=>self::span($expression),'target'=>['kind'=>'method','receiver'=>$receiver,'name'=>$member,'arguments'=>[]],'effects'=>[]]; } }
        }
        return $facts;
    }
    private function catchFlag(Node\Stmt\TryCatch $try,array $scope):?array {
        if(count($try->catches)!==1||count($try->catches[0]->types)!==1||strcasecmp($try->catches[0]->types[0]->toString(),'Throwable')!==0||count($try->catches[0]->stmts)!==1) { return null; }
        $catch=$try->catches[0]->stmts[0];$assignment=$catch instanceof Node\Stmt\Expression?$catch->expr:null;
        if(!$assignment instanceof Node\Expr\Assign||!$assignment->var instanceof Node\Expr\Variable||!is_string($assignment->var->name)
            ||!$assignment->expr instanceof Node\Expr\ConstFetch||strtolower($assignment->expr->name->toString())!=='true') { return null; }
        $first=$try->stmts[0]??null;$value=$first instanceof Node\Stmt\Expression?$first->expr:null;
        if(!$value instanceof Node\Expr\Assign||!$value->var instanceof Node\Expr\Variable||!is_string($value->var->name)||!$value->expr instanceof Node\Expr\MethodCall) { return null; }
        if(($scope['constants'][$assignment->var->name]??null)!=='false'||($scope['constants'][$value->var->name]??null)!=='null') { return null; }
        $producer=$this->describe($value->expr);if($producer===null) { return null; }
        $finder=new NodeFinder;$retained=[$assignment->var->name,$value->var->name];
        $rest=array_slice($try->stmts,1);
        if($finder->findFirst([...$rest,...($try->finally?->stmts??[])],static fn(Node $node):bool=>($node instanceof Node\Expr\Assign||$node instanceof Node\Expr\AssignOp||$node instanceof Node\Expr\AssignRef)
            &&$node->var instanceof Node\Expr\Variable&&in_array($node->var->name,$retained,true)||$node instanceof Node\ClosureUse&&$node->byRef&&in_array($node->var->name,$retained,true))!==null) { return null; }
        $effects=[];foreach($finder->findInstanceOf([...$rest,...($try->finally?->stmts??[])],Node\Expr\CallLike::class) as $call) { $description=$this->describe($call);if($description===null||count($effects)>=64) { return null; }$effects[]=$description; }
        return ['flagKey'=>$this->key($assignment->var),'targetKey'=>$this->key($value->var),'target'=>$this->describe($value->var),'producer'=>$producer,'effects'=>$effects];
    }
    private function effects(Node\Expr $expression,array $facts,array $scope):array {
        $finder=new NodeFinder;
        if($finder->findFirst([$expression],static fn(Node $node):bool=>$node instanceof Node\Expr\AssignRef||$node instanceof Node\Expr\Eval_||$node instanceof Node\Expr\Include_
            ||$node instanceof Node\Expr\FuncCall&&$node->name instanceof Node\Name&&in_array(strtolower($node->name->getLast()),['extract','parse_str'],true))!==null) { return []; }
        foreach($finder->find($expression,static fn(Node $node):bool=>$node instanceof Node\Expr\Assign||$node instanceof Node\Expr\AssignOp||$node instanceof Node\Expr\PreInc||$node instanceof Node\Expr\PostInc||$node instanceof Node\Expr\PreDec||$node instanceof Node\Expr\PostDec) as $write) {
            $key=$this->key($write->var);if($key!==null) { $facts=$this->invalidate($facts,$key,$scope); }
        }
        foreach($finder->findInstanceOf($expression,Node\Expr\CallLike::class) as $call) {
            $description=$this->describe($call);if($description===null) { return []; }
            foreach($facts as &$fact) { $fact['effects'][]=$description;if(count($fact['effects'])>64) { unset($fact);return []; } }unset($fact);
        }
        return $facts;
    }
    private function branchEffects(Node $statement,array $facts,array $scope):array {
        $finder=new NodeFinder;
        foreach($finder->find($statement,static fn(Node $node):bool=>$node instanceof Node\Expr\Assign||$node instanceof Node\Expr\AssignOp||$node instanceof Node\Expr\AssignRef||$node instanceof Node\Expr\PreInc||$node instanceof Node\Expr\PostInc||$node instanceof Node\Expr\PreDec||$node instanceof Node\Expr\PostDec) as $write) {
            $key=$this->key($write->var);if($key!==null) { $facts=$this->invalidate($facts,$key,$scope); }
        }
        foreach($finder->findInstanceOf($statement,Node\Expr\CallLike::class) as $call) {
            $description=$this->describe($call);if($description===null) { return []; }
            foreach($facts as &$fact) { $fact['effects'][]=$description;if(count($fact['effects'])>64) { unset($fact);return []; } }unset($fact);
        }
        return $facts;
    }
    private function invalidate(array $facts,string $key,array $scope=[]):array {
        $canonical=static function(string $value)use($scope):string {
            if(preg_match('/^\$([A-Za-z_][A-Za-z0-9_]*)(.*)$/D',$value,$parts)!==1) { return $value; }
            $root=$parts[1];$seen=[];while(isset($scope['aliases'][$root])&&!isset($seen[$root])) { $seen[$root]=true;$root=$scope['aliases'][$root]; }
            return '$'.$root.$parts[2];
        };
        $key=$canonical($key);$field=str_contains($key,'->')?substr($key,0,strpos($key,'->')):null;
        foreach($facts as $original=>$fact) { $name=$canonical($original);if($name===$key||str_starts_with($name,$key.'->')||$field!==null&&str_starts_with($name,$field.'->')||$fact['kind']==='catch-flag'&&($canonical($fact['data']['targetKey'])===$key||$canonical($fact['data']['flagKey'])===$key)) { unset($facts[$original]); } }
        return $facts;
    }
    private function references(array $nodes):array {
        $finder=new NodeFinder;$names=[];
        foreach($finder->find($nodes,static fn(Node $item):bool=>$item instanceof Node\Expr\AssignRef||$item instanceof Node\ClosureUse&&$item->byRef||$item instanceof Node\Arg&&$item->byRef||$item instanceof Node\Stmt\Foreach_&&$item->byRef) as $reference) {
            foreach($finder->findInstanceOf($reference,Node\Expr\Variable::class) as $variable) { if(is_string($variable->name)) { $names[]=$variable->name; } }
        }return array_unique($names);
    }
    private function terminates(array $statements):bool {
        $last=$statements[array_key_last($statements)]??null;
        return $last instanceof Node\Stmt\Return_||$last instanceof Node\Stmt\Throw_||$last instanceof Node\Stmt\Expression&&($last->expr instanceof Node\Expr\Exit_||$last->expr instanceof Node\Expr\Throw_);
    }
    public function describe(Node $expression):?array {
        if($expression instanceof Node\Expr\Variable&&is_string($expression->name)) { return ['kind'=>'variable','name'=>$expression->name,'span'=>self::span($expression),'key'=>'$'.$expression->name]; }
        if($expression instanceof Node\Expr\New_&&$expression->class instanceof Node\Name) {
            $arguments=[];foreach($expression->args as $argument) { if(!$argument instanceof Node\Arg||$argument->byRef||$argument->unpack) { return null; }$arguments[]=['name'=>$argument->name?->name,'value'=>$this->describe($argument->value)??['kind'=>'opaque-value','span'=>self::span($argument->value)]]; }
            return ['kind'=>'new','class'=>$expression->class->toString(),'arguments'=>$arguments,'span'=>self::span($expression),'key'=>$this->printer->prettyPrintExpr($expression)];
        }
        if($expression instanceof Node\Expr\PropertyFetch&&$expression->name instanceof Node\Identifier&&($receiver=$this->describe($expression->var))!==null) { return ['kind'=>'property','receiver'=>$receiver,'name'=>$expression->name->name,'span'=>self::span($expression),'key'=>$this->printer->prettyPrintExpr($expression)]; }
        if(($expression instanceof Node\Expr\MethodCall||$expression instanceof Node\Expr\StaticCall||$expression instanceof Node\Expr\FuncCall)&&!$expression->isFirstClassCallable()) {
            $name=$expression->name instanceof Node\Identifier?$expression->name->name:($expression->name instanceof Node\Name?$expression->name->toString():($expression instanceof Node\Expr\FuncCall&&$expression->name instanceof Node\Expr\Variable&&is_string($expression->name->name)?'$'.$expression->name->name:null));if($name===null) { return null; }
            $arguments=[];foreach($expression->args as $argument) {
                if(!$argument instanceof Node\Arg||$argument->byRef||$argument->unpack) { return null; }
                $value=$argument->value;$description=$this->describe($value);
                if($description===null&&($value instanceof Node\Scalar||$value instanceof Node\Expr\ConstFetch)) { $description=['kind'=>'literal','text'=>$this->printer->prettyPrintExpr($value),'span'=>self::span($value)]; }
                if($description===null&&$value instanceof Node\Expr\Array_) { $description=['kind'=>'opaque-value','span'=>self::span($value)]; }
                if($description===null) { $description=['kind'=>'opaque-value','span'=>self::span($value)]; }
                $arguments[]=['name'=>$argument->name?->name,'value'=>$description];
            }
            $kind=$expression instanceof Node\Expr\MethodCall?'method':($expression instanceof Node\Expr\StaticCall?'static':(str_starts_with($name,'$')?'dynamic':'function'));$description=['kind'=>$kind,'name'=>$name,'arguments'=>$arguments,'span'=>self::span($expression),'key'=>$this->printer->prettyPrintExpr($expression)];
            if($kind==='method') { $receiver=$this->describe($expression->var);if($receiver===null) { return null; }$description['receiver']=$receiver; }
            if($kind==='static') { if(!$expression->class instanceof Node\Name) { return null; }$description['class']=$expression->class->toString(); }
            return $description;
        }
        return null;
    }
    private function key(Node $expression):?string { return $this->describe($expression)['key']??null; }
    private static function isNull(Node $expression):bool { return $expression instanceof Node\Expr\ConstFetch&&strtolower($expression->name->toString())==='null'; }
    private static function span(Node $node):array { return [$node->getStartFilePos(),$node->getEndFilePos()+1]; }
    private static function spanKey(Node $node):string { return implode(':',self::span($node)); }
}
