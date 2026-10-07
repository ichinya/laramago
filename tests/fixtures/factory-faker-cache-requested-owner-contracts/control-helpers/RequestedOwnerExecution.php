<?php
declare(strict_types=1);
namespace Example\LocalCacheRequestedOwnerTests;

use Example\LocalCacheFocusedTests\LocalCacheFocusedControlPlan;
use Mago\Sdk\Analyzer\IssueFilterContext;

/** Same strict fresh admission/refusal/restoration lifecycle as the accepted local controls. */
final class RequestedOwnerExecution
{
    public static function cacheJob(IssueFilterContext $context, array $job, callable $freshEvaluation): array
    {
        $before = self::admitted($freshEvaluation());
        LocalCacheFocusedControlPlan::recipe($context, $before);
        foreach ($job['physicalDeclarations'] as $physical) {
            if (@hash_file('sha256', $physical['sourcePath']) !== $physical['sourceSha256']) { throw new \RuntimeException('A physical wrong-owner fixture changed before control.'); }
        }
        $envelope = RequestedClassLikeAliases::hash($context->issue); $sourceHash = hash('sha256', $context->contents);
        $native = RequestedClassLikeAliases::select($context->codebase, $job['selected']);
        $replacement = ($job['change'])($native); $changed = null;
        try {
            $receipt = RequestedClassLikeAliases::control($context->codebase, $native, $replacement, $job['bindings'], $job['counterpart'],
                static function() use ($freshEvaluation, &$changed): void {
                    $changed = $freshEvaluation();
                    if (!is_array($changed) || ($changed['remove'] ?? null) !== false || ($changed['nativeTypesChanged'] ?? null) !== false) {
                        throw new \RuntimeException('A promised real requested-parent/trait wrong-owner mutation must Keep.');
                    }
                });
        } finally {
            $restored = self::admitted($freshEvaluation());
            if (RequestedClassLikeAliases::hash($restored) !== RequestedClassLikeAliases::hash($before)
                || RequestedClassLikeAliases::hash($context->issue) !== $envelope || hash('sha256', $context->contents) !== $sourceHash) {
                throw new \RuntimeException('A requested-owner mutation did not restore the complete proof/source/native envelope.');
            }
            foreach ($job['physicalDeclarations'] as $physical) {
                if (@hash_file('sha256', $physical['sourcePath']) !== $physical['sourceSha256']) { throw new \RuntimeException('Physical ancestry source changed during a metadata control.'); }
            }
        }
        return ['family' => $job['family'], 'genuineBeforeRemove' => true, 'changedKeep' => true, 'restoredFreshRemove' => true,
            'completeProofRestored' => true, 'wholeNativeIssueUnchanged' => true, 'cache' => $receipt,
            'physicalDeclarations' => $job['physicalDeclarations'], 'firstFalseStage' => $changed['stage'] ?? null,
            'constructedContexts' => 0, 'replacementConstructed' => false, 'counterpartUnchanged' => true];
    }

    private static function admitted(mixed $proof): array
    {
        if (!is_array($proof) || ($proof['remove'] ?? null) !== true || ($proof['nativeTypesChanged'] ?? null) !== false) {
            throw new \RuntimeException('A fresh genuine requested-owner positive must Remove before and after each mutation.');
        }
        return $proof;
    }
}
