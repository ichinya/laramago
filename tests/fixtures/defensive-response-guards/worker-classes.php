<?php
declare(strict_types=1);
use Ichinya\Laramago\Analyzer\DefensiveResponseGuardProof;
use Example\GuardedMethods\ControlledCache;
use Mago\Sdk\Analyzer\{IssueFilterHook,IssueFilterContext,IssueFilterDecision,Plugin,PluginDefinition,PluginRegistry,Type};
require dirname(__DIR__,3).'/tests/fixtures/guarded-methods/ControlledCache.php';
final class ResponseGuardObserver implements IssueFilterHook
{
    public function __construct(private readonly DefensiveResponseGuardProof $proof,private readonly string $mode,private readonly string $output){}
    public function getCodes():array{return ['redundant-type-comparison','redundant-condition','impossible-condition'];}
    public function filterIssue(IssueFilterContext $context):IssueFilterDecision
    {
        $before=$this->proof->prove($context);
        if($before['remove']&&str_starts_with($this->mode,'cache-')){
            if($this->mode==='cache-forwarder-return'){$native=$context->codebase->getDeclaringMethod('Laratesto\\Testing\\LaravelResponse','inertiaPage');if($native===null||$native->returnType===null){throw new RuntimeException('Admitted forwarder disappeared.');}$replacement=ControlledCache::copy($native,['returnType'=>ControlledCache::copy($native->returnType,['type'=>Type::string()])]);}
            elseif($this->mode==='cache-page-property'){$native=$context->codebase->getDeclaringProperty('Inertia\\Testing\\AssertableInertia','$component');if($native===null||$native->type===null){throw new RuntimeException('Admitted page property disappeared.');}$replacement=ControlledCache::copy($native,['type'=>ControlledCache::copy($native->type,['type'=>Type::int()])]);}
            else{throw new RuntimeException('Unknown cache control.');}$slots=ControlledCache::replace($context->codebase,$native,$replacement);try{$after=$this->proof->prove($context);}finally{ControlledCache::restore($context->codebase,$native,$slots);}
            if($after['remove']||$this->proof->prove($context)!==$before){throw new RuntimeException('Native current contract mutation/restoration failed.');}
            file_put_contents($this->output,json_encode(['stage'=>'real-native-cache-control','role'=>$this->mode,'site'=>$before['source']['selectedSite']['span'],'genuinePositiveBefore'=>true,'slotsChanged'=>count($slots),'deferredAfter'=>true,'completeProofRestored'=>true],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);return IssueFilterDecision::Keep;
        }
        file_put_contents($this->output,json_encode(['stage'=>'response-defensive-guard','file'=>$context->file,'span'=>[$context->issue->annotations[0]->span->start,$context->issue->annotations[0]->span->end],'proof'=>$before,'alwaysKeep'=>$this->mode!=='draft','wholeNativeEnvelope'=>Ichinya\Laramago\Analyzer\DeprecatedMethodCompatibilityProof::canonical($context->issue)],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
        return $this->mode==='draft'&&$before['remove']?IssueFilterDecision::Remove:IssueFilterDecision::Keep;
    }
}
final class ResponseGuardDraftPlugin implements Plugin
{
    public function __construct(private readonly string $root,private readonly string $mode,private readonly string $output){}
    public function getDefinition():PluginDefinition{return new PluginDefinition('example/response-defensive-guards','Response defensive guards','Reporting policy for physically certified Inertia page guards; native types stay intact.');}
    public function register(PluginRegistry $registry):void{$registry->registerIssueFilterHook(new ResponseGuardObserver(new DefensiveResponseGuardProof($this->root),$this->mode,$this->output));}
}
