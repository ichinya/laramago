<?php

declare(strict_types=1);

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$changedBody = in_array('--changed-body', $argv, true);
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago request only '.bin2hex(random_bytes(8));
$laravel = $workspace.'/laravel/framework/src/Illuminate';
mkdir($laravel.'/Support/Traits', 0777, true);
mkdir($laravel.'/Http', 0777, true);
$trait = $laravel.'/Support/Traits/InteractsWithData.php';
copy(__DIR__.'/fixtures/analysis/request-only-trait.php.stub', $trait);
copy(__DIR__.'/fixtures/analysis/request-only-request.php.stub', $laravel.'/Http/Request.php');
if ($changedBody) {
    file_put_contents($trait, str_replace('func_get_args()', '[$keys]', file_get_contents($trait)));
}
file_put_contents($workspace.'/bootstrap.php', '<?php throw new RuntimeException("Never bootstrap test projects.");');
file_put_contents($workspace.'/composer.json', json_encode([
    'autoload' => ['files' => ['bootstrap.php']],
], JSON_THROW_ON_ERROR));

$cases = $changedBody ? [
    'changed body preserves native arity' => ["acceptArray((new Request)->only('a', 'b'));", ['too-many-arguments']],
] : [
    'two positional keys accepted' => ["acceptArray((new Request)->only('a', 'b'));", []],
    'array form remains accepted' => ["acceptArray((new Request)->only(['a', 'b']));", []],
    'missing key remains an error' => ['(new Request)->only();', ['too-few-arguments']],
    'named extra remains an error' => [
        "(new Request)->only(keys: 'a', extra: 'b');",
        ['invalid-named-argument', 'too-many-arguments'],
    ],
    'unpacked arguments use native analysis' => [
        "(new Request)->only(...['a', 'b']);",
        ['too-many-arguments'],
    ],
    'subclass override retains its arity' => ["(new CustomRequest)->only('a', 'b');", ['too-many-arguments']],
];
$source = <<<'PHP'
    <?php
    use Illuminate\Http\Request;
    function acceptArray(array $value): void {}
    class CustomRequest extends Request {
        public function only($keys): array { return []; }
    }

    PHP;
$lines = [];
foreach ($cases as $name => [$body, $expected]) {
    $source .= 'function scenario'.count($lines).'(): void { '.$body.' }' . "\n";
    $lines[substr_count($source, "\n")] = [$name, $expected];
}
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => [
        'paths' => ['cases.php'],
        'includes' => [$laravel],
    ],
    'extension-hosts' => [
        'laramago' => [
            'command' => [PHP_BINARY, $package.'/bin/laramago-worker.php', $package.'/vendor/autoload.php', $workspace],
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
