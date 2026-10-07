<?php
declare(strict_types=1);

// Parser and source checks only: no IssueFilterContext is fabricated.
$package=$argv[1];$candidate=$argv[2];$root=$argv[3];
require $package.'/vendor/autoload.php';
foreach(['SourceArgumentDeclarationContracts','PossibleCallbackTupleContracts'] as $class) {
    if(!class_exists('Ichinya\\Laramago\\Analyzer\\'.$class,false)) { require $candidate.'/src/Analyzer/'.$class.'.php'; }
}
$expected=[
    'positive.php::constantText'=>true,'negative.php::constantMixed'=>true,'positive.php::text'=>true,'positive.php::object'=>true,'positive.php::unknownFields'=>true,
    'negative.php::alias'=>false,'negative.php::referenceAlias'=>false,'negative.php::escaped'=>false,
    'negative.php::rebound'=>false,'negative.php::storedCapture'=>false,'negative.php::referenceLoop'=>false,
    'negative.php::duplicateProjection'=>false,'negative.php::nonArrayWrite'=>false,'negative.php::differentArity'=>false,
    'negative.php::keyedRow'=>false,'negative.php::spreadRow'=>false,'negative.php::referencedEntry'=>false,
    'negative.php::inputReference'=>true,'negative.php::referenceReadFormal'=>true,
    'negative.php::builtinReceiver'=>false,
];
$source=new \Ichinya\Laramago\Analyzer\GuardedStringCastContracts($root);$finder=new \PhpParser\NodeFinder;$checks=[];
foreach(['positive.php','negative.php'] as $name) {
    $file=$source->read($name);if($file===null) { throw new RuntimeException('Fixture source did not parse.'); }
    foreach($finder->findInstanceOf($file['nodes'],\PhpParser\Node\Stmt\Foreach_::class) as $loop) {
        $method=null;foreach($source->ancestors($loop,$file) as $parent) { if($parent instanceof \PhpParser\Node\Stmt\ClassMethod) { $method=$parent;break; } }
        if($method===null) { throw new RuntimeException('Missing fixture method owner.'); }
        $key=$name.'::'.$method->name->name;$proof=new \Ichinya\Laramago\Analyzer\PossibleCallbackTupleContracts($root);
        $lexical=$proof->lexical($source,$file,$loop->valueVar);$actual=$lexical!==null;
        if(!array_key_exists($key,$expected)||$actual!==$expected[$key]) {
            throw new RuntimeException('Unexpected lexical row shape: '.$key.' '.json_encode($proof->stages,JSON_THROW_ON_ERROR));
        }
        $checks[$key]=['expectedSourceShape'=>$expected[$key],'sourceShape'=>$actual,'passed'=>true];
    }
}
if(count($checks)!==count($expected)) { throw new RuntimeException('Missing source-only fixture witness.'); }
echo json_encode(['sourceOnly'=>true,'nativeAcceptanceClaimed'=>false,'analyzerJobsStarted'=>0,'fixtureBodiesExecuted'=>false,
    'syntheticIssueFilterContexts'=>0,'checks'=>$checks,'shapeChecks'=>count($checks),'negativeSourceShapes'=>count(array_filter($expected,static fn(bool $value):bool=>!$value))],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;
