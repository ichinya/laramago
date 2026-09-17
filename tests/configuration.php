<?php

declare(strict_types=1);

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago configuration '.bin2hex(random_bytes(8));
mkdir($workspace);
mkdir($workspace.'/config');
mkdir($workspace.'/bootstrap');
$helpers = 'dependencies with spaces/laravel/framework/src/Illuminate/Foundation/helpers.php';
mkdir(dirname($workspace.'/'.$helpers), 0777, true);
$framework = $workspace.'/dependencies with spaces/laravel/framework/src/Illuminate';
mkdir($framework.'/Support/Facades', 0777, true);
mkdir($framework.'/Config', 0777, true);
copy(__DIR__.'/fixtures/analysis/configuration-facade-base.php.stub', $framework.'/Support/Facades/Facade.php');
copy(__DIR__.'/fixtures/analysis/configuration-facade.php.stub', $framework.'/Support/Facades/Config.php');
copy(__DIR__.'/fixtures/analysis/configuration-repository.php.stub', $framework.'/Config/Repository.php');
file_put_contents($workspace.'/bootstrap/app.php', '<?php throw new RuntimeException("Bootstrap executed.");');
file_put_contents(
    $workspace.'/'.$helpers,
    '<?php function config(?string $key = null, mixed $default = null): mixed { throw new RuntimeException("Helper executed."); }',
);
file_put_contents($workspace.'/config/example.php', <<<'PHP'
    <?php
    return [
        'name' => 'Example',
        'count' => 4,
        'enabled' => true,
        'optional' => null,
        'nested' => ['count' => 2],
        'with.dot' => 'direct',
        'items' => ['first', 'second'],
        'dynamic' => env('EXAMPLE', 'fallback'),
        'computed' => throw new RuntimeException('Configuration executed.'),
        'open' => [...dynamicConfiguration()],
        'class' => stdClass::class,
    ];
    PHP);
file_put_contents($workspace.'/config/dynamic.php', '<?php return dynamicConfiguration();');
file_put_contents(
    $workspace.'/config/conditional.php',
    '<?php if (true) { return ["count" => 1]; } return ["count" => "other"];',
);
$cases = [
    'literal string' => ["return config('example.name');", 'string', []],
    'literal integer' => ["return config('example.count');", 'int', []],
    'literal boolean' => ["return config('example.enabled');", 'bool', []],
    'nested key' => ["return config('example.nested.count');", 'int', []],
    'dotted array key is not traversable' => ["return config('example.with.dot');", 'null', []],
    'closure default deferred' => [
        "return config('example.missing', fn(): int => 42);",
        'int',
        ['mixed-return-statement'],
    ],
    'array shape' => ["return config('example.nested')['count'];", 'int', []],
    'list offset' => ["return config('example.items')[1];", 'string', []],
    'class name' => ["return config('example.class');", 'string', []],
    'missing complete key default' => ["return config('example.absent', 42);", 'int', []],
    'named default arguments' => ["return config(default: 42, key: 'example.absent');", 'int', []],
    'missing complete key null' => ["return config('example.absent');", 'null', []],
    'null ignores default' => ["return config('example.optional', 42);", 'null', []],
    'native mismatch preserved' => ["return config('example.count');", 'string', ['invalid-return-statement']],
    'environment deferred' => ["return config('example.dynamic', 'fallback');", 'string', ['mixed-return-statement']],
    'throw never executed' => ["return config('example.computed');", 'string', ['mixed-return-statement']],
    'unpack missing key deferred' => ["return config('example.open.missing', 42);", 'int', ['mixed-return-statement']],
    'unknown namespace deferred' => ["return config('unknown.missing', 42);", 'int', ['mixed-return-statement']],
    'dynamic namespace deferred' => ["return config('dynamic.missing', 42);", 'int', ['mixed-return-statement']],
    'conditional namespace deferred' => ["return config('conditional.count');", 'int', ['mixed-return-statement']],
    'unknown array root deferred' => ["return config('example');", 'array', ['mixed-return-statement']],
    'native config facade literal' => [
        "return \\Illuminate\\Support\\Facades\\Config::get('example.count');",
        'int',
        [],
    ],
    'native config facade named default' => [
        "return \\Illuminate\\Support\\Facades\\Config::get(default: 42, key: 'example.absent');",
        'int',
        [],
    ],
    'native config facade dynamic key deferred' => [
        '$key = (string) random_int(1, 2); return \\Illuminate\\Support\\Facades\\Config::get($key);',
        'int',
        ['mixed-return-statement'],
    ],
    'arbitrary configuration repository deferred' => [
        "return (new \\Illuminate\\Config\\Repository)->get('example.count');",
        'int',
        ['mixed-return-statement'],
    ],
];
$source = "<?php\n";
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
    'source' => [
        'paths' => ['cases.php'],
        'includes' => [
            $helpers,
            'dependencies with spaces/laravel/framework/src/Illuminate/Support/Facades/Facade.php',
            'dependencies with spaces/laravel/framework/src/Illuminate/Support/Facades/Config.php',
            'dependencies with spaces/laravel/framework/src/Illuminate/Config/Repository.php',
        ],
    ],
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
    throw new RuntimeException('Expected native diagnostics without extension fallback; inspect '.$workspace);
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
    throw new RuntimeException('Unexpected diagnostics outside configuration scenarios; inspect '.$workspace);
}

