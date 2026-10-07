<?php
declare(strict_types=1);
use Ichinya\Laramago\Analyzer\CollectionOffsetGuardProof;
use Example\NativeFixtureSupport\SelectedNativeAliases as ControlledCache;
use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterHook,IssueFilterDecision,Plugin,PluginDefinition,PluginRegistry,Type};
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
require __DIR__.'/SelectedNativeAliases.php';
final class CollectionOffsetGuardObserver implements IssueFilterHook
{
    public function __construct(private readonly CollectionOffsetGuardProof $proof,private readonly string $mode,private readonly string $output){}
    public function getCodes():array{return ['possibly-null-array-index','impossible-condition'];}
    public function filterIssue(IssueFilterContext $context):IssueFilterDecision
    {
        $before=$this->proof->prove($context);
        if($before['remove']&&str_starts_with($this->mode,'cache-')){
            if($this->mode==='cache-query-return'){$native=$context->codebase->getDeclaringMethod('Illuminate\\Database\\Eloquent\\Model','query');$replacement=ControlledCache::copy($native,['returnType'=>ControlledCache::copy($native->returnType,['type'=>Type::string()])]);}
            elseif($this->mode==='cache-key-by-return'){$native=$context->codebase->getDeclaringMethod('Illuminate\\Support\\Collection','keyBy');$replacement=ControlledCache::copy($native,['returnType'=>ControlledCache::copy($native->returnType,['type'=>Type::int()])]);}
            elseif($this->mode==='cache-storage-object'){$native=$context->codebase->getDeclaringProperty('Illuminate\\Support\\Collection','$items');$replacement=ControlledCache::copy($native,['type'=>ControlledCache::copy($native->type,['type'=>Type::object()])]);}
            elseif($this->mode==='cache-caller-reference'){$scope=$before['source']['scope'];$native=$context->codebase->getDeclaringMethod($scope['class'],$scope['name']);$replacement=ControlledCache::copy($native,['flags'=>new MetadataFlags($native->flags->bits|MetadataFlags::BY_REFERENCE)]);}
            elseif(in_array($this->mode,['cache-key-by-population','cache-key-by-constraint','cache-key-by-doc-origin'],true)){
                $native=$context->codebase->getDeclaringMethod('Illuminate\\Support\\Collection','keyBy');$return=$native->returnType;
                if($this->mode==='cache-key-by-doc-origin'){$changedReturn=ControlledCache::copy($return,['fromDocblock'=>!$return->fromDocblock]);}
                else{$union=$return->type;$atoms=$union->atomicTypes;$atom=$atoms[0];$parameters=$atom->parameters;$value=$parameters[1];$generics=$value->atomicTypes;$generic=$generics[0];$constraint=$generic->constraint;
                    if($generic->name!=='TValue'||$generic->definingEntity->name!=='illuminate\\support\\collection'){throw new RuntimeException('Control lacks a genuine selected Collection value constraint.');}
                    $changedConstraint=$this->mode==='cache-key-by-population'?$constraint->withFlags(ControlledCache::copy($constraint->flags,['populated'=>!$constraint->flags->populated])):Type::int();
                    $generics[0]=ControlledCache::copy($generic,['constraint'=>$changedConstraint]);$parameters[1]=Type::fromAtomics(...$generics)->withFlags($value->flags);$atoms[0]=ControlledCache::copy($atom,['parameters'=>$parameters]);$changedReturn=ControlledCache::copy($return,['type'=>Type::fromAtomics(...$atoms)->withFlags($union->flags)]);
                }$replacement=ControlledCache::copy($native,['returnType'=>$changedReturn]);
            }
            elseif($this->mode==='cache-model-mixin'){$native=$context->codebase->getClass($before['source']['query']['model']);$replacement=ControlledCache::copy($native,['mixins'=>[Type::namedObject('stdClass')]]);}
            else{throw new RuntimeException('Unknown Collection cache control.');}
            $bindings=match($this->mode){
                'cache-query-return'=>[['kind'=>'method','class'=>$before['source']['query']['model'],'name'=>'query'],['kind'=>'method','class'=>'Illuminate\\Database\\Eloquent\\Model','name'=>'query']],
                'cache-key-by-population','cache-key-by-constraint','cache-key-by-doc-origin','cache-key-by-return'=>[['kind'=>'method','class'=>'Illuminate\\Database\\Eloquent\\Collection','name'=>'keyBy'],['kind'=>'method','class'=>'Illuminate\\Support\\Collection','name'=>'keyBy']],
                'cache-storage-object'=>[['kind'=>'property','class'=>'Illuminate\\Database\\Eloquent\\Collection','name'=>'$items'],['kind'=>'property','class'=>'Illuminate\\Support\\Collection','name'=>'$items']],
                'cache-model-mixin'=>[['kind'=>'class','name'=>$before['source']['query']['model']]],
                'cache-caller-reference'=>[['kind'=>'method','class'=>$before['source']['scope']['class'],'name'=>$before['source']['scope']['name']]],
            };
            if($this->proof->prove($context)!==$before){throw new RuntimeException('A cache control requires the complete current genuine positive.');}
            $populationOnly=$this->mode==='cache-key-by-population';
            $control=ControlledCache::control($context->codebase,$native,$replacement,$bindings,function()use($context,$populationOnly):void{if($this->proof->prove($context)['remove']!==$populationOnly){throw new RuntimeException('Changed native aliases violated the selected semantic Collection contract.');}});
            if($this->proof->prove($context)!==$before){throw new RuntimeException('Complete Collection proof was not restored after native alias control.');}
            file_put_contents($this->output,json_encode(['stage'=>'real-native-cache-control','role'=>$this->mode,'kind'=>$before['source']['kind'],'genuinePositiveBefore'=>true,'deferredAfter'=>!$populationOnly,'populationOnlyAccepted'=>$populationOnly,'completeProofRestored'=>true]+$control,JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);return IssueFilterDecision::Keep;
        }
        file_put_contents($this->output,json_encode(['stage'=>'collection-boundary','span'=>[$context->issue->annotations[0]->span->start,$context->issue->annotations[0]->span->end],'proof'=>$before,'alwaysKeep'=>$this->mode!=='draft','wholeNativeEnvelope'=>Ichinya\Laramago\Analyzer\DeprecatedMethodCompatibilityProof::canonical($context->issue)],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
        return $this->mode==='draft'&&$before['remove']?IssueFilterDecision::Remove:IssueFilterDecision::Keep;
    }
}
final class CollectionOffsetGuardDraftPlugin implements Plugin
{
    public function __construct(private readonly string $root,private readonly string $mode,private readonly string $output){}
    public function getDefinition():PluginDefinition{return new PluginDefinition('example/collection-boundary','Collection boundary guards','Exact selected defensive Collection Warning reporting policy.');}
    public function register(PluginRegistry $registry):void{$registry->registerIssueFilterHook(new CollectionOffsetGuardObserver(new CollectionOffsetGuardProof($this->root),$this->mode,$this->output));}
}
