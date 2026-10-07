<?php
declare(strict_types=1);
use Ichinya\Laramago\Analyzer\CapturedStateGuardProof;
use Example\GuardedMethods\ControlledCache;
use Mago\Sdk\Analyzer\{IssueFilterHook,IssueFilterContext,IssueFilterDecision,Plugin,PluginDefinition,PluginRegistry,Type};
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;

require dirname(__DIR__,3).'/tests/fixtures/guarded-methods/ControlledCache.php';
final class CapturedWarningObserver implements IssueFilterHook
{
    public function __construct(private readonly string $root,private readonly string $mode,private readonly string $output){}
    public function getCodes():array{return ['impossible-condition','redundant-condition','redundant-type-comparison'];}
    public function filterIssue(IssueFilterContext $context):IssueFilterDecision
    {
        $proof=new CapturedStateGuardProof($this->root);$before=$proof->prove($context);
        if($before['remove'] && str_starts_with($this->mode,'cache-')){
            if($this->mode==='cache-field-type' && $before['source']['storage']!==null){$field=$before['source']['storage'];$native=$context->codebase->getDeclaringProperty($field['class'],'$'.$field['property']);if($native===null||$native->type===null){throw new RuntimeException('Admitted native field disappeared.');}$replacement=ControlledCache::copy($native,['type'=>ControlledCache::copy($native->type,['type'=>Type::mixed()])]);}
            elseif($this->mode==='cache-visited-function' && $before['source']['family']==='recursive-visited-set'){$native=$context->codebase->getFunction('spl_object_id');if($native===null){throw new RuntimeException('Admitted native function disappeared.');}$replacement=ControlledCache::copy($native,['flags'=>new MetadataFlags(($native->flags->bits&~MetadataFlags::BUILTIN)|MetadataFlags::USER_DEFINED)]);}
            else{return IssueFilterDecision::Keep;}
            $slots=ControlledCache::replace($context->codebase,$native,$replacement);try{$after=$proof->prove($context);}finally{ControlledCache::restore($context->codebase,$native,$slots);}
            if($after['remove'] || $proof->prove($context)!==$before){throw new RuntimeException('Native mutation/full proof restoration failed.');}
            file_put_contents($this->output,json_encode(['stage'=>'real-native-cache-control','role'=>$this->mode,'family'=>$before['source']['family'],'site'=>$before['source']['selectedSite']['span'],'genuinePositiveBefore'=>true,'slotsChanged'=>count($slots),'deferredAfter'=>true,'completeProofRestored'=>true],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);return IssueFilterDecision::Keep;
        }
        file_put_contents($this->output,json_encode(['stage'=>'captured-state-warning','file'=>$context->file,'span'=>[$context->issue->annotations[0]->span->start,$context->issue->annotations[0]->span->end],'alwaysKeep'=>$this->mode!=='draft','proof'=>$before,'wholeNativeEnvelope'=>Ichinya\Laramago\Analyzer\DeprecatedMethodCompatibilityProof::canonical($context->issue)],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
        return $this->mode==='draft'&&$before['remove']?IssueFilterDecision::Remove:IssueFilterDecision::Keep;
    }
}
final class CapturedWarningDraftPlugin implements Plugin
{
    public function __construct(private readonly string $root,private readonly string $mode,private readonly string $output){}
    public function getDefinition():PluginDefinition{return new PluginDefinition('example/captured-warning','Captured-state warnings','Explicit bounded captured-state reporting policy; native types and Errors stay intact.');}
    public function register(PluginRegistry $registry):void{$registry->registerIssueFilterHook(new CapturedWarningObserver($this->root,$this->mode,$this->output));}
}
