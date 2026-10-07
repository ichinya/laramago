<?php

declare (strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\{IssueFilterContext, IssueFilterDecision, IssueFilterHook, Plugin, PluginDefinition, PluginRegistry};
/** Default PHPStan deprecated-method reporting policy; signatures and deprecation metadata stay native. */
final class DeprecatedMethodCompatibilityPlugin implements Plugin
{
    public function __construct(private readonly string $root, private readonly bool $preserveAdvisories = false)
    {
    }
    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition('ichinya/deprecated-method-compatibility', 'Deprecated method compatibility', 'Bounded default PHPStan reporting policy with native contracts preserved.');
    }
    public function register(PluginRegistry $registry): void
    {
        $proof = new DeprecatedMethodCompatibilityProof($this->root);
        $registry->registerInitializationHook($proof);
        $registry->registerCodebaseScanHook($proof);
        $registry->registerIssueFilterHook(new DeprecatedMethodCompatibilityFilter($proof, $this->preserveAdvisories));
    }
}
final class DeprecatedMethodCompatibilityFilter implements IssueFilterHook
{
    public function __construct(private readonly DeprecatedMethodCompatibilityProof $proof, private readonly bool $preserve)
    {
    }
    public function getCodes(): array
    {
        return ['deprecated-method'];
    }
    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        return !$this->preserve && $this->proof->prove($context)['remove'] ? IssueFilterDecision::Remove : IssueFilterDecision::Keep;
    }
}
