<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago-pest-conflicts-'.bin2hex(random_bytes(8));
mkdir($workspace.'/tests/Feature', 0777, true);
$cases = [
    'DistinctTest.php',
    'RepeatedTest.php',
    'WithinTest.php',
    'DefaultTest.php',
    'DefaultAfterCustomTest.php',
    'UnknownTest.php',
    'DynamicTest.php',
    'ConditionalTest.php',
    'TraitsTest.php',
];
foreach ($cases as $case) {
    file_put_contents($workspace.'/tests/Feature/'.$case, '<?php throw new RuntimeException("Test file executed");');
}
$source = <<<'PHP'
    <?php
    use App\Tests\First;
    use App\Tests\Second;
    use App\Tests\OneTrait;
    use App\Tests\TwoTrait;
    uses(First::class)->in('Feature/DistinctTest.php');
    uses(Second::class)->in('Feature/DistinctTest.php');
    uses(First::class)->in('Feature/RepeatedTest.php');
    uses(First::class)->in('Feature/RepeatedTest.php');
    uses(First::class, Second::class)->in('Feature/WithinTest.php');
    uses(\PHPUnit\Framework\TestCase::class)->in('Feature/DefaultTest.php');
    uses(First::class)->in('Feature/DefaultTest.php');
    uses(First::class)->in('Feature/DefaultAfterCustomTest.php');
    uses(\PHPUnit\Framework\TestCase::class)->in('Feature/DefaultAfterCustomTest.php');
    uses(\App\Tests\Missing::class)->in('Feature/UnknownTest.php');
    uses(First::class, Second::class)->in('Feature/UnknownTest.php');
    uses($unknown)->in('Feature/DynamicTest.php');
    uses(First::class)->in('Feature/DynamicTest.php');
    if (false) { uses(First::class)->in('Feature/ConditionalTest.php'); }
    uses(Second::class)->in('Feature/ConditionalTest.php');
    uses(OneTrait::class, TwoTrait::class)->in('Feature/TraitsTest.php');
    throw new RuntimeException('Pest source executed');
    PHP;
file_put_contents($workspace.'/tests/Pest.php', $source);
file_put_contents($workspace.'/tests/Types.php', <<<'PHP'
    <?php
    namespace App\Tests;
    class First {}
    class Second {}
    trait OneTrait {}
    trait TwoTrait {}
    namespace PHPUnit\Framework;
    class TestCase {}
    PHP);
$config = static function (bool $enabled) use ($workspace, $cases): void {
    file_put_contents($workspace.'/composer.json', json_encode([
        'extra' => [
            'laramago' => [
                'pest-uses' => [
                    'sources' => ['tests/Pest.php'],
                    'test-files' => array_map(static fn (string $case): string => 'tests/Feature/'.$case, $cases),
                    'diagnose-declared-conflicts' => $enabled,
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR));
};
$config(true);
file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => ['paths' => ['tests/Pest.php', 'tests/Types.php']],
    'extension-hosts' => [
        'laramago' => [
            'command' => [
                PHP_BINARY,
                '-d',
                'opcache.enable_cli=0',
                $package.'/bin/laramago-worker.php',
                $package.'/vendor/autoload.php',
                $workspace,
            ],
            'workers' => 2,
        ],
    ],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$analyze = static function () use ($command, $workspace, $source): array {
    $process = proc_open(
        [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $workspace.'/report.json', 'w'],
            2 => ['file', $workspace.'/stderr.log', 'w'],
        ],
        $pipes,
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start Mago.');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    if (! in_array($exit, [0, 1], true)) {
        throw new RuntimeException('Mago failed; inspect '.$workspace);
    }
    if (preg_match(
        '/External analyzer provider failed|extension worker .*rejected request/i',
        file_get_contents($workspace.'/stderr.log'),
    )) {
        throw new RuntimeException('Worker failed; inspect '.$workspace);
    }
    $report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
    $found = [];
    foreach ($report['issues'] ?? [] as $issue) {
        if (($issue['code'] ?? null) !== 'ichinya/laramago/laramago-pest-declared-test-context-conflict') {
            continue;
        }
        $primary = array_values(array_filter(
            $issue['annotations'],
            static fn (array $item): bool => $item['kind'] === 'Primary',
        ))[0];
        $span = $primary['span'];
        $found[] = [
            'line' => $span['start']['line'],
            'literal' => substr($source, $span['start']['offset'], $span['end']['offset'] - $span['start']['offset']),
            'message' => $issue['message'] ?? '',
        ];
    }

    usort($found, static fn (array $left, array $right): int => $left['line'] <=> $right['line']);

    return $found;
};
$found = $analyze();
if (count($found) !== 3 || array_column($found, 'literal') !== ['Second::class', 'First::class', 'Second::class']) {
    throw new RuntimeException('Conflict diagnostics mismatch: '.json_encode($found).'; inspect '.$workspace);
}
foreach ($found as $record) {
    if (! str_contains($record['message'], 'if both registrations execute')) {
        throw new RuntimeException('Unqualified runtime claim; inspect '.$workspace);
    }
}
echo "PASS: real Mago declared class overlaps, original source spans, and safe negatives\n";
$config(false);
if ($analyze() !== []) {
    throw new RuntimeException('Disabled policy emitted diagnostics; inspect '.$workspace);
}
echo "PASS: explicit diagnostic opt-in\n";

foreach ($cases as $case) {
    unlink($workspace.'/tests/Feature/'.$case);
}
foreach (['tests/Pest.php', 'tests/Types.php', 'composer.json', 'mago.json', 'report.json', 'stderr.log'] as $file) {
    unlink($workspace.'/'.$file);
}
rmdir($workspace.'/tests/Feature');
rmdir($workspace.'/tests');
rmdir($workspace);
