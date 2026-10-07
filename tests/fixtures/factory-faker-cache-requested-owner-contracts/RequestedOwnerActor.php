<?php
declare(strict_types=1);
require_once __DIR__.'/SelectedNativeAliases.php';
$localDirectory=__DIR__.'/control-helpers';
foreach(['LocalCacheSelectedNativeAliases','LocalCacheFocusedControlPlan'] as $helper){require_once $localDirectory.'/'.$helper.'.php';}
$ownerDirectory=__DIR__.'/control-helpers';
foreach(['RequestedClassLikeAliases','RequestedOwnerExecution','RequestedOwnerControlPlan'] as $helper){require_once $ownerDirectory.'/'.$helper.'.php';}
final class RequestedOwnerActor implements \Mago\Sdk\Analyzer\IssueFilterHook
{
    private bool $active=false;
    public function __construct(private readonly \Ichinya\Laramago\Analyzer\FactoryFakerProof $proof,private readonly string $mode,private readonly string $output){}
    public function getCodes():array{return ['array-to-string-conversion','mixed-argument'];}
    public function filterIssue(\Mago\Sdk\Analyzer\IssueFilterContext $context):\Mago\Sdk\Analyzer\IssueFilterDecision
    {
        if($this->active){return \Mago\Sdk\Analyzer\IssueFilterDecision::Keep;}$this->active=true;
        try{
            $before=$this->proof->prove($context);if(!isset($before['source'])){return \Mago\Sdk\Analyzer\IssueFilterDecision::Keep;}
            (new LocalCacheFixtureObserver($this->proof,$this->output))->filterIssue($context);
            if(($before['remove']??null)!==true){throw new RuntimeException('Both requested-owner roles need a genuine fresh Remove.');}
            if($this->mode!=='cache-requested-owner-all'){throw new RuntimeException('Unsupported requested-owner scheduler mode.');}
            $fresh=fn():array=>$this->proof->prove($context);
            $plans=\Example\LocalCacheRequestedOwnerTests\RequestedOwnerControlPlan::append($context,$fresh(),[]);ksort($plans);
            if(count($plans)!==2){throw new RuntimeException('Exactly two genuine requested-owner plans are required.');}
            $receipts=[];foreach($plans as $label=>$job){
                if(($job['executionClass']??null)!==\Example\LocalCacheRequestedOwnerTests\RequestedOwnerExecution::class){throw new RuntimeException('Requested-owner executor differs.');}
                $receipts[$label]=\Example\LocalCacheRequestedOwnerTests\RequestedOwnerExecution::cacheJob($context,$job,$fresh);
            }
            $row=['status'=>'passed','mode'=>$this->mode,'sourceKind'=>$before['source']['kind'],
                'span'=>[$context->issue->annotations[0]->span->start,$context->issue->annotations[0]->span->end],
                'plannedLabels'=>array_keys($plans),'plannedCount'=>2,'selectedLabels'=>array_keys($plans),'receipts'=>$receipts,
                'restored'=>true,'nativeIssueUnchanged'=>true,'constructedContexts'=>0,'legacy131BuilderExecuted'=>false];
            file_put_contents($this->output.'.controls.jsonl',json_encode(\Ichinya\Laramago\Analyzer\DeprecatedMethodCompatibilityProof::canonical($row),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
            return \Mago\Sdk\Analyzer\IssueFilterDecision::Keep;
        }catch(Throwable $failure){
            file_put_contents($this->output.'.first-failure.json',json_encode(['mode'=>$this->mode,'exception'=>$failure::class,'message'=>$failure->getMessage(),'lastLabel'=>$label??null],JSON_THROW_ON_ERROR));throw $failure;
        }finally{$this->active=false;}
    }
}
