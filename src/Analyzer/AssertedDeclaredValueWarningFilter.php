<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\{AssertedDeclaredValueNativeContracts,AssertedDeclaredValueSource,NullFlowNativeContracts};
use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterDecision,IssueFilterHook};

/** Only the contradictory null warning on an exact source-bound declared value postcondition. */
final class AssertedDeclaredValueWarningFilter implements IssueFilterHook
{
    public function __construct(private readonly string $root) {}
    public function getCodes():array { return ['impossible-null-type-comparison']; }
    public function filterIssue(IssueFilterContext $context):IssueFilterDecision { return $this->evaluate($context)['decision']; }
    public function evaluate(IssueFilterContext $context):array
    {
        $result=['decision'=>IssueFilterDecision::Keep,'sourceProof'=>null,'nativeReceipt'=>[],'exactNativeEnvelope'=>false];$issue=$context->issue;
        if($issue->level->name!=='Warning'||$issue->code!=='impossible-null-type-comparison'||$issue->link!==null||$issue->edits!==[]||count($issue->annotations)!==1) { return $result; }
        $primary=$issue->annotations[0];
        if($primary->kind->name!=='Primary'||!NullFlowNativeContracts::sameFile($primary->file??$context->file,$context->file)
            ||$primary->span->start<0||$primary->span->end>$primary->span->start+20000||$primary->span->end>strlen($context->contents)) { return $result; }
        $proof=AssertedDeclaredValueSource::inspect($context->contents,$primary->span->start,$primary->span->end);$result['sourceProof']=$proof;
        if($proof===null) { return $result; }
        $expression=$this->expression($proof['targetExpression']);$type=$proof['kind']==='generic-equality-string-postcondition'?'string':$proof['modelClass'];
        if($expression===null||$primary->message!=='This condition always evaluates to false'
            ||$issue->message!=='Impossible condition: variable `'.$expression.'` (type `'.$type.'`) will always be `null`.'
            ||$issue->notes!==['Variable `'.$expression.'` (type `'.$type.'`) is already known to be `null`, so asserting it\'s not `null` is impossible.']
            ||$issue->help!=='The condition checking if `'.$expression.'` is not `null` will always be false. Review the variable\'s state or condition.') { return $result; }
        $result['exactNativeEnvelope']=true;$receipt=[];
        $admitted=(new AssertedDeclaredValueNativeContracts($this->root))->admits($context,$proof,$receipt);
        // SDK queries may reenter this filter. Only this completed call's local admission authorizes its decision.
        $result['nativeReceipt']=$receipt;if($admitted) { $result['decision']=IssueFilterDecision::Remove; }return $result;
    }
    private function expression(array $expression):?string
    {
        if($expression['kind']==='variable') { return '$'.$expression['name']; }
        if($expression['kind']!=='property') { return null; }$receiver=$this->expression($expression['receiver']);
        return $receiver===null?null:$receiver.'->'.$expression['name'];
    }
}
