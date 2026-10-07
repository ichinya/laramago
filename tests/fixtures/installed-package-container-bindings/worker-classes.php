<?php
declare(strict_types=1);
use Example\Fortify\ContainerHelperProvider;
use Example\ProducerGuards\ControlledCache;
use Mago\Sdk\Analyzer\{FunctionReturnTypeProvider,InitializationHook,InitializationContext,Plugin,PluginDefinition,PluginRegistry,ReturnTypeProviderContext,Type};
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
require __DIR__.'/ObservedBindingSyntax.php';
require __DIR__.'/ObservedPackageBindings.php';
require __DIR__.'/ObservedContainerHelper.php';
require __DIR__.'/ControlledCache.php';
final class FortifyControlObserver implements FunctionReturnTypeProvider,InitializationHook
{
    public function __construct(private readonly ContainerHelperProvider $helper,private readonly string $mode,private readonly string $output,private readonly string $root){}
    private \Ichinya\Laramago\Analyzer\ContainerHelperProvider $production;
    private \Ichinya\Laramago\Analyzer\FixtureBaselineContainerHelperProvider $baseline;
    public function initialize(InitializationContext $context):void{$this->helper->initialize($context);$this->production=new \Ichinya\Laramago\Analyzer\ContainerHelperProvider($this->root);$this->production->initialize($context);$this->baseline=new \Ichinya\Laramago\Analyzer\FixtureBaselineContainerHelperProvider($this->root);$this->baseline->initialize($context);}
    public function getTargets():array{return $this->helper->getTargets();}
    public function getReturnType(ReturnTypeProviderContext $context):?Type
    {
        $result=$this->helper->getReturnType($context);$actual=$this->production->getReturnType($context);if(($actual===null?null:(string)$actual)!==($result===null?null:(string)$result)){throw new RuntimeException('Observed source differs from actual production helper.');}$before=$this->helper->stages;$call=$context->invocation;$baseline=$this->baseline->getReturnType($context);
        file_put_contents($this->output,json_encode(['stage'=>'genuine-helper','span'=>[$call->span->start,$call->span->end],'helper'=>$call->name,'proof'=>$before,'candidate'=>$result===null?null:(string)$result,'baseline'=>$baseline===null?null:(string)$baseline,'alwaysKeep'=>$this->mode!=='draft','authority'=>'current FunctionReturnTypeProvider context; SDK provides no invocation filename'],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
        if(str_starts_with($this->mode,'cache-') && $result!==null){
            $native=match($this->mode){'cache-helper-reference'=>$context->codebase->getFunction($call->name),'cache-package-class-abstract'=>$context->codebase->getClass('Laravel\\Fortify\\TwoFactorAuthenticationProvider'),default=>throw new RuntimeException('Unknown Fortify cache control.')};
            $changedFlag=$this->mode==='cache-helper-reference'?MetadataFlags::BY_REFERENCE:MetadataFlags::ABSTRACT;
            if($native===null || $native->flags->contains($changedFlag)){throw new RuntimeException('No genuine unmodified native control input.');}
            $replacement=ControlledCache::copy($native,['flags'=>new MetadataFlags($native->flags->bits|$changedFlag)]);
            $slots=ControlledCache::replace($context->codebase,$native,$replacement);
            try{$after=$this->helper->getReturnType($context);if($this->production->getReturnType($context)!==null){throw new RuntimeException('Actual production helper did not refuse native mutation.');}$changedProof=$this->helper->stages;}finally{ControlledCache::restore($context->codebase,$native,$slots);}
            if($after!==null){throw new RuntimeException('Changed genuine native contract remained admitted.');}
            $restored=$this->helper->getReturnType($context);$restoredProof=$this->helper->stages;
            if($restored===null || (string)$restored!==(string)$result || $restoredProof!==$before){throw new RuntimeException('Current helper proof was not fully restored.');}
            $symbol=$this->mode==='cache-helper-reference'?$native->identifier->name:$native->name;
            file_put_contents($this->output,json_encode(['stage'=>'real-native-cache-control','mode'=>$this->mode,'span'=>[$call->span->start,$call->span->end],'symbol'=>$symbol,'helper'=>strtolower(ltrim($call->name,'\\')),'genuinePositiveBefore'=>true,'slotsChanged'=>count($slots),'deferredAfter'=>true,'restoredProof'=>true,'changedProof'=>$changedProof],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
        }
        return $this->mode==='draft'?$result:$baseline;
    }
}
final class FortifyControlPlugin implements Plugin
{
    public function __construct(private readonly string $root,private readonly string $mode,private readonly string $output){}
    public function getDefinition():PluginDefinition{return new PluginDefinition('example/fortify-controls','Fortify controls','Genuine helper, discovery and native contract controls.');}
    public function register(PluginRegistry $registry):void{}
}
