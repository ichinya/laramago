<?php
declare(strict_types=1);

// Inspect source only. No SDK context, callable body, or analyzer is executed.
$package=$argv[1];$candidate=$argv[2];require $package.'/vendor/autoload.php';
$parser=(new \PhpParser\ParserFactory)->createForNewestSupportedVersion();$finder=new \PhpParser\NodeFinder;
$nodes=$parser->parse(file_get_contents($candidate.'/tests/fixtures/possible-tuples/NativeHarness.php'))??[];
$hook=$finder->findFirst($nodes,static fn(\PhpParser\Node $node):bool=>$node instanceof \PhpParser\Node\Stmt\ClassMethod&&$node->name->name==='filterIssue');
if(!$hook instanceof \PhpParser\Node\Stmt\ClassMethod) { throw new RuntimeException('Missing observation hook.'); }
$checks=[];$check=static function(string $label,bool $passed)use(&$checks):void { if(!$passed) { throw new RuntimeException('Source observer contract failed: '.$label); }$checks[$label]=true; };
$check('native hook formal remains IssueFilterContext',$hook->params[0]->type instanceof \PhpParser\Node\Name&&$hook->params[0]->type->toString()==='IssueFilterContext');
$writes=$finder->find($hook->stmts,static fn(\PhpParser\Node $node):bool=>$node instanceof \PhpParser\Node\Expr\Assign&&$node->var instanceof \PhpParser\Node\Expr\Variable&&$node->var->name==='context');
$check('actual hook context is never rebound',$writes===[]);
$calls=$finder->find($hook->stmts,static fn(\PhpParser\Node $node):bool=>$node instanceof \PhpParser\Node\Expr\MethodCall&&$node->name instanceof \PhpParser\Node\Identifier&&$node->name->name==='evaluate');
$check('one fresh evaluation receives the original hook context',count($calls)===1&&$calls[0]->args[0]->value instanceof \PhpParser\Node\Expr\Variable&&$calls[0]->args[0]->value->name==='context');
$guards=$finder->find($hook->stmts,static fn(\PhpParser\Node $node):bool=>$node instanceof \PhpParser\Node\Stmt\If_&&$node->cond instanceof \PhpParser\Node\Expr\PropertyFetch&&$node->cond->name instanceof \PhpParser\Node\Identifier&&$node->cond->name->name==='controls');
$check('only the explicit controls branch skips concurrent evaluation',count($guards)===1&&count($guards[0]->stmts)===1&&$guards[0]->stmts[0] instanceof \PhpParser\Node\Stmt\Return_);
$tries=$finder->findInstanceOf($hook->stmts,\PhpParser\Node\Stmt\TryCatch::class);
$check('actual entry lifetime closes in finally',count($tries)===1&&$tries[0]->finally!==null&&count($finder->findInstanceOf($tries[0]->finally->stmts,\PhpParser\Node\Stmt\Unset_::class))===1);
$news=$finder->findInstanceOf($hook->stmts,\PhpParser\Node\Expr\New_::class);
$check('observation never constructs a substitute context',$news===[]);
$printer=new \PhpParser\PrettyPrinter\Standard;
foreach(['PossibleCallbackTupleShapeFilter','PossibleCallbackTupleArgumentFilter'] as $name) {
    $filterNodes=$parser->parse(file_get_contents($candidate.'/src/Analyzer/'.$name.'.php'))??[];
    $production=$finder->findFirst($filterNodes,static fn(\PhpParser\Node $node):bool=>$node instanceof \PhpParser\Node\Stmt\ClassMethod&&$node->name->name==='filterIssue');
    $text=$printer->prettyPrint($production->stmts);
    $check($name.' keeps invocation-local production proof',str_contains($text,'new self(')&&!str_contains($text,'$this->active'));
}
echo json_encode(['sourceOnly'=>true,'analyzerJobsStarted'=>0,'syntheticIssueFilterContexts'=>0,
    'fixtureBodiesExecuted'=>false,'nativeConcurrencyWitnessClaimed'=>false,'checks'=>$checks],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;
