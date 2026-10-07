<?php
declare(strict_types=1);
require dirname(__DIR__, 3).'/src/Analyzer/DefensiveBoundaryGuardProof.php';
require dirname(__DIR__, 3).'/src/Analyzer/DefensiveConfigurationMemberSource.php';
require dirname(__DIR__, 3).'/src/Analyzer/DefensiveConfigurationMemberFilter.php';
require dirname(__DIR__, 3).'/tests/fixtures/configuration-classification-guards/ControlledCache.php';
use Ichinya\Laramago\Analyzer\{DefensiveBoundaryGuardProof, DefensiveConfigurationMemberFilter, DefensiveConfigurationMemberSource, DefensiveBoundaryGuardSource};
use Mago\Sdk\Analyzer\{Plugin, PluginDefinition, PluginRegistry, IssueFilterHook, IssueFilterContext, IssueFilterDecision, Type};
use Example\ProducerGuards\ControlledCache;
final class ConfigurationMemberNativePlugin implements Plugin
{
    public function __construct(private readonly string $root, private readonly string $mode, private readonly string $output) {}
    public function getDefinition(): PluginDefinition { return new PluginDefinition('example/configuration-member-native','Configuration member native gate','Current source and genuine native contracts.'); }
    public function register(PluginRegistry $registry): void
    {
        // The current legacy guard plugin remains registered in the production worker.
        $proof = new DefensiveBoundaryGuardProof($this->root);
        $registry->registerInitializationHook($proof);
        $registry->registerCodebaseScanHook($proof);
        $registry->registerIssueFilterHook(new ConfigurationMemberNativeFilter($proof, $this->mode, $this->output));
    }
}
final class ConfigurationMemberNativeFilter implements IssueFilterHook
{
    private readonly DefensiveConfigurationMemberFilter $filter;
    public function __construct(private readonly DefensiveBoundaryGuardProof $proof, private readonly string $mode, private readonly string $output)
    { $this->filter = new DefensiveConfigurationMemberFilter($proof); }
    public function getCodes(): array { return $this->filter->getCodes(); }
    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        $decision = $this->filter->filterIssue($context);
        $primary = $context->issue->annotations[0] ?? null;
        $site = $primary === null ? null : (DefensiveConfigurationMemberSource::compile(DefensiveBoundaryGuardSource::parse($context->contents))[$primary->span->start.':'.$primary->span->end] ?? null);
        $receipt = $site === null ? null : $this->proof->proveConfigurationGuard($context, $site['predicate']);
        if ($decision === IssueFilterDecision::Remove && str_starts_with($this->mode, 'cache-')) {
            if ($this->mode === 'cache-predicate-domain') {
                $native = $context->codebase->getFunction('is_array');
                if ($native === null || $native->parameters[0]->type === null || $native->parameters[0]->declaredType === null) { throw new RuntimeException('Actual predicate metadata missing.'); }
                $parameters = $native->parameters;
                $parameters[0] = ControlledCache::copy($parameters[0], ['type'=>ControlledCache::copy($parameters[0]->type,['type'=>Type::int()]),'declaredType'=>ControlledCache::copy($parameters[0]->declaredType,['type'=>Type::int()])]);
                $replacement = ControlledCache::copy($native, ['parameters'=>$parameters]);
            } elseif ($this->mode === 'cache-producer-contract') {
                $name = null;
                foreach ($receipt['nativeProducers'] as $producer) { if (isset($producer['native']['symbol'])) { $name = $producer['native']['symbol']; break; } }
                if ($name === null) { throw new RuntimeException('Actual producer metadata missing.'); }
                $parts = explode('::', $name);
                $native = count($parts) === 2 ? $context->codebase->getDeclaringMethod($parts[0], $parts[1]) : $context->codebase->getFunction($name);
                if ($native === null || $native->returnType === null) { throw new RuntimeException('Actual producer return missing.'); }
                $changes = ['returnType'=>ControlledCache::copy($native->returnType,['type'=>Type::int()])];
                if ($native->declaredReturnType !== null) { $changes['declaredReturnType'] = ControlledCache::copy($native->declaredReturnType,['type'=>Type::int()]); }
                $replacement = ControlledCache::copy($native, $changes);
            } else { throw new RuntimeException('Unknown native control.'); }
            $slots = ControlledCache::replace($context->codebase, $native, $replacement);
            try { $after = $this->filter->filterIssue($context); } finally { ControlledCache::restore($context->codebase, $native, $slots); }
            if ($after !== IssueFilterDecision::Keep || $this->filter->filterIssue($context) !== IssueFilterDecision::Remove || $this->proof->proveConfigurationGuard($context, $site['predicate']) !== $receipt) { throw new RuntimeException('Meaningful native veto/restoration failed.'); }
            file_put_contents($this->output, json_encode(['stage'=>'actual-cache-control','mode'=>$this->mode,'slots'=>count($slots),'veto'=>true,'restored'=>true], JSON_THROW_ON_ERROR)."\n", FILE_APPEND|LOCK_EX);
        }
        file_put_contents($this->output, json_encode(['stage'=>'configuration-member','file'=>$context->file,'span'=>[$primary?->span->start,$primary?->span->end],'candidateDecision'=>$decision->name,'sourceSha256'=>hash('sha256',$context->contents),'proof'=>$receipt,'issue'=>DefensiveBoundaryGuardProof::canonical($context->issue)],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
        return $this->mode === 'draft' ? $decision : IssueFilterDecision::Keep;
    }
}
