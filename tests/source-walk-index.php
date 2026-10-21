<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\CodebaseScanSyntax as Syntax;
use PhpParser\Node;
use PhpParser\NodeFinder;

require getenv('LARAMAGO_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
$checks=0;
$check=static function(bool $value,string $label)use(&$checks):void{
    if(!$value){throw new RuntimeException($label);}++$checks;
};
$released=static function()use($check):void{
    foreach(['projectionRoots'=>null,'projectionNodes'=>null,'projectionTypes'=>[]] as $name=>$expected){
        $check((new ReflectionProperty(Syntax::class,$name))->getValue()===$expected,'Temporary root ownership escaped: '.$name);
    }
};
$source= <<<'PHP'
<?php
namespace First {
    use Vendor\Item as Alias;
    /** preserved */ class A {
        function f(Alias $row) { $ready=false; $callback=function()use(&$ready){$ready=true;}; $callback(); return $row->get(); }
    }
    trait Shared {} interface Contract {} enum Choice { case One; }
}
namespace Second {
    function outside() { return new class { function inner(){ return fn($value)=>$value; } }; }
    class B { function f($row){ return $row?->value; } }
}
PHP;
Syntax::clear();$nodes=Syntax::parse($source);$before=serialize($nodes);
foreach(['find','findFirst'] as $method){
    $filter=static fn(Node $node):bool=>$node instanceof Node\FunctionLike;
    $check(serialize(Syntax::$method($nodes,$filter))===serialize((new NodeFinder)->$method($nodes,$filter)),'Standalone predicate selection: '.$method);
}
foreach(['findInstanceOf','findFirstInstanceOf'] as $method){
    $check(serialize(Syntax::$method($nodes,Node\Stmt\ClassLike::class))===serialize((new NodeFinder)->$method($nodes,Node\Stmt\ClassLike::class)),'Standalone type selection: '.$method);
}
$released();
Syntax::registerProjection('invented-early',static function(array $nodes)use($check):int{
    $calls=0;
    $first=Syntax::findFirst($nodes,static function(Node $node)use(&$calls):bool{++$calls;return true;});
    $check($first===$nodes[array_key_first($nodes)]&&$calls===1,'An early search preserves immediate first-match termination');
    $check((new ReflectionProperty(Syntax::class,'projectionNodes'))->getValue()===null,'An early search must not build a complete preorder');
    return $calls;
});
Syntax::registerProjection('invented-full',static function(array $nodes)use($check,$source):array{
    $before=serialize($nodes);$actualVisits=$expectedVisits=[];
    $actual=Syntax::find($nodes,static function(Node $node)use(&$actualVisits):bool{$actualVisits[]=spl_object_id($node);return $node instanceof Node\FunctionLike;});
    $expected=(new NodeFinder)->find($nodes,static function(Node $node)use(&$expectedVisits):bool{$expectedVisits[]=spl_object_id($node);return $node instanceof Node\FunctionLike;});
    $check($actualVisits===$expectedVisits&&$actual===$expected,'Full preorder and mutable node identities match the independent finder');
    foreach([Node\Stmt\ClassLike::class,Node\FunctionLike::class,Node\Expr\Variable::class,Node\Expr\MethodCall::class,Node\Stmt\Goto_::class] as $class){
        $found=Syntax::findInstanceOf($nodes,$class);
        $check($found===(new NodeFinder)->findInstanceOf($nodes,$class),'Type bucket preserves exact order/identity: '.$class);
        $check(Syntax::findInstanceOf($nodes,$class)===$found,'Repeated type lookup remains exact');
        $check(Syntax::findFirstInstanceOf($nodes,$class)===(new NodeFinder)->findFirstInstanceOf($nodes,$class),'First type match stays exact');
        $check(Syntax::findFirst($nodes,static fn(Node $node):bool=>$node instanceof $class)===(new NodeFinder)->findFirstInstanceOf($nodes,$class),'First predicate match over shared preorder');
    }
    foreach([array_slice($nodes,1),$nodes[0]] as $subset){
        $check(Syntax::findInstanceOf($subset,Node\FunctionLike::class)===(new NodeFinder)->findInstanceOf($subset,Node\FunctionLike::class),'Subset/node search keeps independent traversal semantics');
    }
    $nested=Syntax::query($source,'invented-nested',static fn(array $other):array=>Syntax::findInstanceOf($other,Node\Stmt\ClassLike::class));
    $classes=Syntax::findInstanceOf($nodes,Node\Stmt\ClassLike::class);
    $check($nested[0]!==$classes[0]&&serialize($nested)===serialize($classes),'A nested independent parse cannot reuse another mutable root');
    $check(serialize($nodes)===$before,'Trusted selectors do not mutate the source graph');
    return $classes;
});
$classes=Syntax::query($source,'invented-full',static fn(array $nodes):array=>[]);
$released();
$expected=serialize($classes);$classes[0]->name->name='Mutated';
$again=Syntax::query($source,'invented-full',static fn(array $nodes):array=>[]);
$check(serialize($again)===$expected,'Returned-node mutation cannot alter the serialized query snapshot');
$check(serialize($nodes)===$before,'Earlier standalone mutable roots remain unchanged');
$released();
Syntax::clear();
Syntax::registerProjection('invented-failure',static function(array $nodes):never{
    Syntax::findInstanceOf($nodes,Node\FunctionLike::class);
    throw new RuntimeException('Invented projection failure');
});
for($i=0;$i<2;++$i){
    try{Syntax::query($source,'invented-failure',static fn(array $nodes):array=>[]);throw new LogicException('Failure was swallowed');}
    catch(RuntimeException $error){$check($error->getMessage()==='Invented projection failure','Requested exception remains observable');}
    $released();
}
Syntax::clear();$released();
echo json_encode(['complete'=>true,'controls'=>$checks],JSON_THROW_ON_ERROR)."\n";
