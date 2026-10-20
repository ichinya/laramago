<?php
declare(strict_types=1);
use Ichinya\Laramago\Analyzer\StaticAnalysis\CodebaseScanSyntax as Syntax;
require getenv('LARAMAGO_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
$checks=0;
$check=static function(bool $value,string $message)use(&$checks):void {
    if(!$value){throw new RuntimeException($message);}++$checks;
};
Syntax::clear();
$calls=['alpha'=>0,'beta'=>0];
$alpha=static function(array $nodes)use(&$calls):array{++$calls['alpha'];return ['statements'=>count($nodes)];};
$beta=static function(array $nodes)use(&$calls):array{++$calls['beta'];return ['statements'=>count($nodes)];};
Syntax::registerProjection('replay-alpha:v1',$alpha);
Syntax::registerProjection('replay-beta:v1',$beta);
$source='<?php final class ReplayCase {}';
$expected=Syntax::query($source,'replay-alpha:v1',$alpha);
$check($expected===['statements'=>1]&&$calls===['alpha'=>1,'beta'=>1],'Initial batch did not compute both source policies');
// Two bounded 4-MiB values exceed the actual8-MiB byte budget and evict both
// small policies. No reflection mutates cache entries to manufacture a miss.
$large=static fn(array $nodes):string=>str_repeat('x',4*1024*1024);
Syntax::query('<?php','evict-one:v1',$large);
Syntax::query('<?php','evict-two:v1',$large);
$check(Syntax::query($source,'replay-alpha:v1',$alpha)===$expected,'Eviction changed the requested source facts');
$check($calls===['alpha'=>2,'beta'=>1],'A cold sibling was replayed instead of only the requested policy');
$check(Syntax::query($source,'replay-beta:v1',$beta)===$expected&&$calls===['alpha'=>2,'beta'=>2],'Later sibling request changed source facts');
$copy=Syntax::query($source,'replay-alpha:v1',$alpha);$copy['polluted']=true;
$check(Syntax::query($source,'replay-alpha:v1',$alpha)===$expected,'Requested output ownership changed');
Syntax::clear();$badCalls=0;
$bad=static function(array $nodes)use(&$badCalls):never{++$badCalls;throw new RuntimeException('expected-readonly-policy-failure');};
$good=static fn(array $nodes):bool=>true;
Syntax::registerProjection('bad:v1',$bad);Syntax::registerProjection('good:v1',$good);
$check(Syntax::query('<?php','good:v1',$good)===true&&$badCalls===1,'Speculative failure leaked to another policy');
for($attempt=0;$attempt<2;++$attempt){
    try{Syntax::query('<?php','bad:v1',$bad);throw new RuntimeException('Missing original failure');}
    catch(RuntimeException $error){$check($error->getMessage()==='expected-readonly-policy-failure','Requested failure changed');}
}
$check($badCalls===3,'A requested extraction failure was cached');
$check((new ReflectionProperty(Syntax::class,'projectionDepth'))->getValue()===0
    &&(new ReflectionProperty(Syntax::class,'projectionRoots'))->getValue()===null,'Failure retained an active source tree');
Syntax::clear();$small=static fn(array $nodes):int=>count($nodes);
Syntax::registerProjection('bounded:v1',$small);
for($i=0;$i<1026;++$i){
    $contents='<?php /* '.$i.' */';
    // The parser may preserve a trailing comment as a Nop; compare the actual
    // standalone parser result instead of assuming comment-only roots are empty.
    $expected=count((new \PhpParser\ParserFactory)->createForNewestSupportedVersion()->parse($contents)??[]);
    $check(Syntax::query($contents,'bounded:v1',$small)===$expected,'A distinct exact source changed its result');
}
$seen=(new ReflectionProperty(Syntax::class,'projectedSources'))->getValue();
$check(count($seen)===1024,'Completed-batch source metadata exceeded its bound');
$check(array_filter($seen,static fn(mixed $value):bool=>$value!==true)===[],'Completed-source metadata retained a value other than a boolean marker');
$check(!isset($seen[hash('sha256','<?php /* 0 */')])&&isset($seen[hash('sha256','<?php /* 1025 */')]),'Completed-source metadata did not retire older hashes');
Syntax::clear();
$check((new ReflectionProperty(Syntax::class,'projectedSources'))->getValue()===[],'Initialization did not clear completed-source metadata');
echo json_encode(['complete'=>true,'checks'=>$checks,'actual_byte_eviction'=>true,'failed_queries_retried'=>true,
    'source_hash_cap'=>1024,'source_bytes_and_nodes_retained_by_marker'=>false],JSON_THROW_ON_ERROR)."\n";
