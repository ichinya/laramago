<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Tests;
use Ichinya\Laramago\Analyzer\ArgumentClosurePossibleWriteFilter;
use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterDecision,IssueFilterHook,Plugin,PluginDefinition,PluginRegistry};
use Mago\Sdk\Analyzer\Metadata\{FunctionLikeMetadata,MetadataFlags};
use Mago\Sdk\Reporting\{Level,AnnotationKind};
use Mago\Sdk\Span;
final class ArgumentClosureWriteNativeHarness implements Plugin
{
    public function __construct(private readonly string $root,private readonly string $output,private readonly bool $controls=false) {}
    public function getDefinition(): PluginDefinition { return new PluginDefinition('fixture/argument-closure-writes','Argument closure writes','Genuine AlwaysKeep observation and selected SDK cache controls.'); }
    public function register(PluginRegistry $registry): void
    {
        $registry->registerIssueFilterHook(new class($this->root,$this->output,$this->controls) implements IssueFilterHook {
            private bool $checked=false;
            public function __construct(private readonly string $root,private readonly string $output,private readonly bool $controls) {}
            public function getCodes(): array { return ['impossible-type-comparison']; }
            private static function snapshot(mixed $value): mixed {
                if ($value instanceof \BackedEnum) { return $value->value; }if ($value instanceof \UnitEnum) { return $value->name; }
                if (is_object($value)) { return self::snapshot(get_object_vars($value)); }return is_array($value)?array_map(self::snapshot(...),$value):$value;
            }
            public function filterIssue(IssueFilterContext $context): IssueFilterDecision
            {
                $proof=new ArgumentClosurePossibleWriteFilter($this->root);$decision=$proof->filterIssue($context);
                $record=['genuineIssueFilterContext'=>true,'alwaysKeep'=>!$this->controls,'candidateDecision'=>$decision->name,'file'=>$context->file,
                    'sourceSha256'=>hash('sha256',$context->contents),'issue'=>self::snapshot($context->issue),'stages'=>$proof->stages,
                    'certificate'=>self::snapshot($proof->certificate),'selected'=>self::snapshot($proof->dependencies)];
                if (! $this->controls) { file_put_contents($this->output.'/issues.jsonl',json_encode($record,JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);return IssueFilterDecision::Keep; }
                if ($this->checked || basename($context->file)!=='cases.php' || ($proof->dependencies['owner']?->identifier->name??null)!=='listener') { return $decision; }
                $this->checked=true;$checks=[];$mutations=[];$native=$proof->dependencies;
                file_put_contents($this->output.'/initial-proof.json',json_encode($record,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
                $expect=function(string $label,IssueFilterContext $input,bool $remove) use (&$checks,$proof): void {
                    $actual=$proof->filterIssue($input)===IssueFilterDecision::Remove;
                    if ($actual!==$remove) {
                        file_put_contents($this->output.'/first-failure.json',json_encode(['label'=>$label,'expectedRemove'=>$remove,'actualRemove'=>$actual,
                            'stages'=>$proof->stages,'certificate'=>self::snapshot($proof->certificate),'selected'=>self::snapshot($proof->dependencies),'passed'=>$checks],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
                        throw new \RuntimeException('Argument closure write control failed: '.$label);
                    }$checks[$label]=true;
                };
                $expect('genuine positive before any constructed input',$context,true);
                $copy=static function(object $original,array $changes): object { $class=$original::class;return new $class(...array_replace(get_object_vars($original),$changes)); };
                $with=static fn(object $issue,?string $contents=null,?string $file=null):IssueFilterContext=>new IssueFilterContext($context->phpVersion,$context->codebase,$context->types,$context->cancellation,$file??$context->file,$contents??$context->contents,$issue);
                $issue=$context->issue;$primary=$issue->annotations[0];
                foreach (['wrong code'=>['code'=>'invalid-argument'],'wrong level'=>['level'=>Level::Warning],'wrong actual type'=>['message'=>str_replace('type `false`','type `mixed`',$issue->message)],
                    'wrong expected type'=>['message'=>str_replace('`true|true`','`false|false`',$issue->message)],'missing note'=>['notes'=>[]],
                    'wrong help'=>['help'=>null],'foreign link'=>['link'=>'https://example.invalid'],'duplicate primary'=>['annotations'=>[$primary,$primary]],
                    'secondary annotation'=>['annotations'=>[$copy($primary,['kind'=>AnnotationKind::Secondary])]],'wrong primary text'=>['annotations'=>[$copy($primary,['message'=>'Different value.'])]],
                    'operand-only span'=>['annotations'=>[$copy($primary,['span'=>new Span($primary->span->start+1,$primary->span->end)])]],
                    'foreign annotation'=>['annotations'=>[$copy($primary,['file'=>'other.php'])]]] as $label=>$changes) { $expect($label,$with($copy($issue,$changes)),false); }
                $expect('stale contents',$with($issue,$context->contents.' '),false);$expect('foreign caller file',$with($issue,file:'absent.php'),false);
                $cache=(new \ReflectionProperty($context->codebase,'cache'))->getValue($context->codebase);$queue=[];
                foreach ($native as $dependency=>$metadata) {
                    if (! $metadata instanceof FunctionLikeMetadata) { throw new \RuntimeException('Missing genuine selected callable contract.'); }
                    $queue[$dependency.' absent']=[$metadata,null];
                    $queue[$dependency.' reference return']=[$metadata,$copy($metadata,['flags'=>new MetadataFlags($metadata->flags->bits|MetadataFlags::BY_REFERENCE)])];
                    if ($dependency==='assertion') {
                        $queue[$dependency.' missing assertions']=[$metadata,$copy($metadata,['assertions'=>[]])];
                        $parameters=$metadata->parameters;$parameters[0]=$copy($parameters[0],['flags'=>new MetadataFlags($parameters[0]->flags->bits|MetadataFlags::BY_REFERENCE)]);
                        $queue[$dependency.' reference actual formal']=[$metadata,$copy($metadata,['parameters'=>$parameters])];
                    }
                    if (in_array($dependency,['str_contains','strtolower'],true)) {
                        if ($metadata->returnType===null) { throw new \RuntimeException('Missing genuine selected predicate return metadata.'); }
                        $queue[$dependency.' wrong return domain']=[$metadata,$copy($metadata,['returnType'=>$copy($metadata->returnType,['type'=>\Mago\Sdk\Analyzer\Type::float()])])];
                        $formal=$metadata->parameters[0];
                        if ($formal->declaredType===null || $formal->type===null || $metadata->declaredReturnType===null) { throw new \RuntimeException('Missing selected declared/effective predicate contract.'); }
                        foreach (['declaredType','type'] as $field) {
                            $parameters=$metadata->parameters;$parameters[0]=$copy($formal,[$field=>$copy($formal->$field,['type'=>\Mago\Sdk\Analyzer\Type::int()])]);
                            $queue[$dependency.' wrong '.$field.' formal']=[$metadata,$copy($metadata,['parameters'=>$parameters])];
                        }
                        $parameters=$metadata->parameters;$parameters[0]=$copy($formal,['flags'=>new MetadataFlags($formal->flags->bits|MetadataFlags::BY_REFERENCE)]);
                        $queue[$dependency.' reference selected formal']=[$metadata,$copy($metadata,['parameters'=>$parameters])];
                        $queue[$dependency.' wrong declared return']=[$metadata,$copy($metadata,['declaredReturnType'=>$copy($metadata->declaredReturnType,['type'=>\Mago\Sdk\Analyzer\Type::float()])])];
                    }
                    if ($dependency==='strtolower') {
                        $return=$metadata->returnType;$conditional=$return->type->atomicTypes[0]??null;
                        if (! $conditional instanceof \Mago\Sdk\Analyzer\Type\ConditionalType) { throw new \RuntimeException('Missing genuinely selected strtolower conditional.'); }
                        $replacements=[
                            'wrong conditional subject'=>['subject'=>\Mago\Sdk\Analyzer\Type::fromAtomic(new \Mago\Sdk\Analyzer\Type\VariableType('$other'))],
                            'wrong conditional target'=>['target'=>\Mago\Sdk\Analyzer\Type::int()],
                            'wrong conditional then'=>['then'=>\Mago\Sdk\Analyzer\Type::int()],
                            'wrong conditional otherwise'=>['otherwise'=>\Mago\Sdk\Analyzer\Type::int()],
                            'negated conditional'=>['negated'=>true],
                            'missing lowercase refinement'=>['then'=>\Mago\Sdk\Analyzer\Type::nonEmptyString()],
                        ];
                        foreach ($replacements as $label=>$changes) {
                            $queue[$dependency.' '.$label]=[$metadata,$copy($metadata,['returnType'=>$copy($return,
                                ['type'=>\Mago\Sdk\Analyzer\Type::fromAtomic($copy($conditional,$changes))])])];
                        }
                        $queue[$dependency.' missing conditional doc provenance']=[$metadata,$copy($metadata,['returnType'=>$copy($return,['fromDocblock'=>false])])];
                    }
                }
                foreach ($queue as $label=>[$metadata,$replacement]) {
                    $expect('genuine positive immediately before '.$label,$context,true);
                    $id=$metadata->identifier;$current=$id->class===null?$context->codebase->getFunction($id->name):$context->codebase->getMethod($id->class,$id->name);
                    if ($current===null || $current!=$metadata || $current==$replacement) { throw new \RuntimeException('Not a current meaningful native mutation: '.$label); }
                    $saved=$cache->values;$relations=$cache->relations;$slots=[];
                    foreach ($saved as $operation=>$entries) { foreach ($entries as $key=>$entry) { if ($entry===$current) { $cache->values[$operation][$key]=$replacement;$slots[]=['operation'=>$operation,'key'=>$key]; } } }
                    try {
                        if ($slots===[]) { throw new \RuntimeException('Vacuous SDK cache control: '.$label); }
                        $expect('actual SDK cache '.$label,$context,false);$mutations[$label]=['touched'=>count($slots),'actualSlots'=>$slots,'meaningfullyChanged'=>true];
                    } finally { $cache->values=$saved;$cache->relations=$relations; }
                    $expect('native restored after '.$label,$context,true);
                }
                $expect('genuine native metadata restored',$context,true);
                file_put_contents($this->output.'/controls.json',json_encode(['genuinePositive'=>true,'allChecks'=>$checks,'cacheMutations'=>$mutations,
                    'restored'=>true,'noVacuousControls'=>true,'constructedInputNativeAcceptanceClaimed'=>false],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
                return $decision;
            }
        });
    }
}
