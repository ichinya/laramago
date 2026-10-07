<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Tests;
use Ichinya\Laramago\Analyzer\SelectedChunkCollectionArgumentFilter;
use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterDecision,IssueFilterHook,Plugin,PluginDefinition,PluginRegistry,Type};
use Mago\Sdk\Analyzer\Metadata\{FunctionLikeMetadata,ClassLikeMetadata,ClassLikeKind,PropertyMetadata,MetadataFlags};
use Mago\Sdk\Reporting\{Level,AnnotationKind};
use Mago\Sdk\Span;

final class SelectedChunkCollectionNativeHarness implements Plugin
{
    public function __construct(private readonly string $root,private readonly string $output,private readonly bool $controls=false) {}
    public function getDefinition():PluginDefinition { return new PluginDefinition('fixture/selected-chunk-collection','Selected chunk collection observation','AlwaysKeep and actual selected SDK controls.'); }
    public function register(PluginRegistry $registry):void
    {
        $hook=new class(new SelectedChunkCollectionArgumentFilter($this->root),$this->output,$this->controls) implements IssueFilterHook {
            private bool $checked=false;
            public function __construct(private readonly SelectedChunkCollectionArgumentFilter $filter,private readonly string $output,private readonly bool $controls) {}
            public function getCodes():array { return ['less-specific-argument']; }
            private static function snapshot(mixed $value):mixed {
                if($value instanceof \BackedEnum) { return $value->value; }if($value instanceof \UnitEnum) { return $value->name; }
                if(is_object($value)) { return self::snapshot(get_object_vars($value)); }return is_array($value)?array_map(self::snapshot(...),$value):$value;
            }
            public function filterIssue(IssueFilterContext $context):IssueFilterDecision
            {
                $decision=$this->filter->filterIssue($context);
                $native=$this->filter->dependencies;$certificate=$this->filter->certificate;
                $record=['genuineIssueFilterContext'=>true,'alwaysKeep'=>!$this->controls,'file'=>$context->file,'sourceSha256'=>hash('sha256',$context->contents),
                    'issue'=>self::snapshot($context->issue),'candidateDecision'=>$decision->name,'stages'=>$this->filter->stages,'certificate'=>$certificate];
                if(!$this->controls) { file_put_contents($this->output.'/issues.jsonl',json_encode($record,JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);return IssueFilterDecision::Keep; }
                if($this->checked || $decision!==IssueFilterDecision::Remove || ($certificate['owner']??null)!=='Fixture\\Projection::configured') { return $decision; }
                $this->checked=true;$checks=[];$mutations=[];
                file_put_contents($this->output.'/initial-chunk.json',json_encode($record,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
                $expect=function(string $label,IssueFilterContext $input,bool $remove)use(&$checks):void {
                    $actual=$this->filter->filterIssue($input)===IssueFilterDecision::Remove;
                    if($actual!==$remove) { file_put_contents($this->output.'/first-failure-chunk.json',json_encode(['label'=>$label,'expectedRemove'=>$remove,'actualRemove'=>$actual,
                        'stages'=>$this->filter->stages,'dependencies'=>self::snapshot($this->filter->dependencies),'certificate'=>$this->filter->certificate,'passed'=>$checks],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
                        throw new \RuntimeException('Selected chunk native control failed: '.$label); }$checks[$label]=true;
                };
                $expect('genuine positive before any constructed input',$context,true);
                $copy=static function(object $original,array $changes):object { $class=$original::class;return new $class(...array_replace(get_object_vars($original),$changes)); };
                $issue=$context->issue;[$primary,$secondary]=$issue->annotations;
                $with=static fn(object $report,?string $contents=null):IssueFilterContext=>new IssueFilterContext($context->phpVersion,$context->codebase,$context->types,$context->cancellation,$context->file,$contents??$context->contents,$report);
                foreach(['wrong code'=>['code'=>'invalid-argument'],'wrong level'=>['level'=>Level::Warning],'missing notes'=>['notes'=>[]],
                    'wrong note'=>['notes'=>['Other argument contract.']],'wrong help'=>['help'=>null],'foreign link'=>['link'=>'https://example.invalid'],
                    'duplicate primary'=>['annotations'=>[$primary,$primary]],'wrong primary kind'=>['annotations'=>[$copy($primary,['kind'=>AnnotationKind::Secondary]),$secondary]],
                    'wrong primary text'=>['annotations'=>[$copy($primary,['message'=>'Other argument.']),$secondary]],
                    'wrong secondary text'=>['annotations'=>[$primary,$copy($secondary,['message'=>'Other receiving declaration.'])]],
                    'partial argument span'=>['annotations'=>[$copy($primary,['span'=>new Span($primary->span->start+1,$primary->span->end)]),$secondary]],
                    'foreign primary file'=>['annotations'=>[$copy($primary,['file'=>'foreign.php']),$secondary]]] as $label=>$changes) { $expect($label,$with($copy($issue,$changes)),false); }
                $expect('stale caller contents',$with($issue,$context->contents.' '),false);$queue=[];
                foreach($native as $dependency=>$metadata) {
                    if($metadata===null || !isset($certificate['bindings'][$dependency])) { throw new \RuntimeException('Missing genuine selected binding: '.$dependency); }
                    $queue[$dependency.' absent']=[$dependency,$metadata,null];
                    if($metadata instanceof FunctionLikeMetadata) {
                        $queue[$dependency.' displaced method']=[$dependency,$metadata,$copy($metadata,['location'=>$copy($metadata->location,['span'=>new Span($metadata->location->span->start+1,$metadata->location->span->end)])])];
                        $queue[$dependency.' reference return']=[$dependency,$metadata,$copy($metadata,['flags'=>new MetadataFlags($metadata->flags->bits|MetadataFlags::BY_REFERENCE)])];
                        $queue[$dependency.' changed staticness']=[$dependency,$metadata,$copy($metadata,['static'=>!$metadata->static])];
                        if(!in_array($dependency,['receiving','owner'],true)) { $queue[$dependency.' wrong effective result']=[$dependency,$metadata,$copy($metadata,['returnType'=>$copy($metadata->returnType,['type'=>Type::float()])])]; }
                        if($dependency==='receiving') {
                            $parameters=$metadata->parameters;$formal=$parameters[0];$parameters[0]=$copy($formal,['flags'=>new MetadataFlags($formal->flags->bits|MetadataFlags::BY_REFERENCE)]);
                            $queue[$dependency.' selected reference formal']=[$dependency,$metadata,$copy($metadata,['parameters'=>$parameters])];
                            $parameters=$metadata->parameters;$parameters[0]=$copy($formal,['type'=>$copy($formal->type,['type'=>Type::float()])]);
                            $queue[$dependency.' wrong effective formal']=[$dependency,$metadata,$copy($metadata,['parameters'=>$parameters])];
                        }
                        if($dependency==='with') {
                            $return=$metadata->returnType;$atom=count($return?->type->atomicTypes??[])===1?$return->type->atomicTypes[0]:null;
                            if(!$atom instanceof \Mago\Sdk\Analyzer\Type\NamedObjectType || $atom->name!=='$this' || !$atom->isThis || !$atom->static) {
                                throw new \RuntimeException('Missing genuine receiver-relative return carrier.');
                            }
                            foreach(['different receiver name'=>['name'=>'Fixture\OtherBuilder'],'lost this carrier'=>['isThis'=>false],
                                'lost late-static carrier'=>['static'=>false]] as $label=>$changes) {
                                $queue[$dependency.' '.$label]=[$dependency,$metadata,$copy($metadata,['returnType'=>$copy($return,
                                    ['type'=>Type::fromAtomic($copy($atom,$changes))])])];
                            }
                        }
                    } elseif($metadata instanceof ClassLikeMetadata) {
                        $queue[$dependency.' changed class kind']=[$dependency,$metadata,$copy($metadata,['kind'=>ClassLikeKind::Trait])];
                    } elseif($metadata instanceof PropertyMetadata) {
                        $queue[$dependency.' virtual field']=[$dependency,$metadata,$copy($metadata,['flags'=>new MetadataFlags($metadata->flags->bits|MetadataFlags::VIRTUAL_PROPERTY)])];
                        $queue[$dependency.' changed static field']=[$dependency,$metadata,$copy($metadata,['flags'=>new MetadataFlags($metadata->flags->bits&~MetadataFlags::STATIC)])];
                        $queue[$dependency.' wrong declared field']=[$dependency,$metadata,$copy($metadata,['declaredType'=>$copy($metadata->declaredType,['type'=>Type::float()])])];
                        $queue[$dependency.' wrong effective field']=[$dependency,$metadata,$copy($metadata,['type'=>$copy($metadata->type,['type'=>Type::float()])])];
                        $queue[$dependency.' wrong default class']=[$dependency,$metadata,$copy($metadata,['defaultType'=>$copy($metadata->defaultType,['type'=>Type::literalString('Fixture\\OtherRow')])])];
                        $queue[$dependency.' displaced field name']=[$dependency,$metadata,$copy($metadata,['nameLocation'=>$copy($metadata->nameLocation,['span'=>new Span($metadata->nameLocation->span->start+1,$metadata->nameLocation->span->end)])])];
                    } else { throw new \RuntimeException('Unrecognized genuine dependency kind.'); }
                }
                $cache=(new \ReflectionProperty($context->codebase,'cache'))->getValue($context->codebase);
                foreach($queue as $label=>[$dependency,$metadata,$replacement]) {
                    $expect('genuine positive immediately before '.$label,$context,true);$binding=$certificate['bindings'][$dependency];
                    // Populate both genuine public aliases before an absence control can trigger fallback.
                    // The production proof keeps its normal selected/declaring fallback semantics.
                    $aliases=match($binding['kind']) {
                        'method'=>[$context->codebase->getMethod($binding['class'],$binding['name']),
                            $context->codebase->getDeclaringMethod($binding['class'],$binding['name'])],
                        'class'=>[$context->codebase->getClass($binding['name'])],
                        'property'=>[$context->codebase->getDeclaringProperty($binding['class'],$binding['name']),
                            $context->codebase->getProperty($binding['class'],$binding['name'])],
                        default=>throw new \RuntimeException('Unknown selected binding operation.'),
                    };
                    $current=$aliases[0]??$aliases[1]??null;
                    foreach($aliases as $alias) {
                        if($alias!==null && $alias!=$metadata) { throw new \RuntimeException('Genuine selected aliases differ before mutation: '.$label); }
                    }
                    if($current===null || $current!=$metadata || $current==$replacement) { throw new \RuntimeException('Mutation lacks a current meaningful selected native contract: '.$label); }
                    $saved=$cache->values;$relations=$cache->relations;$slots=[];
                    foreach($saved as $operation=>$entries) { foreach($entries as $key=>$entry) { if(is_object($entry) && $entry::class===$current::class && $entry==$current) { $cache->values[$operation][$key]=$replacement;$slots[]=['operation'=>$operation,'key'=>$key]; } } }
                    try { if($slots===[]) { throw new \RuntimeException('Vacuous actual SDK cache control: '.$label); }$expect('actual SDK cache '.$label,$context,false);
                        $mutations[$label]=['actualSlots'=>$slots,'touched'=>count($slots),'meaningfullyChanged'=>true]; }
                    finally { $cache->values=$saved;$cache->relations=$relations; }
                    $expect('native restoration after '.$label,$context,true);
                }
                $expect('genuine native contracts restored',$context,true);
                file_put_contents($this->output.'/controls-chunk.json',json_encode(['genuinePositive'=>true,'allChecks'=>$checks,'cacheMutations'=>$mutations,
                    'restored'=>true,'noVacuousControls'=>true,'constructedInputNativeAcceptanceClaimed'=>false],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));return $decision;
            }
        };
        $registry->registerIssueFilterHook($hook);
    }
}
