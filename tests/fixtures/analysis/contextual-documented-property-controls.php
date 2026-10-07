<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Tests;

use Ichinya\Laramago\Analyzer\ContextualCollectionMemberProvider;
use Ichinya\Laramago\Analyzer\ContextualDocumentedPropertyIssueFilter;

use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\Reporting\AnnotationKind;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Reporting\ReportedIssue;
use Mago\Sdk\Span;

/** Only mutate observed eligible native inputs; leave every real issue visible. */
final class ContextualDocumentedPropertyControls implements IssueFilterHook
{
    private bool $checked = false;
    private ContextualDocumentedPropertyIssueFilter $filter;
    public function __construct(private readonly ContextualCollectionMemberProvider $provider, private readonly string $root)
    { $this->filter = new ContextualDocumentedPropertyIssueFilter($provider); }
    public function getCodes(): array { return $this->filter->getCodes(); }
    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        if ($this->checked || basename($context->file) !== 'focus.php') { return IssueFilterDecision::Keep; }
        $this->checked = true; $checks = [];
        $expect = function (string $label, IssueFilterContext $input, bool $remove) use (&$checks): void {
            $actual = $this->filter->filterIssue($input) === IssueFilterDecision::Remove;
            if ($actual !== $remove) {
                file_put_contents($this->root.'/control-first-failure.json', json_encode(['label'=>$label,
                    'expectedRemove'=>$remove,'actualRemove'=>$actual,'stages'=>$this->filter->stages,'passed'=>$checks], JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
                throw new \RuntimeException('Genuine contextual issue control failed: '.$label);
            }
            $checks[$label] = true;
        };
        $expect('genuine native positive before any mutation', $context, true);
        $copy = static function (object $value, array $changes): object { $class = $value::class; return new $class(...array_replace(get_object_vars($value), $changes)); };
        $issue = $context->issue; [$primary,$secondary] = $issue->annotations;
        $with = static fn (ReportedIssue $report, ?string $contents = null, ?string $file = null): IssueFilterContext => new IssueFilterContext(
            $context->phpVersion,$context->codebase,$context->types,$context->cancellation,$file??$context->file,$contents??$context->contents,$report);
        foreach (['real Error level'=>['level'=>Level::Error], 'other code'=>['code'=>'non-existent-property'],
            'changed message'=>['message'=>$issue->message.' Changed.'], 'missing note'=>['notes'=>[]], 'extra note'=>['notes'=>[...$issue->notes,'Extra.']],
            'unknown help'=>['help'=>null], 'extra link'=>['link'=>'https://example.invalid'], 'missing annotations'=>['annotations'=>[]],
            'extra annotation'=>['annotations'=>[$primary,$secondary,$primary]], 'reversed annotations'=>['annotations'=>[$secondary,$primary]],
            'Primary not primary'=>['annotations'=>[$copy($primary,['kind'=>AnnotationKind::Secondary]),$secondary]],
            'Primary foreign file'=>['annotations'=>[$copy($primary,['file'=>'elsewhere.php']),$secondary]],
            'Primary wrong text'=>['annotations'=>[$copy($primary,['message'=>'Changed']),$secondary]],
            'Secondary foreign file'=>['annotations'=>[$primary,$copy($secondary,['file'=>'elsewhere.php'])]],
            'Secondary wrong text'=>['annotations'=>[$primary,$copy($secondary,['message'=>'Changed'])]],
            'nearby property token'=>['annotations'=>[$copy($primary,['span'=>new Span($primary->span->start+1,$primary->span->end)]),$secondary]],
            'nearby receiver'=>['annotations'=>[$primary,$copy($secondary,['span'=>new Span($secondary->span->start+1,$secondary->span->end)])]]] as $label=>$change) {
            $expect($label,$with($copy($issue,$change)),false);
        }
        $expect('foreign caller path',$with($issue,file:'foreign.php'),false);
        $expect('stale analyzed bytes',$with($issue,contents:$context->contents.' '),false);
        $expect('malformed analyzed bytes',$with($issue,contents:'<?php invalid {'),false);
        $members = $this->provider->members;
        foreach (['incomplete before-issue scan'=>['complete',false], 'failed before-issue scan'=>['failed',true],
            'missing exact-file index'=>['fileMembers',[]], 'missing selected hashes'=>['sources',[]],
            'wrong selected hash'=>['sources',[$members->path($context->file)=>str_repeat('0',64)]]] as $label=>[$field,$replacement]) {
            $property = new \ReflectionProperty($members,$field); $original = $property->getValue($members);
            try { $property->setValue($members,$replacement); $expect($label,$context,false); }
            finally { $property->setValue($members,$original); }
        }
        // A foreign global span collision still blocks the provider. It cannot invalidate actual file authority.
        $global = new \ReflectionProperty($members,'members'); $original = $global->getValue($members);
        try {
            $global->setValue($members,[]);
            if ($members->member($primary->span,'id') !== null) { throw new \RuntimeException('Provider collision veto was weakened.'); }
            $expect('file authority survives global provider collision without changing it',$context,true);
        } finally { $global->setValue($members,$original); }
        $codebase = $context->codebase; $model = $codebase->getClassLike('Row');
        $field = $codebase->getDeclaringMagicProperty('Row','$id');
        $query = $codebase->getMethod('Row','query') ?? $codebase->getDeclaringMethod('Row','query');
        $setter = $codebase->getMethod('Illuminate\\Database\\Eloquent\\Builder','setModel');
        $declaringSetter = $codebase->getDeclaringMethod('Illuminate\\Database\\Eloquent\\Builder','setModel');
        if ($model===null || $field===null || $query===null || $setter===null || $declaringSetter===null) { throw new \RuntimeException('Missing genuine native mutation inputs.'); }
        file_put_contents($this->root.'/control-native-inputs.txt',var_export(['issue'=>$issue,'row'=>$model,'id'=>$field,'query'=>$query,'setModel'=>$setter],true));
        $cache = (new \ReflectionProperty($codebase,'cache'))->getValue($codebase);
        $values = $cache->values; $relations = $cache->relations;
        $mutations = ['native model missing'=>[$model,null], 'native model unknown source'=>[$model,$copy($model,['name'=>'Unknown\\Row'])],
            'native field missing'=>[$field,null], 'native field name mismatch'=>[$field,$copy($field,['name'=>'$other'])],
            'native field private'=>[$field,$copy($field,['readVisibility'=>Visibility::Private])],
            'native field static'=>[$field,$copy($field,['flags'=>new MetadataFlags($field->flags->bits|MetadataFlags::STATIC)])],
            'native field write only'=>[$field,$copy($field,['flags'=>new MetadataFlags($field->flags->bits|MetadataFlags::WRITEONLY)])],
            'native field stronger contradictory type'=>[$field,$copy($field,['type'=>$copy($field->type,['type'=>Type::string()])])],
            'native query missing'=>[$query,null], 'native query wrong owner'=>[$query,$copy($query,['identifier'=>$copy($query->identifier,['class'=>'Unknown\\Owner'])])],
            // Both independently observed method lookups can certify the same physical declaration.
            'native setter missing'=>[[$setter,$declaringSetter],null], 'native setter incorrect parameter count'=>[[$setter,$declaringSetter],$copy($setter,['parameters'=>[]])]];
        foreach ($mutations as $label=>[$old,$replacement]) {
            $changed = 0;
            foreach ($values as $operation=>$entries) { foreach ($entries as $key=>$value) {
                foreach (is_array($old) ? $old : [$old] as $observed) {
                    if ($value === $observed || is_object($value) && $value == $observed) { $cache->values[$operation][$key] = $replacement; $changed++; break; }
                }
            } }
            try { if ($changed===0) { throw new \RuntimeException('Vacuous actual cache mutation: '.$label); } $expect($label,$context,false); }
            finally { $cache->values=$values; $cache->relations=$relations; }
        }
        $expect('all native and source state restored',$context,true);
        file_put_contents($this->root.'/native-controls.json',json_encode($checks,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
        return IssueFilterDecision::Keep;
    }
}
