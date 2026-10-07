<?php
declare(strict_types=1);
use Ichinya\Laramago\Analyzer\SelectedChunkModelWarningProof;
use Example\SelectedChunkModelWarningFixture\SelectedNativeAliases as Cache;
use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterHook,IssueFilterDecision,Plugin,PluginDefinition,PluginRegistry,Type};
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
require __DIR__.'/SelectedNativeAliases.php';
final class SelectedChunkModelWarningObserver implements IssueFilterHook
{
    public function __construct(private readonly SelectedChunkModelWarningProof $proof,private readonly string $mode,private readonly string $output){}
    public function getCodes():array{return ['non-documented-method'];}
    public function filterIssue(IssueFilterContext $context):IssueFilterDecision
    {
        $before=$this->proof->prove($context);
        if($before['remove']&&str_starts_with($this->mode,'cache-')){
            [$owner]=explode('::',$before['chunkCertificate']['owner'],2);$helper=$before['sourceCertificate']['helper']['name'];$model=$before['sourceCertificate']['model'];$method=$before['sourceCertificate']['method'];
            if($this->mode==='cache-helper-formal'){$binding=['kind'=>'method','class'=>$owner,'name'=>$helper];$role='receiving-helper';$native=$context->codebase->getDeclaringMethod($owner,$helper);$parameters=$native->parameters;$parameters[0]=Cache::copy($parameters[0],['type'=>Cache::copy($parameters[0]->type,['type'=>Type::object()])]);$replacement=Cache::copy($native,['parameters'=>$parameters]);}
            elseif($this->mode==='cache-helper-reference'){$binding=['kind'=>'method','class'=>$owner,'name'=>$helper];$role='receiving-helper';$native=$context->codebase->getDeclaringMethod($owner,$helper);$replacement=Cache::copy($native,['flags'=>new MetadataFlags($native->flags->bits|MetadataFlags::BY_REFERENCE)]);}
            elseif($this->mode==='cache-callee-static'){$binding=['kind'=>'method','class'=>$model,'name'=>$method];$role='selected-model-method';$native=$context->codebase->getMethod($model,$method)??$context->codebase->getDeclaringMethod($model,$method);$replacement=Cache::copy($native,['static'=>true]);}
            elseif($this->mode==='cache-chunk-return'){$binding=$before['chunkCertificate']['bindings']['chunkById'];$role='chunkById';$native=$context->codebase->getMethod($binding['class'],$binding['name'])??$context->codebase->getDeclaringMethod($binding['class'],$binding['name']);$replacement=Cache::copy($native,['returnType'=>Cache::copy($native->returnType,['type'=>Type::string()])]);}
            else{throw new RuntimeException('Unknown selected chunk model control.');}
            if(Ichinya\Laramago\Analyzer\DefensiveBoundaryGuardProof::compact($native)!==$before['observedNativeProfiles'][$role]['compact']||$this->proof->prove($context)!==$before){throw new RuntimeException('Selected mutation lacks its complete current genuine profile.');}
            $control=Cache::control($context->codebase,$native,$replacement,[$binding],function()use($context):void{if($this->proof->prove($context)['remove']){throw new RuntimeException('Changed selected native chunk/helper/callee did not defer.');}});
            if($this->proof->prove($context)!==$before){throw new RuntimeException('Whole selected chunk model proof did not restore.');}
            file_put_contents($this->output,json_encode(['stage'=>'real-native-cache-control','role'=>$this->mode,'genuinePositiveBefore'=>true,'deferredAfter'=>true,'completeProofRestored'=>true]+$control,JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);return IssueFilterDecision::Keep;
        }
        file_put_contents($this->output,json_encode(['stage'=>'selected-chunk-model','span'=>[$context->issue->annotations[0]->span->start,$context->issue->annotations[0]->span->end],'proof'=>$before,'alwaysKeep'=>$this->mode!=='draft','wholeNativeEnvelope'=>Ichinya\Laramago\Analyzer\DeprecatedMethodCompatibilityProof::canonical($context->issue)],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);return $this->mode==='draft'&&$before['remove']?IssueFilterDecision::Remove:IssueFilterDecision::Keep;
    }
}
final class SelectedChunkModelWarningDraftPlugin implements Plugin
{
    public function __construct(private readonly string $root,private readonly string $mode,private readonly string $output){}
    public function getDefinition():PluginDefinition{return new PluginDefinition('example/selected-chunk-model','Selected chunk model method','Current hydrated model method under a closed helper and foreach lifetime.');}
    public function register(PluginRegistry $registry):void{$registry->registerIssueFilterHook(new SelectedChunkModelWarningObserver(new SelectedChunkModelWarningProof($this->root),$this->mode,$this->output));}
}
