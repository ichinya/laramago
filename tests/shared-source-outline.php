<?php
declare(strict_types=1);

require __DIR__.'/xml-origin-selector.php';

use Ichinya\Laramago\Analyzer\StaticAnalysis\CodebaseScanSyntax as Syntax;
use Ichinya\Laramago\Analyzer\StaticAnalysis\StoredCallbackSourceDeclarations as Declarations;
use Ichinya\Laramago\Analyzer\StaticAnalysis\NonEmptyCollectionCalls;
use PhpParser\Node;

$startChecks=$checks;
$fixture=json_decode(file_get_contents(__DIR__.'/fixtures/xml-source-outline/oracle.json'),true,flags:JSON_THROW_ON_ERROR);
$legacyIndex=eval('namespace Ichinya\\Laramago\\Analyzer\\StaticAnalysis; use PhpParser\\Node; use PhpParser\\NodeFinder; return static function(array $nodes):array {'.$fixture['source_index_body'].'};');
$legacyNonempty=eval('namespace Ichinya\\Laramago\\Analyzer\\StaticAnalysis; use PhpParser\\Node; use PhpParser\\NodeFinder; use Mago\\Sdk\\Analyzer\\CodebaseScanContext; return function(CodebaseScanContext $context):void {'.$fixture['nonempty_scan_body'].'};');
$indexMethod=new ReflectionMethod(Declarations::class,'sourceIndex');
$bodies=[
    '<?php namespace Example; use Vendor\A as Alias; class One {} trait Shared {} interface Other {} enum Pick { case A; }',
    '<?php declare(TICKS=1); class A { function f($x) { return $x?->field; } }',
    '<?php function f($x,$name) { $x->plain; $x?->{$name}; $x->{$x->nested}; $x->{"literal"}; $x->naГЇve; }',
    '<?php function f() { invoke(); Foo::staticCall(); $a->method(); $a?->method(); new Foo; new class{}; invoke(...); }',
    '<?php use SimpleXMLElement as Document; use function simplexml_load_string as load; function f($x) { new Document($x); load($x); }',
    '<?php namespace Example; function f($x) { new SimpleXMLElement($x); simplexml_load_string($x); }',
    '<?php use Illuminate\Support\Collection as Rows; function f() { $x=new Rows([1]); return $x->isEmpty()?null:$x->first(); }',
    '<?php function f() { $x=new \Illuminate\Database\Eloquent\Collection([]); return $x->isEmpty()?null:$x->last(); }',
    '<?php if(true){class Conditional{}} namespace_invalid();',
];
try {
    Syntax::clear();
    new Declarations;new \Ichinya\Laramago\Analyzer\StaticAnalysis\SimpleXmlProvenance;
    $check((new ReflectionProperty(Syntax::class,'projections'))->getValue()===[],'Inactive consumers must not register facts');
    Declarations::registerSourceIndex();
    (new \Ichinya\Laramago\Analyzer\StaticAnalysis\SimpleXmlProvenance)->initialize($initialization);
    (new Declarations)->initialize($initialization);
    $registered=(new ReflectionProperty(Syntax::class,'projections'))->getValue();
    $check(array_keys($registered)===['stored-callback-source-index:v4'],'All owners share exactly one versioned policy');
    foreach($bodies as $source){
        $nodes=(new \PhpParser\NodeTraverser(new \PhpParser\NodeVisitor\NameResolver))->traverse((new \PhpParser\ParserFactory)->createForNewestSupportedVersion()->parse($source)??[]);
        $before=serialize($nodes);$original=$legacyIndex($nodes);$actual=$indexMethod->invoke(null,$nodes);
        $check(array_intersect_key($actual,$original)===$original,'Original class facts and tick semantics must remain exact');
        $properties=[];$calls=[];$xml=false;$collection=false;
        foreach((new \PhpParser\NodeFinder)->find($nodes,static fn(Node $node):bool=>$node instanceof Node\Expr\PropertyFetch||$node instanceof Node\Expr\NullsafePropertyFetch) as $access){
            $key=$access->name->getStartFilePos().':'.($access->name->getEndFilePos()+1);
            $properties[$key]=array_key_exists($key,$properties);
        }
        foreach((new \PhpParser\NodeFinder)->findInstanceOf($nodes,Node\Expr\CallLike::class) as $call){
            $key=$call->getStartFilePos().':'.($call->getEndFilePos()+1);$calls[$key]=array_key_exists($key,$calls);
            if($call instanceof Node\Expr\New_&&$call->class instanceof Node\Name){
                $xml=$xml||strcasecmp($call->class->toString(),'SimpleXMLElement')===0;
                $collection=$collection||in_array($call->class->toString(),['Illuminate\\Support\\Collection','Illuminate\\Database\\Eloquent\\Collection'],true);
            }
            if($call instanceof Node\Expr\FuncCall&&$call->name instanceof Node\Name){$xml=$xml||strcasecmp($call->name->toString(),'simplexml_load_string')===0;}
        }
        $check($actual['xmlOutline']===['hasOrigin'=>$xml,'properties'=>$properties],'Independent property-span and XML-origin oracle');
        $check($actual['callReservations']===$calls&&$actual['hasCollectionOrigin']===$collection,'Independent call-span and collection-origin oracle');
        $check(serialize($nodes)===$before,'Shared compiler must not mutate source nodes');
        $check(Declarations::sourceIndexSnapshot($source)===$actual,'Batched and isolated facts must match');
        $copy=Declarations::sourceIndexSnapshot($source);$copy['xmlOutline']['properties']['invented']=true;
        $check(Declarations::sourceIndexSnapshot($source)===$actual,'Escaping facts remain independent of cached snapshots');
    }
    $normal='<?php function read() { $rows=new \Illuminate\Support\Collection([1,2]); return $rows->isEmpty()?null:$rows->first(); }';
    $nonemptySources=[$normal,str_replace('Support\\Collection','Database\\Eloquent\\Collection',$normal),str_replace('first()','last()',$normal),'<?php function opaque($rows) { return $rows->isEmpty()?null:$rows->first(); }'];
    foreach($nonemptySources as $i=>$source){
        Syntax::clear();$token->cancelled=false;$old=new NonEmptyCollectionCalls('/invented/root');$new=new NonEmptyCollectionCalls('/invented/root');$legacy=Closure::bind($legacyNonempty,$old,NonEmptyCollectionCalls::class);
        foreach([[[$file('a.php',$source)],true,false],[[$file('b.php',$source)],false,true],[[$file('b.php',$source)],false,true],[[],true,true]] as [$files,$first,$last]){
            $context=new \Mago\Sdk\Analyzer\CodebaseScanContext($version,$token,$files,$first,$last);$legacy($context);$new->scan($context);
            $check(serialize($old)===serialize($new),'Complete original/new nonempty collection scan state');
            if($first&&$files!==[]&&$i<3){$map=(new ReflectionProperty($new,'calls'))->getValue($new);$check(count(array_filter($map,static fn($p):bool=>$p!==null))>0,'Genuine nonempty collection source witness');}
        }
    }
    // Distinct-key clipping preserves both original bounds even when earlier files overlap.
    $property=new Node\Expr\PropertyFetch(new Node\Expr\Variable('x'),new Node\Identifier('name',['startFilePos'=>48,'endFilePos'=>51]));
    $call=new Node\Expr\FuncCall(new Node\Name('foo'),[],['startFilePos'=>60,'endFilePos'=>64]);
    $nodes=[new Node\Stmt\Expression(new Node\Expr\Array_([new Node\ArrayItem($property),new Node\ArrayItem($property),new Node\ArrayItem($call),new Node\ArrayItem($call)]))];
    $facts=$indexMethod->invoke(null,$nodes);
    $check($facts['xmlOutline']['properties']===['48:52'=>true]&&$facts['callReservations']===['60:65'=>true],'Within-source duplicate spans must poison the shared ledgers');
    unset($nodes,$facts);
    $items=[];$variable=new Node\Expr\Variable('x');
    for($i=0;$i<100_002;$i++){$items[]=new Node\ArrayItem(new Node\Expr\PropertyFetch($variable,new Node\Identifier('name',['startFilePos'=>$i*6,'endFilePos'=>$i*6+3])));}
    $items[]=new Node\ArrayItem(new Node\Expr\New_(new Node\Name\FullyQualified('SimpleXMLElement')));
    $nodes=[new Node\Stmt\Declare_([new Node\DeclareItem('ticks',new Node\Scalar\Int_(1))]),new Node\Stmt\Expression(new Node\Expr\Array_($items))];
    $before=hash('sha256',serialize($nodes));$facts=$indexMethod->invoke(null,$nodes);
    $check(count($facts['xmlOutline']['properties'])===100_001&&$facts['xmlOutline']['hasOrigin'],'Property cap must count unique keys and keep scanning for late origins');
    $check($facts['classes']===[]&&$facts['topClasses']===[],'Tick files retain outline facts without admitting class declarations');
    $check(hash('sha256',serialize($nodes))===$before,'Bounded compilation leaves input unchanged');
    unset($nodes,$items,$facts);
    foreach([false,true] as $overlap){
        $source='<?php function ordinary($x) { return $x->name; }';$keyNode=(new \PhpParser\NodeFinder)->findFirstInstanceOf(Syntax::parse($source),Node\Expr\PropertyFetch::class)->name;
        $key=$keyNode->getStartFilePos().':'.($keyNode->getEndFilePos()+1);$filled=[];
        for($i=0;$i<100_000-($overlap?1:0);$i++){$filled['seed:'.$i]=null;}if($overlap){$filled[$key]=null;}
        $old=new \Ichinya\Laramago\Analyzer\StaticAnalysis\SimpleXmlProvenance('/invented/root');$new=new \Ichinya\Laramago\Analyzer\StaticAnalysis\SimpleXmlProvenance('/invented/root');
        foreach([$old,$new] as $scanner){(new ReflectionProperty($scanner,'properties'))->setValue($scanner,$filled);}
        $legacy=Closure::bind($legacyScan,$old,\Ichinya\Laramago\Analyzer\StaticAnalysis\SimpleXmlProvenance::class);
        $context=new \Mago\Sdk\Analyzer\CodebaseScanContext($version,$token,[$file('bound.php',$source)],false,true);$legacy($context);$new->scan($context);
        $check(serialize($old)===serialize($new),'Original/new property bound with cross-file overlap');
        $check((new ReflectionProperty($new,'failed'))->getValue($new)===!$overlap,'Actual original property bound outcome preserved');
    }
} finally {Syntax::clear();$token->cancelled=false;}
echo 'PASS shared source outline: '.($checks-$startChecks)." additional controls.\n";
