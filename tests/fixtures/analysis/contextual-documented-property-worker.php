<?php
declare(strict_types=1);

require $argv[1];
require __DIR__.'/contextual-documented-property-controls.php';

use Ichinya\Laramago\Analyzer\{ContextualCollectionMemberProvider,ContextualDocumentedPropertyIssueFilter,EloquentChunkCallbackProvider};
use Ichinya\Laramago\Tests\ContextualDocumentedPropertyControls;
use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterDecision,IssueFilterHook,Plugin,PluginDefinition,PluginRegistry,PropertyType,PropertyTypeProvider,PropertyTypeProviderContext};

/** Capture decisions from actual issue contexts; positive inputs are never synthesized. */
final class ContextualDocumentedPropertyTestObserver implements IssueFilterHook
{
    private ContextualDocumentedPropertyIssueFilter $filter;
    public function __construct(ContextualCollectionMemberProvider $provider,private readonly string $root,private readonly string $mode)
    { $this->filter=new ContextualDocumentedPropertyIssueFilter($provider); }
    public function getCodes():array { return $this->filter->getCodes(); }
    public function filterIssue(IssueFilterContext $context):IssueFilterDecision {
        $decision=$this->filter->filterIssue($context);
        file_put_contents($this->root.'/'.$this->mode.'-issues.jsonl',json_encode(['file'=>$context->file,'sourceSha256'=>hash('sha256',$context->contents),
            'decision'=>$decision->name,'completeSdkIssue'=>self::value($context->issue),'stages'=>$this->filter->stages],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
        return $this->mode==='compatibility'?$decision:IssueFilterDecision::Keep;
    }
    public static function value(mixed $value):mixed {
        if($value instanceof UnitEnum) { return $value->name; }
        if(is_object($value)) { return self::value(get_object_vars($value)); }
        return is_array($value)?array_map(self::value(...),$value):$value;
    }
}

final class ContextualDocumentedPropertyTestPlugin implements Plugin
{
    public function __construct(private readonly string $root,private readonly string $mode) {}
    public function getDefinition():PluginDefinition { return new PluginDefinition('test/contextual-documented-property','Contextual documented property fixtures','Exact source/native declaration controls.'); }
    public function register(PluginRegistry $registry):void {
        $provider=new ContextualCollectionMemberProvider($this->root);
        $registry->registerInitializationHook($provider);
        $registry->registerCodebaseScanHook($provider->members);
        // Keep the isolated fixture baseline identical in every mode; observe the real provider without returning its type.
        $registry->registerPropertyTypeProvider(new class($provider) implements PropertyTypeProvider {
            public function __construct(private readonly ContextualCollectionMemberProvider $provider) {}
            public function getTargets():array { return $this->provider->getTargets(); }
            public function getPropertyType(PropertyTypeProviderContext $context):?PropertyType { $this->provider->getPropertyType($context);return null; }
        });
        $chunks=new EloquentChunkCallbackProvider($this->root);
        $registry->registerInitializationHook($chunks);
        $registry->registerMethodReturnTypeProvider($chunks);
        if($this->mode==='native') { return; }
        if($this->mode==='guards') { $registry->registerIssueFilterHook(new ContextualDocumentedPropertyControls($provider,$this->root)); }
        $registry->registerIssueFilterHook(new ContextualDocumentedPropertyTestObserver($provider,$this->root,$this->mode));
    }
}

$workspace=$argv[2];$mode=$argv[3];
if(str_starts_with($mode,'full-')) {
    // The generated plugin differs only in issue-hook registration. It retains the real property provider and shared index.
    require $argv[5];
    require $argv[4].'/bin/laramago-worker.php';
    return;
}
(new Mago\Sdk\Worker(new Mago\Sdk\Extension(identifier:'contextual-documented-property-test',name:'Contextual documented property test',version:'1',
    analyzerPlugins:[new ContextualDocumentedPropertyTestPlugin($workspace,$mode)])))->run();
