<?php

declare(strict_types=1);

// Check custom cast read and write contracts through the real SDK worker.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago cast contracts '.bin2hex(random_bytes(8));
mkdir($workspace);
mkdir($workspace.'/bootstrap');
file_put_contents($workspace.'/bootstrap/app.php', '<?php throw new RuntimeException("Bootstrap executed.");');
mkdir($workspace.'/database/migrations', 0o777, true);
file_put_contents(
    $workspace.'/database/migrations/001.php',
    '<?php return new class extends \Illuminate\Database\Migrations\Migration { public function up() { \Illuminate\Support\Facades\Schema::create("cast_contract_records", function ($table) { $table->string("list")->nullable(); $table->string("inbound")->nullable(); }); } };',
);
copy(__DIR__.'/fixtures/analysis/framework.php.stub', $workspace.'/framework.php');
copy(__DIR__.'/fixtures/analysis/generic-casts.php.stub', $workspace.'/base-casts.php');
copy(__DIR__.'/fixtures/analysis/cast-contracts.php.stub', $workspace.'/models.php');
$cases = [
    'nested interface preserves nullable read' => [
        'return (new CastContractExample\Record)->interface_nested;',
        'list<GenericCastExample\Money>|null',
        [],
    ],
    'nested interface write' => [
        '$m = new CastContractExample\Record; $m->interface_nested = ["value" => 1];',
        'void',
        [],
    ],
    'nested interface nullable write' => [
        '$m = new CastContractExample\Record; $m->interface_nested = null;',
        'void',
        [],
    ],
    'nested interface wrong write' => [
        '$m = new CastContractExample\Record; $m->interface_nested = ["value" => "bad"];',
        'void',
        ['invalid-property-assignment-value'],
    ],

    'named generic nested write' => [
        '$m = new CastContractExample\Record; $m->box = CastContractExample\integerBox();',
        'void',
        [],
    ],
    'named generic nested wrong write' => [
        '$m = new CastContractExample\Record; $m->box = CastContractExample\amountBox();',
        'void',
        ['invalid-property-assignment-value'],
    ],
    'nonempty map read' => [
        'return (new CastContractExample\Record)->nonempty_map;',
        'non-empty-array<string, GenericCastExample\Money>',
        [],
    ],
    'nonempty map write' => ['$m = new CastContractExample\Record; $m->nonempty_map = ["a" => 1];', 'void', []],
    'nonempty map rejects empty write' => [
        '$m = new CastContractExample\Record; $m->nonempty_map = [];',
        'void',
        ['invalid-property-assignment-value'],
    ],
    'empty shape read' => ['return (new CastContractExample\Record)->empty_shape;', 'array{}', []],
    'empty shape write' => ['$m = new CastContractExample\Record; $m->empty_shape = [];', 'void', []],

    'nested ancestor list read' => [
        'return (new CastContractExample\Record)->list;',
        'list<GenericCastExample\Money>',
        [],
    ],
    'nested ancestor list write' => ['$m = new CastContractExample\Record; $m->list = [1, 2];', 'void', []],
    'nested ancestor list wrong write' => [
        '$m = new CastContractExample\Record; $m->list = ["bad"];',
        'void',
        ['invalid-property-assignment-value'],
    ],
    'schema nullable does not widen class cast' => [
        '$m = new CastContractExample\Record; $m->list = null;',
        'void',
        ['invalid-property-assignment-value'],
    ],
    'map nested read' => [
        'return (new CastContractExample\Record)->map;',
        'array<string, list<GenericCastExample\Money>>',
        [],
    ],
    'map nested write' => ['$m = new CastContractExample\Record; $m->map = ["a" => [1, null]];', 'void', []],
    'map nested wrong write' => [
        '$m = new CastContractExample\Record; $m->map = ["a" => ["bad"]];',
        'void',
        ['invalid-property-assignment-value'],
    ],
    'multiline shape required read' => [
        'return (new CastContractExample\Record)->shape["value"];',
        'GenericCastExample\Money',
        [],
    ],
    'multiline nested shape read' => ['return (new CastContractExample\Record)->shape["nested"]["count"];', 'int', []],
    'shape optional omitted write' => [
        '$m = new CastContractExample\Record; $m->shape = ["value" => 1, "nested" => ["count" => 2]];',
        'void',
        [],
    ],
    'shape optional supplied write' => [
        '$m = new CastContractExample\Record; $m->shape = ["value" => 1, "nested" => ["count" => 2], "tags" => ["ok"]];',
        'void',
        [],
    ],
    'shape wrong nested write' => [
        '$m = new CastContractExample\Record; $m->shape = ["value" => 1, "nested" => ["count" => "bad"]];',
        'void',
        ['invalid-property-assignment-value'],
    ],
    'shape missing required write' => [
        '$m = new CastContractExample\Record; $m->shape = ["value" => 1];',
        'void',
        ['invalid-property-assignment-value'],
    ],
    'named generic nested read' => [
        'return (new CastContractExample\Record)->box->value();',
        'list<GenericCastExample\Money>',
        [],
    ],
    'named generic wrong read' => [
        'return (new CastContractExample\Record)->box->value();',
        'list<int>',
        ['invalid-return-statement'],
    ],
    'multiple nested ancestry bindings' => [
        'return (new CastContractExample\Record)->deep["payload"];',
        'list<CastContractExample\Box<GenericCastExample\Money>>',
        [],
    ],
    'multiple ancestry nullable nested write' => [
        '$m = new CastContractExample\Record; $m->deep = ["value" => null];',
        'void',
        [],
    ],
    'multiple ancestry wrong nested write' => [
        '$m = new CastContractExample\Record; $m->deep = ["value" => 3];',
        'void',
        ['invalid-property-assignment-value'],
    ],
    'nonempty preserved read' => [
        'return (new CastContractExample\Record)->nonempty;',
        'non-empty-list<GenericCastExample\Money>',
        [],
    ],
    'nonempty write' => ['$m = new CastContractExample\Record; $m->nonempty = [1];', 'void', []],
    'nonempty rejects empty write' => [
        '$m = new CastContractExample\Record; $m->nonempty = [];',
        'void',
        ['invalid-property-assignment-value'],
    ],
    'optional shape may be empty' => ['$m = new CastContractExample\Record; $m->optional = [];', 'void', []],
    'optional shape rejects wrong value' => [
        '$m = new CastContractExample\Record; $m->optional = ["value" => "bad"];',
        'void',
        ['invalid-property-assignment-value'],
    ],
    'one argument array read' => [
        'return (new CastContractExample\Record)->array;',
        'array<array-key, GenericCastExample\Money>',
        [],
    ],
    'one argument array write' => ['$m = new CastContractExample\Record; $m->array = ["value" => 1, 3];', 'void', []],
    'numeric shape read' => ['return (new CastContractExample\Record)->numeric[0];', 'GenericCastExample\Money', []],
    'numeric shape write' => ['$m = new CastContractExample\Record; $m->numeric = [1];', 'void', []],
    'nullable union read' => [
        'return (new CastContractExample\Record)->nullable;',
        'list<GenericCastExample\Money>|null',
        [],
    ],
    'nullable union write' => ['$m = new CastContractExample\Record; $m->nullable = null;', 'void', []],
    'explicit mixed survives' => ['$m = new CastContractExample\Record; $m->mixed = new stdClass;', 'void', []],
    'inbound schema read' => ['return (new CastContractExample\Record)->inbound;', '?string', []],
    'inbound shaped write' => ['$m = new CastContractExample\Record; $m->inbound = ["value" => 1];', 'void', []],
    'inbound wrong shaped write' => [
        '$m = new CastContractExample\Record; $m->inbound = ["value" => "bad"];',
        'void',
        ['invalid-property-assignment-value'],
    ],
    'native caster return priority' => ['return (new CastContractExample\Record)->override;', 'int', []],
    'native caster write mixed priority' => [
        '$m = new CastContractExample\Record; $m->override = "broad";',
        'void',
        [],
    ],
    'explicit phpdoc return priority' => ['return (new CastContractExample\Record)->doc;', 'string', []],
    'explicit phpdoc write priority' => ['$m = new CastContractExample\Record; $m->doc = true;', 'void', []],
    'explicit phpdoc wrong write' => [
        '$m = new CastContractExample\Record; $m->doc = [1];',
        'void',
        ['invalid-property-assignment-value'],
    ],
    'native model property priority' => ['return (new CastContractExample\Record)->native;', 'string', []],
];
foreach ([
    'unknown',
    'unbound',
    'wrong_arity',
    'bare_generic',
    'open_shape',
    'duplicate',
    'invalid_key',
    'overflow_key',
    'malformed_shape',
    'method_template',
    'conflicting',
] as $property) {
    $cases[$property.' deferred'] = [
        '(new CastContractExample\Record)->'.$property.';',
        'void',
        ['non-documented-property', 'unused-statement'],
    ];
}
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
$disabled = proc_open(
    [...$command, '--workspace', $workspace, 'analyze', '--no-extensions', '--reporting-format=json'],
    [
        0 => ['pipe', 'r'],
        1 => ['file', $workspace.'/disabled.json', 'w'],
        2 => ['file', $workspace.'/disabled.log', 'w'],
    ],
    $pipes,
);
if (! is_resource($disabled)) {
    throw new RuntimeException('Cannot start Mago comparison.');
}
fclose($pipes[0]);
if (proc_close($disabled) !== 1) {
    throw new RuntimeException('Expected native diagnostics with the extension disabled; inspect '.$workspace);
}
$baseline = json_decode(file_get_contents($workspace.'/disabled.json'), true, flags: JSON_THROW_ON_ERROR);
$unknown = count(array_filter(
    $baseline['issues'] ?? [],
    static fn (array $issue): bool => $issue['code'] === 'non-documented-property',
));
if ($unknown <= 5 || count($baseline['issues'] ?? []) <= count($report['issues'] ?? [])) {
    throw new RuntimeException('Expected additional unknown properties with the extension disabled; inspect '
    .$workspace);
}
echo 'PASS: extension-disabled comparison preserves native diagnostics'."\n";
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
