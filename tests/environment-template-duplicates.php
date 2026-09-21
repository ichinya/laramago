<?php

declare(strict_types=1);

use Ichinya\Laramago\Metadata\EnvironmentTemplateDuplicates;

require dirname(__DIR__).'/vendor/autoload.php';

$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago environment duplicates '.bin2hex(random_bytes(8));
mkdir($workspace.'/nested', recursive: true);

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};

$source = <<<'ENV'
    REPEAT=first-private-value
    REPEAT=second-private-value
    REPEAT=third-private-value
    CaseName=upper-private-value
    casename=lower-private-value
    "QUOTED_NAME"=quoted-private-value
    export 'QUOTED_NAME'=second-quoted-private-value
    MULTILINE="first line
    FAKE_ASSIGNMENT=inside-private-value
    last line"
    AFTER=after-private-value
    AFTER=second-after-private-value
    UNSUPPORTED ENTRY
    UNCERTAIN="${BROKEN_INTERPOLATION"
    UNCERTAIN=second-uncertain-private-value
    ENV;
$source .= "\n";
$other = "REPEAT=other-file-private-value\n";

file_put_contents($workspace.'/.env.example', $source);
file_put_contents($workspace.'/nested/.env.template', $other);
file_put_contents($workspace.'/.env', "ACTUAL=private\nACTUAL=still-private\n");

try {
    $export = (new EnvironmentTemplateDuplicates)->export(
        $workspace,
        ['.env.example', 'nested/.env.template'],
    );

    $check(
        $export['scope'] === [
            'kind' => 'environment-duplicates',
            'evidence' => 'source-only',
            'semantics' => 'advisory-duplicate-declaration-candidates',
            'matching' => 'exact-case',
            'exhaustive' => false,
        ],
        'Scope documents the exact-case source advisory policy.',
    );
    $check(
        array_column($export['candidates'], 'name') === [
            'REPEAT',
            'REPEAT',
            'QUOTED_NAME',
            'AFTER',
            'UNCERTAIN',
        ],
        'Only repeated exact-case declarations in the same selected file are candidates.',
    );
    $check(
        ! in_array('FAKE_ASSIGNMENT', array_column($export['candidates'], 'name'), true),
        'Assignment-like text in a multiline value is not treated as a declaration.',
    );
    $check(
        ! in_array('CaseName', array_column($export['candidates'], 'name'), true)
        && ! in_array('casename', array_column($export['candidates'], 'name'), true),
        'Names that differ by case remain distinct.',
    );

    $firstRepeat = $export['candidates'][0]['firstLocation'];
    $check($firstRepeat['line'] === 1, 'First declaration location is retained.');
    $check(
        $export['candidates'][1]['firstLocation'] === $firstRepeat,
        'Every later repeat points to the original declaration.',
    );
    foreach ($export['candidates'] as $duplicate) {
        $check(
            substr($source, $duplicate['start'], $duplicate['end'] - $duplicate['start']) === $duplicate['name'],
            'Duplicate location covers only the literal name.',
        );
        $first = $duplicate['firstLocation'];
        $check(
            substr($source, $first['start'], $first['end'] - $first['start']) === $first['name'],
            'First location covers only the original literal name.',
        );
        $check(
            $duplicate['contentHash'] === hash('sha256', $source) && $first['contentHash'] === hash('sha256', $source),
            'Both locations carry the selected source hash.',
        );
    }
    $check(
        array_column($export['errors'], 'code') === ['unsupported-entry'],
        'Unsupported entries are disclosed without suppressing positive candidates.',
    );
    $check(
        array_column($export['uncertainties'], 'code') === ['unsupported-interpolation'],
        'Interpolation uncertainty is preserved beside positive candidates.',
    );
    $encoded = json_encode($export, JSON_THROW_ON_ERROR);
    foreach ([
        'first-private-value',
        'second-private-value',
        'inside-private-value',
        'other-file-private-value',
    ] as $value) {
        $check(! str_contains($encoded, $value), 'Export does not disclose template values.');
    }

    $actual = (new EnvironmentTemplateDuplicates)->export($workspace, ['.env']);
    $check(
        $actual['candidates'] === [] && array_column($actual['errors'], 'code') === ['invalid-source'],
        'Actual environment files are rejected without being read.',
    );

    $missing = (new EnvironmentTemplateDuplicates)->export($workspace, ['missing/.env.example']);
    $check(
        $missing['candidates'] === [] && array_column($missing['errors'], 'code') === ['unreadable-source'],
        'Unreadable explicit templates remain visible as errors.',
    );

    $large = "LIMITED=one\nLIMITED=two\n".str_repeat('x', (1024 * 1024) + 1);
    file_put_contents($workspace.'/nested/.env.template', $large);
    $bounded = (new EnvironmentTemplateDuplicates)->export($workspace, ['nested/.env.template']);
    $check(
        $bounded['candidates'] === []
        && $bounded['truncated']
        && $bounded['truncationReasons'] === ['file-byte-limit']
        && array_column($bounded['errors'], 'code') === ['source-limit'],
        'A truncated file does not pretend to provide complete duplicate candidates.',
    );
} finally {
    foreach (['.env.example', '.env'] as $file) {
        if (is_file($workspace.'/'.$file)) {
            unlink($workspace.'/'.$file);
        }
    }
    if (is_file($workspace.'/nested/.env.template')) {
        unlink($workspace.'/nested/.env.template');
    }
    rmdir($workspace.'/nested');
    rmdir($workspace);
}

echo "Environment template duplicates: {$checks} checks passed.\n";
