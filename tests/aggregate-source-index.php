<?php
declare(strict_types=1);
use Ichinya\Laramago\Analyzer\StaticAnalysis\AggregateProjectionCalls;
use Ichinya\Laramago\Analyzer\StaticAnalysis\CodebaseScanSyntax as Syntax;
use Ichinya\Laramago\Analyzer\StaticAnalysis\StoredCallbackSourceDeclarations as Declarations;
use Mago\Sdk\Analyzer\CodebaseScanContext;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\CancellationTokenInterface;
use Mago\Sdk\PHPVersion;
use Mago\Sdk\Syntax\SourceFile;
use PhpParser\Node;
require getenv('LARAMAGO_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
$fixture=json_decode(file_get_contents(__DIR__.'/fixtures/aggregate-source-index/oracle.json'),true,flags:JSON_THROW_ON_ERROR);
$oracle=eval('namespace Ichinya\\Laramago\\Analyzer\\StaticAnalysis; use PhpParser\\Node; use PhpParser\\NodeFinder; use Mago\\Sdk\\Analyzer\\CodebaseScanContext; return function(CodebaseScanContext $context):void {'.$fixture['scan_body'].'};');
$version=PHPVersion::fromParts(8,5);$init=(new ReflectionClass(InitializationContext::class))->newInstanceWithoutConstructor();
$token=new class implements CancellationTokenInterface {public function isCancelled():bool{return false;}public function throwIfCancelled():void{}public function subscribe(Closure $callback):int{return 0;}public function unsubscribe(int $subscription):void{}};
$file=static fn(string $path,string $source):SourceFile=>new SourceFile($version,$path,$source,[],(new ReflectionClass(\Mago\Sdk\Internal\Syntax\NodeStore::class))->newInstanceWithoutConstructor(),(new ReflectionClass(\Mago\Sdk\Internal\Syntax\ResolvedNameStore::class))->newInstanceWithoutConstructor(),(new ReflectionClass(\Mago\Sdk\Internal\Syntax\TriviaStore::class))->newInstanceWithoutConstructor(),null);
$checks=0;$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok){throw new RuntimeException($label);}++$checks;};
$compare=static function(array $batches,array $seed=[])use($oracle,$version,$token,$check):array{
    $old=new AggregateProjectionCalls;$new=new AggregateProjectionCalls;
    foreach([$old,$new] as $scanner){(new ReflectionProperty($scanner,'calls'))->setValue($scanner,$seed);}
    $legacy=Closure::bind($oracle,$old,AggregateProjectionCalls::class);
    foreach($batches as [$files,$first,$last]){
        $context=new CodebaseScanContext($version,$token,$files,$first,$last);$errors=[];
        foreach([$legacy,[$new,'scan']] as $call){try{$call($context);$errors[]=null;}catch(Throwable $error){$errors[]=[$error::class,$error->getMessage()];}}
        $check($errors[0]===$errors[1]&&serialize($old)===serialize($new),'Full original/new aggregate state and exception outcome');
    }
    return [$old,$new];
};
$sources=['<?php $rows=$query->get();','<?php $rows=$query->GET()->get();','<?php $rows=$query?->get(); StaticRows::get(); ordinary(); new Other;','<?php $rows=$query->{$method}();','<?php $rows=$query->get(...);','<?php namespace Example; function f($x) { return $x->where(fn($x)=>$x->field)->get(); }','<?php malformed syntax'];
try{
    Syntax::clear();new AggregateProjectionCalls;$check((new ReflectionProperty(Syntax::class,'projections'))->getValue()===[],'Inactive construction registers no getters');
    (new \Ichinya\Laramago\Analyzer\EloquentAggregateProjectionProvider('.'))->initialize($init);
    $policies=(new ReflectionProperty(Syntax::class,'projections'))->getValue();$check(count($policies)===2&&isset($policies['stored-callback-source-index:v4']),'Actual owner registers shared facts and its one getter projection');
    foreach($sources as $i=>$source){
        [$old,$new]=$compare([[[$file('one.php',$source)],true,false],[[$file('two.php',$source)],false,true],[[],true,true]]);
        $compare([[[$file('one.php',$source)],true,true],[[$file('one.php',$source)],false,true]]);
        if($i<2){[$old,$new]=$compare([[[$file('one.php',$source)],true,true]]);$check(count(array_filter((new ReflectionProperty($new,'calls'))->getValue($new),static fn($p):bool=>$p!==null))>0,'Genuine ordinary and uppercase/nested getter witness');}
    }
    foreach([false,true] as $overlap){
        $source='<?php $query->get(); $query->other();';$nodes=Syntax::parse($source);$call=(new \PhpParser\NodeFinder)->findFirstInstanceOf($nodes,Node\Expr\MethodCall::class);$key=$call->getStartFilePos().':'.($call->getEndFilePos()+1);
        $seed=[];for($i=0;$i<100_000-($overlap?1:0);$i++){$seed['reserved:'.$i]=null;}if($overlap){$seed[$key]=null;}
        [$old,$new]=$compare([[[$file('bound.php',$source)],false,true]],$seed);$check((new ReflectionProperty($new,'failed'))->getValue($new)&&(new ReflectionProperty($new,'calls'))->getValue($new)===[],'Original unique-dispatch bound actually fails closed');
    }
    unset($seed,$old,$new,$nodes,$call);
    $source='<?php $q->get(); $q?->get(); Box::get(); helper(); new Box;';$nodes=Syntax::parse($source);$expected=[];
    foreach((new \PhpParser\NodeFinder)->find($nodes,static fn(Node $n):bool=>$n instanceof Node\Expr\MethodCall||$n instanceof Node\Expr\NullsafeMethodCall||$n instanceof Node\Expr\StaticCall) as $call){$key=$call->getStartFilePos().':'.($call->getEndFilePos()+1);$expected[$key]=array_key_exists($key,$expected);}
    $check(Declarations::sourceIndexSnapshot($source)['dispatchReservations']===$expected,'Typed dispatch ledger excludes function and constructor spans');
    [$old,$new]=$compare([[[$file('positive.php','<?php $q->get();')],true,true]]);$map=(new ReflectionProperty($new,'calls'))->getValue($new);$key=array_key_first($map);[$start,$end]=array_map('intval',explode(':',$key));
    $first=$new->call(new \Mago\Sdk\Span($start,$end));$first->name->name='changed';$check($new->call(new \Mago\Sdk\Span($start,$end))===$first,'Scanner retains its original mutable return identity');
    [$old,$other]=$compare([[[$file('other.php','<?php $q->get();')],true,true]]);$check($other->call(new \Mago\Sdk\Span($start,$end))->name->name==='get','Getter snapshot mutation cannot cross consumers or reach the cache');
}finally{Syntax::clear();}
echo 'PASS aggregate source index: '.$checks." controls.\n";
