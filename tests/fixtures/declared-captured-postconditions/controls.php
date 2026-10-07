<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Tests;
use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterDecision,IssueFilterHook,Type};
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\Analyzer\Assertion\{TypeAssertion,TypeAssertionKind};
use Mago\Sdk\Reporting\{AnnotationKind,Level,ReportedIssue,TextEdit};
use Mago\Sdk\Span;

/** Actual positive native contexts only. Cache mutation is confined to the explicit engine-one control phase. */
final class DeclaredCapturedWarningNativeControls implements IssueFilterHook
{
    private array $checked=[];
    public function __construct(private readonly string $root,private readonly string $output) {}
    public function getCodes():array { return ['impossible-null-type-comparison','possibly-null-array-index','possibly-undefined-int-array-index']; }
    public function filterIssue(IssueFilterContext $context):IssueFilterDecision
    {
        $role=match(basename($context->file)) { 'I01.php'=>'identity','F01.php'=>'factory','F11.php'=>'factory-inherited','F12.php'=>'factory-uninitialized','A01.php'=>'flag','K01.php'=>'key',default=>null };
        if($role===null||isset($this->checked[$role])) { return IssueFilterDecision::Keep; }
        $family=str_starts_with($role,'factory')?'factory':$role;
        $create=fn()=>in_array($family,['identity','factory'],true)?new \Ichinya\Laramago\Analyzer\AssertedDeclaredValueWarningFilter($this->root):new \Ichinya\Laramago\Analyzer\CapturedArrayWarningFilter($this->root);
        $evaluation=$create()->evaluate($context);
        if($evaluation['decision']!==IssueFilterDecision::Remove) { return IssueFilterDecision::Keep; }$this->checked[$role]=true;
        $certificate=$evaluation['nativeReceipt']??$evaluation['actualNativeCertificate'];$checks=$variants=[];
        $expect=function(string $name,IssueFilterContext $input,bool $remove)use($create,&$checks):void {
            $actual=$create()->evaluate($input);
            if(($actual['decision']===IssueFilterDecision::Remove)!==$remove) {
                file_put_contents($this->output.'/controls-first-failure.json',json_encode(['control'=>$name,'expectedRemove'=>$remove,'completedLocalEvaluation'=>DeclaredCapturedWarningObserver::value($actual),'passed'=>$checks],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
                throw new \RuntimeException('Genuine warning control failed: '.$name);
            }$checks[$name]=true;
        };
        $copy=static function(object $value,array $changes):object { $class=$value::class;return new $class(...array_replace(get_object_vars($value),$changes)); };
        $issue=$context->issue;$primary=$issue->annotations[0];
        $with=static fn(ReportedIssue $report,?string $bytes=null,?string $file=null):IssueFilterContext=>new IssueFilterContext($context->phpVersion,$context->codebase,$context->types,$context->cancellation,$file??$context->file,$bytes??$context->contents,$report);
        $expect('genuine native positive before mutations',$context,true);
        $expect('implicit current Primary file',$with($copy($issue,['annotations'=>[$copy($primary,['file'=>null])]])),true);
        $expect('explicit current Primary file',$with($copy($issue,['annotations'=>[$copy($primary,['file'=>$context->file])]])),true);
        foreach(['every Error kept'=>['level'=>Level::Error],'foreign code'=>['code'=>'invalid-array-index'],'changed message'=>['message'=>$issue->message.' Changed.'],
            'missing notes'=>['notes'=>[]],'added note'=>['notes'=>[...$issue->notes,'Changed.']],'missing help'=>['help'=>null],'foreign link'=>['link'=>'https://example.invalid'],
            'edit present'=>['edits'=>[TextEdit::insert(0,' ')]],'annotations missing'=>['annotations'=>[]],'duplicate annotation'=>['annotations'=>[$primary,$primary]],
            'Secondary annotation'=>['annotations'=>[$copy($primary,['kind'=>AnnotationKind::Secondary])]],'foreign Primary file'=>['annotations'=>[$copy($primary,['file'=>'foreign.php'])]],
            'shifted Primary span'=>['annotations'=>[$copy($primary,['span'=>new Span($primary->span->start+1,$primary->span->end)])]],
            'changed Primary message'=>['annotations'=>[$copy($primary,['message'=>'Changed.'])]]] as $name=>$change) { $expect($name,$with($copy($issue,$change)),false); }
        $expect('stale analyzed caller bytes',$with($issue,$context->contents.' '),false);$expect('foreign current caller path',$with($issue,file:'foreign.php'),false);
        $shift=static fn($location)=>$copy($location,['span'=>new Span($location->span->start+1,$location->span->end)]);
        $caller=$certificate['caller']['native']??null;
        if($caller===null) { throw new \RuntimeException('Actual native caller certificate required.'); }
        $mutations=['caller missing'=>[$caller,null],'caller name span changed'=>[$caller,$copy($caller,['nameLocation'=>$shift($caller->nameLocation)])],
            'caller reference return'=>[$caller,$copy($caller,['flags'=>new MetadataFlags($caller->flags->bits|MetadataFlags::BY_REFERENCE)])]];
        if(in_array($family,['identity','factory'],true)) {
            $assertion=$certificate['targetAssertion']['native'];$mutations['target assertion missing']=[$assertion,null];
            $mutations['target assertions absent']=[$assertion,$copy($assertion,['assertions'=>[]])];
            $mutations['target assertions inferred']=[$assertion,$copy($assertion,['assertionsInferred'=>true])];
            $mutations['target assertion staticness contradicted']=[$assertion,$copy($assertion,['static'=>false])];
            $assertions=$assertion->assertions;$assertions[$assertion->parameters[0]->name][]=new TypeAssertion(TypeAssertionKind::IsType,Type::null());
            $mutations['contradictory target postcondition']=[$assertion,$copy($assertion,['assertions'=>$assertions])];
            if($family==='identity') {
                $same=$certificate['genericEquality']['native'];$field=$certificate['physicalTargetField']['native'];$builtin=$certificate['expectedBuiltin']['actual'];
                $mutations['generic equality missing']=[$same,null];$mutations['generic template missing']=[$same,$copy($same,['templates'=>[]])];
                $mutations['generic assertion missing']=[$same,$copy($same,['assertions'=>[]])];$mutations['generic equality inferred']=[$same,$copy($same,['assertionsInferred'=>true])];
                $assertions=$same->assertions;$assertions[$same->parameters[0]->name][]=new TypeAssertion(TypeAssertionKind::IsIdentical,Type::null());
                $mutations['contradictory equality postcondition']=[$same,$copy($same,['assertions'=>$assertions])];
                foreach(['missing'=>null,'integer'=>$copy($same->parameters[1]->type,['type'=>Type::int()]),'doc provenance lost'=>$copy($same->parameters[1]->type,['fromDocblock'=>false])] as $name=>$type) {
                    $formals=$same->parameters;$formals[1]=$copy($formals[1],['type'=>$type]);$mutations['expected template formal '.$name]=[$same,$copy($same,['parameters'=>$formals])];
                }
                $mutations['physical field missing']=[$field,null];$mutations['physical field name moved']=[$field,$copy($field,['nameLocation'=>$shift($field->nameLocation)])];
                $mutations['physical field type contradicted']=[$field,$copy($field,['declaredType'=>$copy($field->declaredType,['type'=>Type::union(Type::int(),Type::null())])])];
                $mutations['basename missing']=[$builtin,null];$mutations['basename nullable return']=[$builtin,$copy($builtin,['returnType'=>$copy($builtin->returnType,['type'=>Type::null()])])];
            } else {
                foreach($certificate['factoryDeclarations'] as $name=>$record) { $mutations['factory declaration absent '.$name]=[$record['actual'],null]; }
                $createNative=$certificate['factoryDeclarations'][$certificate['declaredFactoryMapping']['factory'].'::create']['actual'];
                $countNative=$certificate['factoryDeclarations'][$certificate['declaredFactoryMapping']['factory'].'::count']['actual'];
                $mutations['factory producer nullable return']=[$createNative,$copy($createNative,['returnType'=>$copy($createNative->returnType,['type'=>Type::union($createNative->returnType->type,Type::null())])])];
                $formals=$countNative->parameters;$formals[0]=$copy($formals[0],['type'=>$copy($formals[0]->type,['type'=>Type::string()])]);
                $mutations['count receiving contract changed']=[$countNative,$copy($countNative,['parameters'=>$formals])];
                foreach($certificate['producerClassDeclarations'] as $name=>$record) { $class=$record['actualSourceBinding']['classes'][strtolower($name)]['native'];$mutations['producer class absent '.$name]=[$class,null]; }
                $offset=$certificate['declaredOffsetGet']['actual'];$mutations['declared offsetGet absent']=[$offset,null];
                $mutations['declared element becomes null']=[$offset,$copy($offset,['returnType'=>$copy($offset->returnType,['type'=>Type::null()])])];
                $mutations['offsetGet reference return']=[$offset,$copy($offset,['flags'=>new MetadataFlags($offset->flags->bits|MetadataFlags::BY_REFERENCE)])];
                $physical=$certificate['physicalFactoryModelDefault'];$property=$physical['native'];$default=$property->defaultType;
                if($property===null||$physical['ownerNative']===null||!array_all($physical['stages'],static fn(bool $passed):bool=>$passed)) { throw new \RuntimeException('A completed physical factory default certificate is required.'); }
                $mutations['factory physical model declaration absent']=[$property,null];
                $mutations['factory physical model name changed']=[$property,$copy($property,['name'=>'$other'])];
                $mutations['factory physical model name span moved']=[$property,$copy($property,['nameLocation'=>$shift($property->nameLocation)])];
                $typePrototype=$property->type??$default;
                if($typePrototype===null) { throw new \RuntimeException('Actual model property needs either a declared effective type or a genuine default type for negative replacement controls.'); }
                $mutations['factory physical model declaration foreign file']=[$property,$copy($property,['nameLocation'=>$copy($property->nameLocation,['file'=>'foreign.php'])])];
                $mutations['factory physical model undeclared type location added']=[$property,$copy($property,['location'=>$property->nameLocation])];
                $mutations['factory physical model declared type contradicted']=[$property,$copy($property,['declaredType'=>$copy($typePrototype,['type'=>Type::string()])])];
                $mutations['factory physical model write type contradicted']=[$property,$copy($property,['writeType'=>$copy($typePrototype,['type'=>Type::int()])])];
                if(preg_match('/@(?:phpstan-|psalm-)?var\b/',$physical['physical']['source']['doc'])===1) {
                    $mutations['factory physical documented model effective type absent']=[$property,$copy($property,['type'=>null])];
                }
                $mutations['factory physical model effective type contradicted']=[$property,$copy($property,['type'=>$copy($typePrototype,['type'=>Type::int()])])];
                if($property->type?->fromDocblock) {
                    $mutations['factory physical model effective doc provenance lost']=[$property,$copy($property,['type'=>$copy($property->type,['fromDocblock'=>false])])];
                    $mutations['factory physical model effective doc span moved']=[$property,$copy($property,['type'=>$copy($property->type,['location'=>$shift($property->type->location)])])];
                    $mutations['factory physical model inference profile contradicted']=[$property,$copy($property,['type'=>$copy($property->type,['inferred'=>!$property->type->inferred])])];
                }
                $mutations['factory physical model default flag contradicted']=[$property,$copy($property,['flags'=>new MetadataFlags($property->flags->bits^MetadataFlags::HAS_DEFAULT)])];
                $mutations['factory physical model static replacement']=[$property,$copy($property,['flags'=>new MetadataFlags($property->flags->bits|MetadataFlags::STATIC)])];
                $mutations['factory physical model reference replacement']=[$property,$copy($property,['flags'=>new MetadataFlags($property->flags->bits|MetadataFlags::BY_REFERENCE)])];
                $mutations['factory physical model virtual replacement']=[$property,$copy($property,['flags'=>new MetadataFlags($property->flags->bits|MetadataFlags::VIRTUAL_PROPERTY)])];
                $mutations['factory physical model visibility changed']=[$property,$copy($property,['readVisibility'=>Visibility::Public])];
                $mutations['factory physical model hooks added']=[$property,$copy($property,['hooks'=>['get'=>true]])];
                $mutations['factory physical model default contradicted']=[$property,$copy($property,['defaultType'=>$copy($typePrototype,['type'=>Type::int(),'fromDocblock'=>false])])];
                $appearingProperty=$physical['appearingNative'];
                if($appearingProperty!==null) {
                    $mutations['factory appearing model lookup absent']=[$appearingProperty,null];
                    $mutations['factory appearing model default contradicted']=[$appearingProperty,$copy($appearingProperty,['defaultType'=>$copy($appearingProperty->defaultType??$appearingProperty->type??$typePrototype,['type'=>Type::int(),'fromDocblock'=>false])])];
                    $mutations['factory appearing model declared type contradicted']=[$appearingProperty,$copy($appearingProperty,['declaredType'=>$copy($appearingProperty->type??$typePrototype,['type'=>Type::string()])])];
                }
                $ownerProperty=$physical['ownerNative'];
                $mutations['factory physical owner model lookup absent']=[$ownerProperty,null];
                $mutations['factory physical owner model name changed']=[$ownerProperty,$copy($ownerProperty,['name'=>'$other'])];
                $parentProperty=$physical['parentTemplate']['native'];$baseClass=$physical['parentTemplate']['sourceClass']['native'];
                $mutations['factory original parent generic property absent']=[$parentProperty,null];
                $mutations['factory original parent generic type contradicted']=[$parentProperty,$copy($parentProperty,['type'=>$copy($parentProperty->type,['type'=>Type::int()])])];
                $mutations['factory original parent generic inference contradicted']=[$parentProperty,$copy($parentProperty,['type'=>$copy($parentProperty->type,['inferred'=>true])])];
                $mutations['factory original parent template absent']=[$baseClass,$copy($baseClass,['templates'=>[]])];
                $template=$baseClass->templates[0];
                $mutations['factory original parent template constraint contradicted']=[$baseClass,$copy($baseClass,['templates'=>[$copy($template,['constraint'=>Type::int()])]])];
                if($physical['defaultKind']==='class') {
                    $mutations['factory physical model literal default absent']=[$property,$copy($property,['defaultType'=>null])];
                    $mutations['factory physical model literal default becomes null']=[$property,$copy($property,['defaultType'=>$copy($default,['type'=>Type::null()])])];
                    $mutations['factory physical model literal default loses provenance']=[$property,$copy($property,['defaultType'=>$copy($default,['fromDocblock'=>true])])];
                    $mutations['factory physical model literal default loses inference']=[$property,$copy($property,['defaultType'=>$copy($default,['inferred'=>false])])];
                    $mutations['factory physical model default source span moved']=[$property,$copy($property,['defaultType'=>$copy($default,['location'=>$shift($default->location)])])];
                }
                foreach($physical['classes'] as $class=>$record) {
                    $mutations['factory physical hierarchy declaration absent '.$class]=[$record['native'],null];
                    $mutations['factory physical hierarchy name span moved '.$class]=[$record['native'],$copy($record['native'],['nameLocation'=>$shift($record['native']->nameLocation)])];
                }
            }
        } else {
            $callee=$certificate['callbackCallee']['actual'];$index=$certificate['callbackCallee']['argumentIndex'];
            $mutations['selected callback receiver missing']=[$callee,null];$mutations['selected callback declaration reference return']=[$callee,$copy($callee,['flags'=>new MetadataFlags($callee->flags->bits|MetadataFlags::BY_REFERENCE)])];
            foreach(['reference'=>['flags'=>new MetadataFlags($callee->parameters[$index]->flags->bits|MetadataFlags::BY_REFERENCE)],
                'parameter-out'=>['outType'=>$callee->parameters[$index]->declaredType],
                'declared type lost'=>['declaredType'=>null],
                'declared type location moved'=>['declaredType'=>$copy($callee->parameters[$index]->declaredType,['location'=>$shift($callee->parameters[$index]->declaredType->location)])]] as $name=>$change) {
                $formals=$callee->parameters;$formals[$index]=$copy($formals[$index],$change);$mutations['callback formal '.$name]=[$callee,$copy($callee,['parameters'=>$formals])];
            }
            foreach($certificate['arrayReadBuiltins']??[] as $name=>$record) { $mutations['array reader builtin absent '.$name]=[$record['actual'],null]; }
            if($family==='flag') { $truth=$certificate['assertionTrue']['actual'];$mutations['true assertion absent']=[$truth,null];$mutations['true postcondition absent']=[$truth,$copy($truth,['assertions'=>[]])];
                $assertions=$truth->assertions;$assertions[$truth->parameters[0]->name][]=new TypeAssertion(TypeAssertionKind::IsType,Type::false());
                $mutations['contradictory true postcondition']=[$truth,$copy($truth,['assertions'=>$assertions])]; }
        }
        // Prime every observed source-bound lookup before locating actual variants. No guessed cache keys or opaque closure IDs.
        $cache=(new \ReflectionProperty($context->codebase,'cache'))->getValue($context->codebase);$values=$cache->values;$relations=$cache->relations;
        if($family==='factory'&&$certificate['physicalFactoryModelDefault']['appearingNative']===null) {
            // Prime only the actual selected lookup in an empty, temporary values
            // cache. Read its populated slot; neither operation IDs nor keys are
            // invented and unrelated null entries are never selected for mutation.
            $nullSlots=[];
            try {
                $cache->values=[];$cache->relations=[];
                $factory=$certificate['declaredFactoryMapping']['factory'];
                $actual=$context->codebase->getProperty($factory,'$model');
                if($actual!==null) { throw new \RuntimeException('The genuine inherited child lookup changed before controls.'); }
                foreach($cache->values as $operation=>$entries) { foreach($entries as $key=>$entry) { $nullSlots[]=[$operation,$key,$entry]; } }
                if(count($nullSlots)!==1||$nullSlots[0][2]!==null) { throw new \RuntimeException('Selected inherited absence cache population is not uniquely observable.'); }
            } finally { $cache->values=$values;$cache->relations=$relations; }
            [$operation,$key]=$nullSlots[0];
            try {
                if(!array_key_exists($key,$values[$operation]??[])||$values[$operation][$key]!==null) { throw new \RuntimeException('Primed inherited absence was not present in the original genuine cache.'); }
                $cache->values[$operation][$key]=$certificate['physicalFactoryModelDefault']['native'];
                $expect('inherited child absence replaced by actual owner property',$context,false);
                $variants['inherited child absence replaced by actual owner property']=1;
            } finally { $cache->values=$values;$cache->relations=$relations; }
            $expect('inherited child absence slot restored',$context,true);
        }
        foreach($mutations as $name=>[$observed,$replacement]) {
            $count=0;foreach($values as $operation=>$entries) { foreach($entries as $key=>$entry) { if($entry===$observed||is_object($entry)&&$entry==$observed) { $cache->values[$operation][$key]=$replacement;$count++; } } }
            try { if($count===0) { throw new \RuntimeException('Actual selected cache mutation was vacuous: '.$name); }$expect($name,$context,false);$variants[$name]=$count; }
            finally { $cache->values=$values;$cache->relations=$relations; }
        }
        $expect('all actual native variants restored',$context,true);
        // Modify actual fixture declaration bytes, then restore them. The SDK metadata
        // stays genuinely observed; a new local evaluation must reject the changed source.
        $declarationFile=$this->root.'/contracts.php';$declarationBytes=file_get_contents($declarationFile);
        $sourceMutations=match($family) {
            'identity'=>[
                'physical property type changed'=>['public ?string $value = null','public ?object $value = null'],
                'generic equality postcondition changed'=>['@phpstan-assert =ExpectedType $actual','@phpstan-assert !ExpectedType $actual'],
                'target null postcondition changed'=>['@phpstan-assert !null $actual','@phpstan-assert false $actual'],
            ],
            'factory'=>[
                'producer declared element changed'=>['Collection<int, TModel>','Collection<int, mixed >'],
                'collection physical read changed'=>['return $this->items[$offset];','return $this->items[0];      '],
            ],
            'flag'=>[
                'flag assertion postcondition changed'=>['@phpstan-assert true $actual','@phpstan-assert null $actual'],
                'callback formal physical type changed'=>['?callable $hook = null','?iterable $hook = null'],
            ],
            'key'=>[
                'direct callback dispatch becomes conditional'=>['$output = $next($input);','$output = true ? null : $next($input);'],
                'direct callback formal becomes nullable'=>['\\Closure $next','?\\Closure $next'],
            ],
        };
        if($role==='factory') {
            // Changing only the literal must veto even when the source declaration
            // spans and cached SDK value survive.
            $sourceMutations['literal producer model binding changed']=['protected $model = \\App\\Models\\Specimen::class','protected $model = \\App\\Models\\Missing_::class'];
            $sourceMutations['physical producer model becomes typed']=['protected $model = \\App\\Models\\Specimen::class','protected string $model = \\App\\Models\\Specimen::class'];
            $sourceMutations['projected model generic source binding changed']=['@extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory<\\App\\Models\\Specimen>','@extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory<\\App\\Models\\Missing_>'];
        } elseif($role==='factory-inherited') {
            $sourceMutations['inherited producer alias binding changed']=['use App\\Models\\InheritedSpecimen as ImportedModel;','use App\\Models\\MissingInherited_ as ImportedModel;'];
            $sourceMutations['inherited producer physical initializer changed']=['protected $model = ImportedModel::class;','protected $model = MissingModel_::class;'];
            $sourceMutations['inherited producer parent binding changed']=['final class InheritedSpecimenFactory extends InheritedSpecimenFactoryBase {}','final class InheritedSpecimenFactory extends UninitializedSpecimenFactory {}'];
            $sourceMutations['inherited projected model generic binding changed']=['@extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory<\\App\\Models\\InheritedSpecimen>','@extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory<\\App\\Models\\MissingInherited_>'];
        } elseif($role==='factory-uninitialized') {
            $sourceMutations['uninitialized producer acquires stale model default']=['protected $model;','protected $model = \\App\\Models\\Specimen::class;'];
            $sourceMutations['uninitialized producer declared model template changed']=['@var class-string<TModel>','@var class-string<mixed >'];
            $sourceMutations['uninitialized producer model generic binding changed']=['@extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory<\\App\\Models\\UninitializedSpecimen>','@extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory<\\App\\Models\\Specimen>'];
        }
        if($family==='factory') { $sourceMutations['original parent template model constraint changed']=['@template TModel of \\Illuminate\\Database\\Eloquent\\Model','@template TModel of \\App\\Models\\Specimen']; }
        $sourceVariants=[];
        foreach($sourceMutations as $name=>[$before,$after]) {
            if(substr_count($declarationBytes,$before)!==1) { throw new \RuntimeException('Physical source control needs one observed declaration: '.$name); }
            $changed=str_replace($before,$after,$declarationBytes);
            try { file_put_contents($declarationFile,$changed);$expect($name,$context,false);$sourceVariants[$name]=['beforeSha256'=>hash('sha256',$declarationBytes),'changedSha256'=>hash('sha256',$changed)]; }
            finally { file_put_contents($declarationFile,$declarationBytes); }
        }
        $expect('all actual declaration bytes restored',$context,true);
        file_put_contents($this->output.'/native-controls-'.$role.'.json',json_encode(['originalGenuineSdkIssue'=>DeclaredCapturedWarningObserver::value($issue),
            'sourceSha256'=>hash('sha256',$context->contents),'checks'=>$checks,'actualCacheVariantsChanged'=>$variants,'actualDeclarationSourceChanges'=>$sourceVariants,'positiveNativeDTOsFabricated'=>false],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
        return IssueFilterDecision::Keep;
    }
}
