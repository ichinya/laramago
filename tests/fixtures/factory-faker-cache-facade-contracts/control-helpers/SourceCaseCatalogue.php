<?php
declare(strict_types=1);
namespace Example\LocalCacheFocusedTests;

/** Source strings only; the runner copies one into a fresh Composer-declared fixture file. */
final class SourceCaseCatalogue
{
    public const EXPECTED_CASES = 13;
    public static function cases(): array
    {
        $specs = json_decode(file_get_contents(__DIR__.'/source-cases.json'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($specs) || count($specs) !== self::EXPECTED_CASES) { throw new \RuntimeException('The thirteen focused source cases are required.'); }
        $cases = [];
        foreach ($specs as $spec) {
            if (!is_array($spec) || !is_string($spec['id'] ?? null) || !is_string($spec['filename'] ?? null)
                || preg_match('/^[a-z0-9-]+\.php\.stub$/D', $spec['filename']) !== 1 || isset($cases[$spec['id']])) {
                throw new \RuntimeException('A focused source case has an invalid or duplicate identity.');
            }
            $path = __DIR__.'/cases/'.$spec['filename']; $bytes = file_get_contents($path);
            if (!is_string($bytes) || hash('sha256', $bytes) !== $spec['sha256']) {
                throw new \RuntimeException('The pinned neutral source case changed.');
            }
            $cases[$spec['id']] = ['id' => $spec['id'], 'source' => $bytes, 'sourcePath' => $path,
                'sha256' => $spec['sha256'], 'reason' => $spec['reason'], 'expectedTargetCount' => 2,
                'expectedRoleDecisions' => ['words-concatenation' => 'Keep', 'sprintf-argument' => 'Keep'],
                'requireIndependentNativeCoverage' => true, 'requireWholeResidualDtoEquality' => true];
        }
        return $cases;
    }
}
