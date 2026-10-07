<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Tests;

use Ichinya\Laramago\Analyzer\RepeatedRefreshNoValueFilter;
use Ichinya\Laramago\Analyzer\StaticAnalysis\RefreshedModelProperties;
use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterDecision,IssueFilterHook,Plugin,PluginDefinition,PluginRegistry,Type};
use Mago\Sdk\Analyzer\Metadata\{FunctionLikeMetadata,ClassLikeMetadata,PropertyMetadata,MetadataFlags,ClassLikeKind};
use Mago\Sdk\Reporting\{Level,AnnotationKind};
use Mago\Sdk\Span;

final class RepeatedRefreshNativeHarness implements Plugin
{
    public function __construct(private readonly string $root,private readonly string $output,private readonly bool $controls=false) {}
    public function getDefinition(): PluginDefinition { return new PluginDefinition('fixture/repeated-refresh-observation','Repeated refresh native observation','AlwaysKeep and actual selected native cache controls.'); }
    public function register(PluginRegistry $registry): void
    {
        $index=new RefreshedModelProperties($this->root);
        $registry->registerInitializationHook($index);$registry->registerCodebaseScanHook($index);
        $hook=new class(new RepeatedRefreshNoValueFilter($index,$this->root),$this->output,$this->controls) implements IssueFilterHook {
            private bool $checked=false;
            public function __construct(private readonly RepeatedRefreshNoValueFilter $filter,private readonly string $output,private readonly bool $controls) {}
            public function getCodes(): array { return ['no-value']; }
            private static function snapshot(mixed $value): mixed {
                if ($value instanceof \BackedEnum) { return $value->value; }if ($value instanceof \UnitEnum) { return $value->name; }
                if (is_object($value)) { return self::snapshot(get_object_vars($value)); }return is_array($value)?array_map(self::snapshot(...),$value):$value;
            }
            public function filterIssue(IssueFilterContext $context): IssueFilterDecision
            {
                $decision=$this->filter->filterIssue($context);
                $record=['genuineIssueFilterContext'=>true,'alwaysKeep'=>!$this->controls,'file'=>$context->file,'sourceSha256'=>hash('sha256',$context->contents),
                    'issue'=>self::snapshot($context->issue),'candidateDecision'=>$decision->name,'stages'=>$this->filter->stages,
                    'certificate'=>self::snapshot($this->filter->certificate),'dependencies'=>self::snapshot($this->filter->dependencies)];
                if (!$this->controls) { file_put_contents($this->output.'/issues.jsonl',json_encode($record,JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);return IssueFilterDecision::Keep; }
                if ($decision!==IssueFilterDecision::Remove || $this->checked) { return $decision; }
                $this->checked=true;$checks=[];$mutations=[];$native=$this->filter->dependencies;
                file_put_contents($this->output.'/initial-refresh.json',json_encode($record,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
                $expect=function(string $label,IssueFilterContext $input,bool $remove)use(&$checks):void {
                    $actual=$this->filter->filterIssue($input)===IssueFilterDecision::Remove;
                    if($actual!==$remove) { file_put_contents($this->output.'/first-failure-refresh.json',json_encode(['label'=>$label,'expectedRemove'=>$remove,'actualRemove'=>$actual,
                        'stages'=>$this->filter->stages,'dependencies'=>self::snapshot($this->filter->dependencies),'certificate'=>$this->filter->certificate,'passed'=>$checks],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
                        throw new \RuntimeException('Repeated refresh control failed: '.$label); }$checks[$label]=true;
                };
                $expect('genuine positive before any constructed input',$context,true);
                $copy=static function(object $original,array $changes):object { $class=$original::class;return new $class(...array_replace(get_object_vars($original),$changes)); };
                $issue=$context->issue;$primary=$issue->annotations[0];
                $with=static fn(object $report,?string $contents=null):IssueFilterContext=>new IssueFilterContext($context->phpVersion,$context->codebase,$context->types,$context->cancellation,$context->file,$contents??$context->contents,$report);
                foreach (['wrong code'=>['code'=>'invalid-argument'],'wrong level'=>['level'=>Level::Warning],'missing notes'=>['notes'=>[]],
                    'wrong note'=>['notes'=>['Different no-value contract.']],'wrong help'=>['help'=>null],'foreign link'=>['link'=>'https://example.invalid'],
                    'duplicate primary'=>['annotations'=>[$primary,$primary]],'wrong primary kind'=>['annotations'=>[$copy($primary,['kind'=>AnnotationKind::Secondary])]],
                    'wrong primary text'=>['annotations'=>[$copy($primary,['message'=>'Different expression.'])]],
                    'partial expression span'=>['annotations'=>[$copy($primary,['span'=>new Span($primary->span->start+1,$primary->span->end)])]],
                    'foreign primary file'=>['annotations'=>[$copy($primary,['file'=>'foreign.php'])]],'different never argument'=>['message'=>'Argument #2 has type `never`.']]
                    as $label=>$changes) { $expect($label,$with($copy($issue,$changes)),false); }
                $expect('stale caller contents',$with($issue,$context->contents.' '),false);
                $queue=[];
                foreach($native as $dependency=>$metadata) {
                    if($metadata===null) { throw new \RuntimeException('Missing genuine dependency: '.$dependency); }
                    $queue[$dependency.' absent']=[$metadata,null];
                    if($metadata instanceof FunctionLikeMetadata) {
                        $queue[$dependency.' displaced physical location']=[$metadata,$copy($metadata,['location'=>$copy($metadata->location,['span'=>new Span($metadata->location->span->start+1,$metadata->location->span->end)])])];
                        $queue[$dependency.' reference return']=[$metadata,$copy($metadata,['flags'=>new MetadataFlags($metadata->flags->bits|MetadataFlags::BY_REFERENCE)])];
                        $queue[$dependency.' changed static dispatch']=[$metadata,$copy($metadata,['static'=>!$metadata->static])];
                        if(in_array($dependency,['caller','helper','assertion'],true)) {
                            if($metadata->returnType===null) { throw new \RuntimeException('Selected return mutation has no genuine type.'); }
                            $queue[$dependency.' incompatible return']=[$metadata,$copy($metadata,['returnType'=>$copy($metadata->returnType,['type'=>$dependency==='helper'?Type::float():Type::never()])])];
                        }
                    } elseif($metadata instanceof ClassLikeMetadata) {
                        $queue[$dependency.' changed class kind']=[$metadata,$copy($metadata,['kind'=>ClassLikeKind::Trait])];
                    } elseif($metadata instanceof PropertyMetadata) {
                        $queue[$dependency.' virtual field']=[$metadata,$copy($metadata,['flags'=>new MetadataFlags($metadata->flags->bits|MetadataFlags::VIRTUAL_PROPERTY)])];
                        $queue[$dependency.' incompatible declared type']=[$metadata,$copy($metadata,['declaredType'=>$copy($metadata->declaredType,['type'=>Type::float()])])];
                        $queue[$dependency.' incompatible effective type']=[$metadata,$copy($metadata,['type'=>$copy($metadata->type,['type'=>Type::float()])])];
                        $queue[$dependency.' displaced property name']=[$metadata,$copy($metadata,['nameLocation'=>$copy($metadata->nameLocation,['span'=>new Span($metadata->nameLocation->span->start+1,$metadata->nameLocation->span->end)])])];
                    }
                }
                $cache=(new \ReflectionProperty($context->codebase,'cache'))->getValue($context->codebase);
                foreach($queue as $label=>[$metadata,$replacement]) {
                    $expect('genuine positive immediately before '.$label,$context,true);
                    if($metadata instanceof FunctionLikeMetadata) { $id=$metadata->identifier;$current=$context->codebase->getMethod($id->class,$id->name)??$context->codebase->getDeclaringMethod($id->class,$id->name); }
                    elseif($metadata instanceof ClassLikeMetadata) { $current=$context->codebase->getClass($metadata->name); }
                    else { [$owner]=explode('::',$record['certificate']['caller'],2);$current=$context->codebase->getProperty($owner,$metadata->name); }
                    if($current===null || $current!=$metadata || $current==$replacement) { throw new \RuntimeException('Mutation lacks a current meaningful native contract: '.$label); }
                    $saved=$cache->values;$relations=$cache->relations;$slots=[];
                    foreach($saved as $operation=>$entries) { foreach($entries as $key=>$entry) {
                        if(is_object($entry)&&$entry::class===$current::class&&$entry==$current) { $cache->values[$operation][$key]=$replacement;$slots[]=['operation'=>$operation,'key'=>$key]; }
                    } }
                    try { if($slots===[]) { throw new \RuntimeException('Vacuous native cache mutation: '.$label); }$expect('actual SDK cache '.$label,$context,false);
                        $mutations[$label]=['actualSlots'=>$slots,'touched'=>count($slots),'meaningfullyChanged'=>true]; }
                    finally { $cache->values=$saved;$cache->relations=$relations; }
                    $expect('native restoration after '.$label,$context,true);
                }
                $expect('genuine native contracts restored',$context,true);
                file_put_contents($this->output.'/controls-refresh.json',json_encode(['genuinePositive'=>true,'allChecks'=>$checks,'cacheMutations'=>$mutations,
                    'restored'=>true,'noVacuousControls'=>true,'constructedInputNativeAcceptanceClaimed'=>false],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));

                return $decision;
            }
        };
        $registry->registerIssueFilterHook($hook);
    }
}
