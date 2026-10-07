<?php
declare(strict_types=1);
namespace Example\LocalCacheFocusedTests;

use Ichinya\Laramago\Analyzer\DeprecatedMethodCompatibilityProof;
use Mago\Sdk\Analyzer\IssueFilterContext;

/** The worker owns serialization/reentry and always keeps its original native issue. */
final class LocalCacheFocusedExecution
{
    public static function cacheJob(IssueFilterContext $context, array $job, callable $freshEvaluation): array
    {
        $before = self::admitted($freshEvaluation());
        LocalCacheFocusedControlPlan::recipe($context, $before);
        $envelope = self::hash($context->issue); $sourceHash = hash('sha256', $context->contents);
        $native = LocalCacheSelectedNativeAliases::select($context->codebase, $job['selected']);
        $replacement = ($job['change'])($native);
        if (!is_object($replacement) || $replacement::class !== $native::class || $replacement == $native) {
            throw new \RuntimeException('A focused SDK mutation is absent, invalid or a no-op.');
        }
        $changed = null; $receipt = null;
        try {
            $receipt = LocalCacheSelectedNativeAliases::control($context->codebase, $native, $replacement, $job['bindings'],
                static function() use ($freshEvaluation, &$changed): void {
                    $changed = $freshEvaluation(); self::refused($changed);
                });
        } finally {
            $restored = self::admitted($freshEvaluation());
            if (self::hash($restored) !== self::hash($before) || self::hash($context->issue) !== $envelope
                || hash('sha256', $context->contents) !== $sourceHash) {
                throw new \RuntimeException('A focused SDK control did not restore the complete proof and native envelope.');
            }
        }
        return ['family' => $job['family'], 'genuineBeforeRemove' => true, 'changedKeep' => true,
            'restoredFreshRemove' => true, 'completeProofRestored' => true, 'wholeNativeIssueUnchanged' => true,
            'cache' => $receipt, 'firstFalseStage' => $changed['stage'] ?? null, 'constructedContexts' => 0];
    }

    public static function sourceJob(IssueFilterContext $context, array $job, string $fixtureRoot,
        callable $freshEvaluation): array
    {
        $before = self::admitted($freshEvaluation()); LocalCacheFocusedControlPlan::recipe($context, $before);
        $envelope = self::hash($context->issue); $changed = null; $receipt = null;
        try {
            $receipt = LocalCacheFocusedSourceControls::control($job, $fixtureRoot,
                static function() use ($freshEvaluation, &$changed): void {
                    $changed = $freshEvaluation(); self::refused($changed);
                });
        } finally {
            $restored = self::admitted($freshEvaluation());
            if (self::hash($restored) !== self::hash($before) || self::hash($context->issue) !== $envelope) {
                throw new \RuntimeException('A focused source control did not restore the complete proof and native envelope.');
            }
        }
        return ['family' => $job['family'], 'genuineBeforeRemove' => true, 'changedKeep' => true,
            'restoredFreshRemove' => true, 'completeProofRestored' => true, 'wholeNativeIssueUnchanged' => true,
            'source' => $receipt, 'firstFalseStage' => $changed['stage'] ?? null, 'constructedContexts' => 0];
    }
    private static function admitted(mixed $proof): array
    {
        if (!is_array($proof) || ($proof['remove'] ?? null) !== true || ($proof['nativeTypesChanged'] ?? null) !== false) {
            throw new \RuntimeException('A fresh genuine focused positive must Remove before and after every control.');
        }
        return $proof;
    }
    private static function refused(mixed $proof): void
    {
        if (!is_array($proof) || ($proof['remove'] ?? null) !== false || ($proof['nativeTypesChanged'] ?? null) !== false) {
            throw new \RuntimeException('A promised focused negative mutation must Keep.');
        }
    }
    private static function hash(mixed $value): string
    {
        return hash('sha256', json_encode(DeprecatedMethodCompatibilityProof::canonical($value),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
