<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Tests;
use Ichinya\Laramago\Analyzer\{PossibleCallbackTupleArgumentFilter,PossibleCallbackTupleShapeFilter};
use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterDecision,IssueFilterHook,Plugin,PluginDefinition,PluginRegistry,Type};
use Mago\Sdk\Analyzer\Metadata\{ClassLikeMetadata,ClassLikeKind,FunctionLikeMetadata,MetadataFlags};
use Mago\Sdk\Reporting\{Level,AnnotationKind};
use Mago\Sdk\Span;

/** Genuine issue observers and mutations of actual selected canonical cache slots. */
final class PossibleTupleNativeHarness implements Plugin
{
    public function __construct(private readonly string $root,private readonly string $output,private readonly bool $controls=false) {}
    public function getDefinition():PluginDefinition { return new PluginDefinition('fixture/possible-tuples','Possible tuple observations','Always-Keep native observer and restored actual SDK controls.'); }
    public function register(PluginRegistry $registry):void
    {
        $registry->registerIssueFilterHook(new class($this->root,$this->output,$this->controls) implements IssueFilterHook {
            private int $sequence=0;private array $active=[];private array $checked=[];private bool $constantChecked=false;
            public function __construct(private readonly string $root,private readonly string $output,private readonly bool $controls) {}
            public function getCodes():array { return ['mixed-argument','invalid-destructuring-source']; }
            private function filter(string $family):object { return $family==='shape'?new PossibleCallbackTupleShapeFilter($this->root):new PossibleCallbackTupleArgumentFilter($this->root); }
            private static function value(mixed $value):mixed { if($value instanceof \BackedEnum) { return $value->value; }if($value instanceof \UnitEnum) { return $value->name; }if(is_object($value)) { return self::value(get_object_vars($value)); }return is_array($value)?array_map(self::value(...),$value):$value; }
            public function filterIssue(IssueFilterContext $context):IssueFilterDecision
            {
                $eventId=getmypid().':'.(++$this->sequence);
                $entry=['genuineIssueFilterContext'=>true,'documentaryEventIdOnly'=>true,'eventId'=>$eventId,
                    'file'=>$context->file,'sourceSha256'=>hash('sha256',$context->contents),
                    'phpVersion'=>self::value($context->phpVersion),'issue'=>self::value($context->issue),
                    'concurrentEvaluationDepth'=>count($this->active),'controls'=>$this->controls];
                file_put_contents($this->output.'/hook-entries.jsonl',json_encode($entry,JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
                if($this->active!==[]) {
                    $record=[...$entry,'activeGenuineEntries'=>array_values($this->active),'observerDecision'=>'Keep',
                        'candidateEvaluationPlanned'=>!$this->controls,
                        'reason'=>$this->controls?'Control guard keeps a concurrent genuine context without evaluating during selected cache mutation.'
                            :'Always-Keep observer evaluates the concurrent genuine context with a fresh proof.'];
                    file_put_contents($this->output.'/concurrent-issues.jsonl',json_encode($record,JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
                    if($this->controls) { return IssueFilterDecision::Keep; }
                }
                $this->active[$eventId]=$entry;
                try { return $this->evaluate($context,$entry); }finally { unset($this->active[$eventId]); }
            }
            private function evaluate(IssueFilterContext $context,array $entry):IssueFilterDecision
            {
                $family=$context->issue->code==='invalid-destructuring-source'?'shape':'argument';$filter=$this->filter($family);$decision=$filter->filterIssue($context);
                $record=[...$entry,'alwaysKeep'=>!$this->controls,'candidateEvaluated'=>true,
                    'candidateDecision'=>$decision->name,'family'=>$family,'stages'=>$filter->stages,
                    'certificate'=>$filter->certificate,'dependencies'=>self::value($filter->dependencies)];
                if(!$this->controls) { file_put_contents($this->output.'/issues.jsonl',json_encode($record,JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);return IssueFilterDecision::Keep; }
                if($this->controls&&!$this->constantChecked&&$family==='argument'&&$context->file==='positive.php'
                    &&($filter->dependencies['owner']?->identifier->name??null)==='constantText') {
                    if($decision!==IssueFilterDecision::Remove) { throw new \RuntimeException('Genuine constant positive refused before any controls.'); }
                    $this->constantChecked=true;PossibleTupleConstantControls::run($this->root,$this->output,$context,$filter);
                    return $decision;
                }
                if($decision!==IssueFilterDecision::Remove || isset($this->checked[$family]) || ($filter->dependencies['owner']?->identifier->name??null)!=='text'
                    || $context->file!=='positive.php') { return $decision; }$this->checked[$family]=true;
                $dependencies=$filter->dependencies;$checks=[];$mutations=[];
                file_put_contents($this->output.'/initial-'.$family.'.json',json_encode($record,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
                $expect=function(string $label,IssueFilterContext $input,bool $wanted)use($family,&$checks):void {
                    $proof=$this->filter($family);$actual=$proof->filterIssue($input)===IssueFilterDecision::Remove;
                    if($actual!==$wanted) { file_put_contents($this->output.'/first-failure-'.$family.'.json',json_encode(['label'=>$label,'expectedRemove'=>$wanted,'actualRemove'=>$actual,
                        'stages'=>$proof->stages,'dependencies'=>self::value($proof->dependencies),'certificate'=>$proof->certificate,'passed'=>$checks],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));throw new \RuntimeException('Native tuple control failed: '.$label); }
                    $checks[$label]=true;
                };
                $copy=static function(object $value,array $changes):object { $class=$value::class;return new $class(...array_replace(get_object_vars($value),$changes)); };
                $change=function(array $fields)use($context,$copy):IssueFilterContext { return new IssueFilterContext($context->phpVersion,$context->codebase,$context->types,$context->cancellation,$context->file,$context->contents,$copy($context->issue,$fields)); };
                $expect('genuine positive before constructed controls',$context,true);
                $expect('wrong severity',$change(['level'=>Level::Warning]),false);
                $expect('wrong issue code',$change(['code'=>'invalid-argument']),false);
                $expect('wrong message',$change(['message'=>$context->issue->message.' changed']),false);
                $expect('wrong notes',$change(['notes'=>[]]),false);
                $expect('wrong help',$change(['help'=>'Changed help.']),false);
                $expect('foreign primary file',$change(['annotations'=>[$copy($context->issue->annotations[0],['file'=>'foreign.php']),...array_slice($context->issue->annotations,1)]]),false);
                $primary=$context->issue->annotations[0];$annotations=$context->issue->annotations;$annotations[0]=$copy($primary,['span'=>new Span($primary->span->start+1,$primary->span->end)]);
                $expect('partial primary span',$change(['annotations'=>$annotations]),false);
                $queue=[];
                foreach($dependencies as $role=>$metadata) {
                    $queue[$role.' absent']=[$metadata,null];
                    if($metadata instanceof FunctionLikeMetadata) {
                        $queue[$role.' displaced declaration']=[$metadata,$copy($metadata,['location'=>$copy($metadata->location,['span'=>new Span($metadata->location->span->start+1,$metadata->location->span->end)])])];
                        $queue[$role.' displaced name']=[$metadata,$copy($metadata,['nameLocation'=>$copy($metadata->nameLocation,['span'=>new Span($metadata->nameLocation->span->start+1,$metadata->nameLocation->span->end)])])];
                        $queue[$role.' changed identity']=[$metadata,$copy($metadata,['identifier'=>$copy($metadata->identifier,['name'=>'differentMember'])])];
                        $queue[$role.' reference return']=[$metadata,$copy($metadata,['flags'=>new MetadataFlags($metadata->flags->bits|MetadataFlags::BY_REFERENCE)])];
                        $queue[$role.' changed staticness']=[$metadata,$copy($metadata,['static'=>!$metadata->static])];
                        $queue[$role.' missing formal']=[$metadata,$copy($metadata,['parameters'=>[]])];
                        $parameters=$metadata->parameters;$formal=$parameters[0];
                        $parameters[0]=$copy($formal,['flags'=>new MetadataFlags($formal->flags->bits|MetadataFlags::BY_REFERENCE)]);$queue[$role.' reference formal']=[$metadata,$copy($metadata,['parameters'=>$parameters])];
                        $parameters=$metadata->parameters;$parameters[0]=$copy($formal,['outType'=>$formal->declaredType]);$queue[$role.' out formal']=[$metadata,$copy($metadata,['parameters'=>$parameters])];
                        $parameters=$metadata->parameters;$parameters[0]=$copy($formal,['declaredType'=>$copy($formal->declaredType,['location'=>$copy($formal->declaredType->location,['span'=>new Span($formal->declaredType->location->span->start+1,$formal->declaredType->location->span->end)])])]);$queue[$role.' displaced formal type']=[$metadata,$copy($metadata,['parameters'=>$parameters])];
                        $parameters=$metadata->parameters;$parameters[0]=$copy($formal,['declaredType'=>$copy($formal->declaredType,['type'=>Type::float()])]);$queue[$role.' wrong declared formal']=[$metadata,$copy($metadata,['parameters'=>$parameters])];
                        $parameters=$metadata->parameters;$parameters[0]=$copy($formal,['name'=>'$otherFormal']);$queue[$role.' renamed formal']=[$metadata,$copy($metadata,['parameters'=>$parameters])];
                        $parameters=$metadata->parameters;$parameters[0]=$copy($formal,['flags'=>new MetadataFlags($formal->flags->bits ^ MetadataFlags::HAS_DEFAULT)]);$queue[$role.' changed formal default']=[$metadata,$copy($metadata,['parameters'=>$parameters])];
                        $parameters=$metadata->parameters;$parameters[0]=$copy($formal,['type'=>$copy($formal->type,['type'=>Type::float()])]);$queue[$role.' wrong effective formal']=[$metadata,$copy($metadata,['parameters'=>$parameters])];
                    } elseif($metadata instanceof ClassLikeMetadata) {
                        $queue[$role.' displaced declaration']=[$metadata,$copy($metadata,['location'=>$copy($metadata->location,['span'=>new Span($metadata->location->span->start+1,$metadata->location->span->end)])])];
                        $queue[$role.' changed identity']=[$metadata,$copy($metadata,['name'=>'Fixture\\OtherClass'])];
                        $queue[$role.' changed kind']=[$metadata,$copy($metadata,['kind'=>ClassLikeKind::Trait])];
                        $queue[$role.' incomplete hierarchy']=[$metadata,$copy($metadata,['unresolvedHierarchyDependencies'=>['Fixture\\UnknownParent']])];
                    } else { throw new \RuntimeException('Unexpected tuple dependency DTO.'); }
                }
                $cache=(new \ReflectionProperty($context->codebase,'cache'))->getValue($context->codebase);
                foreach($queue as $label=>[$metadata,$replacement]) {
                    $expect('genuine positive immediately before '.$label,$context,true);
                    $aliases=$metadata instanceof ClassLikeMetadata?[$context->codebase->getClass($metadata->name)]:[
                        $context->codebase->getMethod($metadata->identifier->class,$metadata->identifier->name),
                        $context->codebase->getDeclaringMethod($metadata->identifier->class,$metadata->identifier->name)];
                    $current=null;
                    foreach($aliases as $alias) { if($alias===null) { continue; }if($alias!=$metadata) { throw new \RuntimeException('Current genuine aliases disagree: '.$label); }$current??=$alias; }
                    if($current===null || $current==$replacement) { throw new \RuntimeException('Cache mutation has no genuine meaningful selected contract.'); }
                    $saved=$cache->values;$relations=$cache->relations;$slots=[];
                    foreach($saved as $operation=>$entries) { foreach($entries as $key=>$entry) { if(is_object($entry) && $entry::class===$current::class && $entry==$current) { $cache->values[$operation][$key]=$replacement;$slots[]=['operation'=>$operation,'key'=>$key]; } } }
                    try { if($slots===[]) { throw new \RuntimeException('No actual selected canonical slots.'); }$expect('mutated actual native cache '.$label,$context,false); }
                    finally { $cache->values=$saved;$cache->relations=$relations; }
                    $expect('native contracts restored after '.$label,$context,true);
                    $mutations[$label]=['actualSlots'=>$slots,'touched'=>count($slots),'meaningfullyChanged'=>true];
                }
                $expect('genuine native contracts restored',$context,true);
                file_put_contents($this->output.'/controls-'.$family.'.json',json_encode(['genuinePositive'=>true,'restored'=>true,'noVacuousControls'=>true,
                    'allChecks'=>$checks,'cacheMutations'=>$mutations,'constructedInputNativeAcceptanceClaimed'=>false],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
                return $decision;
            }
        });
    }
}
