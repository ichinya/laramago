<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Tests;
use Ichinya\Laramago\Analyzer\DefaultDateArgumentFilter;
use Mago\Sdk\Analyzer\{CodebaseScanContext,CodebaseScanHook,IssueFilterContext,IssueFilterDecision,IssueFilterHook,Plugin,PluginDefinition,PluginRegistry,Type};
use Mago\Sdk\Analyzer\Metadata\{FunctionLikeMetadata,ClassLikeMetadata,PropertyMetadata,MetadataFlags};
use Mago\Sdk\Reporting\{Level,AnnotationKind};
use Mago\Sdk\Span;

final class DefaultDateNativeHarness implements Plugin
{
    public function __construct(private readonly string $root,private readonly string $output,private readonly bool $controls=false) {}
    public function getDefinition(): PluginDefinition { return new PluginDefinition('fixture/default-date-observation','Default date native observation','AlwaysKeep and actual selected native cache controls.'); }
    public function register(PluginRegistry $registry): void
    {
        $hook=new class($this->root,$this->output,$this->controls) implements IssueFilterHook,CodebaseScanHook {
            private array $checked=[];private readonly DefaultDateArgumentFilter $dates;
            public function __construct(private readonly string $root,private readonly string $output,private readonly bool $controls) { $this->dates=new DefaultDateArgumentFilter($root); }
            public function getTargets(): array { return ['**']; }
            public function getCodes(): array { return ['less-specific-argument']; }
            public function scan(CodebaseScanContext $context): void { $this->dates->scan($context); }
            private static function snapshot(mixed $value): mixed {
                if ($value instanceof \BackedEnum) { return $value->value; }if ($value instanceof \UnitEnum) { return $value->name; }
                if (is_object($value)) { return self::snapshot(get_object_vars($value)); }return is_array($value)?array_map(self::snapshot(...),$value):$value;
            }
            private function filter(string $family): object { return $this->dates; }
            public function filterIssue(IssueFilterContext $context): IssueFilterDecision
            {
                $families=['dates'];$selected=null;$decision=IssueFilterDecision::Keep;$traces=[];
                foreach ($families as $family) { $filter=$this->filter($family);$value=$filter->filterIssue($context);$traces[$family]=$filter->stages;
                    if ($value===IssueFilterDecision::Remove) { if ($selected!==null) { throw new \RuntimeException('More than one argument family claimed one native issue.'); }$selected=[$family,$filter];$decision=$value; }
                }
                $record=['genuineIssueFilterContext'=>true,'alwaysKeep'=>!$this->controls,'file'=>$context->file,'sourceSha256'=>hash('sha256',$context->contents),
                    'issue'=>self::snapshot($context->issue),'candidateDecision'=>$decision->name,'family'=>$selected[0]??null,'stages'=>$traces,
                    'certificate'=>self::snapshot($selected[1]->certificate??$filter->certificate),'dependencies'=>self::snapshot($selected[1]->dependencies??$filter->dependencies)];
                if (! $this->controls) { file_put_contents($this->output.'/issues.jsonl',json_encode($record,JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);return IssueFilterDecision::Keep; }
                if ($selected===null || isset($this->checked[$selected[0]])) { return $decision; }
                [$family,$filter]=$selected;$this->checked[$family]=true;$checks=[];$mutations=[];$native=$filter->dependencies;
                file_put_contents($this->output.'/initial-'.$family.'.json',json_encode($record,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
                $expect=function(string $label,IssueFilterContext $input,bool $remove) use (&$checks,$filter,$family): void {
                    $actual=$filter->filterIssue($input)===IssueFilterDecision::Remove;
                    if ($actual!==$remove) { file_put_contents($this->output.'/first-failure-'.$family.'.json',json_encode(['label'=>$label,'expectedRemove'=>$remove,'actualRemove'=>$actual,
                        'stages'=>$filter->stages,'dependencies'=>self::snapshot($filter->dependencies),'certificate'=>$filter->certificate,'passed'=>$checks],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
                        throw new \RuntimeException('Projected argument control failed: '.$family.' '.$label); }$checks[$label]=true;
                };
                $expect('genuine positive before any constructed input',$context,true);
                $copy=static function(object $original,array $changes): object { $class=$original::class;return new $class(...array_replace(get_object_vars($original),$changes)); };
                $issue=$context->issue;[$primary,$secondary]=$issue->annotations;
                $with=static fn(object $report,?string $contents=null):IssueFilterContext=>new IssueFilterContext($context->phpVersion,$context->codebase,$context->types,$context->cancellation,$context->file,$contents??$context->contents,$report);
                foreach (['wrong code'=>['code'=>'invalid-argument'],'wrong level'=>['level'=>Level::Warning],'missing notes'=>['notes'=>[]],
                    'wrong note'=>['notes'=>['Other argument contract.']],'wrong help'=>['help'=>null],'foreign link'=>['link'=>'https://example.invalid'],
                    'duplicate primary'=>['annotations'=>[$primary,$primary]],'wrong primary kind'=>['annotations'=>[$copy($primary,['kind'=>AnnotationKind::Secondary]),$secondary]],
                    'wrong primary text'=>['annotations'=>[$copy($primary,['message'=>'Other argument.']),$secondary]],
                    'wrong secondary text'=>['annotations'=>[$primary,$copy($secondary,['message'=>'Other receiving declaration.'])]],
                    'partial argument span'=>['annotations'=>[$copy($primary,['span'=>new Span($primary->span->start+1,$primary->span->end)]),$secondary]],
                    'foreign primary file'=>['annotations'=>[$copy($primary,['file'=>'foreign.php']),$secondary]]] as $label=>$changes) { $expect($label,$with($copy($issue,$changes)),false); }
                $expect('stale caller contents',$with($issue,$context->contents.' '),false);
                $queue=[];$receiving=$native['receiving']??null;
                foreach ($native as $dependency=>$metadata) {
                    if ($metadata===null) { throw new \RuntimeException('Missing genuine dependency: '.$dependency); }
                    $queue[$dependency.' absent']=[$dependency,$metadata,null];
                    if ($metadata instanceof FunctionLikeMetadata) {
                        if ($dependency==='factoryDispatch' || $dependency!=='receiving' && str_contains($dependency,'::')) {
                            $queue[$dependency.' changed static dispatch']=[$dependency,$metadata,$copy($metadata,['static'=>!$metadata->static])];
                        }
                        $queue[$dependency.' displaced physical method']=[$dependency,$metadata,$copy($metadata,['location'=>$copy($metadata->location,['span'=>new Span($metadata->location->span->start+1,$metadata->location->span->end)])])];
                        $queue[$dependency.' reference return']=[$dependency,$metadata,$copy($metadata,['flags'=>new MetadataFlags($metadata->flags->bits|MetadataFlags::BY_REFERENCE)])];
                        if ($metadata===$receiving) {
                            preg_match('/argument #(\d+)/',$issue->message,$position);$index=(int)$position[1]-1;
                            $formal=$metadata->parameters[$index];$parameters=$metadata->parameters;
                            $parameters[$index]=$copy($formal,['flags'=>new MetadataFlags($formal->flags->bits|MetadataFlags::BY_REFERENCE)]);
                            $queue[$dependency.' selected reference formal']=[$dependency,$metadata,$copy($metadata,['parameters'=>$parameters])];
                            $parameters=$metadata->parameters;$parameters[$index]=$copy($formal,['type'=>$copy($formal->type,['type'=>Type::float()])]);
                            $queue[$dependency.' wrong effective formal']=[$dependency,$metadata,$copy($metadata,['parameters'=>$parameters])];
                        }
                    } elseif ($metadata instanceof ClassLikeMetadata) {
                        $queue[$dependency.' displaced physical location']=[$dependency,$metadata,$copy($metadata,['location'=>$copy($metadata->location,['span'=>new Span($metadata->location->span->start+1,$metadata->location->span->end)])])];
                    } elseif ($metadata instanceof PropertyMetadata) {
                        $queue[$dependency.' virtual field']=[$dependency,$metadata,$copy($metadata,['flags'=>new MetadataFlags($metadata->flags->bits|MetadataFlags::VIRTUAL_PROPERTY)])];
                    }
                }
                $cache=(new \ReflectionProperty($context->codebase,'cache'))->getValue($context->codebase);
                foreach ($queue as $label=>[$dependency,$metadata,$replacement]) {
                    $expect('genuine positive immediately before '.$label,$context,true);
                    if ($metadata instanceof FunctionLikeMetadata) {
                        if ($dependency==='receiving') { preg_match('/of `([^`]+)`/',$issue->message,$target);$parts=explode('::',$target[1],2);$current=count($parts)===2?$context->codebase->getMethod($parts[0],$parts[1]):$context->codebase->getFunction($parts[0]); }
                        elseif ($dependency==='helper') { $current=$context->codebase->getFunction('now'); }
                        elseif (str_starts_with($dependency,'global:')) { $current=$context->codebase->getFunction(substr($dependency,strlen('global:'))); }
                        elseif ($dependency==='factoryDispatch') { $current=$context->codebase->getMethod('Illuminate\\Support\\DateFactory','__call'); }
                        else { [$class,$method]=explode('::',$dependency,2);$current=$context->codebase->getMethod($class,$method)??$context->codebase->getDeclaringMethod($class,$method); }
                    }
                    elseif ($metadata instanceof ClassLikeMetadata) { $current=$context->codebase->getClass($metadata->name); }
                    elseif ($family==='dates') {
                        $class=str_starts_with($dependency,'facade:')?'Illuminate\\Support\\Facades\\Facade':'Illuminate\\Support\\DateFactory';
                        $field=substr($dependency,strpos($dependency,':')+1);$current=$context->codebase->getProperty($class,$field);
                    }
                    else { $current=$context->codebase->getProperty('Symfony\\Component\\HttpFoundation\\Request','$headers'); }
                    if ($current===null || $current!=$metadata || $current==$replacement) { throw new \RuntimeException('Mutation lacks a current meaningful native contract: '.$label); }
                    $saved=$cache->values;$relations=$cache->relations;$slots=[];
                    foreach ($saved as $operation=>$entries) { foreach ($entries as $key=>$entry) { if (is_object($entry) && $entry::class===$current::class && $entry==$current) { $cache->values[$operation][$key]=$replacement;$slots[]=['operation'=>$operation,'key'=>$key]; } } }
                    try { if ($slots===[]) { throw new \RuntimeException('Vacuous native cache mutation: '.$label); }$expect('actual SDK cache '.$label,$context,false);
                        $mutations[$label]=['actualSlots'=>$slots,'touched'=>count($slots),'meaningfullyChanged'=>true]; }
                    finally { $cache->values=$saved;$cache->relations=$relations; }
                    $expect('native restoration after '.$label,$context,true);
                }
                // Primary Codebase::getMultipleFunctions -> queryNames -> query defines this exact name slot.
                // It is mutated only after a genuine Remove and a current canonical absent lookup.
                $expect('genuine positive before namespace fallback shadow',$context,true);
                $shadowName='Illuminate\Support\value';$shadow=$context->codebase->getFunction($shadowName);
                $fallback=$context->codebase->getFunction('value');
                $bucket=\Mago\Sdk\Internal\Analyzer\Protocol::GET_FUNCTIONS << 4;$key=strtolower($shadowName);
                if ($shadow!==null || $fallback===null || ! array_key_exists($key,$cache->values[$bucket]??[]) || $cache->values[$bucket][$key]!==null) {
                    throw new \RuntimeException('Missing current genuine absent namespace fallback slot.');
                }
                $saved=$cache->values;$relations=$cache->relations;$cache->values[$bucket][$key]=$fallback;
                try {
                    $expect('actual SDK cache namespace fallback shadow',$context,false);
                    $mutations['namespace fallback shadow']=['actualSlots'=>[['operation'=>$bucket,'key'=>$key]],'touched'=>1,'meaningfullyChanged'=>true,
                        'actualCanonicalAbsence'=>true,'replacementIsGenuineOtherFunction'=>true];
                } finally { $cache->values=$saved;$cache->relations=$relations; }
                $expect('native restoration after namespace fallback shadow',$context,true);
                $expect('genuine native contracts restored',$context,true);
                file_put_contents($this->output.'/controls-'.$family.'.json',json_encode(['genuinePositive'=>true,'allChecks'=>$checks,'cacheMutations'=>$mutations,
                    'restored'=>true,'noVacuousControls'=>true,'constructedInputNativeAcceptanceClaimed'=>false],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));return $decision;
            }
        };
        $registry->registerCodebaseScanHook($hook);$registry->registerIssueFilterHook($hook);
    }
}
