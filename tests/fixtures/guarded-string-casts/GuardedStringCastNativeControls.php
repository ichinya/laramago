<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Tests;
use Ichinya\Laramago\Analyzer\GuardedStringCastCompatibilityFilter;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Analyzer\Metadata\ClassLikeKind;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\ConditionalType;
use Mago\Sdk\Analyzer\Type\VariableType;
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\Reporting\AnnotationKind;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Span;

/** Negative mutations begin only after each family's genuine native positive. */
final class GuardedStringCastNativeControls implements Plugin
{
    public function __construct(private readonly string $root,private readonly string $output) {}
    public function getDefinition(): PluginDefinition { return new PluginDefinition('draft/string-cast-native-controls','Native cast controls','Real positive contexts and non-vacuous SDK cache mutations'); }
    public function register(PluginRegistry $registry): void
    {
        $registry->registerIssueFilterHook(new class($this->root,$this->output) implements IssueFilterHook {
            private array $checked = []; private GuardedStringCastCompatibilityFilter $filter;
            public function __construct(string $root,private readonly string $output) { $this->filter = new GuardedStringCastCompatibilityFilter($root); }
            public function getCodes(): array { return ['invalid-type-cast']; }
            public function filterIssue(IssueFilterContext $context): IssueFilterDecision
            {
                $decision = $this->filter->filterIssue($context);
                $family = basename($context->file) === 'guard-focus.php' ? 'guard' : (basename($context->file) === 'route-focus.php' ? 'route' : null);
                if ($family === null || isset($this->checked[$family])) { return $decision; }
                $this->checked[$family] = true; $checks = []; $dependencies = $this->filter->dependencies;
                $expect = function (string $label,IssueFilterContext $input,bool $remove) use (&$checks,$family): void {
                    $actual = $this->filter->filterIssue($input) === IssueFilterDecision::Remove;
                    if ($actual !== $remove) {
                        file_put_contents($this->output.'/native-controls-'.$family.'-first-failure.json',json_encode(['label'=>$label,'expectedRemove'=>$remove,'actualRemove'=>$actual,'stages'=>$this->filter->stages,'proofReceipts'=>$this->filter->proofReceipts(),'passed'=>$checks],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
                        throw new \RuntimeException('Native string-cast control failed: '.$label);
                    }
                    $checks[$label] = true;
                };
                $expect('genuine native positive before any constructed negative',$context,true);
                $copy = static function (object $original,array $changes): object { $class = $original::class; return new $class(...array_replace(get_object_vars($original),$changes)); };
                $issue = $context->issue; $primary = $issue->annotations[0];
                $with = static fn (object $report,?string $contents=null,?string $file=null): IssueFilterContext => new IssueFilterContext($context->phpVersion,$context->codebase,$context->types,$context->cancellation,$file??$context->file,$contents??$context->contents,$report);
                foreach (['wrong level'=>['level'=>Level::Warning],'wrong code'=>['code'=>'redundant-cast'],'different concrete object'=>['message'=>'Cannot cast `stdClass` to `string`.'],
                    'missing notes'=>['notes'=>[]],'extra note'=>['notes'=>[...$issue->notes,'Other note.']],'changed help'=>['help'=>null],
                    'foreign link'=>['link'=>'https://example.invalid'],'missing primary'=>['annotations'=>[]],'duplicate primary'=>['annotations'=>[$primary,$primary]],
                    'secondary primary'=>['annotations'=>[$copy($primary,['kind'=>AnnotationKind::Secondary])]],'external annotation file'=>['annotations'=>[$copy($primary,['file'=>'foreign.php'])]],
                    'changed primary message'=>['annotations'=>[$copy($primary,['message'=>'Other cast.'])]],
                    'operand-only span'=>['annotations'=>[$copy($primary,['span'=>new Span($primary->span->start+8,$primary->span->end)])]],
                    'nearby full span'=>['annotations'=>[$copy($primary,['span'=>new Span($primary->span->start+1,$primary->span->end)])]]] as $label=>$changes) {
                    $expect($label,$with($copy($issue,$changes)),false);
                }
                $expect('stale caller bytes',$with($issue,$context->contents.' '),false);
                $expect('invalid caller bytes',$with($issue,'<?php invalid {'),false);
                $expect('foreign caller path',$with($issue,file:'absent-foreign.php'),false);
                $cache = (new \ReflectionProperty($context->codebase,'cache'))->getValue($context->codebase);
                $saved = $cache->values; $relations = $cache->relations; $mutations = [];
                foreach ($dependencies as $name=>$metadata) {
                    if ($metadata === null) { throw new \RuntimeException('Expected genuine native dependency missing.'); }
                    $mutations[$name.' missing'] = [$metadata,null];
                    if ($metadata instanceof \Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata) {
                        $mutations[$name.' reference return'] = [$metadata,$copy($metadata,['flags'=>new MetadataFlags($metadata->flags->bits|MetadataFlags::BY_REFERENCE)])];
                        $mutations[$name.' absent parameters'] = [$metadata,$copy($metadata,['parameters'=>[]])];
                        if (in_array($name,['is_object','is_callable','is_null','call_user_func'],true)) {
                            $mutations[$name.' user-defined guard'] = [$metadata,$copy($metadata,['flags'=>new MetadataFlags(($metadata->flags->bits&~MetadataFlags::BUILTIN)|MetadataFlags::USER_DEFINED)])];
                            $mutations[$name.' stronger return'] = [$metadata,$copy($metadata,['returnType'=>$copy($metadata->returnType,['type'=>Type::string()])])];
                            $first = $metadata->parameters[0];
                            $mutations[$name.' reference argument'] = [$metadata,$copy($metadata,['parameters'=>[$copy($first,['flags'=>new MetadataFlags($first->flags->bits|MetadataFlags::BY_REFERENCE)]),...array_slice($metadata->parameters,1)]])];
                            $mutations[$name.' wrong original name'] = [$metadata,$copy($metadata,['originalName'=>'other_builtin'])];
                            $mutations[$name.' inferred assertions'] = [$metadata,$copy($metadata,['assertionsInferred'=>true])];
                        }
                        if (in_array($name,['is_object','is_callable','is_null'],true)) {
                            $mutations[$name.' missing positive assertion'] = [$metadata,$copy($metadata,['ifTrueAssertions'=>[]])];
                            $assertion = $metadata->ifTrueAssertions['$value'][0];
                            $mutations[$name.' wrong asserted type'] = [$metadata,$copy($metadata,['ifTrueAssertions'=>['$value'=>[$copy($assertion,['type'=>Type::string()])]]])];
                        }
                        if ($name === 'is_callable') {
                            $syntax = $metadata->parameters[1]; $output = $metadata->parameters[2];
                            $mutations[$name.' syntax default true'] = [$metadata,$copy($metadata,['parameters'=>[$metadata->parameters[0],$copy($syntax,['defaultType'=>$copy($syntax->defaultType,['type'=>Type::true()])]),$output]])];
                            $mutations[$name.' syntax reference input'] = [$metadata,$copy($metadata,['parameters'=>[$metadata->parameters[0],$copy($syntax,['flags'=>new MetadataFlags($syntax->flags->bits|MetadataFlags::BY_REFERENCE)]),$output]])];
                            $mutations[$name.' missing optional string output'] = [$metadata,$copy($metadata,['parameters'=>[$metadata->parameters[0],$syntax,$copy($output,['outType'=>null])]])];
                        }
                        if ($name === 'call_user_func') {
                            $mutations[$name.' absent templates'] = [$metadata,$copy($metadata,['templates'=>[]])];
                            $mutations[$name.' changed template constraint'] = [$metadata,$copy($metadata,['templates'=>[$copy($metadata->templates[0],['constraint'=>Type::string()]),$metadata->templates[1]]])];
                            $args = $metadata->parameters[1];
                            $mutations[$name.' variadic reference input'] = [$metadata,$copy($metadata,['parameters'=>[$metadata->parameters[0],$copy($args,['flags'=>new MetadataFlags($args->flags->bits|MetadataFlags::BY_REFERENCE)])]])];
                            $mutations[$name.' erased effective argument template'] = [$metadata,$copy($metadata,['parameters'=>[$metadata->parameters[0],$copy($args,['type'=>$copy($args->type,['type'=>Type::mixed()])])]])];
                        }
                        if (in_array($name,['route','declaring_route'],true)) {
                            $mutations[$name.' abstract'] = [$metadata,$copy($metadata,['abstract'=>true])];
                            $mutations[$name.' static'] = [$metadata,$copy($metadata,['static'=>true])];
                            $mutations[$name.' private'] = [$metadata,$copy($metadata,['visibility'=>Visibility::Private])];
                            $mutations[$name.' stronger return'] = [$metadata,$copy($metadata,['returnType'=>$copy($metadata->returnType,['type'=>Type::namedObject('stdClass')])])];
                            $mutations[$name.' inferred return'] = [$metadata,$copy($metadata,['returnType'=>$copy($metadata->returnType,['inferred'=>true])])];
                            $mutations[$name.' undocumented return'] = [$metadata,$copy($metadata,['returnType'=>$copy($metadata->returnType,['fromDocblock'=>false])])];
                        }
                        $conditional = $metadata->returnType?->type->atomicTypes[0] ?? null;
                        if ($conditional instanceof ConditionalType) {
                            foreach (['negated'=>['negated'=>true],'wrong subject'=>['subject'=>Type::fromAtomics(new VariableType('$other'))],
                                'wrong target'=>['target'=>Type::int()],'wrong then branch'=>['then'=>Type::string()],
                                'wrong otherwise branch'=>['otherwise'=>Type::int()]] as $label=>$changes) {
                                $mutations[$name.' conditional '.$label] = [$metadata,$copy($metadata,['returnType'=>$copy($metadata->returnType,['type'=>Type::fromAtomics($copy($conditional,$changes))])])];
                            }
                        }
                    } else {
                        $mutations[$name.' interface'] = [$metadata,$copy($metadata,['kind'=>ClassLikeKind::Interface])];
                        $mutations[$name.' incomplete'] = [$metadata,$copy($metadata,['unresolvedHierarchyDependencies'=>['Missing\\Base']])];
                    }
                }
                $cacheReceipts = [];
                foreach ($mutations as $label=>[$original,$replacement]) {
                    if ($replacement !== null && $replacement == $original) { throw new \RuntimeException('No-op native metadata mutation: '.$label); }
                    $targets = [$original];
                    // Both genuine route projections must change together, so deeper conditional controls reach their selected gate.
                    if (str_starts_with($label,'route ') || str_starts_with($label,'declaring_route ')) {
                        $other = str_starts_with($label,'route ') ? $dependencies['declaring_route'] : $dependencies['route'];
                        if ($other != $original) { throw new \RuntimeException('Genuine route projections do not agree before mutation.'); }
                        if ($other !== $original) { $targets[] = $other; }
                    }
                    // A nested SDK request may replace its caller's bucket snapshot.
                    // Re-query only these genuine contracts and mutate their current slots.
                    $currentTargets = [];
                    foreach ($targets as $native) {
                        if ($native instanceof \Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata) {
                            $id = $native->identifier;
                            $current = $id->class === null ? $context->codebase->getFunction($id->name)
                                : $context->codebase->getMethod($id->class,$id->name);
                            if ($current === null || $current != $native) { throw new \RuntimeException('Native contract changed before control: '.$label); }
                            $currentTargets[] = $current;
                            if (str_starts_with($label,'route ') || str_starts_with($label,'declaring_route ')) {
                                $declaring = $context->codebase->getDeclaringMethod($id->class,$id->name);
                                if ($declaring === null || $declaring != $native) { throw new \RuntimeException('Native declaring contract changed before control: '.$label); }
                                $currentTargets[] = $declaring;
                            }
                        } else {
                            $current = $context->codebase->getClass($native->name);
                            if ($current === null || $current != $native) { throw new \RuntimeException('Native class changed before control: '.$label); }
                            $currentTargets[] = $current;
                        }
                    }
                    $targets = $currentTargets;
                    $saved = $cache->values; $relations = $cache->relations;
                    $touched = 0; $slots = [];
                    foreach ($saved as $operation=>$entries) { foreach ($entries as $key=>$entry) {
                        if (in_array($entry,$targets,true)) { $cache->values[$operation][$key] = $replacement; $touched++; $slots[] = ['operation'=>$operation,'key'=>$key]; }
                    } }
                    try {
                        if ($touched === 0) { throw new \RuntimeException('Vacuous native cache control: '.$label); }
                        $expect('real SDK cache '.$label,$context,false);
                        $cacheReceipts[$label] = ['actualSlots'=>$slots,'touched'=>$touched,'changedMetadata'=>true,'expectedRemove'=>false,'proofReceipts'=>$this->filter->proofReceipts()];
                    } finally { $cache->values = $saved; $cache->relations = $relations; }
                }
                $expect('genuine native metadata restored',$context,true);
                file_put_contents($this->output.'/native-controls-'.$family.'.json',json_encode(['genuinePositive'=>true,'allChecks'=>$checks,'cacheMutations'=>$cacheReceipts,'noVacuousControls'=>true],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
                return $decision;
            }
        });
    }
}
