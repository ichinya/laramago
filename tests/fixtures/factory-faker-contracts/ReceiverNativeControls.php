<?php
declare(strict_types=1);
namespace Example\FakerReceiverTests;

use Example\NativeFixtureSupport\SelectedNativeAliases as Aliases;
use Ichinya\Laramago\Analyzer\FactoryFakerProof;
use Mago\Sdk\Analyzer\{IssueFilterContext,Type};
use Mago\Sdk\Analyzer\Metadata\{FunctionLikeKind,MetadataFlags,TypeMetadata};
use Mago\Sdk\{SourceLocation,Span};

/** Test-only mutations of declarations selected by an initialized, genuine proof. */
final class ReceiverNativeControls
{
    private array $checks=[];
    private array $mutations=[];
    private array $jobs=[];
    private readonly int $batch;
    public const BATCH_SIZE=10;
    public const EXPECTED_MUTATIONS=360;
    private readonly string $root;

    private function __construct(private readonly IssueFilterContext $context,
        private readonly FactoryFakerProof $proof,private readonly string $mode,private readonly string $output)
    {
        $this->root=(new \ReflectionProperty($proof,'root'))->getValue($proof);
        if(preg_match('/^cache-receiver-batch-(\d{2})$/D',$mode,$match)!==1){
            throw new \RuntimeException('A bounded receiver mutation batch is required.');
        }
        $this->batch=(int)$match[1];
        if($this->batch>=intdiv(self::EXPECTED_MUTATIONS,self::BATCH_SIZE)){
            throw new \RuntimeException('The selected receiver mutation batch is outside the complete plan.');
        }
    }

    /** The caller serializes controls and returns Keep for the original native issue. */
    public static function run(IssueFilterContext $context,FactoryFakerProof $proof,string $mode,string $output):array
    {
        $controls=new self($context,$proof,$mode,$output);
        try{return $controls->execute();}
        catch(\Throwable $error){
            $controls->write('receiver-first-failure.json',['status'=>'FAILED','mode'=>$mode,
                'exception'=>$error::class,'message'=>$error->getMessage(),'allChecks'=>$controls->checks,
                'completedMutations'=>array_keys($controls->mutations),'nativeAdmissionClaimed'=>false],true);
            throw $error;
        }
    }

    private function current():array
    {
        // Reentrant SDK requests must not overwrite another invocation's local proof result.
        return (new FactoryFakerProof(clone $this->proof->contracts,$this->root))->prove($this->context);
    }

    private function positive(string $label):void
    {
        $result=$this->current();
        if(($result['remove']??false)!==true){throw new \RuntimeException($label.': genuine positive refused at '.($result['stage']??'unknown stage'));}
        $this->checks[$label]=true;
    }

