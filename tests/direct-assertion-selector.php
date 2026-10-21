<?php
declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\CodebaseScanSyntax as Syntax;
use Ichinya\Laramago\Analyzer\StaticAnalysis\DirectCallbackReferenceEffects;
use Mago\Sdk\Analyzer\CodebaseScanContext;
use Mago\Sdk\CancellationTokenInterface;
use Mago\Sdk\PHPVersion;
use Mago\Sdk\Syntax\SourceFile;
require getenv('LARAMAGO_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
$fixture=json_decode(file_get_contents(__DIR__.'/fixtures/direct-assertion-selector/oracle.json'),true,flags:JSON_THROW_ON_ERROR);
$oracle=eval('namespace Ichinya\\Laramago\\Analyzer\\StaticAnalysis; use PhpParser\\{Node,NodeFinder}; use Mago\\Sdk\\Analyzer\\CodebaseScanContext; return function(CodebaseScanContext $context):void {'.$fixture['scan_body'].'};');
$version=PHPVersion::fromParts(8,5);
$token=new class implements CancellationTokenInterface {
    public bool $cancelled=false;
    public function isCancelled():bool{return $this->cancelled;}
    public function throwIfCancelled():void{if($this->cancelled){throw new RuntimeException('Cancelled');}}
    public function subscribe(Closure $callback):int{return 0;}
    public function unsubscribe(int $subscription):void{}
};
$file=static fn(string $path,string $source):SourceFile=>new SourceFile($version,$path,$source,[],(new ReflectionClass(\Mago\Sdk\Internal\Syntax\NodeStore::class))->newInstanceWithoutConstructor(),(new ReflectionClass(\Mago\Sdk\Internal\Syntax\ResolvedNameStore::class))->newInstanceWithoutConstructor(),(new ReflectionClass(\Mago\Sdk\Internal\Syntax\TriviaStore::class))->newInstanceWithoutConstructor(),null);
$checks=0;$positive=0;
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok){throw new RuntimeException($label);}++$checks;};
$canonical=static function(DirectCallbackReferenceEffects $scanner,bool $lazy):string{
    $values=[];
    foreach((new ReflectionClass($scanner))->getProperties() as $property){
        if(in_array($property->name,['sourceBytes','materialized'],true)){continue;}
        $value=$property->getValue($scanner);
        if($property->name==='classes'&&$lazy){
            $nodes=[];$declaration=new ReflectionMethod($scanner,'declaration');
            foreach(array_keys($value) as $name){
                $entry=$declaration->invoke($scanner,$name);
                if($entry===null){throw new RuntimeException('A known declaration failed exact-byte materialization');}
                $nodes[$name]=$entry;
            }
            $value=$nodes;
        }
        $values[$property->name]=$value;
    }
    return serialize($values);
};
$compare=static function(array $batches)use($oracle,$version,$token,$check,$canonical):array{
    $old=new DirectCallbackReferenceEffects('/invented/root');$new=new DirectCallbackReferenceEffects('/invented/root');
    $legacy=Closure::bind($oracle,$old,DirectCallbackReferenceEffects::class);
    foreach($batches as [$files,$first,$last]){
        $context=new CodebaseScanContext($version,$token,$files,$first,$last);$errors=[];
        foreach([$legacy,[$new,'scan']] as $call){try{$call($context);$errors[]=null;}catch(Throwable $error){$errors[]=[$error::class,$error->getMessage()];}}
        $check($errors[0]===$errors[1]&&$canonical($old,false)===$canonical($new,true),'Original/new complete semantic state and node graph');
    }
    return [$old,$new];
};
$sources=[
    '<?php class A { function f(){ $ready=false; $fn=function()use(&$ready){$ready=true;}; $fn(); \Testo\Assert::true($ready); } }',
    '<?php namespace Example; use Testo\Assert as Check; class A { function f(){ $ready=true; Check::FALSE($ready); } }',
    '<?php class A { function f(){ $ready=false; \testo\assert::TRUE($ready); } }',
    '<?php class A { function f(){ $ready=false; Other::true($ready); } } trait T {} interface I {} enum E { case X; }',
    '<?php class A { function f(){ $ready=false; \Testo\Assert::{$method}($ready); } }',
    '<?php class A { function f(){ $ready=false; \Testo\Assert::true(...); } }',
    '<?php class A { function f(){ $ready=false; \Testo\Assert::true($ready); global $outside; } }',
    '<?php class A { function f(){ $ready=false; /** @var bool $ready */ \Testo\Assert::true($ready); } }',
    '<?php class A { function &f(){ $ready=false; \Testo\Assert::true($ready); } }',
    '<?php declare(ticks=1); class A { function f(){ $ready=false; \Testo\Assert::true($ready); } }',
    '<?php class A {} class B { function f(){ return new class { function g(){ $ready=false; \Testo\Assert::true($ready); } }; } }',
    '<?php function outside(){ $ready=false; \Testo\Assert::true($ready); } class A { function f(){} }',
    '<?php class A { function f(){ return fn()=>Other::true(false); } }',
    '<?php class A {} class A {}',
    '<?php class First { function f(){ $ready=false; \Testo\Assert::true($ready); } } class Later {}',
    '<?php class First { function f(){ $ready=false; \Testo\Assert::true($ready); $other=true; \Testo\Assert::false($other); } } trait Later {} interface Other {}',
    '<?php namespace Grouped; use Testo\Assert as Check; class First { function f(){ $ready=false; Check::true($ready); } } class Second { function f(){ $ready=true; Check::false($ready); } } class Third {}',
    '<?php class First { function f(){ $ready=false; \Testo\Assert::true($ready); } } class First {}',
    '<?php class NoVariable { function f(){ \Testo\Assert::true(true); } }',
    '<?php class NoInitial { function f($ready){ \Testo\Assert::true($ready); } }',
    '<?php class OpenScope { function f(){ global $outside; $ready=false; \Testo\Assert::true($ready); } }',
    '<?php invalid syntax',
    '<?php '.str_repeat(' ',2_000_001),
];
try{
    foreach($sources as $index=>$source){foreach(["\n","\r\n"] as $newline){
        $source=str_replace("\n",$newline,str_replace("\r\n","\n",$source));$token->cancelled=false;
        [$old,$new]=$compare([[[$file('one.php',$source)],true,true]]);
        if($index<3){$proofs=(new ReflectionProperty($new,'files'))->getValue($new);$check(count(array_merge(...array_column($proofs,'proofs')))>0,'Resolved alias/case-sensitive-name positive assertion witness');++$positive;}
        $compare([[[$file('one.php',$source)],true,false],[[$file('two.php',$source)],false,true],[[],true,true]]);
        $compare([[[$file('one.php',$source)],true,true],[[$file('one.php',$source)],false,true]]);
    }}
    $token->cancelled=true;$compare([[[$file('cancelled.php',$sources[0])],true,true]]);$token->cancelled=false;
    $check($positive===6,'All three genuine assertion forms exercise both newline styles');
}finally{Syntax::clear();$token->cancelled=false;}
echo 'PASS isolated lazy declaration graph: '.$checks." controls.\n";

