<?php
declare(strict_types=1);
// Use the package's current public formatter proof.
require_once __DIR__.'/formatter-worker-classes.php';
require_once __DIR__.'/LocalCacheFocusedActor.php';
use Ichinya\Laramago\Analyzer\{FactoryFakerContracts,FactoryFakerProof,DefensiveBoundaryGuardProof,DeprecatedMethodCompatibilityProof};
use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterHook,IssueFilterDecision,Plugin,PluginDefinition,PluginRegistry};
final class LocalCacheFixtureObserver implements IssueFilterHook
{
    public function __construct(private readonly FactoryFakerProof $proof,private readonly string $output){}
    public function getCodes():array{return ['array-to-string-conversion','mixed-argument'];}
    public function filterIssue(IssueFilterContext $context):IssueFilterDecision
    {
        $a=$context->issue->annotations[0]??null;
        $lexical=\Ichinya\Laramago\Analyzer\FactoryFakerSource::compile(\Ichinya\Laramago\Analyzer\DefensiveBoundaryGuardSource::parse($context->contents));
        $site=$a===null?'':$a->span->start.':'.$a->span->end;
        if(isset($lexical[$site])){file_put_contents($this->output.'.entries.jsonl',json_encode(['entry'=>true,'contextFile'=>$context->file,
            'contextContentsSha256'=>hash('sha256',$context->contents),'span'=>[$a->span->start,$a->span->end],'sourceKind'=>$lexical[$site]['kind'],
            'sdkContextsConstructed'=>false],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);}
        unset($lexical);
        $proof=$this->proof->prove($context);
        if(!isset($proof['source'])){return IssueFilterDecision::Keep;}
        $formatterProfiles=[];
        $hash=static function(array $value):string{$sort=static function(mixed $item)use(&$sort):mixed{if(is_array($item)){foreach($item as $key=>$part){$item[$key]=$sort($part);}if(!array_is_list($item)){ksort($item);}}return $item;};return hash('sha256',json_encode($sort($value),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));};
        foreach(\Ichinya\Laramago\Analyzer\FactoryFakerLibraryProfiles::methods() as $symbol=>$selected){
            $native=$context->codebase->getDeclaringMethod($selected['class'],$selected['name']);
            if($native!==null){$compact=DefensiveBoundaryGuardProof::compact($native);$formatterProfiles[$symbol]=['sha256'=>$hash($compact),'compact'=>$compact];}
        }
        foreach(['words','randomElement'] as $name){$native=$context->codebase->getDeclaringMethod('Faker\\Generator',$name);
            if($native!==null){$compact=DefensiveBoundaryGuardProof::compact($native);$formatterProfiles['Faker\\Generator::'.$name]=['sha256'=>$hash($compact),'compact'=>$compact];}}
        $receiving=$context->codebase->getFunction('sprintf');if($receiving!==null){$compact=DefensiveBoundaryGuardProof::compact($receiving);$formatterProfiles['selected-receiving']=['sha256'=>$hash($compact),'compact'=>$compact];}
        $bindings=[];
        foreach([
            ['Illuminate\\Foundation\\Application','make'],['Illuminate\\Foundation\\Application','resolve'],
            ['Illuminate\\Foundation\\Application','loadDeferredProviderIfNeeded'],['Illuminate\\Foundation\\Application','booting'],
            ['Illuminate\\Foundation\\Application','registerCoreContainerAliases'],['Illuminate\\Foundation\\Application','getAlias'],
            ['Illuminate\\Container\\Container','make'],['Illuminate\\Container\\Container','resolve'],['Illuminate\\Container\\Container','instance'],
            ['Illuminate\\Cache\\CacheManager','extend'],['Illuminate\\Cache\\CacheManager','__construct'],
            ['Illuminate\\Cache\\CacheServiceProvider','register'],['Illuminate\\Contracts\\Container\\Container','make'],
            ['Illuminate\\Foundation\\Testing\\WithCachedConfig','markConfigCached'],['Illuminate\\Foundation\\Testing\\WithCachedRoutes','markRoutesCached'],
            ['Illuminate\\Foundation\\Bootstrap\\LoadConfiguration','alwaysUse'],
            ['Illuminate\\Foundation\\Support\\Providers\\RouteServiceProvider','loadCachedRoutesUsing'],
            ['Example\\LocalCacheRegistration\\CacheBootHooks','createApplication'],
            ['Example\\LocalCacheRegistration\\CacheBootHooks','markConfigCached'],['Example\\LocalCacheRegistration\\CacheBootHooks','markRoutesCached'],
        ] as [$class,$method]){
            $declaring=$context->codebase->getDeclaringMethod($class,$method);$effective=$context->codebase->getMethod($class,$method);
            $bindings[$class.'::'.$method]=['declaring'=>$declaring===null?null:DefensiveBoundaryGuardProof::compact($declaring),
                'effective'=>$effective===null?null:DefensiveBoundaryGuardProof::compact($effective)];
        }
        foreach(['class_uses_recursive','trait_uses_recursive'] as $name){$native=$context->codebase->getFunction($name);
            $bindings['function:'.$name]=$native===null?null:DefensiveBoundaryGuardProof::compact($native);}
        foreach(['Illuminate\\Foundation\\Testing\\WithCachedConfig','Illuminate\\Foundation\\Testing\\WithCachedRoutes'] as $name){
            $trait=$context->codebase->getTrait($name);$bindings['trait:'.$name]=$trait===null?null:get_object_vars($trait);}
        $interface=$context->codebase->getInterface('Illuminate\\Contracts\\Container\\Container');
        $bindings['interface:Illuminate\\Contracts\\Container\\Container']=$interface===null?null:get_object_vars($interface);
        foreach(['Illuminate\\Foundation\\Application','Illuminate\\Container\\Container','Illuminate\\Cache\\CacheManager','Illuminate\\Cache\\CacheServiceProvider','Example\\LocalCacheRegistration\\CacheBootHooks'] as $name){
            $native=$context->codebase->getClass($name);$bindings['class:'.$name]=$native===null?null:get_object_vars($native);}
        foreach([['Illuminate\\Foundation\\Bootstrap\\LoadConfiguration','$alwaysUseConfig'],
            ['Illuminate\\Foundation\\Support\\Providers\\RouteServiceProvider','$alwaysLoadCachedRoutesUsing']] as [$class,$name]){
            $declaring=$context->codebase->getDeclaringProperty($class,$name);$effective=$context->codebase->getProperty($class,$name);
            $bindings['property:'.$class.'::'.$name]=['declaring'=>$declaring===null?null:get_object_vars($declaring),'effective'=>$effective===null?null:get_object_vars($effective)];}
        $row=['stage'=>'factory-faker','span'=>[$context->issue->annotations[0]->span->start,$context->issue->annotations[0]->span->end],
            'alwaysKeep'=>true,'contextFile'=>$context->file,'contextContentsSha256'=>hash('sha256',$context->contents),'proof'=>$proof,'independentFormatterProfiles'=>$formatterProfiles,'independentNamedBindings'=>$bindings,'wholeNativeEnvelope'=>$context->issue,
            'nativeCacheMutated'=>false,'nativeTypesChanged'=>false,'sdkContextsConstructed'=>false,'applicationExecuted'=>false];
        file_put_contents($this->output,json_encode(DeprecatedMethodCompatibilityProof::canonical($row),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
        return IssueFilterDecision::Keep;
    }
}
final class LocalCacheFixturePlugin implements Plugin
{
    public function __construct(private readonly string $root,private readonly string $mode,private readonly string $output){}
    public function getDefinition():PluginDefinition{return new PluginDefinition('example/local-cache-receiver','Local cache receiver fixture','Current named caller and physical library bindings; no application execution.');}
    public function register(PluginRegistry $registry):void
    {
        if(in_array($this->mode,['draft','preserve'],true)){(new \Ichinya\Laramago\Analyzer\FactoryFakerPlugin($this->root,$this->mode==='preserve'))->register($registry);}
        $contracts=new FactoryFakerContracts($this->root);$registry->registerInitializationHook($contracts);$registry->registerCodebaseScanHook($contracts);
        if(str_starts_with($this->mode,'cache-local-')||str_starts_with($this->mode,'cache-source-local-')){
            $registry->registerIssueFilterHook(new LocalCacheFocusedActor(new FactoryFakerProof($contracts,$this->root),$this->root,$this->mode,$this->output));
        }else{
        $registry->registerIssueFilterHook(new LocalCacheFixtureObserver(new FactoryFakerProof($contracts,$this->root),$this->output));
        }
    }
}
