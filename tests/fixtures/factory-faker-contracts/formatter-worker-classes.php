<?php
declare(strict_types=1);
use Ichinya\Laramago\Analyzer\{FactoryFakerContracts,FactoryFakerProof};
use Example\NativeFixtureSupport\SelectedNativeAliases as ControlledCache;
use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterHook,IssueFilterDecision,Plugin,PluginDefinition,PluginRegistry,Type};
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
require __DIR__.'/SelectedNativeAliases.php';
final class FactoryFakerObserver implements IssueFilterHook
{
    public function __construct(private readonly FactoryFakerProof $proof,private readonly string $mode,private readonly string $output){}
    public function getCodes():array{return ['array-to-string-conversion','mixed-argument'];}
    public function filterIssue(IssueFilterContext $context):IssueFilterDecision
    {
        $before=$this->proof->prove($context);
        if($before['remove']&&str_starts_with($this->mode,'cache-')){
            if($this->mode==='cache-pseudo-return'){$name=$before['source']['kind']==='default-faker-words-text'?'words':'randomElement';$native=$context->codebase->getDeclaringMethod('Faker\\Generator',$name);$replacement=ControlledCache::copy($native,['returnType'=>ControlledCache::copy($native->returnType,['type'=>Type::object()])]);}
            elseif($this->mode==='cache-factory-field'){$native=$context->codebase->getDeclaringProperty($before['source']['class'],'$faker');$replacement=ControlledCache::copy($native,['type'=>ControlledCache::copy($native->type,['type'=>Type::object()])]);}
            elseif($this->mode==='cache-caller-reference'){$native=$context->codebase->getDeclaringMethod($before['source']['class'],$before['source']['scope']['name']);$replacement=ControlledCache::copy($native,['flags'=>new MetadataFlags($native->flags->bits|MetadataFlags::BY_REFERENCE)]);}
            elseif($this->mode==='cache-caller-traits'){$native=$context->codebase->getClass($before['source']['class']);$replacement=ControlledCache::copy($native,['usedTraits'=>array_merge($native->usedTraits,['stdclass'])]);}
            elseif($this->mode==='cache-parent-traits'){$native=$context->codebase->getClass('Illuminate\Database\Eloquent\Factories\Factory');$replacement=ControlledCache::copy($native,['usedTraits'=>[]]);}
            else{throw new RuntimeException('Unknown Faker real cache control.');}
            $bindings=match($this->mode){
                'cache-pseudo-return'=>[['kind'=>'method','class'=>'Faker\\Generator','name'=>$name]],
                'cache-factory-field'=>[['kind'=>'property','class'=>$before['source']['class'],'name'=>'$faker'],['kind'=>'property','class'=>'Illuminate\\Database\\Eloquent\\Factories\\Factory','name'=>'$faker']],
                'cache-caller-reference'=>[['kind'=>'method','class'=>$before['source']['class'],'name'=>$before['source']['scope']['name']]],
                'cache-caller-traits'=>[['kind'=>'class','name'=>$before['source']['class']]],
                'cache-parent-traits'=>[['kind'=>'class','name'=>'Illuminate\Database\Eloquent\Factories\Factory']],
            };
            if($this->proof->prove($context)!==$before){throw new RuntimeException('Faker alias control lacks a complete current genuine positive.');}
            $control=ControlledCache::control($context->codebase,$native,$replacement,$bindings,function()use($context):void{if($this->proof->prove($context)['remove']){throw new RuntimeException('Changed genuine aliases did not defer Faker proof.');}});
            if($this->proof->prove($context)!==$before){throw new RuntimeException('Complete Faker proof did not restore after native alias control.');}
            file_put_contents($this->output,json_encode(['stage'=>'real-native-cache-control','role'=>$this->mode,'kind'=>$before['source']['kind'],'genuinePositiveBefore'=>true,'deferredAfter'=>true,'completeProofRestored'=>true]+$control,JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);return IssueFilterDecision::Keep;
        }
        $observation=['stage'=>'factory-faker','span'=>[$context->issue->annotations[0]->span->start,$context->issue->annotations[0]->span->end],'proof'=>$before,'alwaysKeep'=>true,'wholeNativeEnvelope'=>Ichinya\Laramago\Analyzer\DeprecatedMethodCompatibilityProof::canonical($context->issue)];
        file_put_contents($this->output,json_encode(Ichinya\Laramago\Analyzer\DeprecatedMethodCompatibilityProof::canonical($observation),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
        return IssueFilterDecision::Keep;
    }
}
final class FactoryFakerDraftPlugin implements Plugin
{
    public function __construct(private readonly string $root,private readonly string $mode,private readonly string $output){}
    public function getDefinition():PluginDefinition{return new PluginDefinition('example/factory-faker','Default Factory formatter domains','Source-bound default formatter reporting and selected argument compatibility.');}
    public function register(PluginRegistry $registry):void{if(in_array($this->mode,['draft','preserve'],true)){(new Ichinya\Laramago\Analyzer\FactoryFakerPlugin($this->root,$this->mode==='preserve'))->register($registry);}$contracts=new FactoryFakerContracts($this->root);$registry->registerInitializationHook($contracts);$registry->registerCodebaseScanHook($contracts);$registry->registerIssueFilterHook(new FactoryFakerObserver(new FactoryFakerProof($contracts,$this->root),$this->mode,$this->output));}
}
