<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Tests;
use Ichinya\Laramago\Analyzer\GuardedRequestHeaderArgumentFilter;
use Mago\Sdk\Analyzer\{CodebaseScanContext,CodebaseScanHook,IssueFilterContext,IssueFilterDecision,IssueFilterHook,Plugin,PluginDefinition,PluginRegistry,Type};
use Mago\Sdk\Analyzer\Metadata\{FunctionLikeMetadata,ClassLikeMetadata,PropertyMetadata,MetadataFlags};
use Mago\Sdk\Reporting\{Level,AnnotationKind};
use Mago\Sdk\Span;

final class GuardedHeaderNativeHarness implements Plugin
{
    public function __construct(private readonly string $root,private readonly string $output,private readonly bool $controls=false) {}
    public function getDefinition(): PluginDefinition { return new PluginDefinition('fixture/guarded-header-observation','Guarded header native observation','AlwaysKeep and actual selected native cache controls.'); }
    public function register(PluginRegistry $registry): void
    {
        $hook=new class($this->root,$this->output,$this->controls) implements IssueFilterHook {
            private array $checked=[];private readonly GuardedRequestHeaderArgumentFilter $dates;
            public function __construct(private readonly string $root,private readonly string $output,private readonly bool $controls) { $this->dates=new GuardedRequestHeaderArgumentFilter($root); }
            public function getTargets(): array { return ['**']; }
            public function getCodes(): array { return ['possibly-invalid-argument']; }
            private static function snapshot(mixed $value): mixed {
                if ($value instanceof \BackedEnum) { return $value->value; }if ($value instanceof \UnitEnum) { return $value->name; }
                if (is_object($value)) { return self::snapshot(get_object_vars($value)); }return is_array($value)?array_map(self::snapshot(...),$value):$value;
            }
            private function filter(string $family): object { return $this->dates; }
            public function filterIssue(IssueFilterContext $context): IssueFilterDecision
            {
                $families=['header'];$selected=null;$decision=IssueFilterDecision::Keep;$traces=[];
                foreach ($families as $family) { $filter=$this->filter($family);$value=$filter->filterIssue($context);$traces[$family]=$filter->stages;
                    if ($value===IssueFilterDecision::Remove) { if ($selected!==null) { throw new \RuntimeException('More than one argument family claimed one native issue.'); }$selected=[$family,$filter];$decision=$value; }
                }
                $record=['genuineIssueFilterContext'=>true,'alwaysKeep'=>!$this->controls,'file'=>$context->file,'sourceSha256'=>hash('sha256',$context->contents),
                    'issue'=>self::snapshot($context->issue),'candidateDecision'=>$decision->name,'family'=>$selected[0]??null,'stages'=>$traces,
                    'certificate'=>self::snapshot($selected[1]->certificate??$filter->certificate),'dependencies'=>self::snapshot($selected[1]->dependencies??$filter->dependencies)];
                if (! $this->controls) { file_put_contents($this->output.'/issues.jsonl',json_encode($record,JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);return IssueFilterDecision::Keep; }
                if ($selected===null || isset($this->checked[$selected[0]])) { return $decision; }
                [$family,$filter]=$selected;$this->checked[$family]=true;$checks=[];$mutations=[];$native=$filter->dependencies;$certificate=$filter->certificate;
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
                foreach($native as $dependency=>$metadata) {
                    if($metadata===null) { throw new \RuntimeException('Missing genuine header dependency.'); }
                    $queue[$dependency.' absent']=[$dependency,$metadata,null];
                    if($metadata instanceof FunctionLikeMetadata) {
                        $queue[$dependency.' reference return']=[$dependency,$metadata,$copy($metadata,['flags'=>new MetadataFlags($metadata->flags->bits|MetadataFlags::BY_REFERENCE)])];
                        if($dependency!=='receiving') {
                            $queue[$dependency.' displaced physical method']=[$dependency,$metadata,$copy($metadata,['location'=>$copy($metadata->location,['span'=>new Span($metadata->location->span->start+1,$metadata->location->span->end)])])];
                        }
                        if(in_array($dependency,['receiving','header','retrieve','get'],true)) {
                            $queue[$dependency.' changed static dispatch']=[$dependency,$metadata,$copy($metadata,['static'=>!$metadata->static])];
                        }
                        if(in_array($dependency,['header','retrieve'],true)) {
                            $parameters=$metadata->parameters;$formal=$parameters[0];
                            $parameters[0]=$copy($formal,['flags'=>new MetadataFlags($formal->flags->bits|MetadataFlags::BY_REFERENCE)]);
                            $queue[$dependency.' reference input']=[$dependency,$metadata,$copy($metadata,['parameters'=>$parameters])];
                            $parameters=$metadata->parameters;$parameters[0]=$copy($formal,['name'=>'$other']);
                            $queue[$dependency.' changed formal name']=[$dependency,$metadata,$copy($metadata,['parameters'=>$parameters])];
                            $parameters=$metadata->parameters;$parameters[0]=$copy($formal,['nameLocation'=>$copy($formal->nameLocation,['span'=>new Span($formal->nameLocation->span->start+1,$formal->nameLocation->span->end)])]);
                            $queue[$dependency.' displaced formal name']=[$dependency,$metadata,$copy($metadata,['parameters'=>$parameters])];
                            $queue[$dependency.' missing formal']=[$dependency,$metadata,$copy($metadata,['parameters'=>[]])];
                            $queue[$dependency.' added native return declaration']=[$dependency,$metadata,$copy($metadata,['declaredReturnType'=>$metadata->returnType])];
                        }
                        if($dependency==='get') {
                            foreach(['returnType','declaredReturnType'] as $field) {
                                $queue[$dependency.' wrong '.$field]=[$dependency,$metadata,$copy($metadata,[$field=>$copy($metadata->$field,['type'=>Type::float()])])];
                            }
                            $parameters=$metadata->parameters;$formal=$parameters[0];
                            $parameters[0]=$copy($formal,['flags'=>new MetadataFlags($formal->flags->bits|MetadataFlags::BY_REFERENCE)]);
                            $queue[$dependency.' reference input']=[$dependency,$metadata,$copy($metadata,['parameters'=>$parameters])];
                        }
                        if($metadata===$receiving) {
                            $parameters=$metadata->parameters;$formal=$parameters[1];
                            $parameters[1]=$copy($formal,['flags'=>new MetadataFlags($formal->flags->bits|MetadataFlags::BY_REFERENCE)]);
                            $queue[$dependency.' selected reference formal']=[$dependency,$metadata,$copy($metadata,['parameters'=>$parameters])];
                            $parameters=$metadata->parameters;$parameters[1]=$copy($formal,['type'=>$copy($formal->type,['type'=>Type::float()])]);
                            $queue[$dependency.' wrong effective formal']=[$dependency,$metadata,$copy($metadata,['parameters'=>$parameters])];
                        }
                    } elseif($metadata instanceof ClassLikeMetadata) {
                        if($metadata->kind===\Mago\Sdk\Analyzer\Metadata\ClassLikeKind::Interface && $metadata->flags->contains(MetadataFlags::BUILTIN)) {
                            $queue[$dependency.' incomplete builtin interface hierarchy']=[$dependency,$metadata,$copy($metadata,['unresolvedHierarchyDependencies'=>['Fixture\\UnknownInterface']])];
                        } else {
                        $queue[$dependency.' displaced physical location']=[$dependency,$metadata,$copy($metadata,['location'=>$copy($metadata->location,['span'=>new Span($metadata->location->span->start+1,$metadata->location->span->end)])])];
                        }
                        $queue[$dependency.' changed class identity']=[$dependency,$metadata,$copy($metadata,['name'=>'Other\\ClassIdentity'])];
                        $queue[$dependency.' changed class kind']=[$dependency,$metadata,$copy($metadata,['kind'=>\Mago\Sdk\Analyzer\Metadata\ClassLikeKind::Trait])];
                        if($metadata->kind===\Mago\Sdk\Analyzer\Metadata\ClassLikeKind::Interface) {
                            $queue[$dependency.' changed builtin identity flag']=[$dependency,$metadata,$copy($metadata,['flags'=>new MetadataFlags($metadata->flags->bits ^ MetadataFlags::BUILTIN)])];
                        }
                    } elseif($metadata instanceof PropertyMetadata) {
                        $queue[$dependency.' virtual field']=[$dependency,$metadata,$copy($metadata,['flags'=>new MetadataFlags($metadata->flags->bits|MetadataFlags::VIRTUAL_PROPERTY)])];
                        $queue[$dependency.' static field']=[$dependency,$metadata,$copy($metadata,['flags'=>new MetadataFlags($metadata->flags->bits|MetadataFlags::STATIC)])];
                        foreach(['type','declaredType'] as $field) { $queue[$dependency.' wrong '.$field]=[$dependency,$metadata,$copy($metadata,[$field=>$copy($metadata->$field,['type'=>Type::float()])])]; }
                        $queue[$dependency.' displaced physical name']=[$dependency,$metadata,$copy($metadata,['nameLocation'=>$copy($metadata->nameLocation,['span'=>new Span($metadata->nameLocation->span->start+1,$metadata->nameLocation->span->end)])])];
                        $setter=$metadata->hooks['set']??null;
                        if($setter!==null) {
                            $queue[$dependency.' added getter hook']=[$dependency,$metadata,$copy($metadata,['hooks'=>$metadata->hooks+['get'=>$copy($setter,['name'=>'get'])]])];
                            foreach(['name'=>'get','returnsByReference'=>true,'abstract'=>true] as $field=>$changed) {
                                $queue[$dependency.' changed setter '.$field]=[$dependency,$metadata,$copy($metadata,['hooks'=>['set'=>$copy($setter,[$field=>$changed])]])];
                            }
                            $queue[$dependency.' displaced setter source']=[$dependency,$metadata,$copy($metadata,['hooks'=>['set'=>$copy($setter,['location'=>$copy($setter->location,['span'=>new Span($setter->location->span->start+1,$setter->location->span->end)])])]])];
                        }
                    }
                }
                $cache=(new \ReflectionProperty($context->codebase,'cache'))->getValue($context->codebase);
                foreach ($queue as $label=>[$dependency,$metadata,$replacement]) {
                    $expect('genuine positive immediately before '.$label,$context,true);
                    // Populate selected and declaring public aliases before mutating genuine metadata.
                    if($metadata instanceof FunctionLikeMetadata) {
                        $id=$metadata->identifier;
                        if($id->class===null) { $aliases=[$context->codebase->getFunction($id->name)]; }
                        else {
                            $selectedClass=in_array($dependency,['header','retrieve'],true)?$certificate['selectedReceiver']:$id->class;
                            $aliases=[$context->codebase->getMethod($selectedClass,$id->name),
                                $context->codebase->getDeclaringMethod($selectedClass,$id->name),
                                $context->codebase->getMethod($id->class,$id->name),
                                $context->codebase->getDeclaringMethod($id->class,$id->name)];
                        }
                    } elseif($metadata instanceof ClassLikeMetadata) { $aliases=[$metadata->kind===\Mago\Sdk\Analyzer\Metadata\ClassLikeKind::Interface?$context->codebase->getInterface($metadata->name):$context->codebase->getClass($metadata->name)]; }
                    else {
                        $selectedReceiver=$certificate['selectedReceiver'];
                        $aliases=[$context->codebase->getProperty($selectedReceiver,'$headers'),
                            $context->codebase->getDeclaringProperty($selectedReceiver,'$headers')];
                    }
                    $current=null;
                    foreach($aliases as $alias) {
                        if($alias===null) { continue; }
                        if($alias!=$metadata) { throw new \RuntimeException('Genuine selected aliases differ before mutation: '.$label); }
                        $current??=$alias;
                    }
                    if($current===null || $current!=$metadata || $current==$replacement) { throw new \RuntimeException('Mutation lacks a current meaningful native contract: '.$label); }
                    $saved=$cache->values;$relations=$cache->relations;$slots=[];
                    foreach ($saved as $operation=>$entries) { foreach ($entries as $key=>$entry) { if (is_object($entry) && $entry::class===$current::class && $entry==$current) { $cache->values[$operation][$key]=$replacement;$slots[]=['operation'=>$operation,'key'=>$key]; } } }
                    try { if ($slots===[]) { throw new \RuntimeException('Vacuous native cache mutation: '.$label); }$expect('actual SDK cache '.$label,$context,false);
                        $mutations[$label]=['actualSlots'=>$slots,'touched'=>count($slots),'meaningfullyChanged'=>true]; }
                    finally { $cache->values=$saved;$cache->relations=$relations; }
                    $expect('native restoration after '.$label,$context,true);
                }

                $framework=$this->root.'/framework.php';$original=file_get_contents($framework);
                $before='$this->headers = $value;';$after='$this->headers = $other;';
                if(substr_count($original,$before)!==1 || strlen($before)!==strlen($after)) { throw new \RuntimeException('Source setter control is not one meaningful same-length edit.'); }
                $expect('genuine positive before physical setter mutation',$context,true);
                try { file_put_contents($framework,str_replace($before,$after,$original));$expect('changed physical setter body with native metadata held',$context,false); }
                finally { file_put_contents($framework,$original); }
                $expect('physical setter source restored',$context,true);
                $expect('genuine native contracts restored',$context,true);
                file_put_contents($this->output.'/controls-'.$family.'.json',json_encode(['genuinePositive'=>true,'allChecks'=>$checks,'cacheMutations'=>$mutations,
                    'restored'=>true,'noVacuousControls'=>true,'constructedInputNativeAcceptanceClaimed'=>false,'physicalSetterMutationChecks'=>3],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));return $decision;
            }
        };
        $registry->registerIssueFilterHook($hook);
    }
}
