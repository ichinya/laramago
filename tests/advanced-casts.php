<?php

declare(strict_types=1);

// Check custom cast read and write contracts through the real SDK worker.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago casts '.bin2hex(random_bytes(8));
mkdir($workspace);
mkdir($workspace.'/bootstrap');
file_put_contents($workspace.'/bootstrap/app.php', '<?php throw new RuntimeException("Bootstrap executed.");');
mkdir($workspace.'/database/migrations', 0o777, true);
file_put_contents(
    $workspace.'/database/migrations/001.php',
    '<?php return new class extends \Illuminate\Database\Migrations\Migration { public function up() { \Illuminate\Support\Facades\Schema::create("cast_records", function ($table) { $table->string("money")->nullable(); $table->string("nullable"); $table->string("inbound")->nullable(); }); } };',
);
copy(__DIR__.'/fixtures/analysis/framework.php.stub', $workspace.'/framework.php');
copy(__DIR__.'/fixtures/analysis/advanced-casts.php.stub', $workspace.'/models.php');
copy(__DIR__.'/fixtures/analysis/casts.php.stub', $workspace.'/base-casts.php');
$cases = [
    'inherited castable factory' => [
        'return (new CastExample\InheritedCastableRecord)->money;',
        'CastExample\Money',
        [],
    ],
    'constructor arguments deferred' => [
        '(new CastExample\ArgumentCastableRecord)->money;',
        'void',
        ['non-documented-property', 'unused-statement'],
    ],
    'literal castable read' => ['return (new CastExample\CastableRecord)->literal;', 'CastExample\Money', []],
    'object castable read' => ['return (new CastExample\CastableRecord)->object;', 'CastExample\Money', []],
    'nullable castable read' => ['return (new CastExample\CastableRecord)->nullable;', '?CastExample\Money', []],
    'literal castable write' => ['$m = new CastExample\CastableRecord; $m->literal = 12;', 'void', []],
    'castable bad write' => [
        '$m = new CastExample\CastableRecord; $m->literal = "bad";',
        'void',
        ['invalid-property-assignment-value'],
    ],
    'castable null write' => [
        '$m = new CastExample\CastableRecord; $m->literal = null;',
        'void',
        ['invalid-property-assignment-value'],
    ],
    'castable nullable write' => ['$m = new CastExample\CastableRecord; $m->nullable = null;', 'void', []],
    'conditional factory deferred' => [
        '(new CastExample\CastableRecord)->conditional;',
        'void',
        ['non-documented-property', 'unused-statement'],
    ],
    'recursive factory deferred' => [
        '(new CastExample\CastableRecord)->recursive;',
        'void',
        ['non-documented-property', 'unused-statement'],
    ],
    'invalid factory deferred' => [
        '(new CastExample\CastableRecord)->invalid;',
        'void',
        ['non-documented-property', 'unused-statement'],
    ],
];
$source = <<<'PHP'
    <?php
    use Illuminate\Database\Eloquent\Builder;
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
    'source' => ['paths' => ['cases.php'], 'includes' => ['framework.php', 'base-casts.php', 'models.php']],
    'extension-hosts' => [
        'laramago' => [
            'command' => [PHP_BINARY, $package.'/bin/laramago-worker.php', $package.'/vendor/autoload.php', $workspace],
            'workers' => 3,
        ],
    ],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$process = proc_open(
    [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
    [0 => ['pipe', 'r'], 1 => ['file', $workspace.'/report.json', 'w'], 2 => ['file', $workspace.'/stderr.log', 'w']],
    $pipes,
);
if (! is_resource($process)) {
    throw new RuntimeException('Cannot start Mago.');
}
fclose($pipes[0]);
$exit = proc_close($process);
$log = file_get_contents($workspace.'/stderr.log');
if ($exit !== 1 || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)) {
    throw new RuntimeException('Expected native negative diagnostics without extension fallback; inspect '.$workspace);
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
    throw new RuntimeException('Unexpected diagnostics outside cast scenarios; inspect '.$workspace);
}
unlink($workspace.'/bootstrap/app.php');
rmdir($workspace.'/bootstrap');
unlink($workspace.'/database/migrations/001.php');
rmdir($workspace.'/database/migrations');
rmdir($workspace.'/database');
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
