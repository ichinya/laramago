<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;
use Ichinya\Laramago\Analyzer\{DefensiveBoundaryGuardSource as Source,DefensiveBoundaryGuardProof as Boundary,GuardedStringCastContracts as Physical};
use Mago\Sdk\Analyzer\{IssueFilterContext,Type};
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Reporting\{Level,AnnotationKind};
use PhpParser\{Node,NodeFinder};
require_once __DIR__.'/FactoryFakerContracts.php';

/** Selected formatter source domain at a real issue context, never a global Generator override. */
final class FactoryFakerProof
{
    private readonly array $observedHashes;public function __construct(public readonly FactoryFakerContracts $contracts,private readonly string $root){$this->observedHashes=FactoryFakerLibraryProfiles::native();}
    public function prove(IssueFilterContext $context):array
    {
        $r=['remove'=>false,'stage'=>'selected-issue-envelope','nativeTypesChanged'=>false,'globalGeneratorOverride'=>false,'formatterExecuted'=>false];$i=$context->issue;$a=$i->annotations[0]??null;
        if($context->cancellation->isCancelled()||$a===null||$a->kind!==AnnotationKind::Primary||$a->file!==null&&$a->file!==''||$i->link!==null||$i->edits!==[]||!in_array($i->code,['array-to-string-conversion','mixed-argument'],true)){return $r;}
        try{$nodes=Source::parse($context->contents);$proofs=FactoryFakerSource::compile($nodes);}catch(\Throwable){return $r;}$p=$proofs[Source::key([$a->span->start,$a->span->end])]??null;if($p===null){return $r;}$r['source']=$p;
        if($p['kind']==='default-faker-words-text'){
            $left=$p['call'][0]===$p['concat'][0];if($i->level!==Level::Warning||$i->code!=='array-to-string-conversion'||count($i->annotations)!==1||$i->message!=='Potential array in '.($left?'left':'right').' operand of string concatenation.'||$i->notes!==["Using an array in string concatenation produces the literal 'Array' and triggers a PHP warning."]||$i->help!=='Add a type check (e.g., `is_string()`) before concatenation to ensure the value is not an array.'||$a->message!=='This expression may be an array.'){return $r;}$receiving=null;$domain=Type::string();
        }else{
            $index=$p['receiving']['argumentIndex'];$secondary=$i->annotations[1]??null;$expected='Stringable|null|scalar';
            if($i->level!==Level::Error||$i->code!=='mixed-argument'||count($i->annotations)!==2||$secondary===null||$secondary->kind!==AnnotationKind::Secondary||$secondary->file!==null&&$secondary->file!==''||[$secondary->span->start,$secondary->span->end]!==$p['receiving']['nameSpan']||$secondary->message!=='Arguments to this function are incorrect'||$i->message!=='Invalid argument type for argument #'.($index+1).' of `sprintf`: expected `'.$expected.'`, but found `mixed`.'||$i->notes!==['The type `mixed` is too general and does not match the expected type `'.$expected.'`.']||$i->help!=='Add specific type hints or assertions to the argument value.'||$a->message!=='Argument has type `mixed`'){return $r;}
            $receiving=(new NodeFinder)->findFirst($nodes,static fn(Node $n):bool=>$n instanceof Node\Expr\FuncCall&&Source::span($n)===$p['receiving']['span']);$types=[];foreach($p['values'] as $value){$types[]=match($value['kind']){'int'=>Type::literalInt($value['value']),'string'=>Type::literalString($value['value']),'float'=>Type::float(),'bool'=>$value['value']?Type::true():Type::false(),'null'=>Type::null()};}$domain=$types===[]?Type::null():array_reduce(array_slice($types,1),static fn(Type $a,Type $b):Type=>Type::union($a,$b),$types[0]);
        }unset($nodes,$proofs,$types);
        $r['stage']='current-default-Factory-formatter-contract';$native=$this->contracts->current($context,$p);$r['contract']=$native;if(!$native['admitted']){return $r;}$profiles=$native['nativeProfiles'];
        if($receiving!==null){$r['stage']='genuine-sprintf-receiving-formal';$physical=new Physical($this->root);$method=$physical->builtinBinding($context,$receiving,'sprintf');if($method===null||$method->flags->contains(MetadataFlags::BY_REFERENCE)||$method->returnType===null){return $r;}$last=count($method->parameters)-1;$index=$p['receiving']['argumentIndex'];$formal=$method->parameters[min($index,$last)]??null;
            if($last<1||$formal===null||$index<$last||!$formal->flags->contains(MetadataFlags::VARIADIC)||$formal->flags->contains(MetadataFlags::BY_REFERENCE)||$formal->outType!==null||$formal->closureThisType!==null||$formal->type===null||!$context->types->isContainedBy($domain,$formal->type->type)){return $r;}$profiles['selected-receiving']=Boundary::compact($method);}
        $r['domain']=(string)$domain;$r['stage']='genuine-current-formatter-profile-pending';foreach($profiles as $symbol=>$profile){$r['observedNativeProfiles'][$symbol]=['sha256'=>self::hash($profile),'compact'=>$profile];}
        foreach($r['observedNativeProfiles'] as $symbol=>$profile){if(($this->observedHashes[$symbol]??null)!==$profile['sha256']){return $r;}}$r['stage']='current-source-bound-default-formatter-domain';$r['remove']=true;return $r;
    }
    private static function hash(array $value):string{$sort=static function(mixed $v)use(&$sort):mixed{if(is_array($v)){foreach($v as $k=>$item){$v[$k]=$sort($item);}if(!array_is_list($v)){ksort($v);}}return $v;};return hash('sha256',json_encode($sort($value),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));}
}
