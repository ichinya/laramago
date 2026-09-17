<?php

declare(strict_types=1);

// Check custom cast read and write contracts through the real SDK worker.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago generic casts '.bin2hex(random_bytes(8));
mkdir($workspace);
mkdir($workspace.'/bootstrap');
file_put_contents($workspace.'/bootstrap/app.php', '<?php throw new RuntimeException("Bootstrap executed.");');
mkdir($workspace.'/database/migrations', 0o777, true);
file_put_contents(
    $workspace.'/database/migrations/001.php',
    '<?php return new class extends \Illuminate\Database\Migrations\Migration { public function up() { \Illuminate\Support\Facades\Schema::create("cast_records", function ($table) { $table->string("concrete")->nullable(); $table->string("nullable"); $table->string("inbound")->nullable(); }); } };',
);
copy(__DIR__.'/fixtures/analysis/framework.php.stub', $workspace.'/framework.php');
copy(__DIR__.'/fixtures/analysis/generic-casts.php.stub', $workspace.'/models.php');
$cases = [
    'interface inherited read' => [
        'return (new GenericCastExample\Record)->interface;',
        '?GenericCastExample\Money',
        [],
    ],
    'interface invalid read' => [
        'return (new GenericCastExample\Record)->interface;',
        'int',
        ['invalid-return-statement', 'nullable-return-statement'],
    ],
    'interface valid write' => ['$m = new GenericCastExample\Record; $m->interface = 12;', 'void', []],
    'interface null write' => ['$m = new GenericCastExample\Record; $m->interface = null;', 'void', []],
    'interface invalid write' => [
        '$m = new GenericCastExample\Record; $m->interface = "wrong";',
        'void',
        ['invalid-property-assignment-value'],
    ],
    'explicit read priority' => ['return (new GenericCastExample\Record)->explicit;', 'GenericCastExample\Money', []],
    'explicit broad write preserved' => ['$m = new GenericCastExample\Record; $m->explicit = "broad";', 'void', []],
    'generic ancestor read' => ['return (new GenericCastExample\Record)->concrete;', 'GenericCastExample\Money', []],
    'generic ancestor write' => ['$m = new GenericCastExample\Record; $m->concrete = 12;', 'void', []],
    'ancestor wrong write' => [
        '$m = new GenericCastExample\Record; $m->concrete = "wrong";',
        'void',
        ['invalid-property-assignment-value'],
    ],
    'ancestor null rejected' => [
        '$m = new GenericCastExample\Record; $m->concrete = null;',
        'void',
        ['invalid-property-assignment-value'],
    ],
    'multiple ancestry levels' => ['return (new GenericCastExample\Record)->multi;', 'GenericCastExample\Money', []],
    'multiple ancestry nullable write' => ['$m = new GenericCastExample\Record; $m->multi = null;', 'void', []],
    'nullable generic read' => ['return (new GenericCastExample\Record)->nullable;', '?GenericCastExample\Money', []],
    'nullable dereference warning' => [
        '(new GenericCastExample\Record)->nullable->amount();',
        'void',
        ['possible-method-access-on-null'],
    ],
    'inbound generic schema read' => ['return (new GenericCastExample\Record)->inbound;', '?string', []],
    'inbound generic write' => [
        '$m = new GenericCastExample\Record; $m->inbound = new GenericCastExample\Money;',
        'void',
        [],
    ],
    'inbound generic wrong write' => [
        '$m = new GenericCastExample\Record; $m->inbound = 12;',
        'void',
        ['invalid-property-assignment-value'],
    ],
    'nested shape substitution' => [
        'return (new GenericCastExample\Record)->shape["value"];',
        'GenericCastExample\Money',
        [],
    ],
    'nested list substitution' => [
        '$m = new GenericCastExample\Record; $m->shape = [new GenericCastExample\Money];',
        'void',
        [],
    ],
    'nested list wrong write' => [
        '$m = new GenericCastExample\Record; $m->shape = [12];',
        'void',
        ['invalid-property-assignment-value'],
    ],
    'generic castable target' => ['return (new GenericCastExample\Record)->castable;', 'GenericCastExample\Money', []],
];
foreach (['chain', 'unresolved', 'unbound', 'wrong', 'inbound_unknown'] as $property) {
    $cases[$property.' deferred'] = [
        '(new GenericCastExample\Record)->'.$property.';',
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
    'source' => ['paths' => ['cases.php'], 'includes' => ['framework.php', 'models.php']],
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
