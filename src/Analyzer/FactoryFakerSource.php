<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\DefensiveBoundaryGuardSource as Source;
use PhpParser\{Node,NodeFinder};

/** Compact lexical certificates only; native Factory and formatter admission is separate. */
final class FactoryFakerSource
{
    private const FACTORY='Illuminate\\Database\\Eloquent\\Factories\\Factory';
    public static function compile(array $nodes):array
    {
        $finder=new NodeFinder;$parents=[];
        $walk=static function(mixed $value,?Node $parent=null)use(&$walk,&$parents):void{
            if(is_array($value)){foreach($value as $item){$walk($item,$parent);}}
            elseif($value instanceof Node){if($parent!==null){$parents[spl_object_id($value)]=$parent;}foreach($value->getSubNodeNames() as $name){$walk($value->$name,$value);}}
        };$walk($nodes);$walk=null;$result=[];
        foreach($finder->findInstanceOf($nodes,Node\Stmt\Class_::class) as $class){
            if($class->isAnonymous()||!isset($class->namespacedName)||!$class->extends instanceof Node\Name||strcasecmp($class->extends->toString(),self::FACTORY)!==0||!self::defaultFieldUses($class,$finder,$parents)){continue;}
            foreach($class->getMethods() as $scope){
                if(strtolower($scope->name->name)!=='definition'||!$scope->isPublic()||$scope->isStatic()||$scope->byRef||$scope->params!==[]||$scope->stmts===null){continue;}
                if($finder->findFirst([$scope],static fn(Node $n):bool=>$n instanceof Node\Expr\AssignRef||$n instanceof Node\Stmt\Global_||$n instanceof Node\Stmt\Static_||$n instanceof Node\Expr\ClosureUse&&$n->byRef||$n instanceof Node\Stmt\Foreach_&&$n->byRef)!==null){continue;}
                foreach($finder->findInstanceOf([$scope],Node\Expr\MethodCall::class) as $call){
                    if(!self::faker($call->var)||!self::plain($call)||!$call->name instanceof Node\Identifier){continue;}
                    $name=strtolower($call->name->name);$base=['class'=>$class->namespacedName->toString(),'classSpan'=>Source::span($class),'scope'=>self::scope($scope),'field'=>Source::span($call->var),'call'=>Source::span($call),'nativeAdmission'=>false,'nativeTypesChanged'=>false];
                    if($name==='words'&&count($call->args)===2&&$call->args[0]->value instanceof Node\Scalar\Int_&&$call->args[0]->value->value>=0&&$call->args[0]->value->value<=1024&&self::true($call->args[1]->value)){
                        $parent=$parents[spl_object_id($call)]??null;
                        if($parent instanceof Node\Expr\BinaryOp\Concat&&(($parent->left===$call&&$parent->right instanceof Node\Scalar\String_)||($parent->right===$call&&$parent->left instanceof Node\Scalar\String_))){
                            $result[Source::key(Source::span($call))]=$base+['kind'=>'default-faker-words-text','arguments'=>array_map(static fn(Node\Arg $arg):array=>Source::span($arg->value),$call->args),'concat'=>Source::span($parent),'domain'=>['string']];
                        }
                    }
                    if($name!=='randomelement'||count($call->args)!==1){continue;}$values=self::literalValues($call->args[0]->value);if($values===null){continue;}
                    $parent=$parents[spl_object_id($call)]??null;
                    if(!$parent instanceof Node\Expr\Assign||!$parent->var instanceof Node\Expr\Variable||!is_string($parent->var->name)){continue;}$variable=$parent->var->name;
                    $definitions=$finder->findInstanceOf([$scope],Node\Expr\Assign::class);$definitions=array_filter($definitions,static fn(Node\Expr\Assign $a):bool=>$a->var instanceof Node\Expr\Variable&&$a->var->name===$variable);if(count($definitions)!==1){continue;}
                    foreach($finder->findInstanceOf([$scope],Node\Expr\FuncCall::class) as $receiving){
                        if(!self::plain($receiving)||!$receiving->name instanceof Node\Name||!self::builtinName($receiving->name,'sprintf')||$receiving->getStartFilePos()<=$parent->getEndFilePos()||count($receiving->args)<2||!$receiving->args[0]->value instanceof Node\Scalar\String_){continue;}
                        foreach($receiving->args as $index=>$argument){
                            if($index===0||!$argument->value instanceof Node\Expr\Variable||$argument->value->name!==$variable||!self::localLifetime($scope,$parent,$argument->value,$variable,$finder,$parents)){continue;}
                            $result[Source::key(Source::span($argument->value))]=$base+['kind'=>'default-faker-literal-element','arguments'=>[Source::span($call->args[0]->value)],'assignment'=>Source::span($parent),'variable'=>$variable,'receiving'=>['name'=>'sprintf','nameSpan'=>Source::span($receiving->name),'span'=>Source::span($receiving),'argumentIndex'=>$index],'selected'=>Source::span($argument->value),'values'=>$values,'emptyReturnsNull'=>$values===[],'domain'=>$values===[]?['null']:array_values(array_unique(array_column($values,'kind')))];
                        }
                    }
                }
            }
        }return $result;
    }
    private static function defaultFieldUses(Node\Stmt\Class_ $class,NodeFinder $finder,array $parents):bool
    {
        if(preg_match('/@(property(?:-read|-write)?|method)\s+[^\r\n]*(?:\$faker\b|\b(?:words|randomElement)\s*\()/i',$class->getDocComment()?->getText()??'')===1){return false;}
        foreach($class->stmts as $statement){
            if($statement instanceof Node\Stmt\TraitUse){return false;}
            if($statement instanceof Node\Stmt\Property){foreach($statement->props as $property){if(strtolower($property->name->name)==='faker'){return false;}}}
            if($statement instanceof Node\Stmt\ClassMethod&&in_array(strtolower($statement->name->name),['__construct','withfaker','__get','__set','__call'],true)){return false;}
        }
        foreach($finder->findInstanceOf([$class],Node\Expr\PropertyFetch::class) as $field){
            if(!self::faker($field)){continue;}$parent=$parents[spl_object_id($field)]??null;
            if(!$parent instanceof Node\Expr\MethodCall||$parent->var!==$field||!self::plain($parent)||!$parent->name instanceof Node\Identifier||!in_array(strtolower($parent->name->name),['words','randomelement','numberbetween'],true)){return false;}
        }return true;
    }
    private static function localLifetime(Node\Stmt\ClassMethod $scope,Node\Expr\Assign $definition,Node\Expr\Variable $selected,string $name,NodeFinder $finder,array $parents):bool
    {
        $definitionStatement=$parents[spl_object_id($definition)]??null;$selectedStatement=$selected;
        while(isset($parents[spl_object_id($selectedStatement)])&&!$selectedStatement instanceof Node\Stmt){$selectedStatement=$parents[spl_object_id($selectedStatement)];}
        if(!$definitionStatement instanceof Node\Stmt\Expression||!in_array($definitionStatement,$scope->stmts,true)||!in_array($selectedStatement,$scope->stmts,true)){return false;}
        foreach($finder->findInstanceOf([$scope],Node\Expr\Variable::class) as $use){
            if($use->name!==$name||$use===$definition->var||$use===$selected||$use->getStartFilePos()>$selected->getEndFilePos()){continue;}
            return false;
        }return true;
    }
    private static function literalValues(Node $node):?array
    {
        if(!$node instanceof Node\Expr\Array_||count($node->items)>32){return null;}$values=[];
        foreach($node->items as $item){
            if($item===null||$item->byRef||$item->unpack||$item->key!==null&&!$item->key instanceof Node\Scalar\Int_&&!$item->key instanceof Node\Scalar\String_){return null;}
            $value=$item->value;
            if($value instanceof Node\Scalar\Int_){$values[]=['kind'=>'int','value'=>$value->value];}
            elseif($value instanceof Node\Scalar\Float_){$values[]=['kind'=>'float','value'=>$value->value];}
            elseif($value instanceof Node\Scalar\String_){$values[]=['kind'=>'string','value'=>$value->value];}
            elseif($value instanceof Node\Expr\ConstFetch&&in_array(strtolower($value->name->toString()),['true','false','null'],true)){$kind=strtolower($value->name->toString());$values[]=['kind'=>$kind==='null'?'null':'bool','value'=>match($kind){'true'=>true,'false'=>false,default=>null}];}
            else{return null;}
        }return $values;
    }
    private static function faker(Node $node):bool{return $node instanceof Node\Expr\PropertyFetch&&$node->var instanceof Node\Expr\Variable&&$node->var->name==='this'&&$node->name instanceof Node\Identifier&&$node->name->name==='faker';}
    private static function true(Node $node):bool{return $node instanceof Node\Expr\ConstFetch&&strtolower($node->name->toString())==='true';}
    private static function plain(Node\Expr\CallLike $node):bool{return !$node->isFirstClassCallable()&&!array_filter($node->args,static fn($a):bool=>!$a instanceof Node\Arg||$a->name!==null||$a->byRef||$a->unpack);}
    private static function builtinName(Node\Name $name,string $expected):bool{$resolved=$name->getAttribute('resolvedName');return $resolved instanceof Node\Name?strcasecmp($resolved->toString(),$expected)===0:!$name instanceof Node\Name\Relative&&($name->isUnqualified()||$name instanceof Node\Name\FullyQualified)&&strcasecmp($name->toString(),$expected)===0;}
    private static function scope(Node\Stmt\ClassMethod $scope):array{$starts=[$scope->getStartFilePos()];foreach($scope->getComments() as $comment){$starts[]=$comment->getStartFilePos();}foreach($scope->attrGroups as $group){$starts[]=$group->getStartFilePos();}return ['name'=>$scope->name->name,'span'=>Source::span($scope),'startAlternatives'=>$starts,'static'=>false,'parameters'=>[]];}
}
