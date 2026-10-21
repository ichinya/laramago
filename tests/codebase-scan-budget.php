<?php
declare(strict_types=1);
use Ichinya\Laramago\Analyzer\StaticAnalysis\CodebaseScanSyntax;
require getenv('LARAMAGO_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
$initial=ini_get('memory_limit');$budget=new ReflectionMethod(CodebaseScanSyntax::class,'treeByteLimit');$bytes=new ReflectionProperty(CodebaseScanSyntax::class,'bytes');$logical=new ReflectionProperty(CodebaseScanSyntax::class,'uncompressedBytes');$trees=new ReflectionProperty(CodebaseScanSyntax::class,'trees');$checks=0;
$check=static function(bool $passed,string $label)use(&$checks):void{if(!$passed){throw new RuntimeException($label);}++$checks;echo 'PASS: '.$label."\n";};
try{
    CodebaseScanSyntax::clear();
    foreach(['256M'=>64,'512M'=>64,'1G'=>128,'2G'=>160,'-1'=>160] as $heap=>$expected){
        $check(ini_set('memory_limit',$heap)!==false,'heap can be set for the budget control');
        $check($budget->invoke(null)===$expected*1024*1024,'AST budget follows the heap and retains its absolute cap: '.$heap);
    }
    ini_set('memory_limit','2G');$literal=str_repeat('x',1024*1024);
    for($index=0;$index<52;$index++){$source='<?php $value = \''.$index.$literal.'\';';CodebaseScanSyntax::parse($source);}
    $check($logical->getValue()>64*1024*1024&&$logical->getValue()<=160*1024*1024,'large heaps can retain the working set beyond the old 64 MiB bound');
    $before=serialize(CodebaseScanSyntax::parse($source));
    $extracts=0;$extract=static function(array $nodes)use(&$extracts):array{++$extracts;return ['statements'=>count($nodes)];};
    $facts=CodebaseScanSyntax::query('<?php $small = 1;','budget-query-control',$extract);
    $check(ini_set('memory_limit','512M')!==false,'heap can shrink with a populated large cache');
    $after=serialize(CodebaseScanSyntax::parse($source));
    $held=$trees->getValue();
    $check($logical->getValue()<=64*1024*1024&&$bytes->getValue()<=64*1024*1024&&$bytes->getValue()===array_sum(array_map('strlen',$held)),'a warm lookup applies the smaller budget with exact byte accounting');
    $check($before===$after,'budget contraction preserves the exact resolved source tree');
    $check(isset($held[hash('sha256',$source)]),'contraction retains the recently used snapshot');
    $check(CodebaseScanSyntax::query('<?php $small = 1;','budget-query-control',$extract)===$facts&&$extracts===1,'tree-budget changes leave independent source facts intact');
    unset($held,$literal,$source,$before,$after);CodebaseScanSyntax::clear();
    $check($bytes->getValue()===0&&$trees->getValue()===[],'a new registration releases all large snapshots');
    echo 'Codebase syntax budget passed: '.$checks." controls.\n";
}finally{CodebaseScanSyntax::clear();gc_collect_cycles();ini_set('memory_limit',$initial);}
