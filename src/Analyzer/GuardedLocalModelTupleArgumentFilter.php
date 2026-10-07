<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterDecision,IssueFilterHook};
use Mago\Sdk\Analyzer\Metadata\FunctionLikeKind;
use PhpParser\{Node,NodeFinder};

/** Independent model projection; captured-formal and tuple-shape rules retain their own scope. */
final class GuardedLocalModelTupleArgumentFilter implements IssueFilterHook
{
    public array $stages=[];public array $dependencies=[];public array $certificate=[];
    public function __construct(private readonly string $root){}
    public function getCodes():array{return ['mixed-argument'];}
    public function filterIssue(IssueFilterContext $context):IssueFilterDecision
    {
        $proof=new self($this->root);$decision=$proof->evaluate($context);
        $this->stages=$proof->stages;$this->dependencies=$proof->dependencies;$this->certificate=$proof->certificate;return $decision;
    }
    private function evaluate(IssueFilterContext $context):IssueFilterDecision
    {
        $receivingProof=new SourceArgumentDeclarationContracts($this->root);$tuple=new PossibleCallbackTupleContracts($this->root);$models=[];
        try{
            $receiving=$receivingProof->receiving($context,['mixed-argument']);if($receiving===null){return IssueFilterDecision::Keep;}
            ['source'=>$source,'file'=>$file,'argument'=>$argument,'call'=>$call]=$receiving;
            if(!$receivingProof->stage('selected physical method receiving scope',
                ($call instanceof Node\Expr\MethodCall||$call instanceof Node\Expr\StaticCall)
                &&$receiving['native']->kind===FunctionLikeKind::Method
                &&is_string($receiving['native']->identifier->class)&&$receiving['native']->identifier->class!=='')){
                return IssueFilterDecision::Keep;
            }
            if(!$tuple->receivingDeclaration($context,$source,$receiving)){return IssueFilterDecision::Keep;}
            $value=$argument->value;
            if(!$receivingProof->stage('one selected local tuple model projection',$value instanceof Node\Expr\Variable&&is_string($value->name))){return IssueFilterDecision::Keep;}
            $lexical=$tuple->lexical($source,$file,$call);if($lexical===null){return IssueFilterDecision::Keep;}
            $position=array_search($value->name,$lexical['names'],true);
            if(!$receivingProof->stage('one exact positional model projection',$position!==false)){return IssueFilterDecision::Keep;}
            $owner=$tuple->bindOwner($context,$source,$file,$lexical,$call);if($owner===null){return IssueFilterDecision::Keep;}
            if(!$receivingProof->stage('native caller method kind agrees with current named scope',
                $owner->kind===FunctionLikeKind::Method
                &&$owner->identifier->kind===\Mago\Sdk\Analyzer\Type\FunctionLikeKind::Method
                &&is_string($owner->identifier->class)&&$owner->identifier->class!=='')){
                return IssueFilterDecision::Keep;
            }
            foreach((new NodeFinder)->findInstanceOf($lexical['loop']->stmts??[],Node\Expr\Variable::class) as $use){
                if($use->name===$value->name&&$use->getStartFilePos()<$value->getStartFilePos()){
                    $receivingProof->stage('projected local unchanged before receiving argument',false);return IssueFilterDecision::Keep;
                }
            }
            $domains=[];$modelCertificates=[];
            foreach($lexical['appends'] as $number=>[$append]){
                $model=new GuardedLocalQueryModelContract($this->root);$models[$number]=$model;
                $domain=$model->localModel($context,$source,$file,$lexical,$owner,$append->expr->items[$position]->value,$append);
                if($domain===null||!$receivingProof->admits($context,$receiving,$domain)){return IssueFilterDecision::Keep;}
                $domains[]=(string)$domain;$modelCertificates[]=$model->certificate;
            }
            // A containment request may reenter a hook. Recheck every selected
            // physical input after the last RPC using this invocation's values.
            $sourceHashes=[$file['path']=>$file['hash']];
            foreach($modelCertificates as $certificate){
                foreach($certificate['sourceHashes']??[] as $path=>$hash){
                    if(isset($sourceHashes[$path])&&$sourceHashes[$path]!==$hash){return IssueFilterDecision::Keep;}
                    $sourceHashes[$path]=$hash;
                }
            }
            foreach($sourceHashes as $path=>$hash){
                if(!is_file($path)||hash_file('sha256',$path)!==$hash){return IssueFilterDecision::Keep;}
            }
            $tuple->certify($file,$lexical,['projection'=>$position,'modelDomains'=>$domains,
                'argumentSpan'=>SourceArgumentDeclarationContracts::span($value),'independentLocalModelRule'=>true,
                'modelCertificates'=>$modelCertificates,'sourceHashes'=>$sourceHashes]);
            $this->certificate=$tuple->certificate;return IssueFilterDecision::Remove;
        }finally{
            $this->stages=array_merge($receivingProof->stages,$tuple->stages);$this->dependencies=$receivingProof->dependencies+$tuple->dependencies;
            foreach($models as $number=>$model){foreach($model->stages as $stage){$stage['stage']='model '.$number.': '.$stage['stage'];$this->stages[]=$stage;}
                foreach($model->dependencies as $role=>$dependency){$this->dependencies['model '.$number.': '.$role]=$dependency;}}
        }
    }
}
