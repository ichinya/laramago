<?php
declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\CodebaseScanSyntax;
use Ichinya\Laramago\Analyzer\StaticAnalysis\StoredCallbackSourceDeclarations;
use Mago\Sdk\Analyzer\CodebaseScanContext;
use Mago\Sdk\CancellationTokenInterface;
use Mago\Sdk\PHPVersion;
use Mago\Sdk\Syntax\SourceFile;
use PhpParser\Node;

require getenv('LARAMAGO_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
$version=PHPVersion::fromParts(8,5);
$token=new class implements CancellationTokenInterface {
    public bool $cancelled=false;
    public function isCancelled():bool { return $this->cancelled; }
    public function throwIfCancelled():void { if($this->cancelled) { throw new RuntimeException('Invented cancellation'); } }
    public function subscribe(Closure $callback):int { return 0; }
    public function unsubscribe(int $subscription):void {}
};
$file=static fn(string $path,string $contents):SourceFile=>new SourceFile($version,$path,$contents,[],
    (new ReflectionClass(\Mago\Sdk\Internal\Syntax\NodeStore::class))->newInstanceWithoutConstructor(),
    (new ReflectionClass(\Mago\Sdk\Internal\Syntax\ResolvedNameStore::class))->newInstanceWithoutConstructor(),
    (new ReflectionClass(\Mago\Sdk\Internal\Syntax\TriviaStore::class))->newInstanceWithoutConstructor(),null);
$scan=static function(object $scanner,array $files,bool $first=true,bool $last=true)use($version,$token):void {
    $scanner->scan(new CodebaseScanContext($version,$token,$files,$first,$last));
};
$state=static fn(object $object,string $name):mixed=>(new ReflectionProperty($object,$name))->getValue($object);
$checks=0;
$check=static function(bool $condition,string $label)use(&$checks):void { if(!$condition) { throw new RuntimeException($label); } ++$checks; };
$root=str_replace('\\','/',sys_get_temp_dir()).'/laramago-source-index-'.bin2hex(random_bytes(8));mkdir($root);
$bodies=[
    'namespaces'=>['<?php namespace First { use Vendor\A as Alias; trait Shared {} interface Contract {} enum Choice { case One; } class Top { function f() { class Nested {} } } } namespace Second { class Other {} }',['First\Top','Second\Other']],
    'conditional'=>['<?php if (true) { class Conditional {} } class Top {}',['Top']],
    'multiple'=>['<?php class A {} class B {}',['A','B']],
    'ticks'=>['<?php declare(TICKS=1); class A {}',[]],
    'empty'=>['<?php $value=1;',[]],
];
try {
    foreach($bodies as $label=>[$source,$topNames]) {
        CodebaseScanSyntax::clear();
        $nodes=(new \PhpParser\NodeTraverser(new \PhpParser\NodeVisitor\NameResolver))->traverse((new \PhpParser\ParserFactory)->createForNewestSupportedVersion()->parse($source)??[]);
        $classes=(new \PhpParser\NodeFinder)->findInstanceOf($nodes,Node\Stmt\ClassLike::class);
        $expected=[];$top=[];
        if($label!=='ticks') {
            foreach($classes as $node) {
                if($node->name===null||$node->namespacedName===null) { continue; }
                $name=$node->namespacedName->toString();
                $expected[strtolower($name)]=['name'=>$name,'kind'=>$node->getType(),'start'=>$node->getStartFilePos(),'end'=>$node->getEndFilePos()+1,
                    'entry'=>['file'=>'index.php','diskFile'=>$root.'/index.php','hash'=>hash('sha256',$source),'proofs'=>[]]];
                if(in_array($name,$topNames,true)) { $top[$name]=['name'=>$name,'start'=>$node->getStartFilePos(),'end'=>$node->getEndFilePos()+1]; }
            }
        }
        $expectedTop=array_map(static fn(string $name):array=>$top[$name],$topNames);
        $scanner=new StoredCallbackSourceDeclarations($root);$scan($scanner,[$file('index.php',$source)],true,false);
        $check($state($scanner,'classes')===$expected,'fresh AST oracle matches every classlike binding: '.$label);
        $check($scanner->scanClassIndex('index.php',$source)===$expectedTop,'fresh AST oracle matches top-level class spans: '.$label);
        $check(!$state($scanner,'complete'),'syntax facts do not assert scan completion: '.$label);
        $check($scanner->scanClassIndex('index.php',$source.' ')===null&&$scanner->scanClassIndex('absent.php',$source)===null,'path and analyzed-byte identity remain outside shared facts: '.$label);
        $copy=$scanner->scanClassIndex('index.php',$source);if($copy!==[]) { $copy[0]['name']='Changed'; }
        $check($scanner->scanClassIndex('index.php',$source)===$expectedTop,'returned scalar data cannot mutate the scanner: '.$label);
        $second=new StoredCallbackSourceDeclarations($root.'/other');$scan($second,[$file('index.php',$source)]);
        $check($second->scanClassIndex('index.php',$source)===$expectedTop,'shared pure facts remain independent of the root: '.$label);
        $secondFiles=$state($second,'files');
        $check($secondFiles['index.php']['diskFile']===$root.'/other/index.php','shared facts do not reuse another root physical identity: '.$label);
        $scan($scanner,[],false,true);$check($state($scanner,'complete'),'last batch still controls completion: '.$label);
        $scan($scanner,[$file('bad.php','<?php class Broken {')],false,true);
        $check($state($scanner,'failed')&&$scanner->scanClassIndex('index.php',$source)===null,'a later parse failure invalidates earlier scan facts: '.$label);
        $scan($scanner,[$file('index.php',$source)]);
        $check($scanner->scanClassIndex('index.php',$source)===$expectedTop,'a fresh generation restores analyzed facts: '.$label);
        $token->cancelled=true;try { $scan($scanner,[$file('later.php','<?php class Later {}')],false,true); }catch(RuntimeException){}
        $token->cancelled=false;
        $check($scanner->scanClassIndex('index.php',$source)===null&&$state($scanner,'scanClassIndexes')===[],'cancellation releases retained indexes: '.$label);
        $scan($scanner,[$file('index.php',$source)]);
        $scanner->initialize((new ReflectionClass(\Mago\Sdk\Analyzer\InitializationContext::class))->newInstanceWithoutConstructor());
        $check($scanner->scanClassIndex('index.php',$source)===null&&$state($scanner,'scanClassIndexes')===[],'initialization releases prior-generation indexes: '.$label);
    }
    foreach(['class','trait','interface','enum'] as $kind) {
        $source='<?php '.$kind.' Duplicate {}';$scanner=new StoredCallbackSourceDeclarations($root);
        $scan($scanner,[$file('one.php',$source),$file('two.php',$source)]);
        $check($state($scanner,'failed')&&$scanner->scanClassIndex('one.php',$source)===null,'duplicate '.$kind.' names still poison all syntax indexes');
    }
    echo 'PASS stored source index: '.$checks." controls.\n";
} finally { rmdir($root);CodebaseScanSyntax::clear(); }
