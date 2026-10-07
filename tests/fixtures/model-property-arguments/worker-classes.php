<?php
declare(strict_types=1);
ini_set('display_errors','stderr');ini_set('log_errors','1');
$schemaWorkerError=($argv[4]??sys_get_temp_dir().'/laramago-schema').'-worker-'.getmypid().'.error.log';ini_set('error_log',$schemaWorkerError);
$schemaWorkerReserve=str_repeat(' ',65536);
register_shutdown_function(static function()use(&$schemaWorkerReserve,$schemaWorkerError):void{$schemaWorkerReserve='';$error=error_get_last();
    if($error!==null&&in_array($error['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR],true)){
        file_put_contents($schemaWorkerError,json_encode(['fatal'=>true,'pid'=>getmypid(),'type'=>$error['type'],'message'=>substr($error['message'],0,4096),
            'file'=>basename($error['file']),'line'=>$error['line']],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
    }
});





require __DIR__.'/SelectedModelMetadataObservation.php';
require __DIR__.'/ModelSchemaNativeControls.php';
require __DIR__.'/ModelCastMapNativeControls.php';
use Mago\Sdk\Analyzer\{Plugin,PluginDefinition,PluginRegistry,IssueFilterHook,IssueFilterContext,IssueFilterDecision};
use Mago\Sdk\Reporting\AnnotationKind;
use Ichinya\Laramago\Analyzer\{ModelPropertyArgumentFilter,GuardedStringCastContracts,SourceArgumentDeclarationContracts};
use Example\ModelPropertyArgumentTests\SelectedModelMetadataObservation;
use PhpParser\{Node,NodeFinder};
/** Native/observe/control modes always keep; draft registers the actual candidate. */
final class ModelPropertyArgumentNativePlugin implements Plugin {
    public function __construct(private readonly string $root,private readonly string $mode,private readonly string $output){}
    public function getDefinition():PluginDefinition{return new PluginDefinition('example/model-property-argument-native','Model property argument matrix','Current physical schema and dispatch with genuine native controls.');}
    public function register(PluginRegistry $registry):void{
        if($this->mode==='cache-schema'){$registry->registerIssueFilterHook(new ModelSchemaNativeControls($this->root,$this->output));$registry->registerIssueFilterHook(new ModelCastMapNativeControls($this->root,$this->output));return;}
        if($this->mode==='draft'){$registry->registerIssueFilterHook(new ModelPropertyArgumentFilter($this->root));return;}
        $registry->registerIssueFilterHook(new class($this->root,$this->mode,$this->output)implements IssueFilterHook{
            private readonly ModelPropertyArgumentFilter $filter;
            public function __construct(private readonly string $root,private readonly string $mode,private readonly string $output){$this->filter=new ModelPropertyArgumentFilter($root);}
            public function getCodes():array{return $this->filter->getCodes();}
            public function filterIssue(IssueFilterContext $context):IssueFilterDecision{
                if(!in_array($this->mode,['observe','draft'],true)){return IssueFilterDecision::Keep;}
                $decision=$this->filter->filterIssue($context);
                // Capture debug values before more RPCs; a yielding SDK call
                // can reenter this hook. These values are observation only.
                $stages=$this->filter->stages;$certificate=$this->filter->certificate;
                if($decision===IssueFilterDecision::Keep&&$certificate!==[]){throw new RuntimeException('A Keep invocation retained a prior reentrant certificate.');}
                $primary=null;foreach($context->issue->annotations as $annotation){if($annotation->kind===AnnotationKind::Primary){$primary=$annotation;break;}}
                $span=$primary===null?null:[$primary->span->start,$primary->span->end];
                $identity=Ichinya\Laramago\Analyzer\DefensiveBoundaryGuardProof::canonical($context->issue);
                $profile=$this->mode==='observe'?self::selectedModel($context,$this->root,$span):null;
                $event=['file'=>$context->file,'span'=>$span,'candidateDecision'=>$decision->name,'stages'=>$stages,'certificate'=>$certificate,
                    'genuineIssue'=>$identity,'selectedModelMetadata'=>$profile,'alwaysKeep'=>$this->mode==='observe','admissionAuthority'=>false];
                file_put_contents($this->output,json_encode($event,JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
                return $this->mode==='draft'?$decision:IssueFilterDecision::Keep;
            }
            private static function selectedModel(IssueFilterContext $context,string $root,?array $span):?array{
                if($span===null){return null;}$source=new GuardedStringCastContracts($root);$file=$source->read($context->file,$context->contents);
                if($file===null){return null;}$matches=[];
                foreach((new NodeFinder)->findInstanceOf($file['nodes'],Node\Expr\PropertyFetch::class) as $field){
                    if(SourceArgumentDeclarationContracts::span($field)===$span&&$field->var instanceof Node\Expr\Variable
                        &&is_string($field->var->name)&&$field->name instanceof Node\Identifier){$matches[]=$field;}
                }
                if(count($matches)!==1){return null;}$field=$matches[0];$scope=null;
                foreach($source->ancestors($field,$file) as $parent){if($parent instanceof Node\FunctionLike){$scope=$parent;break;}}
                if(!$scope instanceof Node\Stmt\ClassMethod&&!$scope instanceof Node\Stmt\Function_){return null;}
                $native=$source->owner($context,$field,$file);if($native===null){return null;}$formals=[];
                foreach($scope->params as $index=>$formal){if($formal->var instanceof Node\Expr\Variable&&$formal->var->name===$field->var->name
                    &&$formal->type instanceof Node\Name){$formals[]=[$formal,$native->parameters[$index]??null];}}
                if(count($formals)!==1){return null;}[$physical,$formal]=$formals[0];$class=$physical->type->toString();
                $type=$formal?->type?->type;$atom=$type?->atomicTypes[0]??null;
                if($formal===null||!$source->located($formal->location,$physical,$file)||!$source->located($formal->nameLocation,$physical->var,$file)
                    ||!$source->located($formal->declaredType?->location,$physical->type,$file)||$type===null||count($type->atomicTypes)!==1
                    ||!$atom instanceof \Mago\Sdk\Analyzer\Type\NamedObjectType||strcasecmp($atom->name,$class)!==0){return null;}
                $caller=['file'=>$file['path'],'sha256'=>$file['hash'],'modelFormal'=>$class,'property'=>$field->name->name,'span'=>$span];
                unset($file,$field,$scope,$physical,$formals);
                $profile=SelectedModelMetadataObservation::collect($context,$class,$root);
                $profile['physicalSelectedCaller']=$caller;$profile['callerSourceCurrent']=hash_file('sha256',$caller['file'])===$caller['sha256'];
                return $profile;
            }
        });
    }
}
