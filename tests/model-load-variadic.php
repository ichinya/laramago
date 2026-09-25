<?php

declare(strict_types=1);

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$changedBody = in_array('--changed-body', $argv, true);
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago model load '.bin2hex(random_bytes(8));
$laravel = $workspace.'/laravel/framework/src/Illuminate';
mkdir($laravel.'/Database/Eloquent', 0777, true);
$model = $laravel.'/Database/Eloquent/Model.php';
copy(__DIR__.'/fixtures/analysis/model-load-variadic.php.stub', $model);
if ($changedBody) {
    file_put_contents($model, str_replace('func_get_args()', '[$relations]', file_get_contents($model)));
}
file_put_contents($workspace.'/bootstrap.php', '<?php throw new RuntimeException("Never bootstrap test projects.");');
file_put_contents($workspace.'/composer.json', json_encode([
    'autoload' => ['files' => ['bootstrap.php']],
], JSON_THROW_ON_ERROR));

$cases = $changedBody ? [
    'changed body preserves native arity' => ["(new WorkLog)->load('first', 'second');", ['too-many-arguments']],
] : [
    'multiple positional relations accepted' => ["(new WorkLog)->load('first', 'second');", []],
    'array form remains accepted' => ["(new WorkLog)->load(['first', 'second']);", []],
    'missing relations remains an error' => ["(new WorkLog)->load();", ['too-few-arguments']],
    'named extra relation remains an error' => [
        "(new WorkLog)->load(relations: 'first', extra: 'second');",
        ['invalid-named-argument', 'too-many-arguments'],
    ],
    'unpacked relations use native analysis' => [
        "(new WorkLog)->load(...['first', 'second']);",
        ['too-many-arguments'],
    ],
    'subclass override retains its arity' => ["(new CustomWorkLog)->load('first', 'second');", ['too-many-arguments']],
];
$source = <<<'PHP'
    <?php
    use Illuminate\Database\Eloquent\Model;
    class WorkLog extends Model {}
    class CustomWorkLog extends Model {
        public function load($relations): static { return $this; }
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
