<?php
declare(strict_types=1);
namespace Example\DeprecatedMethods; use Ichinya\Laramago\Analyzer\DeprecatedMethodCompatibilityProof as MethodDeprecationCompatibilityProof;

use Mago\Sdk\Analyzer\{IssueFilterContext, IssueFilterDecision, IssueFilterHook, Plugin, PluginDefinition, PluginRegistry};

/** Reporting policy only; method signatures, values and deprecation metadata stay native. */
final class MethodDeprecationCompatibilityPlugin implements Plugin
{
    public function __construct(private readonly string $root, private readonly bool $preserveAdvisories = false,
        private readonly bool $alwaysKeep = false, private readonly ?string $observer = null, private readonly ?\Closure $control = null) {}
    public function getDefinition(): PluginDefinition { return new PluginDefinition('example/method-deprecation-compatibility', 'Method deprecation compatibility', 'Bounded default PHPStan reporting policy for observed methods; native contracts remain unchanged.'); }
    public function register(PluginRegistry $registry): void
    {
        $proof = new MethodDeprecationCompatibilityProof($this->root);
        $registry->registerInitializationHook($proof); $registry->registerCodebaseScanHook($proof);
        $registry->registerIssueFilterHook(new MethodDeprecationCompatibilityIssueFilter($proof, $this->preserveAdvisories, $this->alwaysKeep, $this->observer, $this->control));
    }
}
final class MethodDeprecationCompatibilityIssueFilter implements IssueFilterHook
{
    public function __construct(private readonly MethodDeprecationCompatibilityProof $proof, private readonly bool $preserve,
        private readonly bool $alwaysKeep, private readonly ?string $observer, private readonly ?\Closure $control) {}
    public function getCodes(): array { return ['deprecated-method']; }
    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        $receipt = $this->proof->prove($context);
        if ($this->control !== null) { ($this->control)($context, $this->proof, $receipt); }
        $remove = $receipt['remove'] && ! $this->alwaysKeep && ! $this->preserve;
        if ($this->observer !== null) { file_put_contents($this->observer, json_encode(['stage' => 'method-deprecation-policy', 'file' => $context->file,
            'sourceSha256' => hash('sha256', $context->contents), 'span' => [$context->issue->annotations[0]->span->start ?? -1, $context->issue->annotations[0]->span->end ?? -1],
            'decision' => $remove ? 'Remove' : 'Keep', 'alwaysKeep' => $this->alwaysKeep, 'preserveAdvisories' => $this->preserve, 'proof' => $receipt,
            'wholeNativeEnvelope' => MethodDeprecationCompatibilityProof::canonical($context->issue)], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX); }
        return $remove ? IssueFilterDecision::Remove : IssueFilterDecision::Keep;
    }
}
