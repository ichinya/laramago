<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Analyzer\Metadata\ClassLikeKind;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Reporting\Annotation;
use Mago\Sdk\Reporting\AnnotationKind;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Reporting\ReportedIssue;
use Mago\Sdk\Span;

/** Mutate actual eligible single-file native metadata only; never build a simulated positive context. */
final class CoalesceModelPropertyNativeControls implements Plugin
{
    public function __construct(private readonly string $root) {}
    public function getDefinition(): PluginDefinition { return new PluginDefinition('fixture/coalesce-native-controls','Coalesce probe controls','Genuine source/native/envelope controls'); }
    public function register(PluginRegistry $registry): void
    {
        $registry->registerIssueFilterHook(new class($this->root) implements IssueFilterHook {
            private bool $checked = false;
            private CoalesceModelPropertyIssueFilter $filter;
            public function __construct(private readonly string $root) { $this->filter = new CoalesceModelPropertyIssueFilter($root,'Illuminate\\Database\\Eloquent\\Model'); }
            public function getCodes(): array { return ['non-documented-property']; }
            public function filterIssue(IssueFilterContext $context): IssueFilterDecision
            {
                $result = $this->filter->filterIssue($context);
                if ($this->checked || basename($context->file) !== 'focus.php') { return $result; }
                $this->checked = true; $checks = [];
                $expect = function (string $label, IssueFilterContext $input, bool $remove) use (&$checks): void {
                    $actual = $this->filter->filterIssue($input) === IssueFilterDecision::Remove;
                    if ($actual !== $remove) {
                        file_put_contents($this->root.'/coalesce-first-failure.json',json_encode(['label'=>$label,'expectedRemove'=>$remove,'actualRemove'=>$actual,'stages'=>$this->filter->stages,'passed'=>$checks],JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                        throw new \RuntimeException('Native coalesce control: '.$label);
                    }
                    $checks[$label] = true;
                };
                $expect('genuine native eligibility before controls',$context,true);
                $codebase = $context->codebase; $model = $codebase->getClass('Illuminate\\Database\\Eloquent\\Model'); $interface = $codebase->getInterface('ArrayAccess');
                $methods = ['__get'=>$codebase->getMethod('Illuminate\\Database\\Eloquent\\Model','__get'),'__isset'=>$codebase->getMethod('Illuminate\\Database\\Eloquent\\Model','__isset')];
                $physical=$codebase->getDeclaringProperty('CoalesceProbeFixture\\PhysicalRow','$label');
                $magic=$codebase->getDeclaringMagicProperty('CoalesceProbeFixture\\DocumentedRow','$label');
                $cache = (new \ReflectionProperty($codebase,'cache'))->getValue($codebase);
                file_put_contents($this->root.'/coalesce-native-metadata.txt',var_export(['nativeIssue'=>$context->issue,'model'=>$model,'interface'=>$interface,'methods'=>$methods],true));
                $copy = static function (object $value,array $changes): object { $class=$value::class; return new $class(...array_replace(get_object_vars($value),$changes)); };
                $issue=$context->issue; [$primary,$secondary]=$issue->annotations;
                $with = static fn (ReportedIssue $report,?string $contents=null,?string $file=null): IssueFilterContext => new IssueFilterContext($context->phpVersion,$context->codebase,$context->types,$context->cancellation,$file??$context->file,$contents??$context->contents,$report);
                $report = static fn (array $changes): ReportedIssue => $copy($issue,$changes);
                $annotation = static fn (object $original,array $changes): Annotation => $copy($original,$changes);
                foreach (['Error level'=>['level'=>Level::Error],'different code'=>['code'=>'non-existent-property'],'no notes'=>['notes'=>[]],
                    'extra note'=>['notes'=>[...$issue->notes,'Additional note.']],'changed help'=>['help'=>null],
                    'foreign receiver'=>['message'=>str_replace('Illuminate\\Database\\Eloquent\\Model','Foreign\\Model',$issue->message)],
                    'missing annotations'=>['annotations'=>[]],'extra annotation'=>['annotations'=>[$primary,$secondary,$primary]],
                    'reversed annotations'=>['annotations'=>[$secondary,$primary]],'Primary different kind'=>['annotations'=>[$annotation($primary,['kind'=>AnnotationKind::Secondary]),$secondary]],
                    'Primary file'=>['annotations'=>[$annotation($primary,['file'=>'foreign.php']),$secondary]],
                    'nearby Primary'=>['annotations'=>[$annotation($primary,['span'=>new Span($primary->span->start+1,$primary->span->end)]),$secondary]],
                    'nearby Secondary'=>['annotations'=>[$primary,$annotation($secondary,['span'=>new Span($secondary->span->start+1,$secondary->span->end)])]],
                    'foreign link'=>['link'=>'https://example.invalid']] as $label=>$changes) { $expect($label,$with($report($changes)),false); }
                $expect('stale caller bytes',$with($issue,$context->contents.' '),false);
                $expect('malformed caller bytes',$with($issue,'<?php invalid {'),false);
                $expect('foreign caller path',$with($issue,file:'foreign.php'),false);
                $values=$cache->values; $relations=$cache->relations; $mutations=[];
                foreach (['missing'=>null,'wrong kind'=>$copy($model,['kind'=>ClassLikeKind::Interface]),
                    'incomplete'=>$copy($model,['unresolvedHierarchyDependencies'=>['UnknownParent']]),
                    'unknown mixin'=>$copy($model,['mixins'=>[Type::namedObject('stdClass')]]),
                    'unexpected parent'=>$copy($model,['directParentClass'=>'Unknown\\Parent']),
                    'final model'=>$copy($model,['flags'=>new MetadataFlags($model->flags->bits|MetadataFlags::FINAL)]),
                    'missing source interface'=>$copy($model,['directParentInterfaces'=>[]])] as $label=>$replacement) { $mutations['native model '.$label]=[$model,$replacement]; }
                $mutations['native interface missing']=[$interface,null];
                $mutations['native interface wrong kind']=[$interface,$copy($interface,['kind'=>ClassLikeKind::Class_])];
                foreach ($methods as $name=>$method) {
                    foreach (['missing'=>null,'abstract'=>$copy($method,['abstract'=>true]),'static'=>$copy($method,['static'=>true]),
                        'unknown source owner'=>$copy($method,['identifier'=>$copy($method->identifier,['class'=>'Unknown\\Owner'])]),
                        'unknown parameter count'=>$copy($method,['parameters'=>[]]),'stronger return'=>$copy($method,['returnType'=>$copy($method->returnType,['type'=>Type::int()])]),
                        'not doc return'=>$copy($method,['returnType'=>$copy($method->returnType,['fromDocblock'=>false])]),
                        'inferred return'=>$copy($method,['returnType'=>$copy($method->returnType,['inferred'=>true])])] as $label=>$replacement) { $mutations['native '.$name.' '.$label]=[$method,$replacement]; }
                    $param=$method->parameters[0];
                    $mutations['native '.$name.' stronger parameter']=[$method,$copy($method,['parameters'=>[$copy($param,['type'=>$copy($param->type,['type'=>Type::int()])])]])];
                    $mutations['native '.$name.' reference parameter']=[$method,$copy($method,['parameters'=>[$copy($param,['flags'=>new MetadataFlags($param->flags->bits|MetadataFlags::BY_REFERENCE)])]])];
                }
                foreach ($mutations as $label=>[$original,$replacement]) {
                    $changed=0;
                    foreach ($values as $operation=>$entries) { foreach ($entries as $key=>$entry) { if ($entry===$original || $entry==$original) { $cache->values[$operation][$key]=$replacement; $changed++; } } }
                    try { if ($changed===0) { throw new \RuntimeException('Vacuous real cache control: '.$label); } $expect($label,$context,false); }
                    finally { $cache->values=$values; $cache->relations=$relations; }
                }
                foreach (['physical'=>[\Mago\Sdk\Internal\Analyzer\Protocol::GET_DECLARING_PROPERTIES,$physical],
                    'magic'=>[\Mago\Sdk\Internal\Analyzer\Protocol::GET_DECLARING_MAGIC_PROPERTIES,$magic]] as $label=>[$operation,$property]) {
                    $bucket=$operation<<4; $key=strtolower('Illuminate\\Database\\Eloquent\\Model')."\0\$label";
                    if ($property===null || !array_key_exists($key,$values[$bucket]??[]) || $values[$bucket][$key]!==null) { throw new \RuntimeException('Missing genuine stronger-property slot control.'); }
                    try { $cache->values[$bucket][$key]=$property; $expect('genuine stronger '.$label.' property preserved',$context,false); }
                    finally { $cache->values=$values; $cache->relations=$relations; }
                }
                $expect('genuine native metadata restored',$context,true);
                file_put_contents($this->root.'/coalesce-native-controls.json',json_encode($checks,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
                return $result;
            }
        });
    }
}
