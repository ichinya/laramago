<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$root = str_replace('\\', '/', sys_get_temp_dir()) . '/laramago configuration revision ' . bin2hex(random_bytes(8));
$sdk = getenv('MAGO_SDK_AUTOLOAD') ?: $package . '/vendor/autoload.php';
$binary = getenv('MAGO_BINARY') ?: $package . '/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$cases = [
    'unchanged' => [],
    'fresh-string' => ['invalid-return-statement'],
    'changed' => ['configuration-source-changed', 'mixed-return-statement'],
    'deleted' => ['configuration-source-changed', 'mixed-return-statement'],
    'broken' => ['configuration-source-changed', 'mixed-return-statement'],
    'added' => ['configuration-source-changed'],
    'manifest' => ['configuration-source-changed', 'mixed-return-statement'],
    'lock' => ['configuration-source-changed'],
    'lock-unchanged' => [],
    'binding' => ['configuration-source-changed', 'mixed-return-statement'],
    'late-change' => ['configuration-source-changed'],
    'multiple-files' => [],
    'multiple-files-late-change' => ['configuration-source-changed'],
    'typed-helper' => ['invalid-return-statement'],
    'documented-helper' => ['invalid-return-statement'],
    'native-control' => ['configuration-source-changed', 'mixed-return-statement', 'mixed-return-statement'],
    'full-unchanged' => [],
    'full-changed' => ['configuration-source-changed', 'mixed-return-statement'],
    'full-deleted' => ['configuration-source-changed', 'mixed-return-statement'],
    'full-broken' => ['configuration-source-changed', 'mixed-return-statement'],
    'full-manifest' => ['configuration-source-changed', 'mixed-return-statement'],
    'full-lock' => ['configuration-source-changed'],
];
foreach ($cases as $mode => $expected) {
    $prefix = str_starts_with($mode, 'full-') ? 'ichinya/laramago/' : 'fixture/revision/';
    $expected = array_map(static fn(string $code): string => $code === 'configuration-source-changed'
        ? $prefix . $code
        : $code, $expected);
    $workspace = $root . '/' . $mode;
    $framework = $workspace . '/vendor/laravel/framework/src/Illuminate/Foundation';
    mkdir($framework, 0777, true);
    mkdir($workspace . '/config');
    mkdir($workspace . '/bootstrap');
    if ($mode === 'lock-unchanged') {
        file_put_contents($workspace . '/composer.lock', '{}');
    }
    file_put_contents(
        $workspace . '/composer.json',
        $mode === 'binding' ? '{"extra":{"laramago":{"binding-files":["bootstrap/bindings.php"]}}}' : '{}',
    );
    file_put_contents($workspace . '/bootstrap/bindings.php', '<?php // no custom bindings');
    file_put_contents(
        $workspace . '/config/example.php',
        $mode === 'fresh-string' ? '<?php return ["value" => "changed"];' : '<?php return ["value" => 1];',
    );
    $helper = $mode === 'typed-helper'
        ? 'function config(mixed $key = null, mixed $default = null): string'
        : (
            $mode === 'documented-helper'
                ? '/** @return string */ function config(mixed $key = null, mixed $default = null): mixed'
                : 'function config(mixed $key = null, mixed $default = null): mixed'
        );
    file_put_contents(
        $framework . '/helpers.php',
        '<?php ' . $helper . ' { throw new RuntimeException("Never execute helpers"); }',
    );
    $source = '<?php function setting(): int { return config("example.value"); }';
    if ($mode === 'native-control') {
        $source .= ' function foreign(mixed $value): int { return $value; }';
    }
    file_put_contents($workspace . '/cases.php', $source);
    $sourcePaths = ['cases.php'];
    if (str_starts_with($mode, 'multiple-files')) {
        mkdir($workspace . '/parts');
        for ($part = 0; $part < 12; ++$part) {
            file_put_contents(
                $workspace . '/parts/file' . $part . '.php',
                '<?php function part' . $part . '(): int { return config("example.value"); }',
            );
        }
        $sourcePaths[] = 'parts';
    }
    file_put_contents($workspace . '/mago.json', json_encode([
        'extends' => $package . '/presets/laravel.toml',
        'php-version' => '8.2',
        'threads' => 1,
        'source' => ['paths' => $sourcePaths, 'includes' => ['vendor/laravel/framework/src/Illuminate']],
        'extension-hosts' => [
            'revision' => [
                'command' => [
                    PHP_BINARY,
                    $package . '/tests/fixtures/configuration-source-revision/worker.php',
                    $sdk,
                    $workspace,
                    $mode,
                    $package,
                ],
                'workers' => str_starts_with($mode, 'multiple-files') ? 3 : 1,
                'request-timeout-ms' => 120000,
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    $process = proc_open(
        [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $workspace . '/report.json', 'w'],
            2 => ['file', $workspace . '/stderr.log', 'w'],
        ],
        $pipes,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start source revision control.');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $stderr = file_get_contents($workspace . '/stderr.log');
    if (!in_array($exit, [0, 1], true)) {
        throw new RuntimeException('Operational source revision failure: ' . $workspace . '; ' . $stderr);
    }
    $report = json_decode(file_get_contents($workspace . '/report.json'), true, flags: JSON_THROW_ON_ERROR);
    $actual = array_column($report['issues'] ?? [], 'code');
    sort($actual);
    sort($expected);
    if (
        $exit !== ($expected === [] ? 0 : 1)
        || $actual !== $expected
        || preg_match('/rejected request|protocol error|worker[^\r\n]*failed|PHP Fatal|PHP Warning/i', $stderr)
    ) {
        throw new RuntimeException(
            'Source revision control failed: ' . $mode . '; ' . json_encode($actual) . '; ' . $workspace,
        );
    }
    echo 'PASS: ' . $mode . PHP_EOL;
}
