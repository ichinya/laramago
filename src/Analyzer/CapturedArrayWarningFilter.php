<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\{CapturedArrayNativeContracts,CapturedArrayPostconditions};
use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterDecision,IssueFilterHook};

/** Exact captured-array warnings backed by current lexical and native contracts. Unaccepted ignored candidate. */
final class CapturedArrayWarningFilter implements IssueFilterHook
{
    public function __construct(private readonly string $root) {}
    public function getCodes():array { return ['possibly-null-array-index','possibly-undefined-int-array-index']; }
    public function filterIssue(IssueFilterContext $context):IssueFilterDecision { return $this->evaluate($context)['decision']; }
    public function evaluate(IssueFilterContext $context):array
    {
        $keep=['decision'=>IssueFilterDecision::Keep,'nativeAdmissionClaimed'=>false];$issue=$context->issue;
        if($issue->level->name!=='Warning'||!in_array($issue->code,$this->getCodes(),true)||count($issue->annotations)!==1||$issue->annotations[0]->kind->name!=='Primary'
            ||$issue->edits!==[]||$issue->link!==null||strlen($issue->message)>4096||strlen($context->contents)>2_000_000) { return $keep; }
        $span=$issue->annotations[0]->span;$proof=CapturedArrayPostconditions::inspect($context->contents,$issue->code,$span->start,$span->end);
        $file=str_replace('\\','/',$issue->annotations[0]->file??$context->file);$current=str_replace('\\','/',$context->file);
        if(str_starts_with($file,'//?/')) { $file=substr($file,4); }if(str_starts_with($current,'//?/')) { $current=substr($current,4); }
        if(strcasecmp($file,$current)!==0||$span->start<0||$span->start>=$span->end||$span->end>strlen($context->contents)) { return $keep; }
        if($proof===null||!$this->envelope($context,$proof)) { return $keep; }
        $native=[];$admitted=(new CapturedArrayNativeContracts($this->root))->admits($context,$proof,$proof['scope'],$native);
        return ['decision'=>$admitted?IssueFilterDecision::Remove:IssueFilterDecision::Keep,'sourceProof'=>$proof,'actualNativeCertificate'=>$native,
            'nativeAdmissionClaimed'=>$admitted,'positiveNativeDTOsChanged'=>false];
    }
    private function envelope(IssueFilterContext $context,array $proof):bool
    {
        $issue=$context->issue;$primary=$issue->annotations[0];
        if($issue->code==='possibly-null-array-index') {
            return $issue->message==='Possibly using `null` as an array index to access elementof variable $'.$proof['arrayLocal'].'.'
                &&$primary->message==='Index might be `null` here.'
                &&$issue->help==='Ensure the index is always an integer or a string, potentially using checks or assertions before access.'
                &&$issue->notes===["Using `null` as an array key is equivalent to using an empty string `''`.",'The analysis indicates this index could be `null` at runtime.'];
        }
        $key=$proof['integerKey'];
        return preg_match('/^Possibly undefined array key '.preg_quote((string)$key,'/').' accessed on `array\{[^\r\n]+\}`\.$/D',$issue->message)===1
            &&preg_match('/(?:\{|, )'.preg_quote((string)$key,'/').'\?: /',$issue->message)===1
            &&$primary->message==='Key '.$key.' might not exist.'
            &&$issue->help==='Ensure the key '.$key.' is always set before accessing it, or use `isset()` or the null coalesce operator (`??`) to handle potential missing keys.'
            &&$issue->notes===['The analysis indicates this specific key might not be set when this access occurs.'];
    }
}
