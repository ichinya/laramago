<?php
declare(strict_types=1);
use Ichinya\Laramago\Analyzer\StaticAnalysis\CodebaseScanSyntax as Syntax;
use PhpParser\Node;
require getenv('LARAMAGO_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
$checks=0;
$check=static function(bool $condition,string $label)use(&$checks):void { if(!$condition) { throw new RuntimeException($label); } ++$checks; };
$source='<?php namespace Projection; use Vendor\Row as Alias; class Example { function read(): Alias { throw new \Exception; } }';
try {
    Syntax::clear();$calls=['a'=>0,'b'=>0];$seen=[];
    foreach(['a','b'] as $name) {
        Syntax::registerProjection($name,static function(array $nodes)use($name,&$calls,&$seen):Node {
            ++$calls[$name];$seen[$name]=$nodes[0];return $nodes[0];
        });
    }
    $a=Syntax::query($source,'a',static fn()=>throw new RuntimeException('registered extractor not used'));
    $check($calls===['a'=>1,'b'=>1]&&$seen['a']===$seen['b'],'active projections share one owned resolved tree');
    $a->name=new Node\Name('Mutated');
    $b=Syntax::query($source,'b',static fn()=>throw new RuntimeException('cached projection recomputed'));
    $check($calls===['a'=>1,'b'=>1]&&$b->name->toString()==='Projection','consumer mutation cannot cross projection snapshots');
    $fresh=Syntax::parse($source);$check($fresh[0]->name->toString()==='Projection','projection returns cannot mutate public parsed snapshots');
    $b->setAttribute('comments',[]);
    $check(Syntax::query($source,'b',static fn()=>null)!==$b,'every cached projection return has independent mutable nodes');
    Syntax::query($source.' ','a',static fn()=>null);
    $check($calls===['a'=>2,'b'=>2],'changed bytes rebuild the entire active cohort');

    Syntax::clear();$speculative=0;$failure=new LogicException('Invented projection failure');
    Syntax::registerProjection('good',static fn(array $nodes):int=>count($nodes));
    Syntax::registerProjection('bad',static function()use(&$speculative,$failure):never { ++$speculative;throw $failure; });
    $check(Syntax::query($source,'good',static fn()=>0)===1&&$speculative===1,'a speculative failure does not reject a successful requested policy');
    for($i=0;$i<2;++$i) {
        try { Syntax::query($source,'bad',static fn()=>0);throw new RuntimeException('Missing requested failure'); }
        catch(LogicException $caught) { $check($caught===$failure,'the requested original failure remains observable and uncached'); }
    }
    $check($speculative===3,'failed speculative projections are retried when requested');

    Syntax::clear();$calls=0;
    Syntax::registerProjection('bad-serialize',static function()use(&$calls):Closure { ++$calls;return static fn()=>null; });
    Syntax::registerProjection('good',static fn():bool=>true);
    $check(Syntax::query($source,'good',static fn()=>false)===true,'speculative serialization failure cannot reject another policy');
    try { Syntax::query($source,'bad-serialize',static fn()=>null);throw new RuntimeException('Missing serialization failure'); }
    catch(Exception $failure) { $check(str_contains($failure->getMessage(),'Closure')&&$calls===2,'requested serialization failure is not hidden or cached'); }

    Syntax::clear();$calls=0;
    Syntax::registerProjection('valid',static function()use(&$calls):int { ++$calls;return 1; });
    for($i=0;$i<2;++$i) {
        try { Syntax::query('<?php class Broken {','valid',static fn()=>0);throw new RuntimeException('Missing parse failure'); }
        catch(\PhpParser\Error) { $check($calls===0,'invalid source never reaches any projection'); }
    }
    Syntax::clear();$calls=0;
    Syntax::registerProjection('unused',static function()use(&$calls):int { ++$calls;return 1; });
    $check(Syntax::query($source,'ordinary',static fn():int=>42)===42&&$calls===0,'unregistered queries retain ordinary isolated extraction');
    Syntax::clear();
    $check(Syntax::query($source,'unused',static fn():int=>7)===7&&$calls===0,'registration reset removes old cohort extractors');

    Syntax::clear();$nestedCalls=0;
    Syntax::registerProjection('outer',static function()use($source,&$nestedCalls):int { return Syntax::query($source,'inner',static function()use(&$nestedCalls):int { ++$nestedCalls;return 3; }); });
    Syntax::registerProjection('inner',static function()use(&$nestedCalls):int { ++$nestedCalls;return 3; });
    $check(Syntax::query($source,'outer',static fn()=>0)===3&&$nestedCalls===1,'nested queries use isolated extraction without recursively expanding cohorts');
    $check(Syntax::query($source,'inner',static fn()=>0)===3&&$nestedCalls===1,'a nested retained result is preserved by the cohort');

    Syntax::clear();$large=str_repeat('x',8*1024*1024);
    Syntax::registerProjection('small',static fn():int=>5);
    Syntax::registerProjection('large',static fn():string=>$large);
    $check(Syntax::query($source,'small',static fn()=>0)===5,'oversized speculative results do not replace the requested snapshot');
    $check(Syntax::query($source,'large',static fn()=>null)===$large,'oversized requested results are still returned');
    $bytes=(new ReflectionProperty(Syntax::class,'queryBytes'))->getValue();
    $check($bytes<=8*1024*1024,'cohort admission retains the original query byte bound');
    Syntax::clear();
    Syntax::registerProjection('0',static fn():int=>9);
    $check(Syntax::query($source,'0',static fn():int=>0)===9,'numeric policy names preserve strict requested-policy identity');
    echo 'PASS source projections: '.$checks." controls.\n";
} finally { Syntax::clear(); }
