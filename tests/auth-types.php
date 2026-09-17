<?php

declare(strict_types=1);

// Check offline authentication configuration through the real SDK worker.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
foreach (['literal', 'dynamic-default', 'missing', 'altered-request', 'mixed-request'] as $configurationMode) {
    $workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago auth '.bin2hex(random_bytes(8));
    mkdir($workspace);
    mkdir($workspace.'/config');
    copy(__DIR__.'/fixtures/analysis/auth-types.php.stub', $workspace.'/models.php');
    if ($configurationMode === 'altered-request') {
        $models = file_get_contents($workspace.'/models.php');
        file_put_contents($workspace.'/models.php', str_replace(
            '\\Illuminate\\Contracts\\Auth\\Authenticatable|null',
            '\\AuthAdmin|null',
            $models,
        ));
    }
    if ($configurationMode === 'mixed-request') {
        $models = file_get_contents($workspace.'/models.php');
        file_put_contents($workspace.'/models.php', str_replace(
            '\\Illuminate\\Contracts\\Auth\\Authenticatable|null',
            'mixed',
            $models,
        ));
    }
    if ($configurationMode !== 'missing') {
        $configuration = file_get_contents(__DIR__.'/fixtures/analysis/auth-config.php.stub');
        if ($configurationMode === 'dynamic-default') {
            $configuration = str_replace(
                "'defaults' => ['guard' => 'web']",
                "'defaults' => ['guard' => env('AUTH_GUARD', 'web')]",
                $configuration,
            );
        }
        file_put_contents($workspace.'/config/auth.php', $configuration);
    }
    $cases = [
        'default guard' => ['return (new Request)->user();', '?AuthMember', []],
        'falsey guard' => ['return (new Request)->user("0");', '?AuthMember', []],
        'null guard' => ['return (new Request)->user(null);', '?AuthMember', []],
        'explicit guard' => ['return (new Request)->user("admin");', '?AuthAdmin', []],
        'named guard' => ['return (new Request)->user(guard: "admin");', '?AuthAdmin', []],
        'inherited request' => ['return (new ChildAuthRequest)->user();', '?AuthMember', []],
        'override wins' => ['return (new CustomAuthRequest)->user();', '?AuthAdmin', []],
        'narrowed property' => [
            '$user = (new Request)->user(); if ($user !== null) { return $user->email; } return "";',
            'string',
            [],
        ],
        'nullable preserved' => [
            'return (new Request)->user();',
            'AuthMember',
            ['invalid-return-statement', 'nullable-return-statement'],
        ],
        'wrong model' => ['return (new Request)->user("admin");', '?AuthMember', ['invalid-return-statement']],
        'unknown guard' => ['return (new Request)->user("missing");', '?Authenticatable', []],
        'custom driver' => ['return (new Request)->user("custom");', '?Authenticatable', []],
        'database provider' => ['return (new Request)->user("database");', '?Authenticatable', []],
        'dynamic model' => ['return (new Request)->user("dynamic");', '?Authenticatable', []],
        'invalid argument' => ['(new Request)->user(123);', 'void', ['invalid-argument']],
        'property typo' => [
            '$user = (new Request)->user(); if ($user !== null) { return $user->emial; } return "";',
            'mixed',
            ['non-existent-property'],
        ],
    ];
    if ($configurationMode !== 'literal') {
        $cases = [
            $configurationMode.' default defers' => ['return (new Request)->user();', '?Authenticatable', []],
            $configurationMode.' preserves unknown default' => [
                'return (new Request)->user();',
                '?AuthMember',
                ['less-specific-return-statement'],
            ],
            $configurationMode.' explicit guard' => [
                'return (new Request)->user("admin");',
                $configurationMode === 'missing' ? '?Authenticatable' : '?AuthAdmin',
                [],
            ],
        ];
    }
    if ($configurationMode === 'altered-request') {
        $cases = [
            'changed request declaration' => ['return (new Request)->user();', '?AuthAdmin', []],
            'request declaration not replaced' => [
                'return (new Request)->user();',
                '?AuthMember',
                ['invalid-return-statement'],
            ],
        ];
    }
    if ($configurationMode === 'mixed-request') {
        $cases = [
            'mixed request default refined' => ['return (new Request)->user();', '?AuthMember', []],
            'mixed request literal guard refined' => ['return (new Request)->user("admin");', '?AuthAdmin', []],
            'mixed request nullability preserved' => [
                'return (new Request)->user();',
                'AuthMember',
                ['invalid-return-statement', 'nullable-return-statement'],
            ],
            'mixed request wrong model' => [
                'return (new Request)->user();',
                '?AuthAdmin',
                ['invalid-return-statement'],
            ],
        ];
    }
    $source = <<<'PHP'
        <?php
        use Illuminate\Http\Request;
        use Illuminate\Contracts\Auth\Authenticatable;
        PHP;
    $lines = [];
    foreach ($cases as $name => [$body, $return, $codes]) {
        $source .= '/** @return '.$return.' */'."\n";
        $source .= 'function scenario'.count($lines).'() { '.$body.' }'."\n";
        $lines[substr_count($source, "\n")] = [$name, $codes];
    }
    file_put_contents($workspace.'/cases.php', $source);
    file_put_contents($workspace.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml',
        'php-version' => '8.2',
        'source' => ['paths' => ['cases.php'], 'includes' => ['models.php']],
        'extension-hosts' => [
            'laramago' => [
                'command' => [
                    PHP_BINARY,
                    $package.'/bin/laramago-worker.php',
                    $package.'/vendor/autoload.php',
                    $workspace,
                ],
                'workers' => 3,
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
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
    $log = file_get_contents($workspace.'/stderr.log');
    if ($exit !== 1 || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)) {
        throw new RuntimeException('Expected native negative diagnostics without extension fallback; inspect '
        .$workspace);
    }
    $report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
    $actual = [];
    foreach ($report['issues'] ?? [] as $issue) {
        $primary = array_values(array_filter(
            $issue['annotations'],
            static fn (array $a): bool => $a['kind'] === 'Primary',
        ))[0];
        $actual[$primary['span']['start']['line'] + 1][] = $issue['code'];
    }
    foreach ($lines as $line => [$name, $expected]) {
        $codes = $actual[$line] ?? [];
        sort($codes);
        sort($expected);
        if ($codes !== $expected) {
            throw new RuntimeException(
                $name.': expected '.json_encode($expected).', got '.json_encode($codes).'; see '.$workspace,
            );
        }
        unset($actual[$line]);
        echo 'PASS: '.$name."\n";
    }
    if ($actual !== []) {
        throw new RuntimeException('Unexpected diagnostics outside authentication scenarios; inspect '.$workspace);
    }
    if (is_file($workspace.'/config/executed.txt')) {
        throw new RuntimeException('Configuration was executed.');
    }
    if (is_file($workspace.'/config/auth.php')) {
        unlink($workspace.'/config/auth.php');
    }
    rmdir($workspace.'/config');
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
}
