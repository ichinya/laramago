<?php

declare(strict_types=1);

// Check original byte spans from the real Mago worker, including escaped literals and CRLF.
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago relation spans '.bin2hex(random_bytes(8));
mkdir($workspace);
copy(__DIR__.'/fixtures/analysis/framework.php.stub', $workspace.'/framework.php');
copy(__DIR__.'/fixtures/analysis/relation-name-contracts.php.stub', $workspace.'/models.php');
file_put_contents($workspace.'/composer.json', json_encode([
    'extra' => [
        'laramago' => [
            'relation-names' => [
                'RelationNameRecord' => ['complete' => true],
            ],
        ],
    ],
], JSON_THROW_ON_ERROR));
$source = <<<'PHP'
    <?php
    // UTF-8 before the call: пример
    RelationNameRecord::query()->with([
        'children',
        "miss\x31",
        'label' => fn () => null,
        0 => 'overwritten',
        0 => 'children',
    ]);
    RelationNameRecord::query()->with(relations: [
        'miss2' => fn () => null,
    ]);
    RelationNameRecord::query()->with(['0' => 'miss3']);
    PHP;
$source = str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $source))."\r\n";
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => ['paths' => ['cases.php'], 'includes' => ['models.php']],
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
            'workers' => 1,
        ],
    ],
], JSON_THROW_ON_ERROR));
$mago = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($mago, '.exe') ? [$mago] : [PHP_BINARY, '-d', 'opcache.enable_cli=0', $mago];
$process = proc_open(
    [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
    [0 => ['pipe', 'r'], 1 => ['file', $workspace.'/report.json', 'w'], 2 => ['file', $workspace.'/stderr.log', 'w']],
    $pipes,
);
if (! is_resource($process)) {
    throw new RuntimeException('Cannot start Mago.');
}
fclose($pipes[0]);
$exit = proc_close($process);
$log = file_get_contents($workspace.'/stderr.log');
if (
    ! in_array($exit, [0, 1], true)
    || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)
) {
    throw new RuntimeException('Mago worker failed; inspect '.$workspace);
}
$report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
$actual = [];
foreach ($report['issues'] ?? [] as $issue) {
    if (! str_starts_with($issue['code'], 'ichinya/laramago/laramago-')) {
        continue;
    }
    $primary = array_values(array_filter(
        $issue['annotations'],
        static fn (array $annotation): bool => $annotation['kind'] === 'Primary',
    ))[0];
    $span = $primary['span'];
    if ($span['file_id']['name'] !== 'cases.php') {
        throw new RuntimeException('Diagnostic has the wrong original file; inspect '.$workspace);
    }
    $start = $span['start']['offset'];
    $end = $span['end']['offset'];
    $actual[] = [$issue['code'], substr($source, $start, $end - $start), $start];
}
$expected = [
    ['ichinya/laramago/laramago-missing-relation', '"miss\\x31"'],
    ['ichinya/laramago/laramago-invalid-relation', "'label'"],
    ['ichinya/laramago/laramago-missing-relation', "'miss2'"],
    ['ichinya/laramago/laramago-missing-relation', "'miss3'"],
];
foreach ($expected as [$code, $literal]) {
    $offset = strpos($source, $literal);
    if ($offset === false) {
        throw new RuntimeException('Test literal missing from source: '.$literal);
    }
    $found = array_search([$code, $literal, $offset], $actual, true);
    if ($found === false) {
        throw new RuntimeException(
            'Expected exact literal byte span '
            .json_encode([$code, $literal, $offset])
            .'; got '
            .json_encode($actual)
            .'; inspect '
            .$workspace,
        );
    }
    unset($actual[$found]);
}
if ($actual !== []) {
    throw new RuntimeException('Unexpected relation diagnostics: '.json_encode($actual).'; inspect '.$workspace);
}
echo "PASS: original relation literal byte spans, escaped text, CRLF, UTF-8, and overwritten entries\n";
