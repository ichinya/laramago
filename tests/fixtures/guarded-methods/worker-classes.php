<?php
declare(strict_types=1);
use Ichinya\Laramago\Analyzer\CapturedThisMethodExistsFilter as CapturedMethodExistsFilter;

use Ichinya\Laramago\Analyzer\RememberedGuardedGetterProvider as RememberedGetterProvider;
use Ichinya\Laramago\Analyzer\RememberedGuardedGetterScan as RememberedGetterScan;
use Example\GuardedMethods\ControlledCache;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Extension;
use Mago\Sdk\Worker;

if (! defined('GUARDED_PREP_CLASSES_ONLY')) { $package = $argv[1]; $root = $argv[2]; $mode = $argv[3]; $output = $argv[4]; require $package.'/vendor/autoload.php'; }






require __DIR__.'/ControlledCache.php';
final class GuardedGetterObserver implements MethodReturnTypeProvider
{
    public function __construct(private readonly RememberedGetterProvider $provider, private readonly string $mode, private readonly string $output) {}
    public function getTargets(): array { return $this->provider->getTargets(); }
    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $result = $this->provider->getReturnType($context);
        if ($result !== null && $this->mode === 'cache-getter-return') {
            $receiver = $context->invocation->receiverType->atomicTypes[0];
            $method = $context->codebase->getDeclaringMethod($receiver->name, $context->invocation->name);
            $return = ControlledCache::copy($method->returnType, ['type' => Type::string()]);
            $replacement = ControlledCache::copy($method, ['returnType' => $return]);
            $slots = ControlledCache::replace($context->codebase, $method, $replacement);
            try { $after = $this->provider->getReturnType($context); }
            finally { ControlledCache::restore($context->codebase, $method, $slots); }
            if ($after !== null) { throw new RuntimeException('Changed native getter return was still trusted.'); }
            file_put_contents($this->output, json_encode(['stage' => 'real-native-cache-control', 'role' => 'getter-return', 'span' => [$context->invocation->span->start, $context->invocation->span->end], 'genuinePositiveBefore' => true, 'slotsChanged' => count($slots), 'deferredAfter' => true], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
            $result = null;
        }
        if ($result !== null || [] !== []) { file_put_contents($this->output, json_encode(['stage' => 'native-getter-invocation', 'span' => [$context->invocation->span->start, $context->invocation->span->end], 'receiver' => (string) $context->invocation->receiverType, 'proof' => [], 'candidate' => (string) $result, 'alwaysKeep' => $this->mode !== 'draft'], JSON_THROW_ON_ERROR)."\n", FILE_APPEND); }
        return $this->mode === 'draft' ? $result : null;
    }
}
final class GuardedFilterObserver implements IssueFilterHook {
    public function __construct(private readonly string $root,private readonly string $mode,private readonly string $output){}
    public function getCodes():array{return ['non-existent-method'];}
    public function filterIssue(IssueFilterContext $context):IssueFilterDecision{
        $filter=new CapturedMethodExistsFilter($this->root);$decision=$filter->filterIssue($context);$admitted=$decision===IssueFilterDecision::Remove;
        if($admitted && $this->mode==='cache-owner-final'){
            if(preg_match('/does not exist on type `([^`]+)`\.$/D',$context->issue->message,$matched)!==1){throw new RuntimeException('No genuine admitted receiver.');}
            $owner=$context->codebase->getClass($matched[1]);if($owner===null){throw new RuntimeException('The genuine admitted owner disappeared.');}
            $replacement=ControlledCache::copy($owner,['flags'=>new MetadataFlags($owner->flags->bits|MetadataFlags::FINAL)]);$slots=ControlledCache::replace($context->codebase,$owner,$replacement);
            try{$after=$filter->filterIssue($context);}finally{ControlledCache::restore($context->codebase,$owner,$slots);}
            if($after!==IssueFilterDecision::Keep || $filter->filterIssue($context)!==IssueFilterDecision::Remove){throw new RuntimeException('Owner mutation/restoration failed.');}
            file_put_contents($this->output,json_encode(['stage'=>'real-native-cache-control','role'=>'captured-owner-final','file'=>$context->file,'span'=>[$context->issue->annotations[0]->span->start,$context->issue->annotations[0]->span->end],'genuinePositiveBefore'=>true,'slotsChanged'=>count($slots),'deferredAfter'=>true,'exactDecisionRestored'=>true],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
            return IssueFilterDecision::Keep;
        }
        file_put_contents($this->output,json_encode(['stage'=>'issue-filter','file'=>$context->file,'span'=>[$context->issue->annotations[0]->span->start,$context->issue->annotations[0]->span->end],'wouldRemove'=>$admitted,'alwaysKeep'=>$this->mode!=='draft','wholeNativeEnvelope'=>get_object_vars($context->issue)],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
        return $this->mode==='draft'?$decision:IssueFilterDecision::Keep;
    }
}
final class GuardedDraftPlugin implements Plugin
{
    public function __construct(private readonly string $root, private readonly string $mode, private readonly string $output) {}
    public function getDefinition(): PluginDefinition { return new PluginDefinition('example/guarded-method-draft', 'Guarded method draft', 'Read-only default-policy evidence and bounded type/existence adaptation.'); }
    public function register(PluginRegistry $registry): void
    {
        $getter = new RememberedGetterProvider($this->root);
        $registry->registerInitializationHook($getter);
        $registry->registerCodebaseScanHook(new RememberedGetterScan($getter));
        $registry->registerMethodReturnTypeProvider(new GuardedGetterObserver($getter, $this->mode, $this->output));
        $registry->registerIssueFilterHook(new GuardedFilterObserver($this->root, $this->mode, $this->output));
    }
}
if (! defined('GUARDED_PREP_CLASSES_ONLY')) { (new Worker(new Extension(identifier: 'example/guarded-method-draft', name: 'Guarded method draft', version: '0.0.1', analyzerPlugins: [new GuardedDraftPlugin($root, $mode, $output)])))->run(); }