$assertAlteredContractWins = static function (string $name, array $expected) use ($command, $workspace): void {
    file_put_contents(
        $workspace.'/cases.php',
        '<?php function alteredContract(): string { return \\Illuminate\\Support\\Facades\\Config::get("example.count"); }',
    );
    $process = proc_open(
        [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $workspace.'/altered.json', 'w'],
            2 => ['file', $workspace.'/altered.log', 'w'],
        ],
        $pipes,
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start Mago.');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $report = json_decode(file_get_contents($workspace.'/altered.json'), true, flags: JSON_THROW_ON_ERROR);
    $codes = array_column($report['issues'] ?? [], 'code');
    if (
        $exit !== ($expected === [] ? 0 : 1)
        || $codes !== $expected
        || preg_match(
            '/External analyzer provider failed|extension worker .*rejected request/i',
            file_get_contents($workspace.'/altered.log'),
        )
    ) {
        throw new RuntimeException($name.' must retain its installed return contract; inspect '.$workspace);
    }
    echo 'PASS: '.$name."\n";
};
$configFacadePath = $framework.'/Support/Facades/Config.php';
$configFacadeSource = file_get_contents($configFacadePath);
file_put_contents($configFacadePath, str_replace(
    '@method static mixed get',
    '@method static string get',
    $configFacadeSource,
));
$assertAlteredContractWins('altered facade contract priority', []);
file_put_contents($configFacadePath, $configFacadeSource);
$repositoryPath = $framework.'/Config/Repository.php';
$repositorySource = file_get_contents($repositoryPath);
file_put_contents($repositoryPath, str_replace('@return mixed', '@return string', $repositorySource));
$assertAlteredContractWins('altered repository contract priority', ['mixed-return-statement']);
file_put_contents($repositoryPath, $repositorySource);

file_put_contents(
    $workspace.'/'.$helpers,
    '<?php function config(?string $key = null, mixed $default = null): string { throw new RuntimeException("Helper executed."); }',
);
file_put_contents($workspace.'/cases.php', '<?php function concrete(): string { return config("example.count"); }');
$process = proc_open(
    [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
    [
        0 => ['pipe', 'r'],
        1 => ['file', $workspace.'/override.json', 'w'],
        2 => ['file', $workspace.'/override.log', 'w'],
    ],
    $pipes,
);
if (! is_resource($process)) {
    throw new RuntimeException('Cannot start Mago.');
}
fclose($pipes[0]);
$exit = proc_close($process);
$report = json_decode(file_get_contents($workspace.'/override.json'), true, flags: JSON_THROW_ON_ERROR);
if ($exit !== 0 || ($report['issues'] ?? []) !== []) {
    throw new RuntimeException('Concrete helper declaration must win; inspect '.$workspace);
}
echo "PASS: concrete helper declaration priority\n";
file_put_contents($workspace.'/config/broken.php', '<?php return [');
$process = proc_open(
    [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
    [
        0 => ['pipe', 'r'],
        1 => ['file', $workspace.'/warning.json', 'w'],
        2 => ['file', $workspace.'/warning.log', 'w'],
    ],
    $pipes,
);
if (! is_resource($process)) {
    throw new RuntimeException('Cannot start Mago.');
}
fclose($pipes[0]);
$exit = proc_close($process);
$report = json_decode(file_get_contents($workspace.'/warning.json'), true, flags: JSON_THROW_ON_ERROR);
$codes = array_column($report['issues'] ?? [], 'code');
if ($exit > 1 || $codes !== ['ichinya/laramago/configuration-unavailable']) {
    throw new RuntimeException('Malformed configuration must report its own warning; inspect '.$workspace);
}
echo "PASS: malformed configuration warning\n";

file_put_contents(
    $workspace.'/custom-helper.php',
    '<?php function config(?string $key = null, mixed $default = null): mixed { throw new RuntimeException("Custom helper executed."); }',
);
$configuration = json_decode(file_get_contents($workspace.'/mago.json'), true, flags: JSON_THROW_ON_ERROR);
$configuration['source']['includes'] = ['custom-helper.php'];
file_put_contents($workspace.'/mago.json', json_encode($configuration, JSON_THROW_ON_ERROR));
$process = proc_open(
    [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
    [
        0 => ['pipe', 'r'],
        1 => ['file', $workspace.'/custom.json', 'w'],
        2 => ['file', $workspace.'/custom.log', 'w'],
    ],
    $pipes,
);
if (! is_resource($process)) {
    throw new RuntimeException('Cannot start Mago.');
}
fclose($pipes[0]);
$exit = proc_close($process);
$report = json_decode(file_get_contents($workspace.'/custom.json'), true, flags: JSON_THROW_ON_ERROR);
if (
    $exit !== 1
    || array_column($report['issues'] ?? [], 'code') !== ['mixed-return-statement']
    || preg_match(
        '/External analyzer provider failed|extension worker .*rejected request/i',
        file_get_contents($workspace.'/custom.log'),
    )
) {
    throw new RuntimeException('Custom mixed helper must stay native; inspect '.$workspace);
}
echo "PASS: custom mixed helper remains native\n";

// Test reports and syntax-only execution traps remain in the temporary workspace.
