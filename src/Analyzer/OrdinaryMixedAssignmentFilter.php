<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\OrdinaryMixedBindings;

use Mago\Sdk\Analyzer\{InitializationContext,InitializationHook,IssueFilterContext,IssueFilterDecision,IssueFilterHook};
use Mago\Sdk\Reporting\{AnnotationKind,Level};

final class OrdinaryMixedAssignmentFilter implements IssueFilterHook,InitializationHook
{
    private array $cache=[];
    public function initialize(InitializationContext $context):void{$this->cache=[];}
    public function getCodes():array{return ['mixed-assignment'];}
    public function filterIssue(IssueFilterContext $context):IssueFilterDecision
    {
        $decision=IssueFilterDecision::Keep;$proof=null;$issue=$context->issue;
        if(!$context->cancellation->isCancelled()&&strlen($context->contents)<=1024*1024&&$issue->code==='mixed-assignment'&&$issue->level===Level::Warning
            &&$issue->message==='Assigning `mixed` type to a variable may lead to unexpected behavior.'
            &&$issue->notes===['Using `mixed` can lead to runtime errors if the variable is used in a way that assumes a specific type.']
            &&$issue->help==='Consider using a more specific type to avoid potential issues.'&&$issue->link===null&&$issue->edits===[]
            &&in_array(count($issue->annotations),[1,2],true)){
            $primary=null;$secondary=null;$valid=true;
            foreach($issue->annotations as$annotation){
                if($annotation->file!==null&&$annotation->file!==''||$annotation->span->start<0||$annotation->span->end<=$annotation->span->start||$annotation->span->end>strlen($context->contents)){$valid=false;break;}
                if($annotation->kind===AnnotationKind::Primary&&$primary===null&&$annotation->message==='Assigning `mixed` type here.'){$primary=$annotation;}
                elseif($annotation->kind===AnnotationKind::Secondary&&$secondary===null&&$annotation->message==='This expression has type `mixed`.'){$secondary=$annotation;}
                else{$valid=false;break;}
            }
            if($valid&&$primary!==null&&($secondary!==null||count($issue->annotations)===1)){
                $key=hash('sha256',$context->file."\0".$context->contents);
                if(!array_key_exists($key,$this->cache)){
                    if(count($this->cache)>=8){array_shift($this->cache);}
                    $this->cache[$key]=(new OrdinaryMixedBindings())->inspect($context->contents);
                }
                $proof=$this->cache[$key][$primary->span->start.':'.$primary->span->end]??null;
                if($proof!==null&&($secondary===null||$proof['rhs']===[$secondary->span->start,$secondary->span->end])){$decision=IssueFilterDecision::Remove;}
            }
        }
        return$decision;
    }
}
