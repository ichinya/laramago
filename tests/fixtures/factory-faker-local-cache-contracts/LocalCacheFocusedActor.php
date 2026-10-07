<?php
declare(strict_types=1);
require_once __DIR__.'/SelectedNativeAliases.php';
$localControlDirectory=__DIR__.'/control-helpers';
require_once __DIR__.'/control-helpers/LocalCacheFocusedSourceControls.php';
foreach(['LocalCacheSelectedNativeAliases','LocalCacheFocusedControlPlan','LocalCacheFocusedExecution','SourceCaseCatalogue'] as $localHelper){require_once $localControlDirectory.'/'.$localHelper.'.php';}
final class LocalCacheFocusedActor implements \Mago\Sdk\Analyzer\IssueFilterHook
{
    private bool $active=false;
    public function __construct(private readonly \Ichinya\Laramago\Analyzer\FactoryFakerProof $proof,private readonly string $root,
        private readonly string $mode,private readonly string $output){}
    public function getCodes():array{return ['array-to-string-conversion','mixed-argument'];}
    public function filterIssue(\Mago\Sdk\Analyzer\IssueFilterContext $context):\Mago\Sdk\Analyzer\IssueFilterDecision
    {
        if($this->active){return \Mago\Sdk\Analyzer\IssueFilterDecision::Keep;}$this->active=true;
        try{
            $before=$this->proof->prove($context);if(!isset($before['source'])){return \Mago\Sdk\Analyzer\IssueFilterDecision::Keep;}
            (new LocalCacheFixtureObserver($this->proof,$this->output))->filterIssue($context);
            if(($before['remove']??null)!==true){throw new RuntimeException('Every control role needs a fresh genuine Remove before plan construction.');}
            $fresh=fn():array=>$this->proof->prove($context);
            if(str_starts_with($this->mode,'cache-local-')){
                $part=(int)substr($this->mode,strlen('cache-local-'));
                $plans=\Example\LocalCacheFocusedTests\LocalCacheFocusedControlPlan::append($context,$fresh(),[]);ksort($plans);
                if(count($plans)!==131||$part<0||$part>=14){throw new RuntimeException('The complete local control plan or partition differs.');}
                $labels=array_keys($plans);$selected=$this->mode==='cache-local-caller'?array_values(array_filter($labels,static fn(string $label):bool=>str_starts_with($label,'local-cache/caller-'))):array_slice($labels,$part*10,10);
                if($this->mode==='cache-local-caller'&&count($selected)!==8){throw new RuntimeException('All eight exact caller controls are required.');}$receipts=[];
                foreach($selected as $label){$receipts[$label]=\Example\LocalCacheFocusedTests\LocalCacheFocusedExecution::cacheJob($context,$plans[$label],$fresh);}
                $row=['status'=>'passed','mode'=>$this->mode,'sourceKind'=>$before['source']['kind'],'span'=>[$context->issue->annotations[0]->span->start,$context->issue->annotations[0]->span->end],
                    'plannedLabels'=>$labels,'plannedCount'=>131,'selectedLabels'=>$selected,'receipts'=>$receipts,'restored'=>true,'nativeIssueUnchanged'=>true,'constructedContexts'=>0];
            }elseif(str_starts_with($this->mode,'cache-source-local-')){
                $part=(int)substr($this->mode,strlen('cache-source-local-'));
                $plans=\Example\LocalCacheFocusedTests\LocalCacheFocusedSourceControls::sourcePlans($context,$fresh(),$this->root);ksort($plans);
                if(count($plans)!==4||$part<0||$part>=4){throw new RuntimeException('The exact local source control plan differs.');}
                $labels=array_keys($plans);$label=$labels[$part];$receipt=\Example\LocalCacheFocusedTests\LocalCacheFocusedExecution::sourceJob($context,$plans[$label],$this->root,$fresh);
                $row=['status'=>'passed','mode'=>$this->mode,'sourceKind'=>$before['source']['kind'],'span'=>[$context->issue->annotations[0]->span->start,$context->issue->annotations[0]->span->end],
                    'plannedLabels'=>$labels,'plannedCount'=>4,'selectedLabels'=>[$label],'receipts'=>[$label=>$receipt],'restored'=>true,'nativeIssueUnchanged'=>true,'constructedContexts'=>0];
            }else{throw new RuntimeException('Unsupported focused control mode.');}
            file_put_contents($this->output.'.controls.jsonl',json_encode(\Ichinya\Laramago\Analyzer\DeprecatedMethodCompatibilityProof::canonical($row),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
            return \Mago\Sdk\Analyzer\IssueFilterDecision::Keep;
        }catch(Throwable $failure){
            file_put_contents($this->output.'.first-failure.json',json_encode(['mode'=>$this->mode,'exception'=>$failure::class,'message'=>$failure->getMessage(),'lastLabel'=>$label??null,'lastFamily'=>isset($label,$plans[$label])?$plans[$label]['family']:null,'span'=>[$context->issue->annotations[0]->span->start,$context->issue->annotations[0]->span->end]],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));throw $failure;
        }finally{$this->active=false;}
    }
}
