<?php
declare(strict_types=1);

use Ichinya\Laramago\Analyzer\{FactoryFakerContracts,FactoryFakerProof,FactoryFakerPlugin};
use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterDecision,IssueFilterHook,Plugin,PluginDefinition,PluginRegistry};

// Fixture controls use the current public proof dependencies.
// Production declarations load through the package autoloader.
require_once __DIR__.'/formatter-worker-classes.php';
require_once __DIR__.'/ReceiverNativeControls.php';

final class ReceiverFixtureObserver implements IssueFilterHook
{
    private bool $controlling=false;
    public function __construct(private readonly FactoryFakerProof $proof,private readonly string $mode,private readonly string $output){}
    public function getCodes():array{return ['array-to-string-conversion','mixed-argument'];}
    public function filterIssue(IssueFilterContext $context):IssueFilterDecision
    {
        // Cache controls run with one analyzer thread. Nested request service
        // keeps issues and cannot start a second mutation of a borrowed cache.
        if($this->controlling){return IssueFilterDecision::Keep;}
        $before=$this->proof->prove($context);
        if(!$before['remove']){return IssueFilterDecision::Keep;}
        $this->controlling=true;
        try{
            $controlDirectory=dirname($this->output).'/'.basename($this->output,'.jsonl').'-receiver-'.hash('sha256',$before['source']['kind'].':'.$context->file);
            $receipt=\Example\FakerReceiverTests\ReceiverNativeControls::run($context,$this->proof,$this->mode,$controlDirectory);
        }finally{$this->controlling=false;}
        if($this->proof->prove($context)!==$before){throw new RuntimeException('The complete receiver proof did not restore after the selected controls.');}
        file_put_contents($this->output,json_encode(['stage'=>'receiver-native-controls','span'=>[$context->issue->annotations[0]->span->start,$context->issue->annotations[0]->span->end],
            'kind'=>$before['source']['kind'],'alwaysKeep'=>true,'genuinePositiveBefore'=>true,'completeProofRestored'=>true,'receipt'=>$receipt],
            JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
        return IssueFilterDecision::Keep;
    }
}

final class ReceiverFixturePlugin implements Plugin
{
    public function __construct(private readonly string $root,private readonly string $mode,private readonly string $output){}
    public function getDefinition():PluginDefinition{return new PluginDefinition('example/faker-registration-receivers','Faker registration receiver controls','Genuine selected formatter issues with current receiver metadata controls.');}
    public function register(PluginRegistry $registry):void
    {
        if(!str_starts_with($this->mode,'cache-receiver-')){
            (new FactoryFakerDraftPlugin($this->root,$this->mode,$this->output))->register($registry);return;
        }
        $contracts=new FactoryFakerContracts($this->root);
        $registry->registerInitializationHook($contracts);$registry->registerCodebaseScanHook($contracts);
        $registry->registerIssueFilterHook(new ReceiverFixtureObserver(new FactoryFakerProof($contracts,$this->root),$this->mode,$this->output));
    }
}
