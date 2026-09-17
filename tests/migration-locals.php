<?php

declare(strict_types=1);

// Read migrations independently of the single analyzed file, without application execution.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago migration locals '.bin2hex(random_bytes(8));
mkdir($workspace.'/database/migrations', 0o777, true);
mkdir($workspace.'/bootstrap', 0o777, true);
copy(__DIR__.'/fixtures/analysis/framework.php.stub', $workspace.'/framework.php');
file_put_contents($workspace.'/bootstrap/app.php', '<?php throw new RuntimeException("Application must not boot.");');
file_put_contents($workspace.'/composer.json', json_encode([
    'autoload' => ['files' => ['bootstrap/app.php']],
], JSON_THROW_ON_ERROR));
$preparations = [
    'literal local' => ['$prefix = "entry:";', true],
    'driver expression' => [
        '$sql = DB::getDriverName() === "sqlite" ? "code || label" : "concat(code, label)";',
        true,
    ],
    'driver alias' => ['$driver = Database::getDriverName();', true],
    'fully qualified driver' => ['$driver = \\Illuminate\\Support\\Facades\\DB::getDriverName();', true],
    'case insensitive driver method' => ['$driver = DB::GETDRIVERNAME();', true],
    'scalar dependency' => [
        '$driver = DB::getDriverName(); $sql = $driver === "sqlite" ? "a || b" : "concat(a,b)";',
        true,
    ],
    'interpolated dependency' => ['$prefix = "item"; $sql = "{$prefix}:";', true],
    'scalar operators' => ['$count = 2 + 3; $flag = !false; $other = -$count; $sql = "x" . +$other;', true],
    'null coalesce' => ['$empty = null; $sql = $empty ?? "x";', true],
    'short ternary' => ['$value = "x"; $sql = $value ?: "y";', true],
    'floating point local' => ['$value = 1.5;', true],
    'comment statement' => ['/** Preparation is optional. */;', true],
    'query assignment' => ['$sql = DB::statement("alter table entries drop column value");', false],
    'arbitrary helper' => ['$sql = changeSchema();', false],
    'unknown static call' => ['$sql = OtherDatabase::getDriverName();', false],
    'unresolved alias' => ['$sql = UnknownDB::getDriverName();', false],
    'driver arguments' => ['$sql = DB::getDriverName(changeSchema());', false],
    'first class driver callable' => ['$sql = DB::getDriverName(...);', false],
    'dynamic driver method' => ['$method = "getDriverName"; $sql = DB::$method();', false],
    'object driver method' => ['$sql = $connection->getDriverName();', false],
    'conditional schema' => ['if (DB::getDriverName() === "sqlite") { $table->integer("value")->change(); }', false],
    'schema in ternary' => ['$sql = true ? Schema::drop("other") : "x";', false],
    'call in short circuit' => ['$sql = false && changeSchema();', false],
    'call in unused branch' => ['$sql = true ? "x" : changeSchema();', false],
    'call in interpolation' => ['$sql = "{$object->changeSchema()}";', false],
    'object construction' => ['$sql = new SchemaMutator;', false],
    'nested assignment' => ['$sql = ($table = "x");', false],
    'blueprint overwrite' => ['$table = "x";', false],
    'blueprint reference' => ['$alias =& $table; $alias = "x";', false],
    'blueprint alias' => ['$alias = $table;', false],
    'reference to scalar' => ['$sql = "x"; $alias =& $sql;', false],
    'reassignment' => ['$sql = "x"; $sql = "y";', false],
    'assignment operator' => ['$sql = "x"; $sql .= "y";', false],
    'increment' => ['$counter = 1; $sql = ++$counter;', false],
    'external variable' => ['$sql = $external;', false],
    'captured variable write' => ['$captured = "x";', false, ' use (&$captured)'],
    'captured value write' => ['$captured = "x";', false, ' use ($captured)'],
    'additional parameter write' => ['$other = "x";', false, '', ', $other = null'],
    'global variable' => ['$GLOBALS = "x";', false],
    'superglobal variable' => ['$_ENV = "x";', false],
    'property write' => ['$this->sql = "x";', false],
    'dynamic variable write' => ['${"sql"} = "x";', false],
    'array element write' => ['$options["sql"] = "x";', false],
    'unknown constant' => ['$sql = DATABASE_EXPRESSION;', false],
    'class constant' => ['$sql = Settings::EXPRESSION;', false],
    'include expression' => ['$sql = include "schema.php";', false],
    'variable column name stays unknown' => ['$name = "value"; $table->integer($name)->change();', false],
    'prior argument variable' => ['$table->string("other")->default($alias); $alias = "x";', false],
    'reference hidden in argument' => ['$table->string("other")->default($alias =& $table); $alias = "x";', false],
    'scalar knowledge expires' => ['$sql = "x"; $table->string("other")->default($sql); $new = $sql;', false],
    'generated expression argument' => [
        '$sql = DB::getDriverName() === "sqlite" ? "left || right" : "concat(left, right)"; '
            .'$table->string("formula")->virtualAs("prefix {$sql}");',
        true,
    ],
    'stored expression argument' => ['$sql = "quantity * 2"; $table->integer("formula")->storedAs($sql);', true],
    'uncertainty is sticky' => ['changeSchema(); $sql = "x";', false],
    'same variable in another callback' => ['$sql = "separate";', true],
    'dynamic nullable preserves other columns' => [
        '$flag = true; $table->string("optional")->nullable($flag);',
        true,
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
$modelNames = [];
foreach ($preparations as $name => $preparation) {
    [$body, $supported] = $preparation;
    $index = count($cases);
    $table = 'local_entries_'.$index;
    $model = 'LocalEntry'.$index;
    $modelNames[$name] = $model;
    $capture = $preparation[2] ?? '';
    $parameter = $preparation[3] ?? '';
    $migration .=
        'Schema::create("'
        .$table
        .'", function ($table'
        .$parameter
        .')'
        .$capture
        .' { '
        .'$table->string("value"); '
        .$body
        .' $table->string("computed")->virtualAs("code || label");'
        .' $table->unsignedInteger("quantity")->nullable(); });'
        ."\n";
    $models .=
        'class '.$model.' extends \\Illuminate\\Database\\Eloquent\\Model { protected $table = "'.$table.'"; }'."\n";
    $cases[$name] = [
        'return (new '.$model.')->value;',
        $supported ? 'string' : 'mixed',
        $supported ? [] : ['non-documented-property'],
    ];
}
$migration .= <<<'PHP'
            Schema::table('local_entries_0', function ($table) {
                $sql = 'quantity + 1';
                $table->integer('computed')->storedAs($sql)->change();
                $table->renameColumn('value', 'renamed');
                $table->dropColumn('quantity');
            });
        }
        public function down(): void { Schema::drop('local_entries_1'); }
    };
    PHP;
