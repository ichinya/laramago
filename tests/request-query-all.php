<?php

declare(strict_types=1);

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$changedBody = in_array('--changed-body', $argv, true);
$reformatted = in_array('--reformatted', $argv, true);
$contractLf = in_array('--lf-contract', $argv, true);
$contractCrlf = in_array('--crlf-contract', $argv, true);
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago request query '.bin2hex(random_bytes(8));
$laravel = $workspace.'/laravel/framework/src/Illuminate/Http';
$symfony = $workspace.'/symfony/http-foundation';
mkdir($laravel.'/Concerns', 0777, true);
mkdir($symfony, 0777, true);
copy(__DIR__.'/fixtures/analysis/request-query-all-laravel.php.stub', $laravel.'/Concerns/InteractsWithInput.php');
copy(__DIR__.'/fixtures/analysis/request-query-all-request.php.stub', $laravel.'/Request.php');
copy(__DIR__.'/fixtures/analysis/request-query-all-symfony-parameter-bag.php.stub', $symfony.'/ParameterBag.php');
copy(__DIR__.'/fixtures/analysis/request-query-all-symfony-input-bag.php.stub', $symfony.'/InputBag.php');
copy(__DIR__.'/fixtures/analysis/request-query-all-symfony-request.php.stub', $symfony.'/Request.php');
if ($changedBody) {
    $trait = $laravel.'/Concerns/InteractsWithInput.php';
    file_put_contents($trait, str_replace(
        "return \$this->retrieveItem('query', \$key, \$default);",
        "return \$this->retrieveItem('query', 'fixed', \$default);",
        file_get_contents($trait),
    ));
} elseif ($reformatted) {
    $trait = $laravel.'/Concerns/InteractsWithInput.php';
    file_put_contents($trait, str_replace(
        "return \$this->retrieveItem('query', \$key, \$default);",
        'return   $this->retrieveItem( "query" , $key, $default );',
        file_get_contents($trait),
    ));
}
file_put_contents($workspace.'/bootstrap.php', '<?php throw new RuntimeException("Never bootstrap test projects.");');
file_put_contents($workspace.'/composer.json', json_encode([
    'autoload' => ['files' => ['bootstrap.php']],
], JSON_THROW_ON_ERROR));
$autoload = $package.'/vendor/autoload.php';
if ($contractLf || $contractCrlf) {
    $contract = file_get_contents($package.'/src/Analyzer/StaticAnalysis/NativeRequestQueryAll.php');
    $contract = str_replace("\r\n", "\n", $contract);
    if ($contractCrlf) {
        $contract = str_replace("\n", "\r\n", $contract);
    }
    $contractPath = $workspace.'/NativeRequestQueryAll.php';
    file_put_contents($contractPath, $contract);
    $autoload = $workspace.'/worker-autoload.php';
    file_put_contents(
        $autoload,
        '<?php require '
        .var_export($package.'/vendor/autoload.php', true)
        .'; require '
        .var_export($contractPath, true)
        .';',
    );
}

$cases = $changedBody
    ? [
        'changed Laravel body defers' => [
            'acceptArray(request()->query());',
            ['possibly-invalid-argument', 'possibly-null-argument'],
        ],
    ] : [
        'no-argument query is array' => ['acceptArray(request()->query());', []],
        'positional key remains nullable' => [
            "acceptArray(request()->query('id'));",
            ['possibly-invalid-argument', 'possibly-null-argument'],
        ],
        'named key remains nullable' => [
            "acceptArray(request()->query(key: 'id'));",
            ['possibly-invalid-argument', 'possibly-null-argument'],
        ],
        'explicit default remains native' => [
            'acceptArray(request()->query(default: []));',
            ['possibly-invalid-argument', 'possibly-null-argument'],
        ],
        'concrete subclass override preserved' => [
            'acceptArray((new CustomRequest)->query());',
            ['invalid-argument'],
        ],
        'array cannot be used as string' => [
            'acceptString(request()->query());',
            ['invalid-argument'],
        ],
    ];
$source = <<<'PHP'
    <?php
    use Illuminate\Http\Request;
    function request(): Request { return new Request; }
    function acceptArray(array $value): void {}
    function acceptString(string $value): void {}
    class CustomRequest extends Request {
        public function query($key = null, $default = null): string { return 'custom'; }
    }

    PHP;
$lines = [];
foreach ($cases as $name => [$body, $expected]) {
    $source .= 'function scenario'.count($lines).'(): void { '.$body.' }'."\n";
    $lines[substr_count($source, "\n")] = [$name, $expected];
}
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => [
        'paths' => ['cases.php'],
        'includes' => [$laravel, $symfony],
    ],
    'extension-hosts' => [
        'laramago' => [
            'command' => [PHP_BINARY, $package.'/bin/laramago-worker.php', $autoload, $workspace],
            'workers' => 1,
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
    throw new RuntimeException('Unexpected Mago result; inspect '.$workspace.' (exit '.$exit.'): '.$log);
}
$report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
$actual = [];
foreach ($report['issues'] ?? [] as $issue) {
    $primary = array_values(array_filter(
        $issue['annotations'],
        static fn (array $annotation): bool => $annotation['kind'] === 'Primary',
    ))[0];
    $actual[$primary['span']['start']['line'] + 1][] = $issue['code'];
}
foreach ($lines as $line => [$name, $expected]) {
    $codes = $actual[$line] ?? [];
    sort($codes);
    sort($expected);
    if ($codes !== $expected) {
        throw new RuntimeException(
            $name.': expected '.json_encode($expected).', got '.json_encode($codes).'; inspect '.$workspace,
        );
    }
    unset($actual[$line]);
    echo 'PASS: '.$name."\n";
}
if ($actual !== []) {
    throw new RuntimeException('Unexpected diagnostics outside scenarios; inspect '.$workspace);
}

$resolved = realpath($workspace);
$temporary = realpath(sys_get_temp_dir());
if ($resolved === false || $temporary === false || ! str_starts_with($resolved, $temporary.DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Refusing cleanup outside the temporary directory.');
}
$items = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
);
foreach ($items as $item) {
    $path = $item->getPathname();
    if (! str_starts_with($path, $resolved.DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Refusing cleanup outside the test workspace.');
    }
    $item->isDir() ? rmdir($path) : unlink($path);
}
rmdir($resolved);
