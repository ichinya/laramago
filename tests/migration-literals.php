<?php

declare(strict_types=1);

// Read migrations independently of the single analyzed file, without application execution.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago migration literals '.bin2hex(random_bytes(8));
mkdir($workspace.'/database/migrations', 0o777, true);
mkdir($workspace.'/extra migrations', 0o777, true);
mkdir($workspace.'/bootstrap', 0o777, true);
copy(__DIR__.'/fixtures/analysis/framework.php.stub', $workspace.'/framework.php');
file_put_contents($workspace.'/bootstrap/app.php', '<?php throw new RuntimeException("Application must not boot.");');
file_put_contents($workspace.'/composer.json', json_encode([
    'autoload' => ['files' => ['bootstrap/app.php']],
    'extra' => ['laramago' => ['migration-paths' => ['extra migrations']]],
], JSON_THROW_ON_ERROR));
$preparations = [
    'literal column name' => ['$name = "value"; $table->integer($name)->change();', 'int'],
    'copied column name' => ['$name = "value"; $copy = $name; $table->integer($copy)->change();', 'int'],
    'named column argument' => ['$name = "value"; $table->integer(column: $name)->change();', 'int'],
    'literal nullable true' => ['$flag = true; $table->string("value")->nullable($flag)->change();', '?string'],
    'literal nullable false' => ['$flag = false; $table->string("value")->nullable(value: $flag)->change();', 'string'],
    'copied nullable flag' => [
        '$flag = true; $copy = $flag; $table->string("value")->nullable($copy)->change();',
        '?string',
    ],
    'literal null is not a nullable flag' => [
        '$flag = null; $table->string("value")->nullable($flag)->change();',
        null,
    ],
    'integer is not a nullable flag' => ['$flag = 1; $table->string("value")->nullable($flag)->change();', null],
    'computed nullable stays unknown' => ['$flag = !false; $table->string("value")->nullable($flag)->change();', null],
    'literal scalar options' => [
        '$length = 40; $precision = 1.5; $nothing = null; $table->string("value", $length)->default($nothing);',
        'string',
    ],
    'list options' => ['$allowed = ["open", "closed"]; $table->enum("value", $allowed);', 'string'],
    'list containing local' => [
        '$first = "open"; $allowed = [$first, "closed"]; $table->enum("value", $allowed);',
        'string',
    ],
    'list copy' => ['$names = ["value"]; $copy = $names; $table->dropColumn($copy);', null],
    'list drop' => ['$names = ["value", "other"]; $table->dropColumn($names);', null],
    'named list drop' => ['$names = ["value"]; $table->dropColumn(columns: $names);', null],
    'inline local list drop' => ['$name = "value"; $table->dropColumn([$name, "other"]);', null],
    'variadic local drop' => ['$first = "value"; $second = "other"; $table->dropColumn($first, $second);', null],
    'rename from and to locals' => [
        '$from = "value"; $to = "renamed"; $table->renameColumn($from, $to);',
        'string',
        'renamed',
    ],
    'renamed old name disappears' => ['$from = "value"; $to = "renamed"; $table->renameColumn($from, $to);', null],
    'foreign id drop' => ['$name = "value"; $table->dropConstrainedForeignId($name);', null],
    'morph prefix literal' => ['$prefix = "subject"; $table->uuidMorphs($prefix);', 'string', 'subject_id'],
    'nullable morph prefix literal' => [
        '$prefix = "subject"; $table->nullableUuidMorphs($prefix);',
        '?string',
        'subject_type',
    ],
    'morph drop local' => [
        '$table->uuidMorphs("subject"); $prefix = "subject"; $table->dropMorphs($prefix);',
        null,
        'subject_id',
    ],
    'local soft delete drop' => ['$name = "value"; $table->dropSoftDeletes($name);', null],
    'dynamic soft delete drop invalidates table' => ['$table->dropSoftDeletes($external);', null],
    'empty list does not drop' => ['$names = []; $table->dropColumn($names);', 'string'],
    'copy remains independent' => [
        '$name = "value"; $copy = $name; $table->string("other")->default($name); $table->integer($copy)->change();',
        'int',
    ],
    'used local expires' => [
        '$name = "value"; $table->string("other")->default($name); $table->integer($name)->change();',
        null,
    ],
    'used list expires' => ['$names = ["value"]; $table->index($names); $table->dropColumn($names);', null],
    'list reassignment' => ['$names = ["other"]; $names = ["value"]; $table->dropColumn($names);', null],
    'scalar reassignment' => ['$name = "other"; $name = "value"; $table->integer($name)->change();', null],
    'scalar reference' => ['$name = "value"; $copy =& $name; $table->integer($copy)->change();', null],
    'array reference item' => ['$name = "value"; $names = [&$name]; $table->dropColumn($names);', null],
    'array mutation' => ['$names = ["other"]; $names[] = "value"; $table->dropColumn($names);', null],
    'array destructuring' => ['[$name] = ["value"]; $table->integer($name)->change();', null],
    'array unpack' => ['$first = ["value"]; $names = [...$first]; $table->dropColumn($names);', null],
    'associative local stays unknown' => ['$names = ["key" => "value"]; $table->dropColumn($names);', null],
    'nested local stays unknown' => ['$names = [["value"]]; $table->dropColumn($names);', null],
    'unknown list member' => ['$names = [$external]; $table->dropColumn($names);', null],
    'unknown variable' => ['$table->integer($external)->change();', null],
    'concatenation is not folded' => ['$name = "val" . "ue"; $table->integer($name)->change();', null],
    'interpolation is not folded' => ['$part = "value"; $name = "{$part}"; $table->integer($name)->change();', null],
    'captured literal cannot be overwritten' => [
        '$name = "value"; $table->integer($name)->change();',
        null,
        'value',
        ' use ($name)',
    ],
    'captured reference cannot be overwritten' => [
        '$name = "value"; $table->integer($name)->change();',
        null,
        'value',
        ' use (&$name)',
    ],
    'global write' => ['$GLOBALS["name"] = "value";', null],
    'global declaration' => ['global $name; $name = "value"; $table->integer($name)->change();', null],
    'local static declaration' => ['static $name = "value"; $table->integer($name)->change();', null],
    'assignment hidden in option' => ['$name = "value"; $table->integer($name)->default($name = "other");', null],
    'reference hidden in option' => ['$name = "value"; $table->integer($name)->default($copy =& $name);', null],
    'helper hidden in ignored option' => ['$table->string("value")->default(changeSchema());', null],
    'constructor hidden in option' => ['$table->string("value")->default(new SchemaMutator);', null],
    'magic property hidden in option' => ['$table->string("value")->default($object->value);', null],
    'raw literal default' => ['$table->string("value")->default(DB::raw("CURRENT_TIMESTAMP"));', 'string'],
    'raw literal stored expression' => ['$table->string("value")->storedAs(DB::raw("code || label"));', 'string'],
    'raw literal local' => ['$sql = "code || label"; $table->string("value")->virtualAs(DB::raw($sql));', 'string'],
    'raw facade alias' => ['$table->string("value")->default(Database::raw("CURRENT_TIMESTAMP"));', 'string'],
    'raw named argument' => ['$table->string("value")->default(DB::raw(value: "CURRENT_TIMESTAMP"));', 'string'],
    'driver inspection in argument' => [
        '$table->string("value")->storedAs(DB::getDriverName() === "sqlite" ? "x || y" : "concat(x,y)");',
        'string',
    ],
    'raw helper argument' => ['$table->string("value")->default(DB::raw(changeSchema()));', null],
    'raw unknown argument' => ['$table->string("value")->default(DB::raw($external));', null],
    'raw first class callable' => ['$table->string("value")->default(DB::raw(...));', null],
    'raw query facade method' => [
        '$table->string("value")->default(DB::statement("alter table entries drop column value"));',
        null,
    ],
    'object cast hidden in option' => ['$table->string("value")->default((string) $object);', null],
    'clone hidden in option' => ['$table->string("value")->default(clone $object);', null],
    'shell execution hidden in option' => ['$table->string("value")->default(`echo never-execute`);', null],
    'callable argument' => ['$table->string("value")->default(changeSchema(...));', null],
    'unpacked arguments' => ['$names = ["value"]; $table->string(...$names);', null],
    'index helper side effect' => ['$table->index(changeSchema());', null],
    'uncertainty remains sticky' => ['$table->integer($external); $name = "value"; $table->string($name);', null],
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
$modelTemplate = file_get_contents(__DIR__.'/fixtures/analysis/migration-literals-model.php.stub');
foreach ($preparations as $name => $preparation) {
    [$body, $type] = $preparation;
    $index = count($cases);
    $table = 'literal_entries_'.$index;
    $model = 'LiteralEntry'.$index;
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
    $models .= str_replace(['<?php', 'LiteralModel', 'literal_table'], ['', $model, $table], $modelTemplate)."\n";
    $cases[$name] = [
        'return (new '.$model.')->'.$property.';',
        $type ?? 'mixed',
        $type === null ? ['non-documented-property'] : [],
    ];
}
$migration .= 'Schema::create("ordered_literals", function ($table) { $table->string("previous"); $table->string("removed"); });';
$models .= str_replace(
    ['<?php', 'LiteralModel', 'literal_table'],
    ['', 'OrderedLiteral', 'ordered_literals'],
    $modelTemplate,
);
$migration .= ' } public function down(): void { Schema::drop("literal_entries_0"); } };';
file_put_contents($workspace.'/database/migrations/001_literals.php', $migration);
file_put_contents($workspace.'/models.php', $models);
copy(
    __DIR__.'/fixtures/analysis/migration-literals-change.php.stub',
    $workspace.'/extra migrations/002_change.php',
);
copy(
    __DIR__.'/fixtures/analysis/migration-literals-final.php.stub',
    $workspace.'/database/migrations/003_final.php',
);
$cases['ordered extra-path rename and change'] = ['return (new OrderedLiteral)->value;', '?int', []];
$cases['ordered old name removed'] = ['return (new OrderedLiteral)->previous;', 'mixed', ['non-documented-property']];
$cases['ordered extra-path local list drop'] = [
    'return (new OrderedLiteral)->removed;',
    'mixed',
    ['non-documented-property'],
];
$cases['nullable cannot be returned as non-null'] = [
    'return (new LiteralEntry3)->value;',
    'string',
    ['invalid-return-statement', 'nullable-return-statement'],
];
$cases['literal column write rejects object'] = [
    '(new LiteralEntry0)->value = new stdClass;',
    'void',
    ['invalid-property-assignment-value'],
];
$source = "<?php\n";
$lines = [];
foreach ($cases as $name => [$body, $return, $codes]) {
    $source .= '/** @return '.$return.' */'."\n";
    $source .= 'function scenario'.count($lines).'() { '.$body.' }'."\n";
    $lines[substr_count($source, "\n")] = [$name, $codes];
}
file_put_contents($workspace.'/cases.php', $source);
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
