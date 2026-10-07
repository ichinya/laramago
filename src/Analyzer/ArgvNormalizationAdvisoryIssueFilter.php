<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ArgvNormalizationAdvisories;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;

/** Correct only the exact Warning certified by the current argv source generation. */
final class ArgvNormalizationAdvisoryIssueFilter implements IssueFilterHook
{
    public function __construct(private readonly ArgvNormalizationAdvisories $advisories) {}
    public function getCodes(): array { return ['redundant-condition']; }
    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        return $this->advisories->facts($context)['arrayAdvisoryEligible'] ? IssueFilterDecision::Remove : IssueFilterDecision::Keep;
    }
}
