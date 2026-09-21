<?php

declare(strict_types=1);

// Real-engine proof that a return provider cannot distinguish a fresh query
// expression from mutable builder state when their exposed invocation data match.
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago-query-aggregate-sdk-'.bin2hex(random_bytes(8));
mkdir($workspace);
copy(__DIR__.'/fixtures/analysis/query-aggregate-sdk.php.stub', $workspace.'/framework.php');

$freshPrefix = "<?php\nfunction freshCase(): void\n{\n    ";
$mutablePrefix = "<?php\nfunction mutableCase(): void\n{\n    \$queryState0 = freshQuery();\n    ";
$offset = max(strlen($freshPrefix), strlen($mutablePrefix));
$freshPrefix = str_pad($freshPrefix, $offset);
$mutablePrefix = str_pad($mutablePrefix, $offset);
$suffix = "->withCount('items as selected_count')->firstOrFail();\n}\n";
$freshSource = $freshPrefix.'freshQuery()'.$suffix;
$mutableSource = $mutablePrefix.'$queryState0'.$suffix;
file_put_contents($workspace.'/fresh.php', $freshSource);
file_put_contents($workspace.'/mutable.php', $mutableSource);

$audit = $workspace.'/audit.jsonl';
file_put_contents($workspace.'/mago.json', json_encode([
    'php-version' => '8.2',
    'source' => ['paths' => ['fresh.php', 'mutable.php'], 'includes' => ['framework.php']],
    'extension-hosts' => [
        'probe' => [
            'command' => [
                PHP_BINARY,
                '-d',
                'opcache.enable_cli=0',
                $package.'/tests/fixtures/analysis/query-aggregate-sdk-worker.php.stub',
                getenv('MAGO_SDK_AUTOLOAD') ?: $package.'/vendor/autoload.php',
                $audit,
            ],
            'workers' => 1,
        ],
    ],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
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
$stderr = file_get_contents($workspace.'/stderr.log');
if (
    $exit !== 0
    || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $stderr)
) {
    throw new RuntimeException('Mago boundary probe failed; inspect '.$workspace);
}
$report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
if (($report['issues'] ?? []) !== []) {
    throw new RuntimeException('Unexpected diagnostics; inspect '.$workspace);
}

$records = array_map(
    static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
    array_values(array_filter(explode("\n", file_get_contents($audit)))),
);
if (count($records) !== 2 || $records[0] !== $records[1]) {
    throw new RuntimeException('Fresh and mutable invocations exposed distinguishable context: '.json_encode($records));
}
$withCountCall = "freshQuery()->withCount('items as selected_count')";
if ($records[0]['span'] !== [$offset, $offset + strlen($withCountCall)]) {
    throw new RuntimeException('Invocation span did not retain the expected file-local byte offsets.');
}

echo "PASS: fresh and mutable aggregate calls expose identical synchronous provider context\n";

foreach ([
    'framework.php',
    'fresh.php',
    'mutable.php',
    'mago.json',
    'audit.jsonl',
    'report.json',
    'stderr.log',
] as $file) {
    unlink($workspace.'/'.$file);
}
rmdir($workspace);
