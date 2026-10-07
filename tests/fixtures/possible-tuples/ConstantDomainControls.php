<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Tests;

use Ichinya\Laramago\Analyzer\PossibleCallbackTupleArgumentFilter;
use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterDecision,Type};
use Mago\Sdk\Analyzer\Metadata\{ClassLikeMetadata,ClassLikeKind,FunctionLikeMetadata,ClassConstantMetadata,MetadataFlags};
use Mago\Sdk\Analyzer\Type\{ReferenceType,ReferenceTypeKind,ReferenceSelectorKind,Visibility,Variance};
use Mago\Sdk\Span;

/** Only selected actual SDK slots after a complete genuine positive, with finally restoration. */
final class PossibleTupleConstantControls
{
    private static function value(mixed $value):mixed
    {
        if($value instanceof \BackedEnum) { return $value->value; }
        if($value instanceof \UnitEnum) { return $value->name; }
        if(is_object($value)) { return self::value(get_object_vars($value)); }
        return is_array($value)?array_map(self::value(...),$value):$value;
    }
    public static function run(string $root,string $output,IssueFilterContext $context,object $initial):void
    {
        $checks=[];$mutations=[];$sourceMutations=[];$dependencies=$initial->dependencies;
        $copy=static function(object $object,array $changes):object { $class=$object::class;return new $class(...array_replace(get_object_vars($object),$changes)); };
        $expect=static function(string $label,IssueFilterContext $input,bool $wanted)use($root,$output,&$checks):void {
            $proof=new PossibleCallbackTupleArgumentFilter($root);$actual=$proof->filterIssue($input)===IssueFilterDecision::Remove;
            if($actual!==$wanted) {
                file_put_contents($output.'/first-failure-constant-domain.json',json_encode(['label'=>$label,'expectedRemove'=>$wanted,
                    'actualRemove'=>$actual,'stages'=>$proof->stages,'dependencies'=>self::value($proof->dependencies),'passed'=>$checks],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
                throw new \RuntimeException('Actual constant domain control failed: '.$label);
            }
            $checks[$label]=true;
        };
        $expect('genuine current constant positive before constructed controls',$context,true);
        $owner=$dependencies['owner']??null;$formal=$owner?->parameters[0]??null;
        $atomic=$formal?->type?->type->atomicTypes[0]??null;
        if(!$owner instanceof FunctionLikeMetadata||!$atomic instanceof ReferenceType) { throw new \RuntimeException('Actual selected member-reference witness is missing.'); }
        file_put_contents($output.'/genuine-constant-native-profile.json',json_encode(['genuineContextOnly'=>true,
            'issue'=>self::value($context->issue),'owner'=>self::value($owner),'selectedFormal'=>self::value($formal),
            'memberReference'=>self::value($atomic),'dependencies'=>self::value($dependencies)],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
        $queue=[];
        $memberChanges=[
            'member kind'=>['kind'=>ReferenceTypeKind::Symbol],
            'member name'=>['member'=>'UNRELATED_'],
            'member selector'=>['selector'=>ReferenceSelectorKind::Identifier],
            'member owner'=>['name'=>'Fixture\\UnrelatedConstantOwner'],
            'member parameters'=>['parameters'=>[Type::int()]],
            'member variances'=>['variances'=>[Variance::Invariant]],
            'member intersections'=>['intersections'=>[Type::namedObject('stdClass')->atomicTypes[0]]],
        ];
        foreach($memberChanges as $label=>$changes) {
            $parameters=$owner->parameters;
            $altered=Type::fromAtomics($copy($atomic,$changes))->withFlags($formal->type->type->flags);
            $parameters[0]=$copy($formal,['type'=>$copy($formal->type,['type'=>$altered])]);
            $queue[$label]=['owner',$owner,$copy($owner,['parameters'=>$parameters])];
        }
        foreach([
            'effective doc origin'=>['fromDocblock'=>false],
            'effective doc span'=>['location'=>$copy($formal->type->location,['span'=>new Span($formal->type->location->span->start+1,$formal->type->location->span->end)])],
            'effective reference flag'=>['type'=>$formal->type->type->withFlags($copy($formal->type->type->flags,['byReference'=>true]))],
        ] as $label=>$changes) {
            $parameters=$owner->parameters;$parameters[0]=$copy($formal,['type'=>$copy($formal->type,$changes)]);
            $queue[$label]=['owner',$owner,$copy($owner,['parameters'=>$parameters])];
        }
        foreach(['declared doc origin'=>['fromDocblock'=>true],'declared inference'=>['inferred'=>true]] as $label=>$changes) {
            $parameters=$owner->parameters;$parameters[0]=$copy($formal,['declaredType'=>$copy($formal->declaredType,$changes)]);
            $queue[$label]=['owner',$owner,$copy($owner,['parameters'=>$parameters])];
        }
        $classRole='constant-owner:'.strtolower($owner->identifier->class);$class=$dependencies[$classRole]??null;
        if(!$class instanceof ClassLikeMetadata) { throw new \RuntimeException('Actual selected constant owner metadata is missing.'); }
        foreach([
            'constant class absent'=>null,
            'constant class identity'=>$copy($class,['name'=>'Fixture\\UnrelatedConstantOwner']),
            'constant class original identity'=>$copy($class,['originalName'=>'Fixture\\UnrelatedConstantOwner']),
            'constant class kind'=>$copy($class,['kind'=>ClassLikeKind::Trait]),
            'constant class incomplete'=>$copy($class,['unresolvedHierarchyDependencies'=>['Fixture\\MissingParent']]),
            'constant class trait'=>$copy($class,['usedTraits'=>['Fixture\\ForeignConstants']]),
            'constant class parent'=>$copy($class,['directParentClass'=>'Fixture\\ForeignConstants']),
        ] as $label=>$replacement) { $queue[$label]=[$classRole,$class,$replacement]; }
        $constants=array_filter($dependencies,static fn(string $role):bool=>str_starts_with($role,'constant:'),ARRAY_FILTER_USE_KEY);
        if(count($constants)!==2) { throw new \RuntimeException('Both selected actual constants are required before controls.'); }
        foreach($constants as $role=>$constant) {
            if(!$constant instanceof ClassConstantMetadata) { throw new \RuntimeException('Actual constant contract is missing.'); }
            foreach([
                'absent'=>null,
                'identity'=>$copy($constant,['name'=>'UNRELATED_CONSTANT']),
                'span'=>$copy($constant,['location'=>$copy($constant->location,['span'=>new Span($constant->location->span->start+1,$constant->location->span->end)])]),
                'visibility'=>$copy($constant,['visibility'=>Visibility::Private]),
                'flags'=>$copy($constant,['flags'=>new MetadataFlags($constant->flags->bits|MetadataFlags::BY_REFERENCE)]),
                'missing inferred literal'=>$copy($constant,['inferredType'=>null]),
                'different inferred literal'=>$copy($constant,['inferredType'=>Type::literalString('changed-current-value')]),
            ] as $label=>$replacement) { $queue[$role.' '.$label]=[$role,$constant,$replacement]; }
        }
        if(count($queue)!==33) { throw new \RuntimeException('Exact bounded cache control queue changed.'); }
        $cache=(new \ReflectionProperty($context->codebase,'cache'))->getValue($context->codebase);
        foreach($queue as $label=>[$role,$metadata,$replacement]) {
            $expect('genuine positive immediately before '.$label,$context,true);
            $aliases=match(true) {
                $metadata instanceof FunctionLikeMetadata=>[$context->codebase->getMethod($metadata->identifier->class,$metadata->identifier->name),
                    $context->codebase->getDeclaringMethod($metadata->identifier->class,$metadata->identifier->name)],
                $metadata instanceof ClassLikeMetadata=>[$context->codebase->getClass($metadata->name)],
                $metadata instanceof ClassConstantMetadata=>[$context->codebase->getClassConstant($owner->identifier->class,$metadata->name)],
                default=>throw new \RuntimeException('Unexpected actual contract family.'),
            };
            $current=null;
            foreach($aliases as $alias) { if($alias===null) { continue; }if($alias!=$metadata) { throw new \RuntimeException('Current genuine aliases disagree: '.$label); }$current??=$alias; }
            if($current===null||$current==$replacement) { throw new \RuntimeException('Cache mutation is absent or meaningless: '.$label); }
            $saved=$cache->values;$relations=$cache->relations;$slots=[];
            foreach($saved as $operation=>$entries) { foreach($entries as $key=>$entry) {
                if(is_object($entry)&&$entry::class===$current::class&&$entry==$current) {
                    $cache->values[$operation][$key]=$replacement;$slots[]=['operation'=>$operation,'key'=>$key];
                }
            } }
            try { if($slots===[]) { throw new \RuntimeException('No populated canonical SDK slot: '.$label); }$expect('actual native cache mutation '.$label,$context,false); }
            finally { $cache->values=$saved;$cache->relations=$relations; }
            $expect('native contracts restored after '.$label,$context,true);
            $mutations[$label]=['selectedRole'=>$role,'actualSlots'=>$slots,'meaningfulMutation'=>true,'restored'=>true];
        }
        $path=$root.'/'.$context->file;$original=file_get_contents($path);
        foreach(["LABEL_FIRST='alpha'"=>"LABEL_FIRST='omega'","LABEL_SECOND='beta'"=>"LABEL_SECOND='zeta'"] as $from=>$to) {
            if(substr_count($original,$from)!==1||strlen($from)!==strlen($to)) { throw new \RuntimeException('Expected one selected same-length source initializer.'); }
            $label='physical initializer '.$from;$expect('genuine positive before '.$label,$context,true);
            $changed=str_replace($from,$to,$original);file_put_contents($path,$changed);
            try {
                $input=new IssueFilterContext($context->phpVersion,$context->codebase,$context->types,$context->cancellation,$context->file,$changed,$context->issue);
                $expect('changed physical source with retained genuine native cache '.$label,$input,false);
            } finally { file_put_contents($path,$original); }
            $expect('physical initializer restored '.$label,$context,true);
            $sourceMutations[$label]=['sameByteLength'=>true,'currentSourceChanged'=>true,'cachedMetadataRetained'=>true,'restored'=>true,'nativeAcceptanceClaimed'=>false];
        }
        $expect('all genuine native and source contracts restored',$context,true);
        file_put_contents($output.'/controls-constant-domain.json',json_encode(['genuinePositive'=>true,'restored'=>true,
            'noVacuousControls'=>true,'allChecks'=>$checks,'cacheMutations'=>$mutations,'sourceMutations'=>$sourceMutations,
            'constructedInputNativeAcceptanceClaimed'=>false],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
    }
}
