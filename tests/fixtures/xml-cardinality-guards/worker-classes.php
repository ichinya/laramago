<?php
declare(strict_types=1);
use Ichinya\Laramago\Analyzer\XmlCardinalityProof;
use Example\XmlCardinalityFixture\SelectedNativeAliases as ControlledCache;
use Mago\Sdk\Analyzer\{IssueFilterHook,IssueFilterContext,IssueFilterDecision,Plugin,PluginDefinition,PluginRegistry,Type};
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
require __DIR__.'/SelectedNativeAliases.php';
final class XmlCardinalityObserver implements IssueFilterHook
{
    public function __construct(private readonly XmlCardinalityProof $proof,private readonly string $mode,private readonly string $output){ }
    public function getCodes():array{return ['impossible-type-comparison'];}
    public function filterIssue(IssueFilterContext $context):IssueFilterDecision
    {
        $before=$this->proof->prove($context);
        if($before['remove']&&str_starts_with($this->mode,'cache-')){
            if($this->mode==='cache-xpath-return'){$native=$context->codebase->getDeclaringMethod('SimpleXMLElement','xpath');$replacement=ControlledCache::copy($native,['returnType'=>ControlledCache::copy($native->returnType,['type'=>Type::string()])]);}
            elseif($this->mode==='cache-loader-flags'){$native=$context->codebase->getFunction('simplexml_load_string');$replacement=ControlledCache::copy($native,['flags'=>new MetadataFlags($native->flags->bits|MetadataFlags::USER_DEFINED)]);}
            elseif($this->mode==='cache-class-flags'){$native=$context->codebase->getClass('SimpleXMLElement');$replacement=ControlledCache::copy($native,['flags'=>new MetadataFlags($native->flags->bits|MetadataFlags::USER_DEFINED)]);}
            else{throw new RuntimeException('Unknown XML native cache control.');}
            $binding=match($this->mode){'cache-xpath-return'=>['kind'=>'method','class'=>'SimpleXMLElement','name'=>'xpath'],'cache-loader-flags'=>['kind'=>'function','name'=>'simplexml_load_string'],'cache-class-flags'=>['kind'=>'class','name'=>'SimpleXMLElement']};
            if($this->proof->prove($context)!==$before){throw new RuntimeException('The selected XML control lacks a complete genuine current positive.');}
            $control=ControlledCache::control($context->codebase,$native,$replacement,[$binding],function()use($context):void{if($this->proof->prove($context)['remove']){throw new RuntimeException('Changed XML aliases did not defer the current proof.');}});
            if($this->proof->prove($context)!==$before){throw new RuntimeException('Complete XML proof did not restore after native alias control.');}
            file_put_contents($this->output,json_encode(['stage'=>'real-native-cache-control','role'=>$this->mode,'genuinePositiveBefore'=>true,'deferredAfter'=>true,'completeProofRestored'=>true]+$control,JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);return IssueFilterDecision::Keep;
        }
        file_put_contents($this->output,json_encode(['stage'=>'xml-cardinality','file'=>$context->file,'span'=>[$context->issue->annotations[0]->span->start,$context->issue->annotations[0]->span->end],'proof'=>$before,'alwaysKeep'=>$this->mode!=='draft','wholeNativeEnvelope'=>Ichinya\Laramago\Analyzer\DeprecatedMethodCompatibilityProof::canonical($context->issue)],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
        return $this->mode==='draft'&&$before['remove']?IssueFilterDecision::Remove:IssueFilterDecision::Keep;
    }
}
final class XmlCardinalityDraftPlugin implements Plugin
{
    public function __construct(private readonly string $root,private readonly string $mode,private readonly string $output){ }
    public function getDefinition():PluginDefinition{return new PluginDefinition('example/xml-cardinality','XML cardinality guards','Source and genuine builtin profiles for an external XML defensive Warning policy.');}
    public function register(PluginRegistry $registry):void{$registry->registerIssueFilterHook(new XmlCardinalityObserver(new XmlCardinalityProof($this->root),$this->mode,$this->output));}
}
