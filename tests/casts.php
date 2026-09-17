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
copy(__DIR__.'/fixtures/analysis/casts.php.stub', $workspace.'/models.php');
$cases = [
    'method casts declaration' => ['return (new CastExample\MethodRecord)->money;', 'CastExample\Money', []],
    'trait caster methods' => ['return (new CastExample\TraitRecord)->money;', 'CastExample\Money', []],
    'array shape result' => ['return (new CastExample\ArrayRecord)->money["amount"];', 'int', []],
    'array shape write' => ['$m = new CastExample\ArrayRecord; $m->money = ["amount" => 1];', 'void', []],
    'array shape invalid write' => [
        '$m = new CastExample\ArrayRecord; $m->money = ["amount" => "bad"];',
        'void',
        ['invalid-property-assignment-value'],
    ],
    'custom model resolver deferred' => [
        '(new CastExample\CustomResolverRecord)->money;',
        'void',
        ['non-documented-property', 'unused-statement'],
    ],
    'concrete cast read' => ['return (new CastExample\Record)->money;', 'CastExample\Money', []],
    'method on cast result' => ['return (new CastExample\Record)->money->amount();', 'int', []],
    'cast without schema' => ['return (new CastExample\Record)->without_schema;', 'CastExample\Money', []],
    'nullable cast read' => ['return (new CastExample\Record)->nullable;', '?CastExample\Money', []],
    'inherited caster' => ['return (new CastExample\Record)->child;', 'CastExample\Money', []],
    'inherited model' => ['return (new CastExample\ChildRecord)->money;', 'CastExample\Money', []],
    'caster arguments' => ['return (new CastExample\Record)->arguments;', 'CastExample\Money', []],
    'object write' => ['$m = new CastExample\Record; $m->money = new CastExample\Money;', 'void', []],
    'integer write' => ['$m = new CastExample\Record; $m->money = 42;', 'void', []],
    'invalid write' => [
        '$m = new CastExample\Record; $m->money = "invalid";',
        'void',
        ['invalid-property-assignment-value'],
    ],
    'null rejected by caster' => [
        '$m = new CastExample\Record; $m->money = null;',
        'void',
        ['invalid-property-assignment-value'],
    ],
    'null accepted by caster' => ['$m = new CastExample\Record; $m->nullable = null;', 'void', []],
    'nullable read stays nullable' => [
        '(new CastExample\Record)->nullable->amount();',
        'void',
        ['possible-method-access-on-null'],
    ],
    'inbound raw read' => ['return (new CastExample\Record)->inbound;', '?string', []],
    'inbound object write' => ['$m = new CastExample\Record; $m->inbound = new CastExample\Money;', 'void', []],
    'inbound invalid write' => [
        '$m = new CastExample\Record; $m->inbound = "stored";',
        'void',
        ['invalid-property-assignment-value'],
    ],
    'inbound unknown schema' => [
        '(new CastExample\Record)->inbound_unknown;',
        'void',
        ['non-documented-property', 'unused-statement'],
    ],
    'untyped cast deferred' => [
        '(new CastExample\Record)->unknown;',
        'void',
        ['non-documented-property', 'unused-statement'],
    ],
    'dynamic cast deferred' => [
        '(new CastExample\Record)->dynamic;',
        'void',
        ['non-documented-property', 'unused-statement'],
    ],
    'generic cast deferred' => [
        '(new CastExample\Record)->generic;',
        'void',
        ['non-documented-property', 'unused-statement'],
    ],
    'non caster deferred' => [
        '(new CastExample\Record)->plain;',
        'void',
        ['non-documented-property', 'unused-statement'],
    ],
    'property typo' => ['(new CastExample\Record)->monye;', 'void', ['non-documented-property', 'unused-statement']],
    'phpdoc priority' => ['return (new CastExample\Record)->documented;', 'string', []],
    'accessor priority' => ['return (new CastExample\Record)->accessor;', 'string', []],
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
    'source' => ['paths' => ['cases.php'], 'includes' => ['framework.php', 'models.php']],
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
