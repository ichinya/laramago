<?php

declare(strict_types=1);

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$changedBody = in_array('--changed-body', $argv, true);
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago query variadics '.bin2hex(random_bytes(8));
$laravel = $workspace.'/laravel/framework/src/Illuminate';
mkdir($laravel.'/Database/Query', 0777, true);
mkdir($laravel.'/Database/Eloquent/Relations', 0777, true);
$builder = $laravel.'/Database/Query/Builder.php';
copy(__DIR__.'/fixtures/analysis/query-builder-variadics.php.stub', $builder);
copy(__DIR__.'/fixtures/analysis/query-builder-eloquent.php.stub', $laravel.'/Database/Eloquent/Builder.php');
copy(__DIR__.'/fixtures/analysis/query-builder-relation.php.stub', $laravel.'/Database/Eloquent/Relations/BelongsToMany.php');
copy(__DIR__.'/fixtures/analysis/query-builder-has-many.php.stub', $laravel.'/Database/Eloquent/Relations/HasMany.php');
if ($changedBody) {
    file_put_contents($builder, str_replace('func_get_args()', '[$columns]', file_get_contents($builder)));
}
file_put_contents($workspace.'/bootstrap.php', '<?php throw new RuntimeException("Never bootstrap test projects.");');
file_put_contents($workspace.'/composer.json', json_encode([
    'autoload' => ['files' => ['bootstrap.php']],
], JSON_THROW_ON_ERROR));

$cases = $changedBody ? [
    'changed select body retains native arity' => ["(new Builder)->select('id', 'name');", ['too-many-arguments']],
    'changed distinct body retains native arity' => ["(new Builder)->distinct('id');", ['too-many-arguments']],
] : [
    'multiple select columns accepted' => ["(new Builder)->select('id', 'name');", []],
    'select array form remains accepted' => ["(new Builder)->select(['id', 'name']);", []],
    'distinct positional columns accepted' => ["(new Builder)->distinct('id', 'name');", []],
    'distinct without arguments remains accepted' => ["(new Builder)->distinct();", []],
    'Eloquent builder forwards positional select' => ["(new EloquentBuilder)->select('id', 'name');", []],
    'Eloquent builder forwards positional distinct' => ["(new EloquentBuilder)->distinct('id');", []],
    'relation forwards positional distinct' => ["(new BelongsToMany)->distinct('id');", []],
    'has-many relation forwards positional distinct' => ["(new HasMany)->distinct('id');", []],
    'named extra select argument remains an error' => [
        "(new Builder)->select(columns: 'id', extra: 'name');",
        ['invalid-named-argument', 'too-many-arguments'],
    ],
    'unpacked select arguments use native analysis' => [
        "(new Builder)->select(...['id', 'name']);",
        ['too-many-arguments'],
    ],
    'subclass override retains its arity' => ["(new CustomBuilder)->select('id', 'name');", ['too-many-arguments']],
];
$source = <<<'PHP'
    <?php
    use Illuminate\Database\Query\Builder;
    use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
    use Illuminate\Database\Eloquent\Relations\BelongsToMany;
    use Illuminate\Database\Eloquent\Relations\HasMany;
    class CustomBuilder extends Builder {
        public function select($columns = ['*']): static { return $this; }
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
