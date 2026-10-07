<?php
declare(strict_types=1);
namespace Example\LocalModelTests;

use Ichinya\Laramago\Analyzer\GuardedLocalModelTupleArgumentFilter;
use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterDecision,IssueFilterHook,Plugin,PluginDefinition,PluginRegistry,Type};

/** Genuine entry is preserved before every candidate query. Decisions are always Keep. */
final class LocalModelObservation implements Plugin,IssueFilterHook
{
    private int $sequence=0;
    public function __construct(private readonly string $root,private readonly string $output){}
    public function getDefinition():PluginDefinition{return new PluginDefinition('fixture/guarded-local-model-observation','Guarded local model observation','Current declaration and local tuple proofs, observed without removing issues.');}
    public function register(PluginRegistry $registry):void{$registry->registerIssueFilterHook($this);}
    public function getCodes():array{return ['mixed-argument','possibly-null-argument','null-argument','invalid-argument','possibly-invalid-argument'];}
    public function filterIssue(IssueFilterContext $context):IssueFilterDecision
    {
        $sequence=++$this->sequence;
        $entry=['sequence'=>$sequence,'pid'=>getmypid(),'file'=>$context->file,'sourceSha256'=>hash('sha256',$context->contents),
            'genuineIssue'=>self::value($context->issue),'genuineIssueFilterContext'=>true,'alwaysKeep'=>true,
            'entryBeforeCandidateQueries'=>true,'fakeProviderContextUsed'=>false,'returnTypeProvidersRegistered'=>0];
        $this->write('entries.jsonl',$entry);
        $proof=new GuardedLocalModelTupleArgumentFilter($this->root);
        $decision=$proof->filterIssue($context);
        // Each invocation owns its proof object and telemetry. A nested request
        // cannot overwrite this entry or the completed local certificate.
        $dependencies=[];
        foreach($proof->dependencies as $role=>$metadata){$dependencies[$role]=self::metadata($metadata);}
        $methodDiscovery=[];$routeDiscovery=[];
        if(basename(str_replace('\\','/',$context->file))==='positive.php'&&$context->issue->code==='mixed-argument'){
            foreach([
                ['builderFirst','Illuminate\Database\Eloquent\Builder','first'],
                ['builderGet','Illuminate\Database\Eloquent\Builder','get'],
                ['modelQuery','Illuminate\Database\Eloquent\Model','query'],
                ['collectionFirst','Illuminate\Support\Collection','first'],
                ['newCollection','Illuminate\Database\Eloquent\HasCollection','newCollection'],
            ] as [$role,$class,$method]){
                $bound=$context->codebase->getMethod($class,$method);
                $declaring=$context->codebase->getDeclaringMethod($class,$method);
                $methodDiscovery[$role]=['class'=>$class,'method'=>$method,
                    'bound'=>self::metadata($bound),'declaring'=>self::metadata($declaring)];
            }
            $routeDiscovery=RouteDeclarationObservation::collect($context,self::metadata(...));
        }
        $this->write('observations.jsonl',$entry+['candidateDecision'=>$decision->name,
            'stages'=>$proof->stages,'certificate'=>$proof->certificate,'selectedNativeMetadata'=>$dependencies,
            'selectedMethodDiscovery'=>$methodDiscovery,'selectedMethodDiscoveryAuthority'=>false,
            'selectedRouteDiscovery'=>$routeDiscovery,'selectedRouteDiscoveryAuthority'=>false,
            'afterFileAuthority'=>false,'nativeRemovalClaimed'=>false]);
        return IssueFilterDecision::Keep;
    }
    private function write(string $name,array $value):void
    {
        if(file_put_contents($this->output.'/'.$name,json_encode(self::value($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n",FILE_APPEND|LOCK_EX)===false){throw new \RuntimeException('Cannot preserve current local-model observation.');}
    }
    public static function metadata(mixed $metadata):mixed
    {
        if($metadata===null){return null;}
        if(is_array($metadata)){return array_map(self::metadata(...),$metadata);}
        if(!is_object($metadata)){return $metadata;}
        $result=['dtoClass'=>$metadata::class];
        foreach(['identifier','name','originalName','kind','location','nameLocation','flags','static','abstract','visibility','directParentClass',
            'usedTraits','unresolvedHierarchyDependencies','mixins','type','declaredType','defaultType','returnType','declaredReturnType'] as $field){
            if(property_exists($metadata,$field)){$result[$field]=self::value($metadata->$field);}
        }
        foreach(['parameters','templates','attributes','whereConstraints','assertions','ifTrueAssertions','ifFalseAssertions'] as $field){
            if(!property_exists($metadata,$field)){continue;}
            $result[$field.'Count']=count($metadata->$field);$result[$field.'Truncated']=count($metadata->$field)>6;
            $result[$field]=array_map(self::value(...),array_slice($metadata->$field,0,6));
        }
        foreach(['pseudoMethods','staticPseudoMethods'] as $field){
            if(!property_exists($metadata,$field)){continue;}
            $result[$field.'Count']=count($metadata->$field);$result[$field.'Truncated']=count($metadata->$field)>32;
            $result[$field]=array_map(self::value(...),array_slice($metadata->$field,0,32));
        }
        if(property_exists($metadata,'assertionsInferred')){$result['assertionsInferred']=$metadata->assertionsInferred;}
        return $result;
    }
    public static function value(mixed $value):mixed
    {
        if($value instanceof \BackedEnum){return $value->value;}
        if($value instanceof \UnitEnum){return $value->name;}
        if($value instanceof Type){return self::type($value);}
        if(is_object($value)){return self::value(get_object_vars($value));}
        return is_array($value)?array_map(self::value(...),$value):$value;
    }
    private static function type(Type $type,int $depth=0):array
    {
        $text=(string)$type;$result=['text'=>substr($text,0,512),'textTruncated'=>strlen($text)>512,
            'flags'=>self::value($type->flags),'atomicCount'=>count($type->atomicTypes),'atoms'=>[],
            'depthBound'=>$depth>=2,'atomsTruncated'=>count($type->atomicTypes)>6];
        if($depth>=2){return $result;}
        foreach(array_slice($type->atomicTypes,0,6) as $atomic){
            $part=['dtoClass'=>$atomic::class];
            foreach(['name','kind','static','isThis','remappedParameters','definingEntity','literal','variant'] as $field){if(property_exists($atomic,$field)){$part[$field]=self::value($atomic->$field);}}
            if(property_exists($atomic,'parameters')){$part['parametersNull']=$atomic->parameters===null;$part['parametersCount']=count($atomic->parameters??[]);
                $part['parameters']=array_map(static fn(Type $parameter):array=>self::type($parameter,$depth+1),array_slice($atomic->parameters??[],0,6));}
            if(property_exists($atomic,'variances')){$part['variancesNull']=$atomic->variances===null;$part['variancesCount']=count($atomic->variances??[]);}
            if(property_exists($atomic,'intersections')){$part['intersectionsNull']=$atomic->intersections===null;$part['intersectionsCount']=count($atomic->intersections??[]);}
            if(property_exists($atomic,'constraint')&&$atomic->constraint instanceof Type){$part['constraint']=self::type($atomic->constraint,$depth+1);}
            $result['atoms'][]=$part;
        }
        return $result;
    }
}
