<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;
use Ichinya\Laramago\Analyzer\DefensiveBoundaryGuardSource as Source;
use Ichinya\Laramago\Analyzer\DefensiveBoundaryGuardProof as Boundary;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\Metadata\{FunctionLikeMetadata,MetadataFlags};
use Mago\Sdk\Reporting\{AnnotationKind,Level};
use PhpParser\{Node,NodeFinder};

/** Exact built-in declaration profiles bound to a closed XML defensive reporting policy. */
final class XmlCardinalityProof
{
    private const BUILTIN_HASHES = [
        'simplexml_load_string' => 'fbf7687eac1e4254683910bce28ebe5d22da7bf3620a8ed8228a95b9105a4413',
        'SimpleXMLElement::xpath' => '467825de9b185abffc703f1799b8cb851ff8071228366e16554ead21ffceaa6b',
        'SimpleXMLElement::count' => '170414c9b0afe4fa9192f648d54b35d9dcff96dda5ee36b7db9bd62570511d5c',
    ];
    public function __construct(private readonly string $root){ }
    public function prove(IssueFilterContext $context):array
    {
        $r=['remove'=>false,'stage'=>'exact-warning-envelope','nativeTypesChanged'=>false,'xmlExecuted'=>false,'observedBuiltinProfiles'=>[]];$issue=$context->issue;$a=count($issue->annotations)===1?$issue->annotations[0]:null;
        if($context->cancellation->isCancelled()||$issue->level!==Level::Warning||$issue->code!=='impossible-type-comparison'||$issue->link!==null||$issue->edits!==[]||$a===null||$a->kind!==AnnotationKind::Primary||$a->file!==null&&$a->file!==''){return $r;}
        $physical=str_replace('\\','/',str_replace('\\\\?\\','',$context->file));if(!str_starts_with($physical,'/')&&preg_match('~^[a-z]:/~i',$physical)!==1){$physical=$this->root.'/'.$physical;}$physical=realpath($physical);$root=realpath($this->root);
        if($physical===false||$root===false||!str_starts_with(strtolower(str_replace('\\','/',$physical)),rtrim(strtolower(str_replace('\\','/',$root)),'/').'/')||@file_get_contents($physical)!==$context->contents){return $r;}
        try{$nodes=Source::parse($context->contents);$proofs=XmlCardinalitySource::compile($nodes);}catch(\Throwable){return $r;}$p=$proofs[Source::key([$a->span->start,$a->span->end])]??null;if($p===null){return $r;}$r['source']=$p;
        $expr=trim(substr($context->contents,$p['count']['argument'][0],$p['count']['argument'][1]-$p['count']['argument'][0]));$assertion='has-exactly-1';$printedType=null;foreach(['SimpleXMLElement','SimpleXMLElement|null'] as $candidate){if($issue->message==='Impossible condition: variable `'.$expr.'` (type `'.$candidate.'`) can never be `'.$assertion.'`.'){$printedType=$candidate;break;}}if($printedType===null){return $r;}
        if($issue->message!=='Impossible condition: variable `'.$expr.'` (type `'.$printedType.'`) can never be `'.$assertion.'`.'
            || $issue->notes!==['The type of variable `'.$expr.'` (type `'.$printedType.'`) is incompatible with the assertion that it is `'.$assertion.'`.']
            || $issue->help!=='This condition is impossible and the associated code block will never execute. Review the types and condition logic.'||$a->message!=='This condition always evaluates to false'){return $r;}
        $finder=new NodeFinder;$selected=[];foreach(['loader'=>$p['loader']['call'],'xpath'=>$p['xpath'],'count'=>$p['count']['call'],'arrayPredicate'=>$p['arrayPredicate']] as $role=>$span){$selected[$role]=$finder->findFirst($nodes,static fn(Node $n):bool=>Source::span($n)===$span);}unset($nodes,$finder);
        $r['stage']='current-physical-caller';$scope=$p['scope'];$caller=$context->codebase->getDeclaringMethod($scope['class'],$scope['name']);$owner=$context->codebase->getClass($scope['class']);
        if($caller===null||$owner===null||$owner->hasIncompleteHierarchy()||$caller->static!==$scope['static']||$caller->flags->contains(MetadataFlags::BY_REFERENCE)||strcasecmp($caller->identifier->class??'',$scope['class'])!==0
            || self::path($caller->location->file)!==self::path($context->file)||!in_array($caller->location->span->start,$scope['startAlternatives'],true)||$caller->location->span->end!==$scope['span'][1]){return $r;}
        $r['stage']='native-count-and-list-predicate';foreach(['count'=>'count','arrayPredicate'=>'is_array'] as $role=>$name){if(!$selected[$role] instanceof Node\Expr\FuncCall||Boundary::builtin($context,$selected[$role],$name)===null){return $r;}}
        $r['stage']='current-native-xml-symbols';$xml=$context->codebase->getClass('SimpleXMLElement');$exception=$context->codebase->getClass($p['exception']);
        foreach([$xml,$exception] as $class){if($class===null||$class->hasIncompleteHierarchy()||!$class->flags->contains(MetadataFlags::BUILTIN)||$class->flags->contains(MetadataFlags::USER_DEFINED)){return $r;}}
        $load=$context->codebase->getFunction('simplexml_load_string');$xpath=$context->codebase->getDeclaringMethod('SimpleXMLElement','xpath');$xmlCount=$context->codebase->getDeclaringMethod('SimpleXMLElement','count');
        foreach(['simplexml_load_string'=>$load,'SimpleXMLElement::xpath'=>$xpath,'SimpleXMLElement::count'=>$xmlCount] as $symbol=>$native){
            if(!self::builtin($native,$symbol)){return $r;}$compact=Boundary::compact($native);$r['observedBuiltinProfiles'][$symbol]=['sha256'=>self::hash($compact),'compact'=>$compact];
        }
        $loadCall=$selected['loader'];if(!$loadCall instanceof Node\Expr\FuncCall||!$loadCall->name instanceof Node\Name){return $r;}
        if(!$loadCall->name instanceof Node\Name\FullyQualified){$qualified=$loadCall->name->getAttribute('namespacedName');if($qualified instanceof Node\Name&&strcasecmp($qualified->toString(),'simplexml_load_string')!==0&&$context->codebase->getFunction($qualified->toString())!==null){return $r;}}
        $r['stage']='genuine-built-in-profile-pending';foreach($r['observedBuiltinProfiles'] as $symbol=>$profile){if((self::BUILTIN_HASHES[$symbol]??null)!==$profile['sha256']){return $r;}}
        $r['stage']='current-native-xml-defensive-reporting-policy';$r['remove']=true;return $r;
    }
    private static function builtin(?FunctionLikeMetadata $native,string $symbol):bool
    {if($native===null||$native->static||$native->abstract||!$native->flags->contains(MetadataFlags::BUILTIN)||$native->flags->contains(MetadataFlags::USER_DEFINED)||$native->flags->contains(MetadataFlags::BY_REFERENCE)||$native->returnType===null||$native->declaredReturnType===null){return false;}
        $actual=($native->identifier->class===null?'':$native->identifier->class.'::').$native->identifier->name;if(strcasecmp($symbol,$actual)!==0){return false;}foreach($native->parameters as $p){if($p->flags->contains(MetadataFlags::BY_REFERENCE)||$p->outType!==null||$p->closureThisType!==null){return false;}}return true;}
    private static function hash(array $value):string
    {$sort=static function(mixed $v)use(&$sort):mixed{if(is_array($v)){foreach($v as $k=>$item){$v[$k]=$sort($item);}if(!array_is_list($v)){ksort($v);}}return $v;};return hash('sha256',json_encode($sort($value),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));}
    private static function path(?string $path):string{return strtolower(str_replace('\\','/',$path??''));}
}