file_put_contents($workspace.'/database/migrations/001_locals.php', $migration);
file_put_contents($workspace.'/models.php', $models);
$cases['literal local'][0] = 'return (new LocalEntry0)->renamed;';
$cases += [
    'local nullable value is not evaluated' => [
        'return (new '.$modelNames['dynamic nullable preserves other columns'].')->optional;',
        'mixed',
        ['non-documented-property'],
    ],
    'generated column type' => ['return (new LocalEntry1)->computed;', 'string', []],
    'nullable column type' => ['return (new LocalEntry1)->quantity;', '?int', []],
    'nullable remains visible' => [
        'return (new LocalEntry1)->quantity;',
        'int',
        ['invalid-return-statement', 'nullable-return-statement'],
    ],
    'changed column type' => ['return (new LocalEntry0)->computed;', 'int', []],
    'removed column' => ['return (new LocalEntry0)->quantity;', 'mixed', ['non-documented-property']],
    'old column name' => ['return (new LocalEntry0)->value;', 'mixed', ['non-documented-property']],
    'invalid assignment' => ['(new LocalEntry1)->value = new stdClass;', 'void', ['invalid-property-assignment-value']],
    'unknown property' => ['return (new LocalEntry1)->vlaue;', 'mixed', ['non-documented-property']],
    'collection property' => [
        'return (new \\Illuminate\\Database\\Eloquent\\Collection)->value;',
        'mixed',
        ['non-existent-property'],
    ],
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
            'command' => [PHP_BINARY, $package.'/bin/laramago-worker.php', $package.'/vendor/autoload.php', $workspace],
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
