<?php
declare(strict_types=1);
use Example\LocalAppCapture\CapturedThisMethodExistsFilter;
use Example\GuardedMethods\ControlledCache;
use Ichinya\Laramago\Analyzer\{RememberedGuardedGetterProvider,RememberedGuardedGetterScan};
use Mago\Sdk\Analyzer\{IssueFilterHook,IssueFilterContext,IssueFilterDecision,Plugin,PluginDefinition,PluginRegistry,Type};
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
require __DIR__.'/InstrumentedCapturedThisFilter.php';
require dirname(__DIR__,3).'/tests/fixtures/guarded-methods/ControlledCache.php';
final class LocalCaptureObserver implements IssueFilterHook
{
    public function __construct(private readonly string $root,private readonly string $mode,private readonly string $output){}
    public function getCodes():array{return ['non-existent-method'];}
    public function filterIssue(IssueFilterContext $context):IssueFilterDecision
    {
        $proof=new CapturedThisMethodExistsFilter($this->root);$before=$proof->inspect($context);$actual=new \Ichinya\Laramago\Analyzer\CapturedThisMethodExistsFilter($this->root);if(($actual->filterIssue($context)===IssueFilterDecision::Remove)!==$before['remove']){throw new RuntimeException('Instrumented source observation differs from actual production filter.');}
        if($before['remove'] && str_starts_with($this->mode,'cache-')){
            $method=null;$replacement=null;
            if($this->mode==='cache-local-return'){
                if(!isset($before['storageProof']['localBinding'])){return IssueFilterDecision::Keep;}
                $method=$context->codebase->getDeclaringMethod($before['caller']['class'],$before['caller']['method']);
                if($method===null || $method->declaredReturnType===null || $method->returnType===null){throw new RuntimeException('Genuine local caller contract disappeared.');}
                $replacement=ControlledCache::copy($method,['declaredReturnType'=>ControlledCache::copy($method->declaredReturnType,['type'=>Type::string()]),'returnType'=>ControlledCache::copy($method->returnType,['type'=>Type::string()])]);
            }elseif($this->mode==='cache-callback-reference'){
                $selected=$before['storageProof']['callbackMethod'];$method=$context->codebase->getDeclaringMethod($selected['owner'],$selected['name']);
                if($method===null || count($method->parameters)!==1){throw new RuntimeException('Genuine callback method disappeared.');}
                $params=$method->parameters;$params[0]=ControlledCache::copy($params[0],['flags'=>new MetadataFlags($params[0]->flags->bits|MetadataFlags::BY_REFERENCE)]);
                $replacement=ControlledCache::copy($method,['parameters'=>$params]);
            }else{throw new RuntimeException('Unknown cache control.');}
            $slots=ControlledCache::replace($context->codebase,$method,$replacement);
            try{$after=$proof->inspect($context);if(($actual->filterIssue($context)===IssueFilterDecision::Remove)!==$after['remove']){throw new RuntimeException('Actual production filter did not follow native mutation.');}}finally{ControlledCache::restore($context->codebase,$method,$slots);}
            if($after['remove'] || !$proof->inspect($context)['remove']){throw new RuntimeException('Real selected metadata mutation/restoration failed.');}
            file_put_contents($this->output,json_encode(['stage'=>'real-native-cache-control','role'=>$this->mode,'caller'=>$before['caller'],'genuinePositiveBefore'=>true,'slotsChanged'=>count($slots),'deferredAfter'=>true,'restored'=>true],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
            return IssueFilterDecision::Keep;
        }
        file_put_contents($this->output,json_encode(['stage'=>'native-local-capture','file'=>$context->file,'span'=>[$context->issue->annotations[0]->span->start,$context->issue->annotations[0]->span->end],'alwaysKeep'=>$this->mode!=='draft','proof'=>$before,'wholeNativeEnvelope'=>Ichinya\Laramago\Analyzer\DeprecatedMethodCompatibilityProof::canonical($context->issue)],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
        return $this->mode==='draft'&&$before['remove']?IssueFilterDecision::Remove:IssueFilterDecision::Keep;
    }
}
final class LocalCaptureDraftPlugin implements Plugin
{
    public function __construct(private readonly string $root,private readonly string $mode,private readonly string $output){}
    public function getDefinition():PluginDefinition{return new PluginDefinition('example/local-capture','Local capture source guard','Bounded native fixture proof, no application execution.');}
    public function register(PluginRegistry $registry):void
    {
        $getter=new RememberedGuardedGetterProvider($this->root);$registry->registerInitializationHook($getter);$registry->registerCodebaseScanHook(new RememberedGuardedGetterScan($getter));$registry->registerMethodReturnTypeProvider($getter);
        $registry->registerIssueFilterHook(new LocalCaptureObserver($this->root,$this->mode,$this->output));
    }
}
