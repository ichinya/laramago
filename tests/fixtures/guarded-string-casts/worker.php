<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Tests;

use Ichinya\Laramago\Analyzer\{GuardedStringCastCompatibilityFilter,GuardedStringCastCompatibilityPlugin};
use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterDecision,IssueFilterHook,Plugin,PluginDefinition,PluginRegistry};

require $argv[1];
require __DIR__.'/GuardedStringCastNativeControls.php';

/** Capture completed call-local proof state without making further reentrant SDK queries. */
final class GuardedStringCastPortableObserver implements Plugin
{
    public function __construct(private readonly string $root,private readonly string $output) {}
    public function getDefinition():PluginDefinition { return new PluginDefinition('fixture/guarded-string-cast-observer','Guarded cast observer','AlwaysKeep genuine issue and completed proof receipts.'); }
    public function register(PluginRegistry $registry):void {
        $registry->registerIssueFilterHook(new class($this->root,$this->output) implements IssueFilterHook {
            private GuardedStringCastCompatibilityFilter $filter;
            public function __construct(string $root,private readonly string $output) { $this->filter=new GuardedStringCastCompatibilityFilter($root); }
            public function getCodes():array { return ['invalid-type-cast']; }
            public function filterIssue(IssueFilterContext $context):IssueFilterDecision {
                $decision=$this->filter->filterIssue($context);
                $snapshot=static function(mixed $value)use(&$snapshot):mixed {
                    if($value instanceof \BackedEnum) { return $value->value; }
                    if($value instanceof \UnitEnum) { return $value->name; }
                    if(is_object($value)) { return $snapshot(get_object_vars($value)); }
                    return is_array($value)?array_map($snapshot,$value):$value;
                };
                $record=['genuineIssueFilterContext'=>true,'file'=>$context->file,'sourceSha256'=>hash('sha256',$context->contents),
                    'issue'=>$snapshot($context->issue),'candidateDecision'=>$decision->name,'alwaysKeep'=>true,
                    'stages'=>$this->filter->stages,'proofReceipts'=>$this->filter->proofReceipts(),'dependencies'=>$snapshot($this->filter->dependencies)];
                file_put_contents($this->output.'/issues.jsonl',json_encode($record,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n",FILE_APPEND|LOCK_EX);
                return IssueFilterDecision::Keep;
            }
        });
    }
}

/** The outside-branch fixture has no native Error; test its source refusal only from a genuine positive context. */
final class GuardedStringCastNegativeSourceProbe implements Plugin
{
    public function __construct(private readonly string $root,private readonly string $output) {}
    public function getDefinition():PluginDefinition { return new PluginDefinition('fixture/guarded-string-cast-source-probe','Guarded cast source probe','Constructed negative after a genuine native positive.'); }
    public function register(PluginRegistry $registry):void {
        $registry->registerIssueFilterHook(new class($this->root,$this->output) implements IssueFilterHook {
            private bool $checked=false;
            public function __construct(private readonly string $root,private readonly string $output) {}
            public function getCodes():array { return ['invalid-type-cast']; }
            public function filterIssue(IssueFilterContext $context):IssueFilterDecision {
                if($this->checked||basename($context->file)!=='guard-focus.php') { return IssueFilterDecision::Keep; }
                $this->checked=true;$filter=new GuardedStringCastCompatibilityFilter($this->root);
                if($filter->filterIssue($context)!==IssueFilterDecision::Remove) { throw new \RuntimeException('Source probe requires genuine native positive first.'); }
                $contents=file_get_contents($this->root.'/cases.php');$nodes=(new \PhpParser\ParserFactory)->createForNewestSupportedVersion()->parse($contents)??[];$finder=new \PhpParser\NodeFinder;
                $owners=$finder->find($nodes,static fn(\PhpParser\Node $node):bool=>$node instanceof \PhpParser\Node\Stmt\Function_&&$node->name->name==='outsideBranch');
                if(count($owners)!==1) { throw new \RuntimeException('Missing unique outside-branch source owner.'); }
                $casts=$finder->findInstanceOf([$owners[0]],\PhpParser\Node\Expr\Cast\String_::class);
                if(count($casts)!==1) { throw new \RuntimeException('Missing unique outside-branch source cast.'); }
                $cast=$casts[0];$copy=static function(object $original,array $changes):object { $class=$original::class;return new $class(...array_replace(get_object_vars($original),$changes)); };
                $primary=$copy($context->issue->annotations[0],['span'=>new \Mago\Sdk\Span($cast->getStartFilePos(),$cast->getEndFilePos()+1)]);
                $issue=$copy($context->issue,['annotations'=>[$primary]]);
                $negative=new IssueFilterContext($context->phpVersion,$context->codebase,$context->types,$context->cancellation,'cases.php',$contents,$issue);
                if($filter->filterIssue($negative)!==IssueFilterDecision::Keep) { throw new \RuntimeException('Out-of-branch constructed negative was removed.'); }
                if($filter->filterIssue($context)!==IssueFilterDecision::Remove) { throw new \RuntimeException('Genuine positive failed after source probe.'); }
                file_put_contents($this->output.'/outside-branch-source-control.json',json_encode(['genuinePositiveBefore'=>true,'constructedNegative'=>true,
                    'nativeNegativeAcceptanceClaimed'=>false,'sourceSha256'=>hash('sha256',$contents),'sourceCastSpan'=>[$cast->getStartFilePos(),$cast->getEndFilePos()+1],
                    'expectedDecision'=>'Keep','actualDecision'=>'Keep','genuinePositiveRestored'=>true],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
                return IssueFilterDecision::Keep;
            }
        });
    }
}

$mode=$argv[3]??'native';$root=$argv[2];$output=$root.'/'.$mode;
if(str_starts_with($mode,'full-')) {
    // These files contain the production registry, differing only by removal of the cast registration in full-before.
    require $mode==='full-before'?$root.'/full-before-worker.php':$argv[4].'/bin/laramago-worker.php';
} else {
    $plugins=match($mode) {
        'native'=>[],
        'observe'=>[new GuardedStringCastPortableObserver($root,$output)],
        'isolated'=>[new GuardedStringCastCompatibilityPlugin($root)],
        'controls'=>[new GuardedStringCastNegativeSourceProbe($root,$output),new GuardedStringCastNativeControls($root,$output)],
        default=>throw new \RuntimeException('Unknown guarded string-cast worker mode.'),
    };
    (new \Mago\Sdk\Worker(new \Mago\Sdk\Extension(identifier:'fixture/guarded-string-casts',name:'Guarded string cast fixture',version:'1',analyzerPlugins:$plugins)))->run();
}
