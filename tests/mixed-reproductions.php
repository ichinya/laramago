<?php

declare(strict_types=1);

// These are diagnostic controls, not suppressions or application fixes.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago mixed reproductions '.bin2hex(random_bytes(8));
mkdir($workspace);
copy(__DIR__.'/fixtures/analysis/mixed-repro-contracts.php.stub', $workspace.'/contracts.php');
file_put_contents($workspace.'/bootstrap.php', '<?php throw new RuntimeException("Do not bootstrap.");');
file_put_contents($workspace.'/composer.json', json_encode([
    'autoload' => ['files' => ['bootstrap.php']],
], JSON_THROW_ON_ERROR));
$cases = [
    'inline second tag is not a tuple contract' => [
        '[$entry, $path] = $contracts->inlineTags([]); $entry->label(); consumeString($path);',
        'void',
        ['mixed-assignment', 'mixed-assignment', 'mixed-method-access', 'mixed-argument'],
    ],
    'separate tags preserve the tuple' => [
        '[$entry, $path] = $contracts->separateTags([]); $entry->label(); consumeString($path);',
        'void',
        [],
    ],
    'bare array return loses tuple elements' => [
        '[$entry, $path] = $contracts->rawTuple(); $entry->label(); consumeString($path);',
        'void',
        ['mixed-assignment', 'mixed-assignment', 'mixed-method-access', 'mixed-argument'],
    ],
    'raw callback wrapper loses its result' => [
        'return $contracts->rawCallback(fn (): Entry => new Entry);',
        'Entry',
        ['mixed-return-statement'],
    ],
    'generic callback wrapper retains its result' => [
        'return $contracts->typedCallback(fn (): Entry => new Entry);',
        'Entry',
        [],
    ],
    'generic callback still rejects wrong result' => [
        'return $contracts->typedCallback(fn (): Entry => new Entry);',
        'string',
        ['invalid-return-statement'],
    ],
    'fallback string does not constrain external input' => [
        'consumeString($contracts->input("name", "fallback"));',
        'void',
        ['mixed-argument'],
    ],
    'checking input is a valid narrowing boundary' => [
        'if (is_string($input)) { consumeString($input); }',
        'void',
        [],
    ],
    'untyped local parameter remains mixed' => ['$input->label();', 'void', ['mixed-method-access']],
    'native trait static return is supported' => ['return DateValue::create();', '?DateValue', []],
    'native trait fluent return is supported' => ['return (new DateValue)->start()->start();', 'DateValue', []],
    'nullable trait factory stays nullable' => [
        'return DateValue::create();',
        'DateValue',
        ['invalid-return-statement', 'nullable-return-statement'],
    ],
];
$source = "<?php\nnamespace MixedReproduction;\n";
$lines = [];
foreach ($cases as $name => [$body, $return, $codes]) {
    $source .= 'function scenario'.count($lines).'(Contracts $contracts, mixed $input): '.$return.' { '.$body.' }'."\n";
    $lines[substr_count($source, "\n")] = [$name, $codes];
}
file_put_contents($workspace.'/cases.php', $source);
$config = [
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => ['paths' => ['cases.php'], 'includes' => ['contracts.php']],
];
foreach (['native', 'extension'] as $mode) {
    if ($mode === 'extension') {
        $config['extension-hosts'] = [
            'laramago' => [
                'command' => [
                    PHP_BINARY,
                    $package.'/bin/laramago-worker.php',
                    $package.'/vendor/autoload.php',
                    $workspace,
                ],
                'workers' => 3,
            ],
        ];
    }
    file_put_contents($workspace.'/mago.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    $process = proc_open(
        [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $workspace.'/'.$mode.'.json', 'w'],
            2 => ['file', $workspace.'/'.$mode.'.log', 'w'],
        ],
        $pipes,
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start Mago.');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $log = file_get_contents($workspace.'/'.$mode.'.log');
    if ($exit !== 1 || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)) {
        throw new RuntimeException('Expected native negative diagnostics without extension fallback; inspect '
        .$workspace);
    }
    $report = json_decode(file_get_contents($workspace.'/'.$mode.'.json'), true, flags: JSON_THROW_ON_ERROR);
    $actual = [];
    foreach ($report['issues'] ?? [] as $issue) {
        $primary = array_values(array_filter(
            $issue['annotations'],
            static fn (array $a): bool => $a['kind'] === 'Primary',
        ))[0];
        if ($primary['span']['file_id']['name'] !== 'cases.php') {
            throw new RuntimeException('Unexpected diagnostic outside scenarios; inspect '.$workspace);
        }
        $actual[$primary['span']['start']['line'] + 1][] = $issue['code'];
    }
    foreach ($lines as $line => [$name, $expected]) {
        $codes = $actual[$line] ?? [];
        sort($codes);
        sort($expected);
        if ($codes !== $expected) {
            throw new RuntimeException(
                $mode.' '.$name.': expected '.json_encode($expected).', got '.json_encode($codes).'; see '.$workspace,
            );
        }
        unset($actual[$line]);
        echo 'PASS: '.$mode.' '.$name."\n";
    }
    if ($actual !== []) {
        throw new RuntimeException('Unexpected diagnostics outside scenarios; inspect '.$workspace);
    }
}
$resolvedWorkspace = realpath($workspace);
foreach (glob($workspace.'/*') ?: [] as $file) {
    $resolvedFile = realpath($file);
    if (
        $resolvedWorkspace === false
        || $resolvedFile === false
        || ! str_starts_with($resolvedFile, $resolvedWorkspace.DIRECTORY_SEPARATOR)
    ) {
        throw new RuntimeException('Refusing cleanup outside the test workspace.');
    }
    unlink($resolvedFile);
}
rmdir($workspace);
