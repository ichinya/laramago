<?php
declare(strict_types=1);

use Ichinya\Laramago\Analyzer\GuardedStringCastContracts;
use Ichinya\Laramago\Analyzer\PossibleCallbackTupleContracts;
use Ichinya\Laramago\Analyzer\StaticAnalysis\CodebaseScanSyntax;
use PhpParser\{Node,NodeFinder};

require getenv('LARAMAGO_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
$directory=sys_get_temp_dir().'/laramago-tuple-prefilter-'.bin2hex(random_bytes(8));mkdir($directory);
$checks=0;$accepted=0;$rejected=0;
$check=static function(bool $passed,string $label)use(&$checks):void {
    if(!$passed) { throw new RuntimeException($label); }++$checks;
};
try {
    $reader=new GuardedStringCastContracts($directory);$finder=new NodeFinder;
    // Compare the gate to the unchanged full lexical policy at every call site,
    // including nested scopes and unsupported source effects in both matrices.
    $sources=[];
    foreach(['fixtures/possible-tuples/source','data/guarded-local-model-tuples/fixtures/source'] as $family) {
        foreach(['positive','negative'] as $name) { $sources[]=file_get_contents(__DIR__.'/'.$family.'/'.$name.'.php.stub'); }
    }
    foreach($sources as $index=>$contents) {
        $path=$directory.'/source.php';file_put_contents($path,$contents);$file=$reader->read($path,$contents);
        $check($file!==null,'fixture parses');
        foreach($finder->findInstanceOf($file['nodes'],Node\Expr\CallLike::class) as $call) {
            foreach($call->getArgs() as $argument) {
                if(!$argument instanceof Node\Arg) { continue; }
                $full=(new PossibleCallbackTupleContracts($directory))->lexical($reader,$file,$call);
                $possible=PossibleCallbackTupleContracts::mayProjectArgument($contents,$argument->value->getStartFilePos(),$argument->value->getEndFilePos()+1);
                $check($full===null||$possible,'full lexical acceptance must survive the necessary gate');
                if($full!==null) { ++$accepted; }if(!$possible) { ++$rejected; }
            }
        }
    }
    $check($accepted>0&&$rejected>0,'oracle exercises admitted and skipped arguments');
    foreach(['[$value]','list($value)','[ /* comment */ $value ]',"[\n\$value\n]"] as $tuple) {
        $contents='<?php class Example { function run() { foreach ($rows as '.$tuple.') { selected(($value)); } } }';
        $start=strpos($contents,'$value',strpos($contents,'selected'));
        $check(PossibleCallbackTupleContracts::mayProjectArgument($contents,$start,$start+6),'all supported tuple spellings survive');
        $check(!PossibleCallbackTupleContracts::mayProjectArgument($contents,0,5),'an argument outside the loop is skipped');
    }
    $plain='<?php selected($value);';$start=strpos($plain,'$value');
    CodebaseScanSyntax::clear();
    $check(!PossibleCallbackTupleContracts::mayProjectArgument($plain,$start,$start+6),'a source without a tuple loop skips full reconstruction');
    $queries=(new ReflectionProperty(CodebaseScanSyntax::class,'queries'))->getValue();
    $check(count($queries)===1,'compact necessary syntax is cached separately from full trees');
    $check(!PossibleCallbackTupleContracts::mayProjectArgument($plain,$start,$start+6),'repeated identical source remains rejected');
    $check((new ReflectionProperty(CodebaseScanSyntax::class,'queries'))->getValue()===$queries,'repeat uses the same exact source query');
    $changed='<?php foreach ($rows as [$value]) { selected($value); }';$changedStart=strrpos($changed,'$value');
    $check(PossibleCallbackTupleContracts::mayProjectArgument($changed,$changedStart,$changedStart+6),'changed source cannot reuse a prior negative');
    $check(PossibleCallbackTupleContracts::mayProjectArgument('<?php broken {',0,5),'malformed syntax retains the complete fallback path');
    $check(PossibleCallbackTupleContracts::mayProjectArgument($plain,-1,5)&&PossibleCallbackTupleContracts::mayProjectArgument($plain,0,strlen($plain)+1),'invalid spans retain the complete fallback path');
    echo 'Tuple argument prefilter passed: '.$checks.' controls; '.$accepted.' full lexical candidates preserved; '.$rejected." impossible arguments skipped.\n";
} finally {
    if(is_file($directory.'/source.php')) { unlink($directory.'/source.php'); }rmdir($directory);CodebaseScanSyntax::clear();
}
