<?php
declare(strict_types=1);

namespace Ichinya\Laramago\Tests;

use Ichinya\Laramago\Analyzer\ValidatedArrayContractPlugin;
use Mago\Sdk\Analyzer\{FunctionReturnTypeProvider,IssueFilterContext,IssueFilterDecision,IssueFilterHook,Plugin,PluginDefinition,PluginRegistry,ReturnTypeProviderContext,Type};

require $argv[1];
require __DIR__.'/validated-array-contract-controls.php';

/** The original native context is the only positive diagnostic input. */
final class ValidatedArrayTestObserver implements IssueFilterHook
{
    public function __construct(private readonly ValidatedArrayContractPlugin $plugin,private readonly string $root,private readonly string $mode) {}
    public function getCodes():array { return $this->plugin->getCodes(); }
    public function filterIssue(IssueFilterContext $context):IssueFilterDecision {
        $decision=$this->plugin->filterIssue($context);
        file_put_contents($this->root.'/'.$this->mode.'-issues.jsonl',json_encode(['file'=>$context->file,'sourceSha256'=>hash('sha256',$context->contents),
            'completeSdkIssue'=>self::value($context->issue),'candidateDecision'=>$decision->name,'proof'=>self::value($this->plugin->lastProof),'trace'=>self::value($this->plugin->lastTrace),'workerPid'=>getmypid()],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n",FILE_APPEND|LOCK_EX);
        return $this->mode==='compatibility'?$decision:IssueFilterDecision::Keep;
    }
    public static function value(mixed $input):mixed {
        if($input instanceof \UnitEnum) { return $input->name; }
        if(is_object($input)) { return self::value(get_object_vars($input)); }
        return is_array($input)?array_map(self::value(...),$input):$input;
    }
}

/** Share the production initialization and always-null callback provider in every observed mode. */
final class ValidatedArrayTestPlugin implements Plugin,FunctionReturnTypeProvider
{
    private ValidatedArrayContractPlugin $plugin;
    public function __construct(private readonly string $root,private readonly string $mode) { $this->plugin=new ValidatedArrayContractPlugin($root); }
    public function getDefinition():PluginDefinition { return new PluginDefinition('fixture/validated-array-contracts','Validated array fixtures','Genuine source, callback and diagnostic controls.'); }
    public function register(PluginRegistry $registry):void {
        $registry->registerInitializationHook($this->plugin);
        // A mode with no provider proves that issue decisions need no callback-hook execution.
        if($this->mode!=='without-provider') { $registry->registerFunctionReturnTypeProvider($this); }
        $registry->registerIssueFilterHook($this->mode==='guards'?new ValidatedArrayNativeControls($this->plugin,$this->root):new ValidatedArrayTestObserver($this->plugin,$this->root,$this->mode==='without-provider'?'compatibility':$this->mode));
    }
    public function getTargets():array { return $this->plugin->getTargets(); }
    public function getReturnType(ReturnTypeProviderContext $context):?Type {
        $property=new \ReflectionProperty($this->plugin,'callbacks');$before=$property->getValue($this->plugin);
        $result=$this->plugin->getReturnType($context);
        if($result!==null) { throw new \RuntimeException('The native callback observer replaced a return type.'); }
        foreach($property->getValue($this->plugin) as $key=>$certificate) {
            if(isset($before[$key])) { continue; }
            $native=[];
            foreach($context->invocation->arguments[1]->type?->atomicTypes??[] as $atom) {
                if(!$atom instanceof \Mago\Sdk\Analyzer\Type\CallableType||$atom->signature===null||$atom->signature->source===null) { continue; }
                $declaration=$context->codebase->getFunctionLike($atom->alias??$atom->signature->source);
                if($declaration!==null) { $native[]=['identifier'=>ValidatedArrayTestObserver::value($declaration->identifier),'location'=>ValidatedArrayTestObserver::value($declaration->location),
                    'parameters'=>ValidatedArrayTestObserver::value($declaration->parameters),'signature'=>ValidatedArrayTestObserver::value($atom->signature)]; }
            }
            file_put_contents($this->root.'/'.$this->mode.'-callbacks.jsonl',json_encode(['actualCacheKey'=>$key,'certificate'=>$certificate,'nativeCallback'=>$native,
                'nativeInvocation'=>ValidatedArrayTestObserver::value($context->invocation),'providerResult'=>null],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n",FILE_APPEND|LOCK_EX);
        }
        return null;
    }
}

$mode=$argv[3]??'native';
if(str_starts_with($mode,'full-')) {
    // Full after runs the exact production registry without an additional filter invocation.
    require $mode==='full-before'?$argv[5]:$argv[2].'/full-after-worker.php';
} else {
    $plugins=$mode==='native'?[]:[new ValidatedArrayTestPlugin($argv[2],$mode)];
    (new \Mago\Sdk\Worker(new \Mago\Sdk\Extension(identifier:'fixture/validated-array-contracts',name:'Validated array contract fixture',version:'1',analyzerPlugins:$plugins)))->run();
}
