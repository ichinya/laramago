<?php

declare (strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\{IssueFilterContext, IssueFilterDecision, IssueFilterHook, Plugin, PluginDefinition, PluginRegistry};
/** Explicit reporting policy for source-certified defensive boundary guards. */
final class DefensiveBoundaryGuardPlugin implements Plugin
{
    public function __construct(private readonly string $root, private readonly bool $preserveAdvisories = false)
    {
    }
    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition('ichinya/defensive-boundary-advisories', 'Defensive boundary advisories', 'Certified boundary guard Warnings can be omitted while native types, Errors and control flow stay unchanged.');
    }
    public function register(PluginRegistry $registry): void
    {
        $proof = new DefensiveBoundaryGuardProof($this->root);
        $registry->registerInitializationHook($proof);
        $registry->registerCodebaseScanHook($proof);
        $registry->registerIssueFilterHook(new DefensiveBoundaryGuardFilter($proof, $this->preserveAdvisories));
    }
}
final class DefensiveBoundaryGuardFilter implements IssueFilterHook
{
    public function __construct(private readonly DefensiveBoundaryGuardProof $proof, private readonly bool $preserve)
    {
    }
    public function getCodes(): array
    {
        return ['redundant-type-comparison', 'impossible-condition', 'redundant-condition', 'impossible-type-comparison'];
    }
    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        return !$this->preserve && $this->proof->prove($context)['remove'] ? IssueFilterDecision::Remove : IssueFilterDecision::Keep;
    }
}