Syntax::clear();
$bytes='<?php namespace Example; class A { function first(){return 1;} } class B { function second(){return 2;} }';
$scanner=new DirectCallbackReferenceEffects('/invented/root');
$scanner->scan(new CodebaseScanContext($version,$token,[$file('identity.php',$bytes)],true,true));
$cache=new ReflectionProperty($scanner,'materialized');$declaration=new ReflectionMethod($scanner,'declaration');
$check($cache->getValue($scanner)===[],'No assertion: keep compact declarations without materialized nodes');
$check((new ReflectionProperty($scanner,'sourceBytes'))->getValue($scanner)['identity.php']===$bytes,'Unmaterialized declarations retain the exact analyzed bytes');
$a=$declaration->invoke($scanner,'Example\A');$b=$declaration->invoke($scanner,'Example\B');
$check(count($cache->getValue($scanner))===2,'Hydrate the complete requested file together');
$check($a['node']===$declaration->invoke($scanner,'Example\A')['node'],'Stable mutable class identity');
$a['node']->getMethod('first')->name->name='changed';
$check($declaration->invoke($scanner,'Example\A')['node']->getMethod('changed')!==null,'Consumer node mutation survives repeat lookup');
$check(!array_key_exists('identity.php',(new ReflectionProperty($scanner,'sourceBytes'))->getValue($scanner)),'Complete-file materialization releases redundant source bytes without evicting nodes');
$check($declaration->invoke($scanner,'Missing')===null,'Unknown declarations stay absent');
$scanner->scan(new CodebaseScanContext($version,$token,[],true,true));
$check($cache->getValue($scanner)===[]&&(new ReflectionProperty($scanner,'sourceBytes'))->getValue($scanner)===[],'First-batch reset releases backing bytes and nodes');
$many='<?php namespace Many; ';for($i=0;$i<40;$i++){$many.='class C'.$i.' {} ';}
$scanner->scan(new CodebaseScanContext($version,$token,[$file('many.php',$many)],true,true));
for($i=0;$i<40;$i++){$check($declaration->invoke($scanner,'Many\C'.$i)!==null,'No smaller hydration limit may drop a supported declaration');}
Syntax::clear();
echo 'PASS additional lazy identity/lifecycle controls; total '.$checks.".\n";

