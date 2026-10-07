<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Tests;
use Ichinya\Laramago\Analyzer\SessionArrayKeyArgumentFilter;
use Mago\Sdk\Analyzer\{CodebaseScanContext,CodebaseScanHook,IssueFilterContext,IssueFilterDecision,IssueFilterHook,Plugin,PluginDefinition,PluginRegistry,Type};
use Mago\Sdk\Analyzer\Metadata\{FunctionLikeMetadata,ClassLikeMetadata,PropertyMetadata,MetadataFlags};
use Mago\Sdk\Reporting\{Level,AnnotationKind};
use Mago\Sdk\Span;

final class SessionArrayKeyNativeHarness implements Plugin
{
    public function __construct(private readonly string $root,private readonly string $output,private readonly bool $controls=false) {}
    public function getDefinition(): PluginDefinition { return new PluginDefinition('fixture/session-array-key-observation','Session array-key native observation','AlwaysKeep and actual selected native cache controls.'); }
    public function register(PluginRegistry $registry): void
    {
        $hook=new class($this->root,$this->output,$this->controls) implements IssueFilterHook {
            private array $checked=[];private readonly SessionArrayKeyArgumentFilter $dates;
            public function __construct(private readonly string $root,private readonly string $output,private readonly bool $controls) { $this->dates=new SessionArrayKeyArgumentFilter($root); }
            public function getTargets(): array { return ['**']; }
            public function getCodes(): array { return ['less-specific-argument']; }
            private static function snapshot(mixed $value): mixed {
                if ($value instanceof \BackedEnum) { return $value->value; }if ($value instanceof \UnitEnum) { return $value->name; }
                if (is_object($value)) { return self::snapshot(get_object_vars($value)); }return is_array($value)?array_map(self::snapshot(...),$value):$value;
            }
            private function filter(string $family): object { return $this->dates; }
            public function filterIssue(IssueFilterContext $context): IssueFilterDecision
            {
                $families=['session'];$selected=null;$decision=IssueFilterDecision::Keep;$traces=[];
                foreach ($families as $family) { $filter=$this->filter($family);$value=$filter->filterIssue($context);$traces[$family]=$filter->stages;
                    if ($value===IssueFilterDecision::Remove) { if ($selected!==null) { throw new \RuntimeException('More than one argument family claimed one native issue.'); }$selected=[$family,$filter];$decision=$value; }
                }
                $record=['genuineIssueFilterContext'=>true,'alwaysKeep'=>!$this->controls,'file'=>$context->file,'sourceSha256'=>hash('sha256',$context->contents),
                    'issue'=>self::snapshot($context->issue),'candidateDecision'=>$decision->name,'family'=>$selected[0]??null,'stages'=>$traces,
                    'certificate'=>self::snapshot($selected[1]->certificate??$filter->certificate),'dependencies'=>self::snapshot($selected[1]->dependencies??$filter->dependencies)];
                if (! $this->controls) { file_put_contents($this->output.'/issues.jsonl',json_encode($record,JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);return IssueFilterDecision::Keep; }
                if ($selected===null || ($selected[1]->dependencies['owner']?->identifier->name??null)!=='request' || isset($this->checked[$selected[0]])) { return $decision; }
                [$family,$filter]=$selected;$this->checked[$family]=true;$checks=[];$mutations=[];$native=$filter->dependencies;$certificate=$filter->certificate;
                file_put_contents($this->output.'/initial-'.$family.'.json',json_encode($record,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
                $expect=function(string $label,IssueFilterContext $input,bool $remove) use (&$checks,$filter,$family): void {
                    $actual=$filter->filterIssue($input)===IssueFilterDecision::Remove;
                    if ($actual!==$remove) { file_put_contents($this->output.'/first-failure-'.$family.'.json',json_encode(['label'=>$label,'expectedRemove'=>$remove,'actualRemove'=>$actual,
                        'stages'=>$filter->stages,'dependencies'=>self::snapshot($filter->dependencies),'certificate'=>$filter->certificate,'passed'=>$checks],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
                        throw new \RuntimeException('Projected argument control failed: '.$family.' '.$label); }$checks[$label]=true;
                };
                $expect('genuine positive before any constructed input',$context,true);
                $copy=static function(object $original,array $changes): object { if($original instanceof Type) { throw new \RuntimeException('Use the public Type factories for native type controls.'); }$class=$original::class;return new $class(...array_replace(get_object_vars($original),$changes)); };
                $issue=$context->issue;[$primary,$secondary]=$issue->annotations;
                $with=static fn(object $report,?string $contents=null):IssueFilterContext=>new IssueFilterContext($context->phpVersion,$context->codebase,$context->types,$context->cancellation,$context->file,$contents??$context->contents,$report);
                foreach (['wrong code'=>['code'=>'invalid-argument'],'wrong level'=>['level'=>Level::Warning],'missing notes'=>['notes'=>[]],
                    'wrong note'=>['notes'=>['Other argument contract.']],'wrong help'=>['help'=>null],'foreign link'=>['link'=>'https://example.invalid'],
                    'duplicate primary'=>['annotations'=>[$primary,$primary]],'wrong primary kind'=>['annotations'=>[$copy($primary,['kind'=>AnnotationKind::Secondary]),$secondary]],
                    'wrong primary text'=>['annotations'=>[$copy($primary,['message'=>'Other argument.']),$secondary]],
                    'wrong actual array-key'=>['message'=>str_replace('provided type `array-key`','provided type `mixed`',$issue->message)],
                    'wrong secondary text'=>['annotations'=>[$primary,$copy($secondary,['message'=>'Other receiving declaration.'])]],
                    'partial argument span'=>['annotations'=>[$copy($primary,['span'=>new Span($primary->span->start+1,$primary->span->end)]),$secondary]],
                    'foreign primary file'=>['annotations'=>[$copy($primary,['file'=>'foreign.php']),$secondary]]] as $label=>$changes) { $expect($label,$with($copy($issue,$changes)),false); }
                $expect('stale caller contents',$with($issue,$context->contents.' '),false);
                $queue=[];
                foreach($native as $dependency=>$metadata) {
                    if($metadata===null) { throw new \RuntimeException('Missing genuine selected dependency.'); }
                    $queue[$dependency.' absent']=[$dependency,$metadata,null];
                    if($metadata instanceof FunctionLikeMetadata) {
                        $queue[$dependency.' reference return']=[$dependency,$metadata,$copy($metadata,['flags'=>new MetadataFlags($metadata->flags->bits|MetadataFlags::BY_REFERENCE)])];
                        $queue[$dependency.' displaced physical method']=[$dependency,$metadata,$copy($metadata,['location'=>$copy($metadata->location,['span'=>new Span($metadata->location->span->start+1,$metadata->location->span->end)])])];
                        $queue[$dependency.' displaced physical name']=[$dependency,$metadata,$copy($metadata,['nameLocation'=>$copy($metadata->nameLocation,['span'=>new Span($metadata->nameLocation->span->start+1,$metadata->nameLocation->span->end)])])];
                        $queue[$dependency.' wrong kind']=[$dependency,$metadata,$copy($metadata,['kind'=>\Mago\Sdk\Analyzer\Metadata\FunctionLikeKind::Closure])];
                        if($metadata->identifier->class!==null) { $queue[$dependency.' changed static dispatch']=[$dependency,$metadata,$copy($metadata,['static'=>!$metadata->static])]; }
                        foreach($metadata->parameters as $index=>$formal) {
                            foreach(['reference'=>['flags'=>new MetadataFlags($formal->flags->bits^MetadataFlags::BY_REFERENCE)],
                                'name'=>['name'=>'$other'],'name span'=>['nameLocation'=>$copy($formal->nameLocation,['span'=>new Span($formal->nameLocation->span->start+1,$formal->nameLocation->span->end)])],
                                'out'=>['outType'=>$formal->type??$native['receiving']->parameters[0]->type]] as $label=>$change) {
                                $parameters=$metadata->parameters;$parameters[$index]=$copy($formal,$change);
                                $queue[$dependency.' formal '.$index.' '.$label]=[$dependency,$metadata,$copy($metadata,['parameters'=>$parameters])];
                            }
                        }
                        if($metadata->parameters!==[]) { $queue[$dependency.' missing formals']=[$dependency,$metadata,$copy($metadata,['parameters'=>[]])]; }
                        $queue[$dependency.' added formal']=[$dependency,$metadata,$copy($metadata,['parameters'=>[...$metadata->parameters,$native['receiving']->parameters[0]]])];
                        if(in_array($dependency,['receiving','basePut'],true)) {
                            foreach(['wrong effective key'=>['type'=>Type::float()],'unknown effective key'=>['type'=>Type::mixed()],'missing key doc'=>['fromDocblock'=>false]] as $label=>$change) {
                                $parameters=$metadata->parameters;$parameters[0]=$copy($parameters[0],['type'=>$copy($parameters[0]->type,$change)]);
                                $queue[$dependency.' '.$label]=[$dependency,$metadata,$copy($metadata,['parameters'=>$parameters])];
                            }
                        }
                        if(in_array($dependency,['receiving','basePut'],true)) {
                            $key=$metadata->parameters[0]->type;$atoms=$key->type->atomicTypes;
                            $strings=array_keys(array_filter($atoms,static fn(object $atom):bool=>$atom instanceof \Mago\Sdk\Analyzer\Type\ScalarType
                                && $atom->kind===\Mago\Sdk\Analyzer\Type\ScalarTypeKind::String));
                            if(count($strings)!==1 || !$atoms[$strings[0]]->refinement instanceof \Mago\Sdk\Analyzer\Type\StringType) {
                                throw new \RuntimeException('Selected genuine string refinement profile differs.');
                            }
                            $stringIndex=$strings[0];$string=$atoms[$stringIndex];$refinement=$string->refinement;
                            foreach(['literal'=>['literalKind'=>\Mago\Sdk\Analyzer\Type\StringLiteralKind::Value,'literalValue'=>'fixed'],
                                'unspecified literal'=>['literalKind'=>\Mago\Sdk\Analyzer\Type\StringLiteralKind::Unspecified],
                                'numeric'=>['numeric'=>true],'truthy'=>['truthy'=>true],'nonempty'=>['nonEmpty'=>true],
                                'callable'=>['callable'=>true],'casing'=>['casing'=>\Mago\Sdk\Analyzer\Type\StringCasing::Lowercase]] as $label=>$change) {
                                $changed=$atoms;$changed[$stringIndex]=$copy($string,['refinement'=>$copy($refinement,$change)]);
                                $parameters=$metadata->parameters;$parameters[0]=$copy($parameters[0],['type'=>$copy($key,['type'=>Type::fromAtomics(...$changed)->withFlags($key->type->flags)])]);
                                $queue[$dependency.' refined string '.$label]=[$dependency,$metadata,$copy($metadata,['parameters'=>$parameters])];
                            }
                        }
                        if($dependency==='arraySet') {
                            foreach(['missing integer branch'=>Type::string(),'wrong key'=>Type::float()] as $label=>$type) {
                                $parameters=$metadata->parameters;$parameters[1]=$copy($parameters[1],['type'=>$copy($parameters[1]->type,['type'=>$type])]);
                                $queue[$dependency.' '.$label]=[$dependency,$metadata,$copy($metadata,['parameters'=>$parameters])];
                            }
                        }
                        if($dependency==='owner') {
                            foreach(['type','declaredType'] as $field) {
                                $parameters=$metadata->parameters;$parameters[0]=$copy($parameters[0],[$field=>$copy($parameters[0]->$field,['type'=>Type::float()])]);
                                $queue[$dependency.' wrong '.$field]=[$dependency,$metadata,$copy($metadata,['parameters'=>$parameters])];
                            }
                        }
                        if($dependency==='enumValue') {
                            $formal=$metadata->parameters[0];$template=$formal->type->type->atomicTypes[0];
                            $variants=['wrong type'=>['type'=>Type::float()],'missing doc'=>['fromDocblock'=>false],
                                'wrong template name'=>['type'=>Type::fromAtomic($copy($template,['name'=>'TOther']))],
                                'wrong template constraint'=>['type'=>Type::fromAtomic($copy($template,['constraint'=>Type::float()]))],
                                'wrong template binding'=>['type'=>Type::fromAtomic($copy($template,['definingEntity'=>$copy($template->definingEntity,['member'=>'other'])]))]];
                            foreach($variants as $label=>$change) {
                                $parameters=$metadata->parameters;$parameters[0]=$copy($formal,['type'=>$copy($formal->type,$change)]);
                                $queue[$dependency.' '.$label]=[$dependency,$metadata,$copy($metadata,['parameters'=>$parameters])];
                            }
                        }
                        if($dependency==='all') {
                            $return=$metadata->returnType;$array=$return->type->atomicTypes[0];
                            foreach(['missing doc'=>['fromDocblock'=>false],'inferred'=>['inferred'=>true],'wrong domain'=>['type'=>Type::float()],
                                'wrong key'=>['type'=>Type::fromAtomic($copy($array,['keyType'=>Type::float()]))],
                                'wrong value'=>['type'=>Type::fromAtomic($copy($array,['valueType'=>Type::string()]))],
                                'closed known items'=>['type'=>Type::fromAtomic($copy($array,['knownItems'=>[]]))],
                                'nonempty'=>['type'=>Type::fromAtomic($copy($array,['nonEmpty'=>true]))],
                                'missing fallback'=>['type'=>Type::fromAtomic($copy($array,['keyType'=>null,'valueType'=>null]))]] as $label=>$change) {
                                $queue[$dependency.' '.$label]=[$dependency,$metadata,$copy($metadata,['returnType'=>$copy($return,$change)])];
                            }
                        }
                    } elseif($metadata instanceof PropertyMetadata) {
                        foreach(['virtual'=>['flags'=>new MetadataFlags($metadata->flags->bits|MetadataFlags::VIRTUAL_PROPERTY)],
                            'static'=>['flags'=>new MetadataFlags($metadata->flags->bits|MetadataFlags::STATIC)],
                            'declared type'=>['declaredType'=>$metadata->type],
                            'name span'=>['nameLocation'=>$copy($metadata->nameLocation,['span'=>new Span($metadata->nameLocation->span->start+1,$metadata->nameLocation->span->end)])],
                            'wrong domain'=>['type'=>$copy($metadata->type,['type'=>Type::float()])],
                            'missing doc'=>['type'=>$copy($metadata->type,['fromDocblock'=>false])],
                            'inferred'=>['type'=>$copy($metadata->type,['inferred'=>true])]] as $label=>$change) { $queue[$dependency.' '.$label]=[$dependency,$metadata,$copy($metadata,$change)]; }
                    } else { throw new \RuntimeException('Unrecognized selected genuine dependency.'); }
                }
                if(count($queue)!==138) { throw new \RuntimeException('Genuine mutation plan changed: '.count($queue)); }
                $cache=(new \ReflectionProperty($context->codebase,'cache'))->getValue($context->codebase);
                foreach ($queue as $label=>[$dependency,$metadata,$replacement]) {
                    $expect('genuine positive immediately before '.$label,$context,true);
                    // Populate selected and declaring public aliases before mutating genuine metadata.
                    if($metadata instanceof FunctionLikeMetadata) {
                        $id=$metadata->identifier;
                        if($id->class===null) { $aliases=[$context->codebase->getFunction($id->name)]; }
                        else {
                            $selectedClass=$dependency==='receiving'?$certificate['selectedReceiver']:$id->class;
                            $aliases=[$context->codebase->getMethod($selectedClass,$id->name),
                                $context->codebase->getDeclaringMethod($selectedClass,$id->name),
                                $context->codebase->getMethod($id->class,$id->name),
                                $context->codebase->getDeclaringMethod($id->class,$id->name)];
                        }
                    } elseif($metadata instanceof ClassLikeMetadata) { $aliases=[$context->codebase->getClass($metadata->name)]; }
                    else {
                        $selectedReceiver=$certificate['selectedReceiver'];
                        $aliases=[$context->codebase->getProperty($selectedReceiver,'$attributes'),
                            $context->codebase->getDeclaringProperty($selectedReceiver,'$attributes')];
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
                $framework=$this->root.'/'.$native['basePut']->location->file;$original=file_get_contents($framework);
                foreach(['array setter delimiter'=>["explode('.', \$key)","explode(':', \$key)"],
                    'forwarded key rewrite'=>['$this->checkFailure(); parent::put($key,$value)','$this->checkFailure(); parent::put(null,$value)']] as $label=>[$before,$after]) {
                    $expect('genuine positive before source '.$label,$context,true);
                    if(substr_count($original,$before)!==1 || strlen($before)!==strlen($after)) { throw new \RuntimeException('Source control is not one exact same-length change.'); }
                    try { file_put_contents($framework,str_replace($before,$after,$original));$expect('physical source '.$label,$context,false); }
                    finally { file_put_contents($framework,$original); }
                    $expect('source restored '.$label,$context,true);
                }
                $expect('genuine native contracts restored',$context,true);
                file_put_contents($this->output.'/controls-'.$family.'.json',json_encode(['genuinePositive'=>true,'allChecks'=>$checks,'cacheMutations'=>$mutations,
                    'restored'=>true,'noVacuousControls'=>true,'constructedInputNativeAcceptanceClaimed'=>false],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));return $decision;
            }
        };
        $registry->registerIssueFilterHook($hook);
    }
}
