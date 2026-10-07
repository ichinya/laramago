<?php
declare(strict_types=1);
namespace Example\LocalModelTests;

use Ichinya\Laramago\Analyzer\GuardedLocalModelTupleArgumentFilter;
use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterDecision,IssueFilterHook,Plugin,PluginDefinition,PluginRegistry,Type};
use Mago\Sdk\Analyzer\Metadata\{ClassLikeMetadata,ClassLikeKind,FunctionLikeMetadata,FunctionLikeKind,MetadataFlags,PropertyMetadata};
use Mago\Sdk\Analyzer\Type\{NamedObjectType,Variance};
use Mago\Sdk\Span;

require_once __DIR__.'/SourceControls.php';

/** Every returned issue stays visible; controls require an actual admitted frame first. */
final class LocalModelNativeControls implements Plugin
{
    public function __construct(private readonly string $root,private readonly string $output){}
    public function getDefinition():PluginDefinition{return new PluginDefinition('fixture/guarded-local-model-controls',
        'Guarded local model native controls','Always-Keep observer with restored selected SDK and physical-source controls.');}
    public function register(PluginRegistry $registry):void{$registry->registerIssueFilterHook(new LocalModelNativeControlHook($this->root,$this->output));}
}

final class LocalModelNativeControlHook implements IssueFilterHook
{
    private bool $testing=false;private bool $completed=false;private int $sequence=0;
    private array $checks=[];private array $cacheMutations=[];private array $sourceMutations=[];private array $failedAssertions=[];
    public function __construct(private readonly string $root,private readonly string $output){}
    public function getCodes():array{return ['mixed-argument'];}
    private static function value(mixed $value):mixed
    {
        if($value instanceof \BackedEnum){return $value->value;}if($value instanceof \UnitEnum){return $value->name;}
        if($value instanceof Type){return ['display'=>(string)$value,'flags'=>self::value($value->flags),'atomicTypes'=>self::value($value->atomicTypes)];}
        if(is_object($value)){return self::value(get_object_vars($value));}
        return is_array($value)?array_map(self::value(...),$value):$value;
    }
    private function write(string $name,array $value):void
    {
        if(!is_dir($this->output)&&!mkdir($this->output,0777,true)&&!is_dir($this->output)){throw new \RuntimeException('Cannot create native control output.');}
        file_put_contents($this->output.'/'.$name,json_encode(self::value($value),JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
    }
    private static function copy(object $value,array $fields):object
    {
        if($value instanceof Type){throw new \RuntimeException('Use public Type factories and withFlags; never copy a Type payload.');}
        $class=$value::class;return new $class(...array_replace(get_object_vars($value),$fields));
    }
    private static function changedLocation(object $location):object
    {
        if($location->span->end<=$location->span->start){throw new \RuntimeException('A genuine nonempty native span is required.');}
        return self::copy($location,['span'=>new Span($location->span->start+1,$location->span->end)]);
    }
    private static function changedType(Type $type,array $fields):Type
    {
        return Type::fromAtomics(...$type->atomicTypes)->withFlags(self::copy($type->flags,$fields));
    }
    public function filterIssue(IssueFilterContext $context):IssueFilterDecision
    {
        $entry=['eventId'=>getmypid().':'.(++$this->sequence),'genuineIssueFilterContext'=>true,
            'documentaryEventIdOnly'=>true,'file'=>$context->file,'sourceSha256'=>hash('sha256',$context->contents),
            'issue'=>self::value($context->issue),'controlsActive'=>$this->testing];
        if(!is_dir($this->output)){mkdir($this->output,0777,true);}
        file_put_contents($this->output.'/entries.jsonl',json_encode($entry,JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
        if($this->testing){
            file_put_contents($this->output.'/completions.jsonl',json_encode([...$entry,'candidateEvaluated'=>false,
                'observerDecision'=>'Keep','reason'=>'Controls serialize actual cache mutations and keep this concurrent genuine context.'],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
            return IssueFilterDecision::Keep;
        }
        $proof=new GuardedLocalModelTupleArgumentFilter($this->root);$decision=$proof->filterIssue($context);
        $completion=[...$entry,'candidateEvaluated'=>true,'candidateDecision'=>$decision->name,'observerDecision'=>'Keep',
            'stages'=>$proof->stages,'certificate'=>$proof->certificate];
        file_put_contents($this->output.'/completions.jsonl',json_encode(self::value($completion),JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
        if($this->completed||$context->file!=='positive.php'||($proof->dependencies['owner']?->identifier->name??null)!=='guardedLocal'){
            return IssueFilterDecision::Keep;
        }
        $this->write('initial.json',[...$completion,'dependencies'=>$proof->dependencies]);
        if($decision!==IssueFilterDecision::Remove){throw new \RuntimeException('The selected genuine guardedLocal positive must Remove before controls.');}
        $this->testing=true;
        try{$this->run($context,$proof);$this->completed=true;}finally{$this->testing=false;}
        return IssueFilterDecision::Keep;
    }
    private function expect(string $label,IssueFilterContext $context,bool $wanted):GuardedLocalModelTupleArgumentFilter
    {
        $proof=new GuardedLocalModelTupleArgumentFilter($this->root);$decision=$proof->filterIssue($context);
        if(($decision===IssueFilterDecision::Remove)!==$wanted){
            $this->checks[$label]=false;
            if(!is_file($this->output.'/first-failure.json')){
                $this->write('first-failure.json',['label'=>$label,'expectedRemove'=>$wanted,'actualDecision'=>$decision->name,
                    'genuineSelectedSourceFile'=>$context->file,'issue'=>$context->issue,'stages'=>$proof->stages,
                    'certificate'=>$proof->certificate,'dependencies'=>$proof->dependencies,'allChecks'=>$this->checks,
                    'cacheMutations'=>$this->cacheMutations,'sourceMutations'=>$this->sourceMutations]);
            }
            // Only an unexpected admission of the deliberately changed negative
            // may continue. The caller's finally always restores actual slots or
            // bytes, then a fresh genuine Remove is still mandatory.
            if(!$wanted){
                $failure=['label'=>$label,'expectedRemove'=>false,'actualDecision'=>$decision->name];
                $this->failedAssertions[$label]=$failure;
                file_put_contents($this->output.'/failed-assertions.jsonl',json_encode($failure,JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
                return $proof;
            }
            throw new \RuntimeException('Guarded local model native control failed: '.$label);
        }
        $this->checks[$label]=true;return $proof;
    }
    /** Exact public lookup bindings from the completed certificate, plus actual selected owners. */
    private function contracts(GuardedLocalModelTupleArgumentFilter $proof):array
    {
        $bindings=[];
        foreach($proof->certificate['modelCertificates']??[] as $index=>$model){
            foreach($model['declaration']['bindings']??[] as $role=>$binding){$bindings['model '.$index.': '.$role]=$binding;}
        }
        $contracts=[];
        foreach($proof->dependencies as $role=>$metadata){
            if($metadata===null||is_array($metadata)){continue;}
            if($metadata instanceof ClassLikeMetadata){$binding=$bindings[$role]??['kind'=>'class','name'=>$metadata->name];}
            elseif($metadata instanceof FunctionLikeMetadata){
                if($metadata->kind!==FunctionLikeKind::Method||!is_string($metadata->identifier->class)||$metadata->identifier->class===''){
                    throw new \RuntimeException('Only genuinely selected named method dependencies can be controlled.');
                }
                $binding=$bindings[$role]??['kind'=>'method','class'=>$metadata->identifier->class,'name'=>$metadata->identifier->name];
                // Bound/declaring dependency roles use the same already proved query binding.
                foreach([':bound',':declaring'] as $suffix){if(str_ends_with($role,$suffix)&&isset($bindings[substr($role,0,-strlen($suffix))])){
                    $binding=$bindings[substr($role,0,-strlen($suffix))];break;
                }}
            }elseif($metadata instanceof PropertyMetadata){
                $binding=$bindings[$role]??null;
                if($binding===null||$binding['kind']!=='property'||$binding['name']!==$metadata->name){
                    throw new \RuntimeException('A property requires its exact genuine lookup binding.');
                }
            }else{throw new \RuntimeException('Unexpected genuine dependency DTO: '.$role);}
            $key=$binding['kind'].':'.strtolower($binding['class']??$binding['name']);
            if($binding['kind']!=='class'){$key.='::'.($binding['kind']==='property'?$binding['name']:strtolower($binding['name']));}
            if(isset($contracts[$key])){$contracts[$key]['roles'][]=$role;continue;}
            $contracts[$key]=['binding'=>$binding,'metadata'=>$metadata,'roles'=>[$role]];
        }
        foreach(['owner','receiving','model 0: localWhere','model 0: forwarded:lockForUpdate','model 0: forwarded:lock',
            'model 0: forwardedBuilderCall','model 0: localRead:0','model 0: first','model 0: get','model 0: collectionFirst',
            'model 0: $builder','model 0: $collectionClass'] as $required){
            if(!isset($proof->dependencies[$required])||!is_object($proof->dependencies[$required])){
                throw new \RuntimeException('Required genuine selected dependency is absent: '.$required);
            }
        }
        return $contracts;
    }
    /** All actual non-null aliases are fetched immediately before the cache snapshot. */
    private function aliases(IssueFilterContext $context,array $binding):array
    {
        $codebase=$context->codebase;
        if($binding['kind']==='class'){
            $selected=$codebase->getClassLike($binding['name']);$aliases=['class-like'=>$selected];
            if($selected!==null){$aliases['specific']=match($selected->kind){ClassLikeKind::Class_=>$codebase->getClass($binding['name']),
                ClassLikeKind::Trait=>$codebase->getTrait($binding['name']),ClassLikeKind::Interface=>$codebase->getInterface($binding['name']),
                ClassLikeKind::Enum=>$codebase->getEnum($binding['name'])};}
            return $aliases;
        }
        if($binding['kind']==='property'){return ['bound'=>$codebase->getProperty($binding['class'],$binding['name']),
            'declaring'=>$codebase->getDeclaringProperty($binding['class'],$binding['name'])];}
        $aliases=['bound'=>$codebase->getMethod($binding['class'],$binding['name']),
            'declaring'=>$codebase->getDeclaringMethod($binding['class'],$binding['name'])];
        foreach(['bound','declaring'] as $role){if($aliases[$role]!==null){
            $aliases['function-like-'.$role]=$codebase->getFunctionLike($aliases[$role]->identifier);
        }}
        return $aliases;
    }
    private static function snapshot(object $cache):array
    {
        $result=[];foreach(['values','lists','existence','relations','methodProjections','typeComparisons'] as $field){$result[$field]=$cache->$field;}return $result;
    }
    private static function restore(object $cache,array $saved):void{foreach($saved as $field=>$value){$cache->$field=$value;}}
    private function cacheControl(IssueFilterContext $context,object $cache,string $label,array $binding,\Closure $change):void
    {
        $this->expect('genuine Remove before '.$label,$context,true);
        $aliases=$this->aliases($context,$binding);$nonNull=array_filter($aliases,static fn($value):bool=>$value!==null);
        if($nonNull===[]){throw new \RuntimeException('Selected contract disappeared before mutation: '.$label);}
        $saved=self::snapshot($cache);$slots=[];$matched=[];
        try{
            foreach($saved['values'] as $operation=>$entries){foreach($entries as $key=>$entry){
                if(!is_object($entry)){continue;}$roles=[];
                foreach($nonNull as $role=>$alias){if($entry===$alias||$entry::class===$alias::class&&$entry==$alias){$roles[]=$role;}}
                if($roles===[]){continue;}$replacement=$change($entry);
                if($entry==$replacement){throw new \RuntimeException('Cache mutation is absent or meaningless: '.$label);}
                $cache->values[$operation][$key]=$replacement;$slots[]=['operation'=>$operation,'key'=>$key,'actualAliases'=>$roles];
                foreach($roles as $role){$matched[$role]=true;}
            }}
            foreach($nonNull as $role=>$unused){if(!isset($matched[$role])){throw new \RuntimeException('A genuine primed alias has no actual populated slot: '.$label.' '.$role);}}
            if($slots===[]){throw new \RuntimeException('No genuine selected canonical cache slot: '.$label);}
            $this->expect('actual SDK mutation Keeps '.$label,$context,false);
        }finally{self::restore($cache,$saved);}
        $this->expect('exact SDK restoration Removes '.$label,$context,true);
        $this->cacheMutations[$label]=['binding'=>$binding,'actualSlots'=>$slots,'meaningfullyChanged'=>true,
            'actualPrimedAliases'=>array_keys($aliases),'nonNullAliasesMutated'=>array_keys($nonNull),'restored'=>true];
    }
    private function queue(array $contracts):array
    {
        $queue=[];$root=$this->root;$add=static function(string $label,array $binding,\Closure $change)use(&$queue):void{
            if(isset($queue[$label])){throw new \RuntimeException('Duplicate native control label.');}$queue[$label]=[$binding,$change];
        };
        foreach($contracts as $key=>$contract){
            $binding=$contract['binding'];$metadata=$contract['metadata'];$role=$contract['roles'][0];
            $add($key.' absent',$binding,static fn(object $native):null=>null);
            if($metadata instanceof ClassLikeMetadata){
                $add($key.' changed identity',$binding,static fn(object $native):object=>self::copy($native,['name'=>'Example\\OtherDeclaration']));
                $add($key.' changed kind',$binding,static fn(object $native):object=>self::copy($native,['kind'=>$native->kind===ClassLikeKind::Trait?ClassLikeKind::Class_:ClassLikeKind::Trait]));
                $add($key.' displaced name',$binding,static fn(object $native):object=>self::copy($native,['nameLocation'=>self::changedLocation($native->nameLocation)]));
            }elseif($metadata instanceof FunctionLikeMetadata){
                $add($key.' changed identity',$binding,static fn(object $native):object=>self::copy($native,['identifier'=>self::copy($native->identifier,['name'=>'otherMethod'])]));
                $add($key.' changed kind',$binding,static fn(object $native):object=>self::copy($native,['kind'=>FunctionLikeKind::Function_]));
                $add($key.' reference return',$binding,static fn(object $native):object=>self::copy($native,['flags'=>new MetadataFlags($native->flags->bits|MetadataFlags::BY_REFERENCE)]));
                $add($key.' displaced declaration',$binding,static fn(object $native):object=>self::copy($native,['location'=>self::changedLocation($native->location)]));
                if($metadata->parameters!==[]){
                    $add($key.' changed formal name',$binding,static function(object $native):object{
                        $parameters=$native->parameters;$parameters[0]=self::copy($parameters[0],['name'=>'$otherInput']);return self::copy($native,['parameters'=>$parameters]);
                    });
                    $add($key.' reference formal',$binding,static function(object $native):object{
                        $parameters=$native->parameters;$parameters[0]=self::copy($parameters[0],['flags'=>new MetadataFlags($parameters[0]->flags->bits|MetadataFlags::BY_REFERENCE)]);return self::copy($native,['parameters'=>$parameters]);
                    });
                }
            }elseif($metadata instanceof PropertyMetadata){
                $add($key.' changed name',$binding,static fn(object $native):object=>self::copy($native,['name'=>'$otherField']));
                $add($key.' instance default field',$binding,static fn(object $native):object=>self::copy($native,['flags'=>new MetadataFlags($native->flags->bits&~MetadataFlags::STATIC)]));
                $add($key.' displaced default',$binding,static fn(object $native):object=>self::copy($native,['defaultType'=>self::copy($native->defaultType,['location'=>self::changedLocation($native->defaultType->location)])]));
                $add($key.' changed default class',$binding,static fn(object $native):object=>self::copy($native,['defaultType'=>self::copy($native->defaultType,['type'=>Type::string()])]));
                $add($key.' documented default',$binding,static fn(object $native):object=>self::copy($native,['defaultType'=>self::copy($native->defaultType,['fromDocblock'=>true])]));
                $add($key.' changed PHP field type',$binding,static fn(object $native):object=>self::copy($native,['declaredType'=>self::copy($native->declaredType,['type'=>Type::int()])]));
                $add($key.' changed effective field origin',$binding,static fn(object $native):object=>self::copy($native,['type'=>self::copy($native->type,['fromDocblock'=>false])]));
            }
        }
        $find=static function(string $kind,string $class,?string $name=null)use($contracts):array{
            foreach($contracts as $contract){$binding=$contract['binding'];if($binding['kind']===$kind
                &&strcasecmp($binding['class']??$binding['name'],$class)===0&&($name===null||$binding['name']===$name)){return $contract;}}
            throw new \RuntimeException('Required selected control binding is absent: '.$class.'::'.$name);
        };
        $builder=$find('class','Illuminate\\Database\\Eloquent\\Builder');
        $collection=$find('class','Illuminate\\Database\\Eloquent\\Collection');$support=$find('class','Illuminate\\Support\\Collection');
        $enumerates=$find('class','Illuminate\\Support\\Traits\\EnumeratesValues');
        $conditionable=$find('class','Illuminate\\Support\\Traits\\Conditionable');
        $macroable=$find('class','Illuminate\\Support\\Traits\\Macroable');
        $conditionName='illuminate\\support\\traits\\conditionable';
        if($enumerates['metadata']->kind!==ClassLikeKind::Trait
            ||array_map(strtolower(...),$enumerates['metadata']->usedTraits)!==[$conditionName]
            ||$conditionable['metadata']->kind!==ClassLikeKind::Trait||$conditionable['metadata']->usedTraits!==[]
            ||$macroable['metadata']->kind!==ClassLikeKind::Trait
            ||strcasecmp($macroable['metadata']->name,'Illuminate\\Support\\Traits\\Macroable')!==0){
            throw new \RuntimeException('The two nested controls require their exact genuine current trait profiles.');
        }
        $add('EnumeratesValues missing actual Conditionable trait',$enumerates['binding'],static function(object $native)use($conditionName):object{
            if($native->kind!==ClassLikeKind::Trait||array_map(strtolower(...),$native->usedTraits)!==[$conditionName]){
                throw new \RuntimeException('A primed EnumeratesValues alias lacks its actual singleton Conditionable trait.');
            }
            return self::copy($native,['usedTraits'=>[]]);
        });
        $knownMacroable=$macroable['metadata']->name;
        $add('Conditionable gains known Macroable trait',$conditionable['binding'],static function(object $native)use($knownMacroable):object{
            if($native->kind!==ClassLikeKind::Trait||$native->usedTraits!==[]){
                throw new \RuntimeException('A primed Conditionable alias must genuinely have no used traits.');
            }
            return self::copy($native,['usedTraits'=>[$knownMacroable]]);
        });
        foreach(['Builder'=>$builder,'Collection'=>$collection,'Support'=>$support] as $label=>$contract){
            $binding=$contract['binding'];
            $add($label.' extra trait',$binding,static fn(object $native):object=>self::copy($native,['usedTraits'=>[...$native->usedTraits,'Example\\UnexpectedTrait']]));
            $add($label.' wrong parent',$binding,static fn(object $native):object=>self::copy($native,['directParentClass'=>'Example\\UnexpectedParent']));
            if($contract['metadata']->usedTraits===[]){throw new \RuntimeException('The selected trait graph must be nonempty: '.$label);}
            foreach($contract['metadata']->usedTraits as $index=>$trait){
                $add($label.' missing trait '.$trait,$binding,static function(object $native)use($index):object{
                    $traits=$native->usedTraits;array_splice($traits,$index,1);return self::copy($native,['usedTraits'=>$traits]);
                });
            }
        }
        $mixins=$builder['metadata']->mixins;if(count($mixins)!==3){throw new \RuntimeException('The genuine Builder must supply the exact three-source mixin multiset.');}
        $add('Builder missing mixin multiset',$builder['binding'],static fn(object $native):object=>self::copy($native,['mixins'=>[]]));
        foreach($mixins as $index=>$mixin){
            $add('Builder missing mixin entry '.$index,$builder['binding'],static function(object $native)use($index):object{
                $mixins=$native->mixins;array_splice($mixins,$index,1);return self::copy($native,['mixins'=>$mixins]);
            });
        }
        $add('Builder duplicate extra mixin',$builder['binding'],static fn(object $native):object=>self::copy($native,['mixins'=>[...$native->mixins,$native->mixins[0]]]));
        $add('Builder wrong mixin class',$builder['binding'],static function(object $native):object{
            $mixins=$native->mixins;$mixins[0]=Type::namedObject('stdClass');return self::copy($native,['mixins'=>$mixins]);
        });
        $add('Builder generic mixin',$builder['binding'],static function(object $native):object{
            $mixins=$native->mixins;$type=$mixins[0];$atom=$type->atomicTypes[0];
            if(!$atom instanceof NamedObjectType){throw new \RuntimeException('A genuine plain named mixin is required.');}
            $mixins[0]=Type::fromAtomic(self::copy($atom,['parameters'=>[Type::int()],'variances'=>null]))->withFlags($type->flags);
            return self::copy($native,['mixins'=>$mixins]);
        });
        foreach(['Collection'=>$collection,'Support'=>$support] as $label=>$contract){
            $add($label.' unexpected mixin',$contract['binding'],static fn(object $native):object=>self::copy($native,['mixins'=>[Type::namedObject('stdClass')]]));
        }
        foreach(['Illuminate\\Database\\Eloquent\\Builder','Illuminate\\Database\\Concerns\\BuildsQueries',
            'Illuminate\\Database\\Eloquent\\Collection','Illuminate\\Support\\Collection'] as $class){
            $contract=$find('class',$class);
            if($contract['metadata']->templates===[]){throw new \RuntimeException('Current generic templates are required: '.$class);}
            foreach($contract['metadata']->templates as $index=>$template){
                foreach(['readonly','owner','constraint','variance'] as $field){
                    $add($class.' template '.$index.' '.$field,$contract['binding'],static function(object $native)use($index,$field):object{
                        $templates=$native->templates;$template=$templates[$index];$changes=match($field){
                            'readonly'=>['readonly'=>!$template->readonly],
                            'owner'=>['definingEntity'=>self::copy($template->definingEntity,['name'=>'Example\\OtherOwner'])],
                            'constraint'=>['constraint'=>Type::int()],
                            'variance'=>['variance'=>$template->variance===Variance::Invariant?Variance::Covariant:Variance::Invariant]};
                        $templates[$index]=self::copy($template,$changes);return self::copy($native,['templates'=>$templates]);
                    });
                }
            }
        }
        foreach([['Illuminate\\Database\\Eloquent\\Builder','where'],['Illuminate\\Database\\Query\\Builder','lockForUpdate'],
            ['Illuminate\\Database\\Query\\Builder','lock']] as [$class,$name]){
            $contract=$find('method',$class,$name);$type=$contract['metadata']->returnType?->type;$atom=$type?->atomicTypes[0]??null;
            if($type===null||count($type->atomicTypes)!==1||!$atom instanceof NamedObjectType||$atom->name!=='$this'||!$atom->static||!$atom->isThis){
                throw new \RuntimeException('The genuine current $this marker is required: '.$name);
            }
            foreach(['name','static','isThis','parameters','variances','intersections','remapping'] as $field){
                $add($class.'::'.$name.' member '.$field,$contract['binding'],static function(object $native)use($field):object{
                    $type=$native->returnType->type;$atom=$type->atomicTypes[0];$changes=match($field){
                        'name'=>['name'=>'stdClass'],'static'=>['static'=>false],'isThis'=>['isThis'=>false],
                        'parameters'=>['parameters'=>[Type::int()],'variances'=>null],
                        'variances'=>['parameters'=>null,'variances'=>[Variance::Invariant]],
                        'intersections'=>['intersections'=>[Type::namedObject('stdClass')->atomicTypes[0]]],
                        'remapping'=>['remappedParameters'=>true]};
                    $changed=Type::fromAtomic(self::copy($atom,$changes))->withFlags($type->flags);
                    return self::copy($native,['returnType'=>self::copy($native->returnType,['type'=>$changed])]);
                });
            }
        }
        foreach([['Illuminate\\Database\\Eloquent\\Builder','first'],['Illuminate\\Database\\Eloquent\\Builder','get'],
            ['Illuminate\\Database\\Eloquent\\Collection','first']] as [$class,$name]){
            $contract=$find('method',$class,$name);$binding=$contract['binding'];
            $add($class.'::'.$name.' return doc origin',$binding,static fn(object $native):object=>self::copy($native,['returnType'=>self::copy($native->returnType,['fromDocblock'=>false])]));
            $add($class.'::'.$name.' return doc span',$binding,static fn(object $native):object=>self::copy($native,['returnType'=>self::copy($native->returnType,['location'=>self::changedLocation($native->returnType->location)])]));
            $add($class.'::'.$name.' reference result type',$binding,static fn(object $native):object=>self::copy($native,['returnType'=>self::copy($native->returnType,['type'=>self::changedType($native->returnType->type,['byReference'=>true])])]));
            foreach($contract['metadata']->parameters as $index=>$formal){
                if($formal->defaultType===null){throw new \RuntimeException('A selected current default must be present: '.$name);}
                foreach(['span','file','expression-only','doc-origin','value'] as $field){
                    $add($class.'::'.$name.' default '.$index.' '.$field,$binding,static function(object $native)use($index,$field,$root):object{
                        $parameters=$native->parameters;$default=$parameters[$index]->defaultType;
                        $change=match($field){'span'=>['location'=>self::changedLocation($default->location)],
                            'file'=>['location'=>self::copy($default->location,['file'=>'foreign.php'])],
                            'expression-only'=>['location'=>self::expressionOnlyDefault($default->location,$root)],
                            'doc-origin'=>['fromDocblock'=>true],'value'=>['type'=>Type::int()]};
                        $parameters[$index]=self::copy($parameters[$index],['defaultType'=>self::copy($default,$change)]);
                        return self::copy($native,['parameters'=>$parameters]);
                    });
                }
            }
        }
        return $queue;
    }
    private static function expressionOnlyDefault(object $location,string $root):object
    {
        $file=$location->file;if(is_string($file)&&!is_file($file)){$file=$root.'/'.$file;}
        $contents=is_string($file)&&is_file($file)?file_get_contents($file):false;
        if($contents===false){throw new \RuntimeException('The native parameter default file must be physically readable.');}
        $segment=substr($contents,$location->span->start,$location->span->end-$location->span->start);
        if(preg_match('/^=\s*/',$segment,$match)!==1){throw new \RuntimeException('The actual default must retain its equals-inclusive span.');}
        return self::copy($location,['span'=>new Span($location->span->start+strlen($match[0]),$location->span->end)]);
    }
    /** An absent slot is learned from one real query in an empty temporary values map. */
    private function absentScopeControl(IssueFilterContext $context,object $cache,string $class,FunctionLikeMetadata $replacement):void
    {
        $name='scopeLockForUpdate';$label=$class.'::'.$name.' genuine absent lookup override';
        $this->expect('genuine Remove before '.$label,$context,true);$slots=[];
        foreach(['getMethod','getDeclaringMethod'] as $api){
            if($context->codebase->$api($class,$name)!==null){throw new \RuntimeException('The current selected scope must be genuinely absent.');}
            $before=self::snapshot($cache);
            try{
                $cache->values=[];
                if($context->codebase->$api($class,$name)!==null){throw new \RuntimeException('The primed native scope query must be genuinely absent.');}
                $published=[];foreach($cache->values as $operation=>$entries){foreach($entries as $key=>$value){
                    if($value!==null){throw new \RuntimeException('An absent scope query published an unexpected metadata object.');}
                    $published[]=['operation'=>$operation,'key'=>$key,'api'=>$api];
                }}
                if(count($published)!==1){throw new \RuntimeException('The single actual absent lookup slot must be unambiguous.');}
                $slots[]=$published[0];
            }finally{self::restore($cache,$before);}
        }
        $saved=self::snapshot($cache);
        try{
            foreach($slots as $slot){
                if(!array_key_exists($slot['key'],$saved['values'][$slot['operation']]??[])
                    ||$saved['values'][$slot['operation']][$slot['key']]!==null){
                    throw new \RuntimeException('The exact genuinely primed absent slot is not current.');
                }
                // Negative metadata only: a real selected declaration cannot be a new scope.
                $cache->values[$slot['operation']][$slot['key']]=$replacement;
            }
            $this->expect('actual SDK mutation Keeps '.$label,$context,false);
        }finally{self::restore($cache,$saved);}
        $this->expect('exact SDK restoration Removes '.$label,$context,true);
        $this->cacheMutations[$label]=['binding'=>['kind'=>'method','class'=>$class,'name'=>$name],
            'actualSlots'=>$slots,'genuinelyAbsentBefore'=>true,'primedByActualPublicApis'=>true,
            'meaningfullyChanged'=>true,'restored'=>true,'negativeMetadataAsPositiveAuthority'=>false];
    }
    private function run(IssueFilterContext $context,GuardedLocalModelTupleArgumentFilter $initial):void
    {
        $this->expect('genuine selected native positive before all controls',$context,true);
        $contracts=$this->contracts($initial);$queue=$this->queue($contracts);
        $cache=(new \ReflectionProperty($context->codebase,'cache'))->getValue($context->codebase);
        foreach($queue as $label=>[$binding,$change]){$this->cacheControl($context,$cache,$label,$binding,$change);}
        $replacement=$initial->dependencies['model 0: forwarded:lockForUpdate'];
        $model=$initial->certificate['modelCertificates'][0]['model']??null;
        if(!is_string($model)||$model===''){throw new \RuntimeException('The current selected model identity is required.');}
        foreach(['Illuminate\\Database\\Eloquent\\Builder',$model] as $class){$this->absentScopeControl($context,$cache,$class,$replacement);}
        $hashes=$initial->certificate['sourceHashes']??[];
        $plans=LocalModelSourceControls::plan($this->root,$context,$contracts,$hashes);
        foreach($plans as $label=>$plan){
            $this->expect('genuine Remove before physical '.$label,$context,true);
            if(file_get_contents($plan['file'])!==$plan['original']){throw new \RuntimeException('Selected source changed before physical control: '.$label);}
            $saved=self::snapshot($cache);
            try{
                file_put_contents($plan['file'],$plan['replacement']);
                $input=$context;
                if(realpath($this->root.'/'.$context->file)===realpath($plan['file'])){
                    // Constructed negative only; the actual original is never replaced as positive authority.
                    $input=new IssueFilterContext($context->phpVersion,$context->codebase,$context->types,$context->cancellation,
                        $context->file,$plan['replacement'],$context->issue);
                }
                $this->expect('physical changed source Keeps '.$label,$input,false);
            }finally{file_put_contents($plan['file'],$plan['original']);self::restore($cache,$saved);}
            if(file_get_contents($plan['file'])!==$plan['original']){throw new \RuntimeException('Physical source restoration failed: '.$label);}
            $this->expect('physical source restoration Removes '.$label,$context,true);
            $this->sourceMutations[$label]=['file'=>$plan['file'],'beforeSha256'=>hash('sha256',$plan['original']),
                'mutatedSha256'=>hash('sha256',$plan['replacement']),'restoredSha256'=>hash_file('sha256',$plan['file']),
                'meaningfullyChanged'=>true,'sameLength'=>$plan['sameLength'],'restored'=>true];
        }
        $this->expect('genuine selected native positive after all restorations',$context,true);
        foreach($hashes as $path=>$hash){if(hash_file('sha256',$path)!==$hash){throw new \RuntimeException('A genuine physical source was not restored.');}}
        $this->write('controls.json',['status'=>$this->failedAssertions===[]?'PASS':'FAILED',
            'failedAssertions'=>$this->failedAssertions,'unexpectedNegativeAdmissions'=>count($this->failedAssertions),
            'genuinePositive'=>true,'restored'=>true,'alwaysKeep'=>true,'noVacuousControls'=>true,
            'allChecks'=>$this->checks,'cacheMutations'=>$this->cacheMutations,'sourceMutations'=>$this->sourceMutations,
            'nativePositiveContextsConstructed'=>0,'constructedNegativesAsNativeAcceptanceClaimed'=>false,
            'selectedContracts'=>array_map(static fn(array $contract):array=>['binding'=>$contract['binding'],'roles'=>$contract['roles']],$contracts)]);
    }
}