$scanner->scan(new CodebaseScanContext($version,$token,[$file('classless.php','<?php function plain(){return 1;}')],true,true));
$check((new ReflectionProperty($scanner,'sourceBytes'))->getValue($scanner)===[],'Classless files need no retained backing bytes');
$positiveSource='<?php class Ready { function f(){ $ready=false; \Testo\Assert::true($ready); } }';
$scanner->scan(new CodebaseScanContext($version,$token,[$file('positive.php',$positiveSource)],true,true));
$check(count($cache->getValue($scanner))===1&&(new ReflectionProperty($scanner,'sourceBytes'))->getValue($scanner)===[],'Positive files retain stable nodes without redundant full source');
$check($declaration->invoke($scanner,'Ready')!==null,'Positive declarations remain available after backing bytes release');
$tickSource='<?php declare(ticks=1); class Tick {}';
$scanner->scan(new CodebaseScanContext($version,$token,[$file('ticks.php',$tickSource)],true,true));
$check($cache->getValue($scanner)===[]&&(new ReflectionProperty($scanner,'sourceBytes'))->getValue($scanner)===[],'Unsupported tick files retain neither class nodes nor backing bytes');
Syntax::clear();
echo 'PASS lazy backing-byte lifetime controls; total '.$checks.".\n";
$noProof='<?php class Unproven { function f(){ \Testo\Assert::true(true); } } class Peer {}';
$scanner->scan(new CodebaseScanContext($version,$token,[$file('unproven.php',$noProof)],true,true));
$check($cache->getValue($scanner)===[],'Necessary assertion without supported proof retains no expanded nodes');
$check((new ReflectionProperty($scanner,'sourceBytes'))->getValue($scanner)['unproven.php']===$noProof,'Unproven assertion retains exact analyzed bytes');
$one=$declaration->invoke($scanner,'Unproven');$peer=$declaration->invoke($scanner,'Peer');
$check($one!==null&&$peer!==null&&count($cache->getValue($scanner))===2,'All unproven-file declarations materialize together');
$one['node']->name->name='Edited';
$check($one['node']===$declaration->invoke($scanner,'Unproven')['node'],'Unproven-file mutable identity survives repeat lookup');
Syntax::clear();
echo 'PASS proofless assertion retention controls; total '.$checks.".\n";
