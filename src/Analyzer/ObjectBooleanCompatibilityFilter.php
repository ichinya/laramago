<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ObjectBooleanPolicy;
use Mago\Sdk\Analyzer\{InitializationContext,InitializationHook,IssueFilterContext,IssueFilterDecision,IssueFilterHook};
/** Match PHPStan's object boolean reporting while preserving all operand types. */
final class ObjectBooleanCompatibilityFilter implements InitializationHook,IssueFilterHook
{
    private ObjectBooleanPolicy $policy;
    public function __construct() { $this->policy=new ObjectBooleanPolicy; }
    public function initialize(InitializationContext $context):void { $this->policy=new ObjectBooleanPolicy; }
    public function getCodes():array { return ['invalid-operand']; }
    public function filterIssue(IssueFilterContext $context):IssueFilterDecision {
        return $this->policy->proposal($context)===null?IssueFilterDecision::Keep:IssueFilterDecision::Remove;
    }
}