    private function execute():array
    {
        // Initialize the actual selected contracts' lazy catalogue before reading its source recipes.
        $initial=$this->proof->prove($this->context);
        if(($initial['remove']??false)!==true){
            throw new \RuntimeException('The actual original receiver proof is not an admitted genuine context.');
        }
        $this->checks['initial actual receiver proof Removes']=true;unset($initial);
        $this->positive('repeated genuine receiver proof Removes');
        $receiver=(new \ReflectionProperty($this->proof->contracts,'registrationReceivers'))->getValue($this->proof->contracts);
        if(!is_object($receiver)){throw new \RuntimeException('Initialized registration receiver source state is absent.');}
        $selected=[];
        foreach(['classes','methods','closureCallers','roles'] as $field){
            $selected[$field]=(new \ReflectionProperty($receiver,$field))->getValue($receiver);
            self::scalarTree($selected[$field]);
        }
        unset($receiver);
        $classes=array_values(array_filter($selected['classes'],static fn(array $entry):bool=>$entry['nativeRequired']));
        if(count($classes)!==15||count($selected['methods'])!==27||$selected['closureCallers']===[]
            ||!isset($selected['roles']['middleware-alias-configuration'])){
            throw new \RuntimeException('All fifteen classes, twenty-seven methods, provider receivers, and middleware routes are required.');
        }
        $this->write('receiver-initial.json',['mode'=>$this->mode,'genuinePositive'=>true,'genuineContextConstructed'=>false,
            'contextFile'=>$this->context->file,'contextSha256'=>hash('sha256',$this->context->contents),
            'code'=>$this->context->issue->code,'sourceSelectionCounts'=>['nativeClasses'=>count($classes),
                'methods'=>count($selected['methods']),'closureCallers'=>count($selected['closureCallers'])]]);
        foreach($classes as $source){$this->classControls($source['name']);}
        foreach($selected['methods'] as $source){
            $binding=$source['queryClass']===null?['kind'=>'function','name'=>$source['name']]
                :['kind'=>'method','class'=>$source['queryClass'],'name'=>$source['name']];
            $this->methodControls($binding);
        }
        $providerBindings=[];
        foreach($selected['closureCallers'] as $caller){
            $class=$caller['class'];$this->classControls($class);
            $this->methodControls(['kind'=>'method','class'=>$class,'name'=>$caller['method']]);
            $constructor=['kind'=>'method','class'=>$class,'name'=>'__construct'];
            $this->methodControls($constructor);
            $this->constructorDomainControls($constructor);
            $providerBindings[]=['kind'=>'property','class'=>$class,'name'=>'$app'];
        }
        $this->appFieldControls($providerBindings);
        if(isset($selected['roles']['middleware-alias-configuration'])){$this->builderFieldControls();}
        // Queue the original callbacks first so every hook has the same deterministic complete plan.
        ksort($this->jobs,SORT_STRING);$plan=[];
        foreach($this->jobs as $label=>$job){$plan[$label]=['selectedBinding'=>$job['selected'],'queriedBindings'=>$job['bindings']];}
        if(count($plan)!==self::EXPECTED_MUTATIONS){
            throw new \RuntimeException('The complete unchanged receiver mutation plan differs from its selected native declarations.');
        }
        $batchJobs=array_slice($this->jobs,$this->batch*self::BATCH_SIZE,self::BATCH_SIZE,true);
        if(count($batchJobs)!==self::BATCH_SIZE){throw new \RuntimeException('A complete receiver mutation batch is required.');}
        foreach($batchJobs as $label=>$job){$this->applyControl($label,$job['selected'],$job['change'],$job['bindings']);}
        if(array_keys($this->mutations)!==array_keys($batchJobs)){
            throw new \RuntimeException('The actual receiver mutation batch did not execute every selected callback exactly once.');
        }
        $this->positive('all receiver SDK controls restored genuine Remove');
        $receipt=['status'=>'PASS','mode'=>$this->mode,'genuinePositive'=>true,'restored'=>true,'alwaysKeepRequired'=>true,
            'allChecks'=>$this->checks,'cacheMutations'=>$this->mutations,'sourceMutations'=>[],
            'plannedMutations'=>$plan,'plannedMutationCount'=>count($plan),
            'plannedMutationPlanSha256'=>hash('sha256',json_encode($plan,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),
            'batchIndex'=>$this->batch,'batchSize'=>self::BATCH_SIZE,
            'batchCount'=>intdiv(self::EXPECTED_MUTATIONS,self::BATCH_SIZE),'batchLabels'=>array_keys($batchJobs),
            'sourceSelectionCounts'=>['nativeClasses'=>count($classes),'methods'=>count($selected['methods']),
                'closureCallers'=>count($selected['closureCallers'])],'fakePositiveContexts'=>0,
            'constructedNegativeMetadataAsPositiveAuthority'=>false,'nativeAdmissionClaimed'=>false];
        $this->write('receiver-native-controls.json',$receipt);return $receipt;
    }

    private function classControls(string $name):void
    {
        $binding=['kind'=>'class','name'=>$name];$label='class '.$name;
        $this->control($label.' absent',$binding,static fn(object $native):?object=>null);
        $this->control($label.' physical location changed',$binding,
            static fn(object $native):object=>Aliases::copy($native,['location'=>self::shift($native->location)]));
        $this->control($label.' physical name location changed',$binding,
            static fn(object $native):object=>Aliases::copy($native,['nameLocation'=>self::shift($native->nameLocation)]));
    }

    private function methodControls(array $binding):void
    {
        $label=self::bindingName($binding);
        $this->control($label.' kind changed',$binding,static fn(object $native):object=>Aliases::copy($native,
            ['kind'=>$native->kind===FunctionLikeKind::Method?FunctionLikeKind::Function_:FunctionLikeKind::Method]));
        $this->control($label.' returns by reference',$binding,static fn(object $native):object=>Aliases::copy($native,
            ['flags'=>new MetadataFlags($native->flags->bits|MetadataFlags::BY_REFERENCE)]));
        $this->control($label.' physical location changed',$binding,static fn(object $native):object=>Aliases::copy($native,
            ['location'=>self::shift($native->location)]));
        $this->control($label.' physical name location changed',$binding,static fn(object $native):object=>Aliases::copy($native,
            ['nameLocation'=>self::shift($native->nameLocation)]));
        $native=$this->select($binding);$count=count($native->parameters);unset($native);
        for($index=0;$index<$count;$index++){
            foreach(['location','nameLocation'] as $field){
                $this->parameterControl($binding,$index,'physical '.$field.' changed',
                    static fn(object $parameter):object=>Aliases::copy($parameter,[$field=>self::shift($parameter->$field)]));
            }
            $this->parameterControl($binding,$index,'out domain added',static fn(object $parameter):object=>Aliases::copy($parameter,
                ['outType'=>new TypeMetadata($parameter->location,Type::mixed(),false,false)]));
            $this->parameterControl($binding,$index,'by reference',static fn(object $parameter):object=>Aliases::copy($parameter,
                ['flags'=>new MetadataFlags($parameter->flags->bits|MetadataFlags::BY_REFERENCE)]));
            $native=$this->select($binding);$hasClosureThis=$native->parameters[$index]->closureThisType!==null;unset($native);
            if($hasClosureThis){
                $this->parameterControl($binding,$index,'closure-this removed',
                    static fn(object $parameter):object=>Aliases::copy($parameter,['closureThisType'=>null]));
                $this->parameterControl($binding,$index,'closure-this physical location changed',
                    static fn(object $parameter):object=>Aliases::copy($parameter,['closureThisType'=>Aliases::copy($parameter->closureThisType,
                        ['location'=>self::shift($parameter->closureThisType->location)])]));
                $this->parameterControl($binding,$index,'closure-this unrelated object',
                    static fn(object $parameter):object=>Aliases::copy($parameter,['closureThisType'=>Aliases::copy($parameter->closureThisType,
                        ['type'=>Type::namedObject('stdClass')])]));
            }
        }
    }

    private function parameterControl(array $binding,int $index,string $label,callable $change):void
    {
        $this->control(self::bindingName($binding).' parameter '.$index.' '.$label,$binding,
            static function(object $native)use($index,$change):object{
                if(!isset($native->parameters[$index])){throw new \RuntimeException('Selected genuine formal disappeared.');}
                $parameters=$native->parameters;$parameters[$index]=$change($parameters[$index]);
                return Aliases::copy($native,['parameters'=>$parameters]);
            });
    }

    private function constructorDomainControls(array $binding):void
    {
        $native=$this->select($binding);
        if(count($native->parameters)!==1||$native->parameters[0]->type===null||$native->parameters[0]->declaredType!==null){
            throw new \RuntimeException('The physical inherited constructor formal must have its observed documented-only domain.');
        }
        unset($native);
        $this->parameterControl($binding,0,'declared domain added',static fn(object $p):object=>Aliases::copy($p,['declaredType'=>$p->type]));
        $this->parameterControl($binding,0,'documented domain removed',static fn(object $p):object=>Aliases::copy($p,['type'=>null]));
        $this->parameterControl($binding,0,'documented origin removed',static fn(object $p):object=>Aliases::copy($p,
            ['type'=>Aliases::copy($p->type,['fromDocblock'=>false])]));
        $this->parameterControl($binding,0,'inferred domain substituted',static fn(object $p):object=>Aliases::copy($p,
            ['type'=>Aliases::copy($p->type,['inferred'=>true])]));
        $this->parameterControl($binding,0,'unrelated object domain',static fn(object $p):object=>Aliases::copy($p,
            ['type'=>Aliases::copy($p->type,['type'=>Type::namedObject('stdClass')])]));
    }

    private function appFieldControls(array $descendants):void
    {
        $owner=['kind'=>'property','class'=>'Illuminate\\Support\\ServiceProvider','name'=>'$app'];
        $bindings=[$owner,...$descendants];
        $this->control('owning and inherited provider app field absent',$owner,static fn(object $p):?object=>null,$bindings);
        $this->control('provider app physical name changed',$owner,static fn(object $p):object=>Aliases::copy($p,
            ['nameLocation'=>self::shift($p->nameLocation)]),$bindings);
        $this->control('provider app effective name changed',$owner,static fn(object $p):object=>Aliases::copy($p,['name'=>'$other']),$bindings);
        $this->control('provider app documented domain removed',$owner,static fn(object $p):object=>Aliases::copy($p,['type'=>null]),$bindings);
        $this->control('provider app doc location changed',$owner,static fn(object $p):object=>Aliases::copy($p,
            ['type'=>Aliases::copy($p->type,['location'=>new SourceLocation('foreign-declaration.php',$p->type->location->span)])]),$bindings);
        $this->control('provider app unrelated object domain',$owner,static fn(object $p):object=>Aliases::copy($p,
            ['type'=>Aliases::copy($p->type,['type'=>Type::namedObject('stdClass')])]),$bindings);
        $this->control('provider app declared domain added',$owner,static fn(object $p):object=>Aliases::copy($p,['declaredType'=>$p->type]),$bindings);
        $this->control('provider app write domain added',$owner,static fn(object $p):object=>Aliases::copy($p,['writeType'=>$p->type]),$bindings);
        foreach([MetadataFlags::STATIC,MetadataFlags::READONLY,MetadataFlags::BY_REFERENCE,MetadataFlags::VIRTUAL_PROPERTY,
            MetadataFlags::WRITEONLY,MetadataFlags::PROMOTED_PROPERTY,MetadataFlags::ASYMMETRIC_PROPERTY] as $flag){
            $this->control('provider app forbidden flag '.$flag,$owner,static fn(object $p):object=>Aliases::copy($p,
                ['flags'=>new MetadataFlags($p->flags->bits|$flag)]),$bindings);
        }
    }

    private function builderFieldControls():void
    {
        $binding=['kind'=>'property','class'=>'Illuminate\\Foundation\\Application','name'=>'$applicationBuilder'];
        $this->control('application builder field absent',$binding,static fn(object $p):?object=>null);
        $this->control('application builder default removed',$binding,static fn(object $p):object=>Aliases::copy($p,['defaultType'=>null]));
        $this->control('application builder documented default',$binding,static fn(object $p):object=>Aliases::copy($p,
            ['defaultType'=>Aliases::copy($p->defaultType,['fromDocblock'=>true])]));
        $this->control('application builder uninferred default',$binding,static fn(object $p):object=>Aliases::copy($p,
            ['defaultType'=>Aliases::copy($p->defaultType,['inferred'=>false])]));
        $this->control('application builder unrelated default',$binding,static fn(object $p):object=>Aliases::copy($p,
            ['defaultType'=>Aliases::copy($p->defaultType,['type'=>Type::namedObject('stdClass')])]));
    }

    private function control(string $label,array $selected,callable $change,?array $bindings=null):void
    {
        if(isset($this->jobs[$label])){throw new \RuntimeException('Duplicate promised receiver control: '.$label);}
        $this->jobs[$label]=['selected'=>$selected,'change'=>$change,'bindings'=>$bindings??[$selected]];
    }

    private function applyControl(string $label,array $selected,callable $change,?array $bindings=null):void
    {
        if(isset($this->mutations[$label])){throw new \RuntimeException('Duplicate promised receiver control: '.$label);}
        $this->positive('before '.$label);
        $native=$this->select($selected);$replacement=$change($native);$bindings??=[$selected];
        $changed=function()use($label):void{
            $result=$this->current();
            if(($result['remove']??false)!==false){throw new \RuntimeException('Changed selected receiver metadata was admitted: '.$label);}
            $this->checks['changed Keep '.$label]=true;
        };
        try{
            $details=$replacement!==null&&$selected['kind']!=='function'
                ?Aliases::control($this->context->codebase,$native,$replacement,$bindings,$changed)
                :$this->localControl($native,$replacement,$bindings,$changed);
        }finally{$this->positive('restored '.$label);}
        $this->mutations[$label]=['selectedBinding'=>$selected,'queriedBindings'=>$bindings,
            'genuineRemoveBefore'=>true,'changedKeep'=>true,'restoredRemove'=>true]+$details;
    }

    /** Extension for real global-function queries and missing equivalent declarations only. */
    private function localControl(object $native,?object $replacement,array $bindings,callable $changed):array
    {
        if($replacement!==null&&($native::class!==$replacement::class||$native==$replacement)){
            throw new \RuntimeException('A receiver cache variant must change actual native metadata.');
        }
        $populated=0;
        foreach($bindings as $binding){
            $present=0;
            foreach($this->aliases($binding) as $alias){
                if($alias===null){continue;}
                if($alias::class!==$native::class||$alias!=$native){throw new \RuntimeException('A selected genuine receiver alias changed identity.');}
                $populated++;$present++;
            }
            if($present===0){throw new \RuntimeException('A promised selected binding has no populated declaration.');}
        }
        $cache=(new \ReflectionProperty($this->context->codebase,'cache'))->getValue($this->context->codebase);
        $values=$cache->values;$relations=$cache->relations;$slots=[];
        foreach($values as $operation=>$entries){foreach($entries as $key=>$entry){
            if(is_object($entry)&&$entry::class===$native::class&&$entry==$native){
                $cache->values[$operation][$key]=$replacement;$slots[]=['operation'=>$operation,'key'=>$key];
            }
        }}
        try{if($slots===[]){throw new \RuntimeException('No actual populated receiver cache slot changed.');}$changed();}
        finally{$cache->values=$values;$cache->relations=$relations;}
        return ['actualPopulatedAliases'=>$populated,'slotsChanged'=>count($slots),'actualSlots'=>$slots,
            'meaningfulChange'=>true,'cacheValuesAndRelationsRestored'=>true,'replacementAbsent'=>$replacement===null];
    }

    private function select(array $binding):object
    {
        $aliases=$this->aliases($binding);
        $native=$binding['kind']==='method'?$aliases[1]:$aliases[0];
        if(!is_object($native)){throw new \RuntimeException('The selected genuine receiver declaration is absent: '.self::bindingName($binding));}
        foreach($aliases as $alias){if($alias!==null&&($alias::class!==$native::class||$alias!=$native)){
            throw new \RuntimeException('Genuine populated APIs disagree on selected receiver metadata.');
        }}
        return $native;
    }

    private function aliases(array $binding):array
    {
        $codebase=$this->context->codebase;
        return match($binding['kind']){
            'class'=>[$codebase->getClass($binding['name'])],
            'function'=>[$codebase->getFunction($binding['name'])],
            'method'=>[$codebase->getMethod($binding['class'],$binding['name']),$codebase->getDeclaringMethod($binding['class'],$binding['name'])],
            'property'=>[$codebase->getDeclaringProperty($binding['class'],$binding['name']),$codebase->getProperty($binding['class'],$binding['name'])],
            default=>throw new \RuntimeException('Unsupported genuine receiver binding.'),
        };
    }

    private static function shift(?SourceLocation $native):SourceLocation
    {
        if($native===null||$native->span->end>=4294967295){throw new \RuntimeException('A current physical span is required for this mutation.');}
        return new SourceLocation($native->file,new Span($native->span->start+1,$native->span->end+1));
    }
    private static function bindingName(array $binding):string{return $binding['kind'].' '.($binding['class']??'').'::'.$binding['name'];}
    private static function scalarTree(mixed $value):void
    {
        if(is_array($value)){foreach($value as $item){self::scalarTree($item);}return;}
        if($value!==null&&!is_scalar($value)){throw new \RuntimeException('Receiver controls retain only compact source arrays.');}
    }
    private function write(string $name,array $value,bool $firstOnly=false):void
    {
        if(!is_dir($this->output)&&!mkdir($this->output,0777,true)&&!is_dir($this->output)){throw new \RuntimeException('Cannot preserve receiver control output.');}
        $path=$this->output.'/'.$name;if($firstOnly&&is_file($path)){return;}
        if(file_put_contents($path,json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT))===false){
            throw new \RuntimeException('Cannot preserve receiver control receipt.');
        }
    }
}
