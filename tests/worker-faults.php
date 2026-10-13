<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$root = str_replace('\\', '/', sys_get_temp_dir()) . '/laramago worker faults ' . bin2hex(random_bytes(8));
mkdir($root);
$sdk = getenv('MAGO_SDK_AUTOLOAD') ?: $package . '/vendor/autoload.php';
$binary = getenv('MAGO_BINARY') ?: $package . '/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
file_put_contents($root . '/source.php', '<?php function healthy(): string { return "ok"; }');
$cases = [
    'healthy' => null,
    'exit' => '/disconnected|closed stdout|exited/i',
    'malformed' => '/frame magic/i',
    'truncated' => '/buffer|frame|payload/i',
    'bad-version' => '/protocol.*version/i',
    'timeout' => '/within .*ms|timed out|timeout/i',
    'throw' => '/Synthetic worker failure/',
    'response-magic' => '/frame magic/i',
    'response-empty' => '/payload|header/i',
    'response-version' => '/protocol.*major|protocol.*version/i',
];
$receipts = [];
foreach ($cases as $mode => $failure) {
    file_put_contents($root . '/mago.json', json_encode([
        'php-version' => '8.2',
        'threads' => 1,
        'source' => ['paths' => ['source.php']],
        'extension-hosts' => [
            'faults' => [
                'command' => [PHP_BINARY, $package . '/tests/fixtures/worker-faults/worker.php', $sdk, $mode],
                'workers' => 1,
                'request-timeout-ms' => $mode === 'timeout' ? 1000 : 120000,
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    $process = proc_open(
        [...$command, '--workspace', $root, 'analyze', '--reporting-format=json'],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $root . '/' . $mode . '.json', 'w'],
            2 => ['file', $root . '/' . $mode . '.stderr', 'w'],
        ],
        $pipes,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start worker fault control.');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $stderr = file_get_contents($root . '/' . $mode . '.stderr');
    $output = file_get_contents($root . '/' . $mode . '.json');
    $receipts[] = [
        'mode' => $mode,
        'exit' => $exit,
        'stdoutHash' => hash('sha256', $output),
        'stderrHash' => hash('sha256', $stderr),
    ];
    file_put_contents($root . '/receipts.json', json_encode($receipts, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    if ($failure === null) {
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        if ($exit !== 0 || ($report['issues'] ?? null) !== []) {
            throw new RuntimeException('Healthy worker control failed: ' . $root);
        }
    } elseif ($exit !== 2 || preg_match($failure, $stderr) !== 1 || str_contains($stderr, 'No issues found')) {
        throw new RuntimeException('Worker fault was accepted or misclassified: ' . $mode . '; ' . $root);
    }
    echo 'PASS: ' . $mode . ' / native exit ' . $exit . PHP_EOL;
}
