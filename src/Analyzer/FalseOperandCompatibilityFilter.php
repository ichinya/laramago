<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\FalseOperandPolicy;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;

/** Match native PHP false conversions only at bounded ordered-comparison and string-concatenation sites. */
final class FalseOperandCompatibilityFilter implements IssueFilterHook, InitializationHook
{
    private FalseOperandPolicy $policy;

    public function __construct() { $this->policy = new FalseOperandPolicy; }
    public function initialize(InitializationContext $context): void { $this->policy->reset(); }
    public function getCodes(): array { return ['possibly-false-operand']; }
    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        return $this->policy->proposal($context) === null ? IssueFilterDecision::Keep : IssueFilterDecision::Remove;
    }
}
