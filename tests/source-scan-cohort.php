<?php
declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\CodebaseScanSyntax as Syntax;
use Mago\Sdk\Analyzer\CodebaseScanContext;
use Mago\Sdk\CancellationTokenInterface;
use Mago\Sdk\PHPVersion;
use Mago\Sdk\Syntax\SourceFile;
use PhpParser\Node;

require getenv('LARAMAGO_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
$fixture=json_decode(file_get_contents(__DIR__.'/fixtures/source-scan-cohort/oracles.json'),true,flags:JSON_THROW_ON_ERROR);
$version=PHPVersion::fromParts(8,5);
$context=(new ReflectionClass(\Mago\Sdk\Analyzer\InitializationContext::class))->newInstanceWithoutConstructor();
$token=new class implements CancellationTokenInterface {
    public bool $cancelled=false;
    public function isCancelled():bool{return $this->cancelled;}
    public function throwIfCancelled():void{if($this->cancelled){throw new RuntimeException('Invented source cancellation');}}
    public function subscribe(Closure $callback):int{return 0;} public function unsubscribe(int $subscription):void{}
};
$file=static fn(string $path,string $contents):SourceFile=>new SourceFile($version,$path,$contents,[],
    (new ReflectionClass(\Mago\Sdk\Internal\Syntax\NodeStore::class))->newInstanceWithoutConstructor(),
    (new ReflectionClass(\Mago\Sdk\Internal\Syntax\ResolvedNameStore::class))->newInstanceWithoutConstructor(),
    (new ReflectionClass(\Mago\Sdk\Internal\Syntax\TriviaStore::class))->newInstanceWithoutConstructor(),null);
$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok){throw new RuntimeException($label);}++$checks;};
$initialize=static function()use($context):void{
    Syntax::clear();
    foreach(['ValidatedYieldedLists','DefensiveArrayGuardProofs','ReconstructedArrayShapes','FiniteMapArgumentProofs'] as $name){$class='Ichinya\\Laramago\\Analyzer\\StaticAnalysis\\'.$name;(new $class)->initialize($context);}
    foreach(['ModelRefreshReturnTypeProvider','KernelIntersectionProvider','AssertedPureGetterReturnProvider'] as $name){$class='Ichinya\\Laramago\\Analyzer\\'.$name;(new $class('.'))->initialize($context);}
    (new \Ichinya\Laramago\Analyzer\ValidatedIntegerAssertionProvider)->initialize($context);
};
$samples=[
    '<?php namespace Example; class A { function f($row) { return $row->refresh(); } }',
    '<?php function f($raw) { $value=filter_var($raw,FILTER_VALIDATE_INT,["options"=>["min_range"=>0]]); if(is_int($value)){ return $value; } }',
    '<?php function f($kernel) { if($kernel instanceof \Illuminate\Contracts\Http\Kernel) { $kernel->handle(); } }',
    '<?php function f($row) { \Testo\Assert::notNull($row->getName()); return $row->getName(); }',
    '<?php function f() { $input=load(); $value=is_array($input)?($input["name"]??null):null; if(!is_string($value)){throw new \UnexpectedValueException("bad");} return $value; }',
    '<?php $values=array_map(static fn(string $name): Entry=>new Entry($name),["alpha","beta"]);',
    '<?php declare(ticks=1); class Tick { function f($x) { return $x->refresh(); } }',
    '<?php namespace First { trait Shared {} interface Contract {} enum Choice { case One; } } namespace Second { class Other {} }',
    '<?php class Duplicate {} class duplicate {}',
    '<?php malformed syntax',
];
$yieldTest=file_get_contents(__DIR__.'/validated-yielded-lists.php');
preg_match("/\\\$body = <<<'PHP'\\R(.*?)\\RPHP;/s",$yieldTest,$match);
if(!isset($match[1])){throw new RuntimeException('Original invented yielded-list body not found');}
$samples[]='<?php function yielded(string $json): iterable {'.$match[1].'}';
$origin='$record = $input["record"]; ';
$guard='if (!is_array($record) || !in_array($record["mode"] ?? null, ["all", "one"], true) || ($record["mode"] === "one" && !is_int($record["entry"] ?? null))) { throw new RuntimeException("Invalid"); } ';
$copy='$record = $record["mode"] === "all" ? ["mode" => "all"] : ["mode" => "one", "entry" => $record["entry"]]; ';
$samples[]='<?php final class Record { /** @param array{mode: \'all\'}|array{mode: \'one\', entry: int} $record */ private function __construct(array $record) {} public static function build(array $input): self {'.$origin.$guard.$copy.'return new self($record); } }';
$oracles=[];
foreach($fixture['classes'] as $name=>$data){
    $oracles[$name]=eval('namespace Ichinya\\Laramago\\Analyzer\\StaticAnalysis; use PhpParser\\Node; use PhpParser\\NodeFinder; return function(\\Mago\\Sdk\\Analyzer\\CodebaseScanContext $context): void {'.$data['scan_body'].'};');
}
$states=static function(object $scanner):string{return serialize($scanner);};
$observed=[];
try{
    foreach($fixture['classes'] as $name=>$data){
        $class='Ichinya\\Laramago\\Analyzer\\StaticAnalysis\\'.$name;
        foreach($samples as $index=>$source){
            $initialize();$token->cancelled=false;
            $construct=static fn():object=>(new ReflectionClass($class))->getConstructor()===null?new $class:new $class('/invented/source/root');
            $old=$construct();$new=$construct();
            $legacy=Closure::bind($oracles[$name],$old,$class);
            $scan=new CodebaseScanContext($version,$token,[$file('cases.php',$source)],true,true);
            $errors=[];
            foreach([$legacy,[$new,'scan']] as $call){try{$call($scan);$errors[]=null;}catch(Throwable $failure){$errors[]=[$failure::class,$failure->getMessage()];}}
            $check($errors[0]===$errors[1],'Exception behavior differs: '.$name.'/'.$index);
            $check($states($old)===$states($new),'Complete scanner state differs: '.$name.'/'.$index);
            $values=(new ReflectionClass($new))->hasProperty('calls')?(new ReflectionProperty($new,'calls'))->getValue($new):[];
            $positive=count(array_filter($values,static fn($value):bool=>$value!==null));
            if((new ReflectionClass($new))->hasProperty('files')){foreach((new ReflectionProperty($new,'files'))->getValue($new) as $entry){$positive+=count($entry['proofs']??[]);}}
            $observed[$name]=($observed[$name]??0)+$positive;
            // Repeated files, partial batches, cancellation and reinitialization preserve state.
            foreach([[[$file('again.php',$source)],true,false,false],[[$file('again.php',$source)],false,true,false],[[],false,true,true],[[],true,true,false]] as [$files,$first,$last,$cancelled]){
                $token->cancelled=$cancelled;$scan=new CodebaseScanContext($version,$token,$files,$first,$last);$errors=[];
                foreach([$legacy,[$new,'scan']] as $call){try{$call($scan);$errors[]=null;}catch(Throwable $failure){$errors[]=[$failure::class,$failure->getMessage()];}}
                $check($errors[0]===$errors[1]&&$states($old)===$states($new),'Lifecycle/collision/cancellation state differs: '.$name.'/'.$index);
            }
        }
        $check(($observed[$name]??0)>0,'No positive source witness: '.$name);
    }
    foreach(['ValidatedIntegerCalls','ModelRefreshCalls','KernelIntersectionCalls','AssertedPureGetterCalls'] as $name){
        $class='Ichinya\\Laramago\\Analyzer\\StaticAnalysis\\'.$name;
        foreach([false,true] as $overlap){
            $initialize();$token->cancelled=false;$source='<?php foo(); bar();';
            $nodes=Syntax::parse($source);$call=(new \PhpParser\NodeFinder)->findFirstInstanceOf($nodes,Node\Expr\FuncCall::class);
            $key=$call->getStartFilePos().':'.($call->getEndFilePos()+1);
            $filled=[];for($i=0;$i<100_000-($overlap?1:0);++$i){$filled['reservation:'.$i]=null;}
            if($overlap){$filled[$key]=null;}
            $construct=static fn():object=>(new ReflectionClass($class))->getConstructor()===null?new $class:new $class('/invented/source/root');
            $old=$construct();$new=$construct();
            foreach([$old,$new] as $scanner){
                (new ReflectionProperty($scanner,'calls'))->setValue($scanner,$filled);
                if((new ReflectionClass($scanner))->hasProperty('started')){(new ReflectionProperty($scanner,'started'))->setValue($scanner,true);}
            }
            $legacy=Closure::bind($oracles[$name],$old,$class);$scan=new CodebaseScanContext($version,$token,[$file('cap.php',$source)],false,true);
            $legacy($scan);$new->scan($scan);
            $check($states($old)===$states($new),'Compacted admission changes the cross-file limit: '.$name);
            $check((new ReflectionProperty($new,'failed'))->getValue($new)&& (new ReflectionProperty($new,'calls'))->getValue($new)===[],'The unchanged call bound must actually fail closed: '.$name);
        }
    }
    unset($filled,$old,$new,$scanner,$nodes,$call);
    $initialize();$projectors=(new ReflectionProperty(Syntax::class,'projections'))->getValue();
    $check(count($projectors)===10,'All nine proof policies and their shared source index must be registered by active owners');
    foreach(array_slice($samples,0,9) as $source){
        foreach($projectors as $id=>$extractor){
            $function=new ReflectionFunction($extractor);
            $check($function->getClosureThis()===null&&$function->getStaticVariables()===[],'Projection retains instance state');
            $nodes=Syntax::parse($source);$before=serialize($nodes);$isolated=serialize($extractor($nodes));
            $check(serialize($nodes)===$before,'Projection mutated its input: '.$id);
            $check(serialize(Syntax::query($source,$id,$extractor))===$isolated,'Batched projection differs: '.$id);
        }
    }
    echo 'PASS source scan cohort: '.$checks.' controls; positive source witnesses '.json_encode($observed,JSON_THROW_ON_ERROR)."\n";
}finally{Syntax::clear();}
