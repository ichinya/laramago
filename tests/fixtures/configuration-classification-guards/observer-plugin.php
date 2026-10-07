<?php
declare(strict_types=1);
namespace Example\ProducerGuards; use Ichinya\Laramago\Analyzer\DefensiveBoundaryGuardProof as BoundaryGuardProof;
use Mago\Sdk\Analyzer\{IssueFilterContext,IssueFilterDecision,IssueFilterHook,Plugin,PluginDefinition,PluginRegistry};

final class BoundaryGuardPlugin implements Plugin
{
    public function __construct(private readonly string $root,private readonly bool $preserve = false,private readonly bool $alwaysKeep = false,
        private readonly ?string $observer = null,private readonly ?\Closure $control = null) {}
    public function getDefinition(): PluginDefinition { return new PluginDefinition('example/defensive-boundary-advisories','Defensive boundary advisories','An explicit reporting policy for certified boundary checks; native types and Errors remain unchanged.'); }
    public function register(PluginRegistry $registry): void
    {
        $proof = new BoundaryGuardProof($this->root); $registry->registerInitializationHook($proof); $registry->registerCodebaseScanHook($proof);
        $registry->registerIssueFilterHook(new BoundaryGuardFilter($proof,$this->preserve,$this->alwaysKeep,$this->observer,$this->control));
    }
}
final class BoundaryGuardFilter implements IssueFilterHook
{
    public function __construct(private readonly BoundaryGuardProof $proof,private readonly bool $preserve,private readonly bool $alwaysKeep,private readonly ?string $observer,private readonly ?\Closure $control) {}
    public function getCodes(): array { return ['redundant-type-comparison','impossible-condition','redundant-condition','impossible-type-comparison']; }
    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        $receipt = $this->proof->prove($context);
        if ($this->control !== null) { ($this->control)($context,$this->proof,$receipt); }
        $remove = $receipt['remove'] && !$this->alwaysKeep && !$this->preserve;
        if ($this->observer !== null) { file_put_contents($this->observer,json_encode(['stage'=>'boundary-guard-policy','file'=>$context->file,
            'span'=>[$context->issue->annotations[0]->span->start ?? -1,$context->issue->annotations[0]->span->end ?? -1],
            'sourceSha256'=>hash('sha256',$context->contents),'decision'=>$remove?'Remove':'Keep','alwaysKeep'=>$this->alwaysKeep,'preserve'=>$this->preserve,
            'proof'=>$receipt,'wholeNativeEnvelope'=>BoundaryGuardProof::canonical($context->issue)],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX); }
        return $remove ? IssueFilterDecision::Remove : IssueFilterDecision::Keep;
    }
}
