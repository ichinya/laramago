<?php

declare(strict_types=1);

// Read migrations independently of the single analyzed file, without application execution.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago migration contracts '.bin2hex(random_bytes(8));
mkdir($workspace.'/database/migrations', 0o777, true);
mkdir($workspace.'/bootstrap', 0o777, true);
copy(__DIR__.'/fixtures/analysis/framework.php.stub', $workspace.'/framework.php');
file_put_contents($workspace.'/bootstrap/app.php', '<?php throw new RuntimeException("Application must not boot.");');
file_put_contents($workspace.'/composer.json', json_encode([
    'autoload' => ['files' => ['bootstrap/app.php']],
    'extra' => ['laramago' => []],
], JSON_THROW_ON_ERROR));
$preparations = [
    'inline list declaration' => [
        'foreach (["value", "second"] as $column) { $table->integer($column)->change(); }',
        'int',
    ],
    'second inline list column' => [
        'foreach (["value", "second"] as $column) { $table->integer($column)->nullable(); }',
        '?int',
        'second',
    ],
    'local literal list' => [
        '$names = ["value", "second"]; foreach ($names as $column) { $table->integer($column); }',
        'int',
    ],
    'copied literal list' => [
        '$names = ["value"]; $copy = $names; foreach ($copy as $column) { $table->integer($column); }',
        'int',
    ],
    'list containing fresh local' => [
        '$name = "value"; foreach ([$name] as $column) { $table->integer($column); }',
        'int',
    ],
    'local nullable modifier' => [
        '$flag = true; foreach (["value"] as $column) { $table->integer($column)->nullable($flag); }',
        '?int',
    ],
    'named column argument' => ['foreach (["value"] as $column) { $table->integer(column: $column); }', 'int'],
    'empty list preserves schema' => ['foreach ([] as $column) { $table->dropColumn($column); }', 'string'],
    'comment in body' => ['foreach (["value"] as $column) { /** declaration */; $table->integer($column); }', 'int'],
    'multiple independent declarations' => [
        'foreach (["value"] as $column) { $table->integer($column); $table->string("added"); }',
        'string',
        'added',
    ],
    'raw wrapper inside loop' => [
        'foreach (["value"] as $column) { $table->integer($column)->default(DB::raw("1")); }',
        'int',
    ],
    'list drop removes column' => ['foreach (["value", "other"] as $column) { $table->dropColumn($column); }', null],
    'ordered loop rename' => [
        'foreach (["value"] as $column) { $table->renameColumn($column, "renamed"); }',
        'string',
        'renamed',
    ],
    'ordered old name absent' => ['foreach (["value"] as $column) { $table->renameColumn($column, "renamed"); }', null],
    'duplicate iterations retain order' => [
        'foreach (["value", "value"] as $column) { $table->integer($column); $table->dropColumn("value"); }',
        null,
    ],
    'loop followed by change' => [
        'foreach (["value"] as $column) { $table->integer($column)->nullable(); } $table->string("value")->change();',
        'string',
    ],
    'loop followed by drop' => [
        'foreach (["value"] as $column) { $table->integer($column); } $table->dropColumn("value");',
        null,
    ],
    'loop followed by rename' => [
        'foreach (["value"] as $column) { $table->integer($column); } $table->renameColumn("value", "renamed");',
        'int',
        'renamed',
    ],
    'unknown list' => ['foreach ($external as $column) { $table->integer($column); }', null],
    'driver dependent list' => [
        '$names = DB::getDriverName() === "sqlite" ? ["value"] : ["other"]; foreach ($names as $column) { $table->integer($column); }',
        null,
    ],
    'database condition stays unknown' => [
        'if (Schema::hasColumn("entries", "value")) { foreach (["value"] as $column) { $table->integer($column); } }',
        null,
    ],
    'reference iteration' => ['foreach (["value"] as &$column) { $table->integer($column); }', null],
    'key variable iteration' => ['foreach (["value"] as $key => $column) { $table->integer($column); }', null],
    'destructuring target' => ['foreach ([["value"]] as [$column]) { $table->integer($column); }', null],
    'blueprint target' => ['foreach (["value"] as $table) { $table->integer("value"); }', null],
    'previously assigned target' => [
        '$column = "other"; foreach (["value"] as $column) { $table->integer($column); }',
        null,
    ],
    'captured value target' => [
        'foreach (["value"] as $column) { $table->integer($column); }',
        null,
        'value',
        ' use ($column)',
    ],
    'captured reference target' => [
        'foreach (["value"] as $column) { $table->integer($column); }',
        null,
        'value',
        ' use (&$column)',
    ],
    'superglobal target' => ['foreach (["value"] as $_ENV) { $table->integer($_ENV); }', null],
    'associative list' => ['foreach (["name" => "value"] as $column) { $table->integer($column); }', null],
    'unknown member' => ['foreach ([$external] as $column) { $table->integer($column); }', null],
    'nested list' => ['foreach ([["value"]] as $column) { $table->integer($column); }', null],
    'reference list member' => ['$name = "value"; foreach ([&$name] as $column) { $table->integer($column); }', null],
    'unpacked list' => ['$names = ["value"]; foreach ([...$names] as $column) { $table->integer($column); }', null],
    'mutated list' => [
        '$names = ["other"]; $names[] = "value"; foreach ($names as $column) { $table->integer($column); }',
        null,
    ],
    'body assignment' => ['foreach (["value"] as $column) { $column = "other"; $table->integer($column); }', null],
    'body break' => ['foreach (["value"] as $column) { break; $table->integer($column); }', null],
    'body continue' => ['foreach (["value"] as $column) { continue; $table->integer($column); }', null],
    'body return' => ['foreach (["value"] as $column) { return; $table->integer($column); }', null],
    'nested loop' => [
        'foreach (["value"] as $column) { foreach (["value"] as $inner) { $table->integer($inner); } }',
        null,
    ],
    'body conditional' => ['foreach (["value"] as $column) { if (true) { $table->integer($column); } }', null],
    'body helper' => ['foreach (["value"] as $column) { changeSchema(); $table->integer($column); }', null],
    'hidden mutation' => [
        'foreach (["value"] as $column) { $table->integer($column)->default($column = "other"); }',
        null,
    ],
    'hidden reference' => [
        'foreach (["value"] as $column) { $table->integer($column)->default($alias =& $column); }',
        null,
    ],
    'hidden helper' => ['foreach (["value"] as $column) { $table->integer($column)->default(changeSchema()); }', null],
    'hidden closure capture' => [
        'foreach (["value"] as $column) { $table->integer($column)->default(function () use (&$column) {}); }',
        null,
    ],
    'unknown blueprint method' => ['foreach (["value"] as $column) { $table->unknownColumn($column); }', null],
    'loop target expires' => [
        'foreach (["other"] as $column) { $table->integer($column); } $table->integer($column);',
        null,
    ],
    'source local expires' => [
        '$names = ["value"]; foreach ($names as $column) { $table->integer($column); } $table->dropColumn($names);',
        null,
    ],
    'body local expires' => [
        '$flag = true; foreach (["value"] as $column) { $table->integer($column)->nullable($flag); } $table->string("value")->nullable($flag);',
        null,
    ],
    'reused target in second declaration' => [
        'foreach (["value"] as $column) { $table->index($column); $table->integer($column); }',
        null,
    ],
    'null member is not a column' => ['foreach ([null] as $column) { $table->integer($column); }', null],
    'integer member is not a column' => ['foreach ([1] as $column) { $table->integer($column); }', null],
    'sixty four elements supported' => [
        'foreach (['.implode(',', array_fill(0, 64, '"value"')).'] as $column) { $table->integer($column); }',
        'int',
    ],
    'sixty five elements rejected' => [
        'foreach (['.implode(',', array_fill(0, 65, '"value"')).'] as $column) { $table->integer($column); }',
        null,
    ],
    'body statement bound' => [
        'foreach (["value"] as $column) { '.str_repeat('$table->integer("value");', 65).' }',
        null,
    ],
    'expansion bound' => [
        'foreach (['
            .implode(',', array_fill(0, 64, '"value"'))
            .'] as $column) { '
            .str_repeat('$table->integer("value");', 5)
            .' }',
        null,
    ],
    'sticky earlier uncertainty' => [
        '$table->integer($external); foreach (["value"] as $column) { $table->integer($column); }',
        null,
    ],
];
$migration = <<<'PHP'
    <?php
    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Support\Facades\Schema;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\DB as Database;
    file_put_contents(__DIR__.'/executed', 'Migration must not execute.');
    throw new RuntimeException('Migration must only be parsed.');
    return new class extends Migration {
        public function up(): void {
    PHP;
$models = "<?php\n";
$cases = [];
$modelTemplate = file_get_contents(__DIR__.'/fixtures/analysis/migration-contracts-model.php.stub');
foreach ($preparations as $name => $preparation) {
    [$body, $type] = $preparation;
    $index = count($cases);
    $table = 'contract_entries_'.$index;
    $model = 'ContractEntry'.$index;
    $property = $preparation[2] ?? 'value';
    $capture = $preparation[3] ?? '';
    $migration .=
        'Schema::create("'
        .$table
        .'", function ($table)'
        .$capture
        .' { '
        .'$table->string("value"); $table->string("other"); '
        .$body
        .' });'
        ."\n";
    $models .= str_replace(['<?php', 'ContractModel', 'contract_table'], ['', $model, $table], $modelTemplate)."\n";
    $cases[$name] = [
        'return (new '.$model.')->'.$property.';',
        $type ?? 'mixed',
        $type === null ? ['non-documented-property'] : [],
    ];
}
$migration .= 'Schema::create("ordered_contracts", function ($table) { foreach (["previous", "removed"] as $column) { $table->string($column); } });';
$migration .= ' } public function down(): void { Schema::drop("contract_entries_0"); } };';
file_put_contents($workspace.'/database/migrations/001_contracts.php', $migration);
$models .= str_replace(
    ['<?php', 'ContractModel', 'contract_table'],
    ['', 'OrderedContract', 'renamed_contracts'],
    $modelTemplate,
);
copy(
    __DIR__.'/fixtures/analysis/migration-contracts-change.php.stub',
    $workspace.'/database/migrations/002_change.php',
);
copy(__DIR__.'/fixtures/analysis/migration-contracts-final.php.stub', $workspace.'/database/migrations/003_final.php');
$models .= str_replace(
    '<?php',
    '',
    file_get_contents(__DIR__.'/fixtures/analysis/migration-contracts-overrides.php.stub'),
);
file_put_contents($workspace.'/models.php', $models);
$cases['nullable type rejected as non-null'] = [
    'return (new ContractEntry1)->second;',
    'int',
    ['invalid-return-statement', 'nullable-return-statement'],
];
$cases['wrong write rejected'] = [
    '(new ContractEntry0)->value = new stdClass;',
    'void',
    ['invalid-property-assignment-value'],
];
$cases['native property wins'] = ['return (new NativeContract)->value;', 'bool', []];
$cases['documented property wins'] = ['return (new DocumentedContract)->value;', 'bool', []];
$cases['ordered migrations rename then change'] = ['return (new OrderedContract)->value;', '?int', []];
$cases['ordered old column removed'] = [
    'return (new OrderedContract)->previous;',
    'mixed',
    ['non-documented-property'],
];
$cases['ordered drop removes column'] = [
    'return (new OrderedContract)->removed;',
    'mixed',
    ['non-documented-property'],
];
$config = [
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
];
file_put_contents($workspace.'/mago.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
function check_migration_contracts(array $cases, array $command, string $workspace): void
{
    $source = "<?php\n";
    $lines = [];
    foreach ($cases as $name => [$body, $return, $codes]) {
        $source .= '/** @return '.$return.' */'."\n";
        $source .= 'function scenario'.count($lines).'() { '.$body.' }'."\n";
        $lines[substr_count($source, "\n")] = [$name, $codes];
    }
    file_put_contents($workspace.'/cases.php', $source);
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
    if ($exit !== 1 || preg_match('/provider failed|rejected request/i', file_get_contents($workspace.'/stderr.log'))) {
        throw new RuntimeException('Expected negative diagnostics without extension fallback; inspect '.$workspace);
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
        throw new RuntimeException('Unexpected diagnostics outside migration scenarios; inspect '.$workspace);
    }
}

check_migration_contracts($cases, $command, $workspace);

unset($config['extension-hosts']);
file_put_contents($workspace.'/mago.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
check_migration_contracts(
    [
        'disabled loop inference control' => [
            'return (new ContractEntry0)->value;',
            'int',
            ['non-documented-property', 'mixed-return-statement'],
        ],
        'disabled native override' => ['return (new NativeContract)->value;', 'bool', []],
        'disabled documented override' => ['return (new DocumentedContract)->value;', 'bool', []],
    ],
    $command,
    $workspace,
);
foreach (['database/migrations/executed', '.env'] as $file) {
    if (file_exists($workspace.'/'.$file)) {
        throw new RuntimeException('Application execution or environment detected: '.$file);
    }
}
$resolvedWorkspace = realpath($workspace);
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
);
foreach ($files as $file) {
    $resolvedFile = $file->getRealPath();
    if (
        $resolvedWorkspace === false
        || $resolvedFile === false
        || strncasecmp($resolvedFile, $resolvedWorkspace.DIRECTORY_SEPARATOR, strlen($resolvedWorkspace) + 1) !== 0
    ) {
        throw new RuntimeException('Refusing cleanup outside the test workspace.');
    }
    if ($file->isDir()) {
        rmdir($file->getPathname());
        continue;
    }
    unlink($file->getPathname());
}
rmdir($workspace);
