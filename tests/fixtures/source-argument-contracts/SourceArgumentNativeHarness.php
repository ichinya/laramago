<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Tests;

use Ichinya\Laramago\Analyzer\SourceArgumentContractFilter;
use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterDecision,IssueFilterHook,Plugin,PluginDefinition,PluginRegistry,Type};
use Mago\Sdk\Analyzer\Metadata\{FunctionLikeMetadata,ClassLikeMetadata,MetadataFlags,PropertyMetadata};
use Mago\Sdk\Reporting\{AnnotationKind,Level};
use Mago\Sdk\Span;

final class SourceArgumentNativeHarness implements Plugin
{
    public function __construct(private readonly string $root,private readonly string $output,private readonly bool $controls=false) {}
    public function getDefinition(): PluginDefinition { return new PluginDefinition('fixture/source-argument-native-harness','Argument native harness','AlwaysKeep observation or controls beginning with genuine native positives.'); }
    public function register(PluginRegistry $registry): void
    {
        $registry->registerIssueFilterHook(new class($this->root,$this->output,$this->controls) implements IssueFilterHook {
            private array $checked=[];
            public function __construct(private readonly string $root,private readonly string $output,private readonly bool $controls) {}
            public function getCodes(): array { return ['possibly-invalid-argument']; }
            private static function snapshot(mixed $value): mixed
            {
                if ($value instanceof \BackedEnum) { return $value->value; }
                if ($value instanceof \UnitEnum) { return $value->name; }
                if (is_object($value)) { return self::snapshot(get_object_vars($value)); }
                return is_array($value)?array_map(self::snapshot(...),$value):$value;
            }
            public function filterIssue(IssueFilterContext $context): IssueFilterDecision
            {
                $filter=new SourceArgumentContractFilter($this->root);$decision=$filter->filterIssue($context);
                if (! $this->controls) {
                    $record=['genuineIssueFilterContext'=>true,'alwaysKeep'=>true,'candidateDecision'=>$decision->name,
                        'file'=>$context->file,'sourceSha256'=>hash('sha256',$context->contents),'issue'=>self::snapshot($context->issue),
                        'stages'=>$filter->stages,'certificate'=>self::snapshot($filter->certificate),'dependencies'=>self::snapshot($filter->dependencies)];
                    // Bounded extra observations diagnose first refusals. They are never decision input.
                    $extra=[];foreach (['usleep','proc_open','is_string','ctype_digit','dirname'] as $name) { $extra[$name]=self::snapshot($context->codebase->getFunction($name)); }
                    $record['extraObservationOnly']=$extra;
                    file_put_contents($this->output.'/issues.jsonl',json_encode($record,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n",FILE_APPEND|LOCK_EX);
                    return IssueFilterDecision::Keep;
                }
                $family=match(basename($context->file)) { 'decimal-top.php'=>'decimal','directory.php'=>'directory',default=>null };
                if ($family===null || isset($this->checked[$family])) { return $decision; }
                $this->checked[$family]=true;$checks=[];$mutations=[];$dependencies=$filter->dependencies;$certificate=$filter->certificate;
                $firstProof=['decision'=>$decision->name,'stages'=>$filter->stages,'selected'=>self::snapshot($dependencies),'certificate'=>self::snapshot($certificate)];
                file_put_contents($this->output.'/initial-proof-'.$family.'.json',json_encode($firstProof,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
                $expect=function(string $label,IssueFilterContext $input,bool $remove) use (&$checks,$filter,$family): void {
                    $actual=$filter->filterIssue($input)===IssueFilterDecision::Remove;
                    if ($actual!==$remove) {
                        file_put_contents($this->output.'/first-failure-'.$family.'.json',json_encode(['label'=>$label,'expectedRemove'=>$remove,'actualRemove'=>$actual,
                            'stages'=>$filter->stages,'certificate'=>self::snapshot($filter->certificate),'selected'=>self::snapshot($filter->dependencies),'passed'=>$checks],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
                        throw new \RuntimeException('Source argument control failed: '.$label);
                    }
                    $checks[$label]=true;
                };
                $expect('genuine native positive before any constructed input',$context,true);
                $copy=static function(object $original,array $changes): object { $class=$original::class;return new $class(...array_replace(get_object_vars($original),$changes)); };
                $issue=$context->issue;[$primary,$secondary]=$issue->annotations;
                $with=static fn(object $report,?string $contents=null,?string $file=null):IssueFilterContext=>new IssueFilterContext(
                    $context->phpVersion,$context->codebase,$context->types,$context->cancellation,$file??$context->file,$contents??$context->contents,$report);
                foreach (['wrong code'=>['code'=>'invalid-argument'],'wrong level'=>['level'=>Level::Warning],
                    'wrong receiving position'=>['message'=>str_replace('argument #'.($family==='decimal'?'1':'4'),'argument #2',$issue->message)],
                    'different receiving callable'=>['message'=>str_replace($family==='decimal'?'usleep':'proc_open','other_receiver',$issue->message)],
                    'unknown argument type'=>['message'=>str_replace('received `'.($family==='decimal'?'int':'string').'`','received `mixed`',$issue->message)],
                    'missing note'=>['notes'=>[]],'different note'=>['notes'=>['Different contract.']],'different help'=>['help'=>null],
                    'foreign link'=>['link'=>'https://example.invalid'],'missing annotation'=>['annotations'=>[$primary]],
                    'duplicate primary'=>['annotations'=>[$primary,$primary]],'wrong primary kind'=>['annotations'=>[$copy($primary,['kind'=>AnnotationKind::Secondary]),$secondary]],
                    'wrong primary text'=>['annotations'=>[$copy($primary,['message'=>'Different argument.']),$secondary]],
                    'foreign primary file'=>['annotations'=>[$copy($primary,['file'=>'foreign.php']),$secondary]],
                    'operand-only span'=>['annotations'=>[$copy($primary,['span'=>new Span($primary->span->start+1,$primary->span->end)]),$secondary]],
                    'wrong callable span'=>['annotations'=>[$primary,$copy($secondary,['span'=>new Span($secondary->span->start+1,$secondary->span->end)])]]] as $label=>$changes) {
                    $expect($label,$with($copy($issue,$changes)),false);
                }
                $expect('stale caller contents',$with($issue,$context->contents.' '),false);
                $expect('foreign caller file',$with($issue,file:'absent.php'),false);
                $cache=(new \ReflectionProperty($context->codebase,'cache'))->getValue($context->codebase);
                $queue=[];
                foreach ($dependencies as $label=>$native) {
                    if ($native===null) { throw new \RuntimeException('Missing selected native dependency.'); }
                    $queue[$label.' absent']=[$label,$native,null];
                    if ($native instanceof FunctionLikeMetadata) {
                        $queue[$label.' reference return']=[$label,$native,$copy($native,['flags'=>new MetadataFlags($native->flags->bits|MetadataFlags::BY_REFERENCE)])];
                        if ($label!=='owner') {
                            $queue[$label.' nonbuiltin binding']=[$label,$native,$copy($native,['flags'=>new MetadataFlags(($native->flags->bits&~MetadataFlags::BUILTIN)|MetadataFlags::USER_DEFINED)])];
                            $index=$label==='proc_open'?3:0;$selected=$native->parameters[$index];
                            $parameters=$native->parameters;$parameters[$index]=$copy($selected,['flags'=>new MetadataFlags($selected->flags->bits|MetadataFlags::BY_REFERENCE)]);
                            $queue[$label.' selected reference formal']=[$label,$native,$copy($native,['parameters'=>$parameters])];
                        }
                        if (in_array($label,['usleep','proc_open'],true)) {
                            foreach (['declaredType','type'] as $field) {
                                $index=$label==='usleep'?0:3;$selected=$native->parameters[$index];
                                if ($selected->$field===null) { throw new \RuntimeException('Missing selected receiving type metadata.'); }
                                $parameters=$native->parameters;$parameters[$index]=$copy($selected,[$field=>$copy($selected->$field,['type'=>Type::float()])]);
                                $queue[$label.' wrong '.$field]=[$label,$native,$copy($native,['parameters'=>$parameters])];
                            }
                        }
                        if (in_array($label,['is_string','ctype_digit'],true)) {
                            $queue[$label.' wrong predicate return']=[$label,$native,$copy($native,['returnType'=>$copy($native->returnType,['type'=>Type::string()])])];
                        }
                    } elseif ($native instanceof PropertyMetadata) {
                        $queue[$label.' mutable']=[$label,$native,$copy($native,['flags'=>new MetadataFlags($native->flags->bits&~MetadataFlags::READONLY)])];
                        $queue[$label.' unknown read type']=[$label,$native,$copy($native,['type'=>$copy($native->type,['type'=>Type::mixed()])])];
                        $queue[$label.' wrong declared type']=[$label,$native,$copy($native,['declaredType'=>$copy($native->declaredType,['type'=>Type::int()])])];
                    } elseif ($native instanceof ClassLikeMetadata) {
                        $queue[$label.' nonfinal']=[$label,$native,$copy($native,['flags'=>new MetadataFlags($native->flags->bits&~MetadataFlags::FINAL)])];
                    }
                }
                foreach ($queue as $label=>[$dependency,$native,$replacement]) {
                    $expect('genuine positive immediately before '.$label,$context,true);
                    // Re-query only the selected genuine contract immediately before cache slot selection.
                    if ($native instanceof FunctionLikeMetadata) {
                        $id=$native->identifier;$current=$id->class===null?$context->codebase->getFunction($id->name):$context->codebase->getMethod($id->class,$id->name);
                    } elseif ($native instanceof ClassLikeMetadata) { $current=$context->codebase->getClass($native->name); }
                    else { [$class,$property]=explode('::$',$certificate['property'],2);$current=$context->codebase->getProperty($class,'$'.$property); }
                    if ($current===null || $current!=$native || $replacement==$current) { throw new \RuntimeException('Native control requires a current, meaningfully changed contract: '.$label); }
                    $saved=$cache->values;$relations=$cache->relations;$slots=[];
                    foreach ($saved as $operation=>$entries) { foreach ($entries as $key=>$entry) {
                        if ($entry===$current) { $cache->values[$operation][$key]=$replacement;$slots[]=['operation'=>$operation,'key'=>$key]; }
                    } }
                    try {
                        if ($slots===[]) { throw new \RuntimeException('Vacuous native cache control: '.$label); }
                        $expect('actual selected SDK cache '.$label,$context,false);
                        $mutations[$label]=['actualSlots'=>$slots,'touched'=>count($slots),'meaningfullyChanged'=>true,'expectedRemove'=>false];
                    } finally { $cache->values=$saved;$cache->relations=$relations; }
                    $expect('native restoration after '.$label,$context,true);
                }
                $expect('genuine native metadata restored',$context,true);
                file_put_contents($this->output.'/controls-'.$family.'.json',json_encode(['genuinePositive'=>true,'allChecks'=>$checks,'cacheMutations'=>$mutations,
                    'noVacuousControls'=>true,'constructedInputNativeAcceptanceClaimed'=>false,'restored'=>true],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
                return $decision;
            }
        });
    }
}
