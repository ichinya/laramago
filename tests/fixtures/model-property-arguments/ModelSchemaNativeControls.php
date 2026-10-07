<?php
declare(strict_types=1);
use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterHook,IssueFilterDecision,Type};
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Ichinya\Laramago\Analyzer\ModelPropertyArgumentFilter;

/** Actual positive SDK cache/source mutation only; engine-one phase, AlwaysKeep. */
final class ModelSchemaNativeControls implements IssueFilterHook
{
    private bool $checked=false;
    public function __construct(private readonly string $root,private readonly string $output){}
    public function getCodes():array{return ['mixed-argument'];}
    public function filterIssue(IssueFilterContext $context):IssueFilterDecision
    {
        if($this->checked||basename($context->file)!=='schema-cases.php'||[$context->issue->annotations[0]->span->start,$context->issue->annotations[0]->span->end]!==[1009,1028]){return IssueFilterDecision::Keep;}
        $this->checked=true;$checks=$variants=[];
        $evaluate=fn()=>new ModelPropertyArgumentFilter($this->root);
        $expect=function(string $name,bool $remove)use($context,$evaluate,&$checks):ModelPropertyArgumentFilter{
            $filter=$evaluate();$decision=$filter->filterIssue($context);
            if(($decision===IssueFilterDecision::Remove)!==$remove){file_put_contents($this->output.'-first-control-failure.json',json_encode([
                'control'=>$name,'expectedRemove'=>$remove,'actualDecision'=>$decision->name,'stages'=>$filter->stages,'certificate'=>$filter->certificate,
                'priorChecks'=>$checks,'nativeAcceptance'=>false],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));throw new RuntimeException('Genuine schema control failed: '.$name);}
            if($decision===IssueFilterDecision::Keep&&$filter->certificate!==[]){throw new RuntimeException('Keep leaked an earlier certificate.');}
            $checks[$name]=true;return $filter;
        };
        $initial=$expect('genuine schema Error before mutations',true);$actual=$initial->dependencies;$model=$initial->certificate['class'];
        $declaringRoles=array_filter(array_keys($actual),static fn(string $role):bool=>str_starts_with($role,'declaring:'));
        $constantRoles=array_filter(array_keys($actual),static fn(string $role):bool=>str_starts_with($role,'constant:'));
        if(count($declaringRoles)!==13||count($constantRoles)!==2){throw new RuntimeException('All actual selected dispatch and constant dependencies are required before controls.');}
        $copy=static function(object $value,array $changes):object{$class=$value::class;return new $class(...array_replace(get_object_vars($value),$changes));};
        $cache=(new ReflectionProperty($context->codebase,'cache'))->getValue($context->codebase);
        $slots=static function(array $objects)use($cache):array{
            $found=[];foreach($cache->values as $operation=>$entries){foreach($entries as $key=>$value){foreach($objects as $object){
                if($object!==null&&$value===$object){$found[$operation.'|'.$key]=[$operation,$key,$value];}
            }}}if($found===[]){throw new RuntimeException('Actual selected SDK object has no populated observed cache variant.');}return array_values($found);
        };
        $mutate=function(string $name,array|callable $objects,callable $replace)use($cache,$slots,$expect,&$variants):void{
            // Prime the genuine selected lookup immediately before observing
            // its actual populated slots; a prior proof may have evicted them.
            if(is_callable($objects)){$objects=$objects();}
            $selected=$slots($objects);$roles=[];
            try{foreach($selected as [$operation,$key,$value]){$cache->values[$operation][$key]=$replace($value);$roles[]=['operation'=>$operation,'observedKey'=>$key,'actualClass'=>$value::class];}
                $expect($name,false);
            }finally{foreach($selected as [$operation,$key,$value]){$cache->values[$operation][$key]=$value;}}
            $expect($name.' restored',true);$variants[$name]=$roles;
        };
        // Requery actual selected class variants. Never guess an operation/key,
        // and do not retain a duplicate whole metadata cache.
        $modelObjects=static fn():array=>array_filter([$context->codebase->getClass($model),$context->codebase->getClassLike($model)]);
        $mutate('unknown extra selected trait',$modelObjects,fn(object $value):object=>$copy($value,['usedTraits'=>[...$value->usedTraits,'Example\\UnboundDiagnosticTrait']]));
        $mutate('selected native class original identity changed',$modelObjects,fn(object $value):object=>$copy($value,['originalName'=>'Example\\OtherRecord']));
        $properties=static fn():array=>array_filter([$context->codebase->getDeclaringProperty($model,'$table'),$context->codebase->getProperty($model,'$table')]);
        $mutate('selected physical native property name changed',$properties,fn(object $value):object=>$copy($value,['name'=>'$other']));
        foreach($actual as $role=>$object){
            $selectedRole=static function()use($context,$model,$role,$object):array{
                [$kind,$member]=explode(':',$role,2);
                $current=match($kind){'declaring'=>$context->codebase->getDeclaringMethod($model,$member),
                    'appearing'=>$context->codebase->getMethod($model,$member),'constant'=>$context->codebase->getClassConstant($model,$member),default=>null};
                if($current===null||$current!=$object){throw new RuntimeException('Actual selected native role changed before cache mutation.');}
                return [$current];
            };
            if(str_starts_with($role,'declaring:')||str_starts_with($role,'appearing:')){
                $mutate('native method name changed '.$role,$selectedRole,fn(object $value):object=>$copy($value,['originalName'=>'otherMethod']));
                $mutate('native method reference return '.$role,$selectedRole,fn(object $value):object=>$copy($value,['flags'=>new MetadataFlags($value->flags->bits|MetadataFlags::BY_REFERENCE)]));
                $mutate('native method return domain changed '.$role,$selectedRole,fn(object $value):object=>$copy($value,['returnType'=>$copy($value->returnType,['type'=>Type::namedObject('stdClass')])]));
                $mutate('native method return doc origin changed '.$role,$selectedRole,fn(object $value):object=>$copy($value,['returnType'=>$copy($value->returnType,['fromDocblock'=>false])]));
                if($object->parameters!==[]){$mutate('native method formal output '.$role,$selectedRole,function(object $value)use($copy):object{
                    $parameters=$value->parameters;$parameters[0]=$copy($parameters[0],['outType'=>$parameters[0]->type]);return $copy($value,['parameters'=>$parameters]);
                });}
            }elseif(str_starts_with($role,'constant:')){
                $mutate('native temporal inferred literal changed '.$role,$selectedRole,fn(object $value):object=>$copy($value,['inferredType'=>Type::literalString('context_id')]));
                $mutate('native temporal effective domain changed '.$role,$selectedRole,fn(object $value):object=>$copy($value,['type'=>$copy($value->type,['type'=>Type::int()])]));
                $mutate('native temporal effective documentation changed '.$role,$selectedRole,fn(object $value):object=>$copy($value,['type'=>$copy($value->type,['fromDocblock'=>false])]));
            }
        }
        $selectedCallRole=static function(string $role)use($context,$actual):array{
            $baseline=$actual[$role]??null;$caller=$actual['caller']??null;
            if($baseline===null||$caller===null||$caller->identifier->class===null){throw new RuntimeException('Missing genuine physical selected caller/receiver.');}
            // The selected fixture call is physically $this->displayName(...),
            // so both lookups use its actual named caller class.
            $objects=array_filter([$context->codebase->getMethod($caller->identifier->class,$baseline->identifier->name),
                $context->codebase->getDeclaringMethod($caller->identifier->class,$baseline->identifier->name)]);
            if(!array_filter($objects,static fn(object $object):bool=>$object==$baseline)){throw new RuntimeException('The genuine selected caller/receiver lookup changed.');}
            return $objects;
        };
        foreach(['receiving','caller'] as $role){
            $mutate('native caller/receiver reference '.$role,static fn():array=>$selectedCallRole($role),fn(object $value):object=>$copy($value,['flags'=>new MetadataFlags($value->flags->bits|MetadataFlags::BY_REFERENCE)]));
        }
        $receiving=$actual['receiving'];
        $mutate('genuine nullable schema no longer contained by receiving int',static fn():array=>$selectedCallRole('receiving'),function(object $value)use($copy):object{
            $parameters=$value->parameters;$parameters[1]=$copy($parameters[1],['type'=>$copy($parameters[1]->type,['type'=>Type::int()])]);return $copy($value,['parameters'=>$parameters]);
        });
        $sources=[
            'selected schema column disappears'=>[$this->root.'/database/migrations/2026_01_01_000000_create_records.php',"integer('context_id')","integer('context_ix')"],
            'physical table default contradicts native'=>[$this->root.'/app/Record.php',"\$table='records'","\$table='unknown'"],
            'physical timestamp literal contradicts native'=>[$this->root.'/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Model.php',"CREATED_AT = 'created_at'","CREATED_AT = 'context_id'"],
            'physical getDates body contradicts default dispatch'=>[$this->root.'/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasAttributes.php','$this->getUpdatedAtColumn(),','$this->getCreatedAtColumn(),'],
            'physical method return documentation contradicts native'=>[$this->root.'/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Model.php',"@return string\n     */\n    public function getTable()","@return object\n     */\n    public function getTable()"],
            'physical method parameter documentation contradicts native'=>[$this->root.'/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Model.php',"@param  string  \$key\n     * @return mixed\n     */\n    public function __get","@param  object  \$key\n     * @return mixed\n     */\n    public function __get"],
            'stronger physical temporal documentation retains priority'=>[$this->root.'/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Model.php',"const CREATED_AT = 'created_at';","/** @phpstan-var object */ const CREATED_AT = 'created_at';"],
            'same-length malformed temporal documentation suffix'=>[$this->root.'/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Model.php',"@var string|null\n     */\n    const CREATED_AT","@var string|nullX     */\n    const CREATED_AT"],
            'physical child attribute override'=>[$this->root.'/app/Record.php',"class SchemaRecord extends Record { protected \$casts=['zeta'=>'string','alpha'=>'integer']; }","class SchemaRecord extends Record { protected \$casts=['zeta'=>'string','alpha'=>'integer']; public function getAttribute(\$key){ return new \\stdClass; } }"],
            'physical child timestamp accessor override'=>[$this->root.'/app/Record.php',"class SchemaRecord extends Record { protected \$casts=['zeta'=>'string','alpha'=>'integer']; }","class SchemaRecord extends Record { protected \$casts=['zeta'=>'string','alpha'=>'integer']; public function getCreatedAtColumn(){ return 'context_id'; } }"],
            'physical child date constant override'=>[$this->root.'/app/Record.php',"class SchemaRecord extends Record { protected \$casts=['zeta'=>'string','alpha'=>'integer']; }","class SchemaRecord extends Record { protected \$casts=['zeta'=>'string','alpha'=>'integer']; public const CREATED_AT='context_id'; }"],
            'physical selected directional tag'=>[$this->root.'/app/Record.php',"class SchemaRecord extends Record { protected \$casts=['zeta'=>'string','alpha'=>'integer']; }","/** @property-read object \$context_id */ class SchemaRecord extends Record { protected \$casts=['zeta'=>'string','alpha'=>'integer']; }"],
            'physical earlier argument becomes effectful'=>[$this->root.'/schema-cases.php','return $this->displayName($projects,$record->context_id);','return $this->displayName(effect($projects),$record->context_id);'],
        ];
        foreach($sources as $name=>[$file,$before,$after]){$bytes=file_get_contents($file);
            // The last earlier-argument string appears in several negative
            // scopes; modify only the selected ordinary schema caller once.
            $offset=strpos($bytes,$before);if($offset===false){throw new RuntimeException('One current source control anchor required.');}
            $changed=substr_replace($bytes,$after,$offset,strlen($before));
            try{file_put_contents($file,$changed);$expect($name,false);}finally{file_put_contents($file,$bytes);}
            $expect($name.' restored',true);$variants[$name]=['beforeSha256'=>hash('sha256',$bytes),'changedSha256'=>hash('sha256',$changed),'bytesRestored'=>true];
        }
        if(count($checks)!==1+2*count($variants)||count($checks)<163){throw new RuntimeException('Every real source/cache refusal must have a completed restoration and re-admission.');}
        file_put_contents($this->output.'-native-controls.json',json_encode(['genuinePositiveControls'=>true,'checks'=>$checks,'actualSelectedVariants'=>$variants,
            'actualDeclaringDispatchRoles'=>count($declaringRoles),'actualConstantRoles'=>count($constantRoles),'physicalSourceControls'=>count($sources),
            'allRestored'=>true,'nativeIssueAlwaysKept'=>true,'fabricatedPositiveDTOs'=>false],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
        return IssueFilterDecision::Keep;
    }
}
