<?php
declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\CodebaseScanSyntax;
use Ichinya\Laramago\Analyzer\StaticAnalysis\SimpleXmlProvenance;
use Mago\Sdk\Analyzer\CodebaseScanContext;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\CancellationTokenInterface;
use Mago\Sdk\PHPVersion;
use Mago\Sdk\Syntax\SourceFile;

require getenv('LARAMAGO_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
$oracle = json_decode(file_get_contents(__DIR__.'/fixtures/xml-origin-selector/oracles.json'),true,flags:JSON_THROW_ON_ERROR);
$imports = 'namespace Ichinya\\Laramago\\Analyzer\\StaticAnalysis; use PhpParser\\Node; use PhpParser\\NodeFinder; use PhpParser\\NodeTraverser; use PhpParser\\NodeVisitor\\ParentConnectingVisitor; use Mago\\Sdk\\Analyzer\\CodebaseScanContext; ';
$legacyAnalyse = eval($imports.'return function(Node\\Stmt\\Function_|Node\\Stmt\\ClassMethod $scope): ?array {'.$oracle['analyse_body'].'};');
if (substr_count($oracle['scan_body'],'$this->analyse($scope)') !== 1) { throw new RuntimeException('Original scan delegation changed'); }
$legacyScan = eval($imports.'return function(CodebaseScanContext $context) use ($legacyAnalyse): void {'.str_replace('$this->analyse($scope)','$legacyAnalyse->call($this, $scope)',$oracle['scan_body']).'};');
$version = PHPVersion::fromParts(8,5);
$initialization = (new ReflectionClass(InitializationContext::class))->newInstanceWithoutConstructor();
$token = new class implements CancellationTokenInterface {
    public bool $cancelled=false;
    public function isCancelled():bool{return $this->cancelled;}
    public function throwIfCancelled():void{if($this->cancelled){throw new RuntimeException('Invented XML cancellation');}}
    public function subscribe(Closure $callback):int{return 0;}
    public function unsubscribe(int $subscription):void{}
};
$file = static fn(string $path,string $contents):SourceFile => new SourceFile($version,$path,$contents,[],
    (new ReflectionClass(\Mago\Sdk\Internal\Syntax\NodeStore::class))->newInstanceWithoutConstructor(),
    (new ReflectionClass(\Mago\Sdk\Internal\Syntax\ResolvedNameStore::class))->newInstanceWithoutConstructor(),
    (new ReflectionClass(\Mago\Sdk\Internal\Syntax\TriviaStore::class))->newInstanceWithoutConstructor(),null);
$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok){throw new RuntimeException($label);}++$checks;};
$normal = '$document = \\simplexml_load_string($text); if ($document === false) { throw new \\RuntimeException("bad"); } if (count($document->group) !== 1) { throw new \\RuntimeException("one"); } return (string)$document->group["size"];';
$positive = '<?php function read(string $text): string {'.$normal.'}';
$samples = [
    $positive,
    str_replace('\\simplexml_load_string($text)','new \\SimpleXMLElement($text)',$positive),
    '<?php use SimpleXMLElement as Document; function read(string $text): string {'.str_replace('\\simplexml_load_string($text)','new Document($text)',$normal).'}',
    '<?php use function simplexml_load_string as parseDocument; function read(string $text): string {'.str_replace('\\simplexml_load_string','parseDocument',$normal).'}',
    '<?php namespace Example; function read(string $text): string {'.str_replace('\\simplexml_load_string','simplexml_load_string',$normal).'}',
    '<?php namespace Example; function read(string $text): string {'.str_replace('\\simplexml_load_string','\\SIMPLEXML_LOAD_STRING',$normal).'}',
    '<?php function ordinary($row) { return $row->name; }',
    '<?php function nullable($row) { return $row?->name; }',
    '<?php function dynamic($row,$name) { return $row->{$name}; }',
    '<?php function text() { return "new SimpleXMLElement simplexml_load_string"; }',
    '<?php function closure() { return function($x) { return simplexml_load_string($x); }; }',
    '<?php function unsafe(string $text) { $ref =& $text; '. $normal.'}',
    '<?php function opaque(\\SimpleXMLElement $document) { return (string)$document->group["size"]; }',
    '<?php declare(ticks=1); class Host { function read(string $text): string {'.$normal.'} }',
    '<?php class Host { function read(string $text): string {'.$normal.'} function ordinary($x) { return $x->name; } }',
    '<?php malformed syntax',
    '<?php '.str_repeat(' ',2_000_001),
];
$compare = static function(array $batches)use($legacyScan,$token,$version,$initialization,$check):array {
    CodebaseScanSyntax::clear();$token->cancelled=false;
    $old = new SimpleXmlProvenance('/invented/xml/root');$new = new SimpleXmlProvenance('/invented/xml/root');
    $old->initialize($initialization);$new->initialize($initialization);
    $scan = Closure::bind($legacyScan,$old,SimpleXmlProvenance::class);
    foreach($batches as [$files,$first,$last,$cancelled]) {
        $token->cancelled=$cancelled;
        $context = new CodebaseScanContext($version,$token,$files,$first,$last);
        $errors=[];
        foreach([$scan,[$new,'scan']] as $call) {
            try {$call($context);$errors[]=null;} catch(Throwable $failure) {$errors[]=[$failure::class,$failure->getMessage()];}
        }
        $check($errors[0]===$errors[1],'XML selector changes exceptions');
        $check(serialize($old)===serialize($new),'XML selector changes complete scanner state');
    }
    $token->cancelled=false;return [$old,$new];
};
try {
    $positiveCount=0;
    foreach($samples as $index=>$source) {
        [$old,$new]=$compare([[[$file('sample.php',$source)],true,true,false]]);
        $entries=(new ReflectionProperty($new,'files'))->getValue($new);
        $proofs=count($entries['sample.php']['proofs']??[]);
        if($index<6){$check($proofs>0,'Required parser/constructor/alias positive witness');$positiveCount+=$proofs;}
        $compare([[[$file('sample.php',$source)],true,false,false],[[$file('second.php',$source)],false,true,false],[[$file('second.php',$source)],false,true,false],[[$file('cancel.php',$source)],true,true,true],[[],true,true,false]]);
    }
    $needle='\\simplexml_load_string($text)';
    $unrelated=str_replace($needle,str_pad('opaque()',strlen($needle)),$positive);
    $check(strlen($unrelated)===strlen($positive),'Collision control preserves all later property spans');
    [$old,$new]=$compare([[[$file('positive.php',$positive)],true,false,false],[[$file('unrelated.php',$unrelated)],false,true,false]]);
    $map=(new ReflectionProperty($new,'properties'))->getValue($new);
    $check($map!==[]&&count(array_filter($map,static fn($value):bool=>$value!==null))===0,'Unrelated non-XML property accesses must poison exact shared spans');
    $check($positiveCount>=6,'Positive coverage is nonvacuous');
} finally {CodebaseScanSyntax::clear();$token->cancelled=false;}
echo 'PASS XML origin selector: '.$checks." original/new state and exception controls.\n";
