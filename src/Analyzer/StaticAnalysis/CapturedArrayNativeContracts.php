<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\{IssueFilterContext,Type};
use Mago\Sdk\Analyzer\Assertion\{TypeAssertion,TypeAssertionKind};
use Mago\Sdk\Analyzer\Metadata\{FunctionLikeMetadata,MetadataFlags};
use Mago\Sdk\Analyzer\Type\{CallableType,SimpleAtomicType,SimpleAtomicTypeKind};

/** Source/native admission for the lexical captured-array certificate. No return provider or callback IDs. */
final class CapturedArrayNativeContracts
{
    public function __construct(private readonly string $root) {}
    public function admits(IssueFilterContext $context,array $proof,array $scope,array &$receipt):bool
    {
        // Every invocation owns fresh local state; SDK queries may reenter the same hook.
        $reader=new NullFlowNativeContracts($this->root);$details=[];
        if($proof['sourceSha256']!==hash('sha256',$context->contents)||!$reader->current($context->file,$context->contents)
            ||$proof['scope']['owner']!==$scope['owner']||$proof['scope']['name']!==$scope['name']||!$reader->caller($context,$scope,$details)) { $receipt=$details;return false; }
        $scope=$proof['scope']; // Use the closed lexical fresh-New receiver, never a later or nested assignment guess.
        $namespace=str_contains($scope['owner'],'\\')?substr($scope['owner'],0,strrpos($scope['owner'],'\\')):'';
        foreach($proof['arrayReadBuiltinFormals'] as $name=>$positions) {
            $builtin=$context->codebase->getFunction($name);$shadow=$namespace===''?null:$context->codebase->getFunction($namespace.'\\'.$name);
            $details['arrayReadBuiltins'][$name]=['actual'=>$builtin,'namespaceShadow'=>$shadow];
            if($shadow!==null||$builtin===null||!$builtin->flags->contains(MetadataFlags::BUILTIN)||$builtin->flags->contains(MetadataFlags::BY_REFERENCE)
                ||strcasecmp($builtin->identifier->name,$name)!==0||$builtin->identifier->class!==null||$builtin->abstract||$builtin->constructor) { $receipt=$details;return false; }
            foreach(array_unique($positions) as $position) {
                $formal=$builtin->parameters[$position]??null;
                if($formal===null||$formal->flags->contains(MetadataFlags::BY_REFERENCE)||$formal->flags->contains(MetadataFlags::VARIADIC)
                    ||$formal->outType!==null||$formal->closureThisType!==null) { $receipt=$details;return false; }
            }
        }
        $selected=$proof['selectedCallback'];$call=$selected['invocation'];$binding=[];
        $owner=$reader->className($context,$call['receiver'],$scope,$binding);
        $details['callbackReceiver']=['owner'=>$owner,'sourceBinding'=>$binding];
        if($owner===null) { $receipt=$details;return false; }
        $callee=$context->codebase->getDeclaringMethod($owner,$call['name']);$bound=$callee===null?null:PhysicalCallableFunctionBinding::current($context,$reader,$callee);
        $details['callbackCallee']=['actual'=>$callee,'source'=>$bound,'argumentIndex'=>$selected['argumentIndex']];
        if($callee===null||$bound===null||$callee->static||$callee->abstract||$callee->constructor||$callee->flags->contains(MetadataFlags::BUILTIN)
            ||$callee->flags->contains(MetadataFlags::BY_REFERENCE)||!$this->arguments($call,$callee)) { $receipt=$details;return false; }
        $parameter=$callee->parameters[$selected['argumentIndex']]??null;
        if($parameter===null||$parameter->flags->contains(MetadataFlags::BY_REFERENCE)||$parameter->flags->contains(MetadataFlags::VARIADIC)
            ||$parameter->outType!==null||$parameter->closureThisType!==null||$parameter->declaredType===null||$parameter->declaredType->inferred||$parameter->declaredType->fromDocblock) { $receipt=$details;return false; }
        $physical=$bound['source']['parameters'][$selected['argumentIndex']]['type']??null;
        if(!is_string($physical)||!$this->physicalCallable($context,$parameter->declaredType->type,$physical)) { $receipt=$details;return false; }
        if($proof['kind']==='flag-proves-earlier-append') {
            $assertion=$proof['flagAssertion'];$native=$context->codebase->getDeclaringMethod($assertion['class'],$assertion['name']);$assertionSource=$native===null?null:$reader->functionBound($context,$native);
            $formal=$native?->parameters[0]??null;$assertions=$formal===null?[]:($native->assertions[$formal->name]??$native->assertions[ltrim($formal->name,'$')]??[]);
            $true=$assertions!==[];foreach($assertions as $assert) { if(!$assert instanceof TypeAssertion||$assert->kind!==TypeAssertionKind::IsType||!$context->types->equals($assert->type,Type::true())) { $true=false; } }
            $details['assertionTrue']=['actual'=>$native,'source'=>$assertionSource,'actualTrueAssertion'=>$true];
            if($native===null||$assertionSource===null||!$native->static||$native->abstract||$native->constructor||$native->assertionsInferred||$native->flags->contains(MetadataFlags::BY_REFERENCE)
                ||$formal===null||$formal->flags->contains(MetadataFlags::BY_REFERENCE)||$formal->outType!==null||$formal->closureThisType!==null
                ||!$this->arguments($assertion,$native)||!$true||preg_match('/@phpstan-assert\s+true\s+'.preg_quote($formal->name,'/').'(?:\s|$)/m',$assertionSource['source']['doc'])!==1) { $receipt=$details;return false; }
            $details['possibleDispatchWithAssertedFlagSufficient']=true;$details['guaranteedCallbackDispatchClaimed']=false;
        } elseif($proof['kind']==='normal-continuation-after-callback-key-write') {
            $bytes=$reader->bytes($callee->location->file??'');$sourceMethod=$bound['source'];
            $dispatcher=$bytes===null?null:CapturedArrayPostconditions::directDispatcher($bytes,$sourceMethod['span'],ltrim($parameter->name,'$'));
            $details['normalContinuationDispatcher']=$dispatcher;
            if($dispatcher===null||$parameter->defaultType!==null||$parameter->flags->contains(MetadataFlags::HAS_DEFAULT)
                ||$parameter->declaredType->location===null||$parameter->nameLocation===null
                ||$dispatcher['formalTypeSpan']!==[$parameter->declaredType->location->span->start,$parameter->declaredType->location->span->end]
                ||$dispatcher['formalNameSpan']!==[$parameter->nameLocation->span->start,$parameter->nameLocation->span->end]
                ||ltrim($physical,'\\')!=='Closure') { $receipt=$details;return false; }
        } else { $receipt=$details;return false; }
        // Recheck current bodies after all SDK requests; only then publish this evaluation's receipt.
        if(!$reader->current($context->file,$context->contents)||PhysicalCallableFunctionBinding::current($context,$reader,$callee)===null) { $receipt=$details;return false; }
        $details['currentSourceAndNativeBound']=true;$receipt=$details;return true;
    }
    private function arguments(array $expression,FunctionLikeMetadata $native):bool
    {
        $arguments=$expression['arguments']??[];if(count($arguments)>count($native->parameters)) { return false; }
        foreach($arguments as $index=>$argument) { $formal=$native->parameters[$index]??null;if(($argument['name']??null)!==null||$formal===null
            ||$formal->flags->contains(MetadataFlags::VARIADIC)||$formal->flags->contains(MetadataFlags::BY_REFERENCE)||$formal->outType!==null) { return false; } }
        foreach(array_slice($native->parameters,count($arguments)) as $formal) { if(!$formal->flags->contains(MetadataFlags::HAS_DEFAULT)) { return false; } }return true;
    }
    private function physicalCallable(IssueFilterContext $context,Type $type,string $physical):bool
    {
        // The ABI represents physical Closure/callable as CallableType, not a NamedObjectType.
        // Both actual families are read from the current source formal, including ?callable for a possible stage hook.
        $optional=str_starts_with($physical,'?');$name=ltrim($optional?substr($physical,1):$physical,'\\');
        if(!in_array($name,['Closure','callable'],true)||$type->flags->byReference||$type->flags->possiblyUndefined) { return false; }
        $callables=[];$nulls=0;
        foreach($type->atomicTypes as $atom) { if($atom instanceof SimpleAtomicType&&$atom->kind===SimpleAtomicTypeKind::Null) { $nulls++; }
            elseif($atom instanceof CallableType) { $callables[]=$atom; }else { return false; } }
        if($nulls!==($optional?1:0)||count($callables)!==1||$callables[0]->alias!==null||$callables[0]->signature===null) { return false; }
        $signature=$callables[0]->signature;$parameter=$signature->parameters[0]??null;
        return !$signature->pure&&$signature->closure===($name==='Closure')&&$signature->source===null&&$signature->constraints===[]
            &&count($signature->parameters)===1&&$parameter!==null&&$parameter->name===null&&$parameter->type!==null&&!$parameter->byReference&&$parameter->variadic
            &&$parameter->closureThisType===null&&$context->types->equals($parameter->type,Type::mixed())
            &&$signature->returnType!==null&&$context->types->equals($signature->returnType,Type::mixed());
    }
}
