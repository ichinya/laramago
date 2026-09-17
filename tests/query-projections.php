<?php

declare(strict_types=1);

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago query projections '.bin2hex(random_bytes(8));
mkdir($workspace.'/database/migrations', 0777, true);
$framework = file_get_contents(__DIR__.'/fixtures/analysis/framework.php.stub');
$methods = substr(file_get_contents(__DIR__.'/fixtures/analysis/query-projections-framework.php.stub'), 5);
$framework = preg_replace_callback(
    '/class Builder\n\{/',
    static fn (): string => "class Builder\n{".$methods,
    str_replace("\r\n", "\n", $framework),
    1,
);
$framework .= <<<'PHP'

    namespace Illuminate\Support;
    /** @template TKey of array-key
     * @template TValue */
    class Collection {
        /** @return array<TKey, TValue> */ public function all() {}
    }
    PHP;
file_put_contents($workspace.'/framework.php', $framework);
copy(__DIR__.'/fixtures/analysis/query-projections.php.stub', $workspace.'/models.php');
copy(
    __DIR__.'/fixtures/analysis/query-projections-migration.php.stub',
    $workspace.'/database/migrations/001_create.php',
);
file_put_contents(
    $workspace.'/bootstrap.php',
    '<?php throw new RuntimeException("Do not bootstrap the application.");',
);
file_put_contents($workspace.'/composer.json', json_encode([
    'autoload' => ['files' => ['bootstrap.php']],
], JSON_THROW_ON_ERROR));
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
$cases = [
    'required value still needs row' => ['return ProjectionRecord::value("name");', '?string', []],
    'nullable value' => ['return ProjectionRecord::value("nickname");', '?string', []],
    'cast value' => ['return ProjectionRecord::value("active");', '?bool', []],
    'accessor value' => ['return ProjectionRecord::value("label");', '?int', []],
    'named value argument' => ['return ProjectionRecord::value(column: "name");', '?string', []],
    'instance fresh query value' => ['return (new ProjectionRecord)->value("name");', '?string', []],
    'pluck values' => ['return ProjectionRecord::pluck("name");', 'Collection<int, string>', []],
    'pluck nullable values' => ['return ProjectionRecord::pluck("nickname");', 'Collection<int, string|null>', []],
    'pluck cast values' => ['return ProjectionRecord::pluck("active", null);', 'Collection<int, bool>', []],
    'pluck accessor values' => [
        'return ProjectionRecord::pluck(column: "label", key: null);',
        'Collection<int, int>',
        [],
    ],
    'wrong value' => ['return ProjectionRecord::value("name");', '?int', ['invalid-return-statement']],
    'missing row retained' => [
        'projectionString(ProjectionRecord::value("name"));',
        'void',
        ['possibly-null-argument'],
    ],
    'pluck preserves null' => [
        'return ProjectionRecord::pluck("nickname");',
        'Collection<int, string>',
        ['invalid-return-statement'],
    ],
    'wrong pluck values' => [
        'return ProjectionRecord::pluck("name");',
        'Collection<int, int>',
        ['invalid-return-statement'],
    ],
    'unknown field deferred' => ['return ProjectionRecord::value("nmae");', 'string', ['mixed-return-statement']],
    'dynamic field deferred' => [
        'return ProjectionRecord::value((string) rand());',
        'string',
        ['mixed-return-statement'],
    ],
    'qualified field retains missing row' => [
        'return ProjectionRecord::value("projection_records.name");',
        'string',
        ['invalid-return-statement', 'nullable-return-statement'],
    ],
    'value alias deferred' => [
        'return ProjectionRecord::value("name as selected_name");',
        'string',
        ['mixed-return-statement'],
    ],
    'builder value deferred' => [
        'return ProjectionRecord::query()->value("name");',
        'string',
        ['mixed-return-statement'],
    ],
    'custom dispatch deferred' => ['ProjectionCustom::value("name");', 'void', ['non-documented-method']],
    'native declaration wins' => ['return ProjectionNative::value("name");', 'int', []],
    'documented declaration wins' => ['return ProjectionDocumented::value("name");', 'int', []],
    'installed argument validation' => ['ProjectionRecord::value(42);', 'void', ['invalid-argument']],
    'method typo' => ['ProjectionRecord::vlaue("name");', 'void', ['non-documented-method']],
    'sum is not assumed integer' => ['return ProjectionRecord::sum("id");', 'int', ['mixed-return-statement']],
    'terminal literal alias' => [
        'return ProjectionRecord::firstOrFail(["name as selected_name"])->selected_name;',
        'string',
        [],
    ],
    'terminal nullable alias' => [
        'return ProjectionRecord::sole(["nickname AS selected_name"])->selected_name;',
        '?string',
        [],
    ],
    'terminal nullable model' => [
        'return ProjectionRecord::first(["name as selected_name"])?->selected_name;',
        '?string',
        [],
    ],
    'collection alias shape' => [
        'return ProjectionRecord::get(["name as selected_name"]);',
        '\\Illuminate\\Database\\Eloquent\\Collection<int, ProjectionRecord&object{selected_name: string, ...}>',
        [],
    ],
    'collection wrong alias shape' => [
        'return ProjectionRecord::get(["name as selected_name"]);',
        '\\Illuminate\\Database\\Eloquent\\Collection<int, ProjectionRecord&object{selected_name: int, ...}>',
        ['invalid-return-statement'],
    ],
    'multiple aliases' => [
        'return ProjectionRecord::firstOrFail(["id as selected_id", "name as selected_name"])->selected_id;',
        'int',
        [],
    ],
    'alias wrong type' => [
        'return ProjectionRecord::firstOrFail(["name as selected_name"])->selected_name;',
        'int',
        ['invalid-return-statement'],
    ],
    'alias cast source remains raw' => [
        'return ProjectionRecord::firstOrFail(["amount as selected_amount"])->selected_amount;',
        'int|float|numeric-string',
        [],
    ],
    'boolean alias retains driver types' => [
        'projectionBool(ProjectionRecord::firstOrFail(["active as selected_active"])->selected_active);',
        'void',
        ['possibly-invalid-argument'],
    ],
    'unselected model no alias' => [
        'return (new ProjectionRecord)->selected_name;',
        'string',
        ['non-documented-property', 'mixed-return-statement'],
    ],
    'separate query no alias' => [
        'ProjectionRecord::firstOrFail(["name as selected_name"]); return ProjectionRecord::firstOrFail()->selected_name;',
        'string',
        ['non-documented-property', 'mixed-return-statement'],
    ],
    'unknown alias source deferred' => [
        'return ProjectionRecord::firstOrFail(["missing as selected_name"])->selected_name;',
        'string',
        ['non-documented-property', 'mixed-return-statement'],
    ],
    'builder alias state deferred' => [
        'return ProjectionRecord::query()->firstOrFail(["name as selected_name"])->selected_name;',
        'string',
        ['non-documented-property', 'mixed-return-statement'],
    ],
    'alias accessor source stays raw' => [
        'return ProjectionRecord::firstOrFail(["label as selected_label"])->selected_label;',
        'string',
        [],
    ],
    'named alias argument' => [
        'return ProjectionRecord::firstOrFail(columns: ["name as selected_name"])->selected_name;',
        'string',
        [],
    ],
    'string alias selection' => [
        'return ProjectionRecord::firstOrFail("name as selected_name")->selected_name;',
        'string',
        [],
    ],
    'alias typo remains diagnosed' => [
        'return ProjectionRecord::firstOrFail(["name as selected_name"])->selected_nmae;',
        'string',
        ['non-documented-property', 'mixed-return-statement'],
    ],
    'alias invalid write' => [
        '$record = ProjectionRecord::firstOrFail(["name as selected_name"]); $record->selected_name = 42;',
        'void',
        ['invalid-property-assignment-value'],
    ],
    'nullable alias remains nullable' => [
        'projectionString(ProjectionRecord::firstOrFail(["nickname as selected_name"])->selected_name);',
        'void',
        ['possibly-null-argument'],
    ],
    'alias SQL expression deferred' => [
        'return ProjectionRecord::firstOrFail(["UPPER(name) as selected_name"])->selected_name;',
        'string',
        ['non-documented-property', 'mixed-return-statement'],
    ],
    'alias duplicate deferred' => [
        'return ProjectionRecord::firstOrFail(["id as selected_name", "name as selected_name"])->selected_name;',
        'string',
        ['non-documented-property', 'mixed-return-statement'],
    ],
    'alias cast collision preserved' => [
        'return ProjectionCastedAlias::firstOrFail(["name as selected_name"])->selected_name;',
        '?int',
        [],
    ],
    'keyed pluck' => [
        'return ProjectionRecord::pluck("name", "id");',
        'Collection<int, string>',
        [],
    ],
    'unpacked value deferred' => ['return ProjectionRecord::value(...["name"]);', 'string', ['mixed-return-statement']],
];

function check_query_projections(array $cases, array $command, string $workspace): void
{
    $source = "<?php\nuse Illuminate\\Support\\Collection;\n";
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
    $log = file_get_contents($workspace.'/stderr.log');
    if ($exit !== 1 || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)) {
        throw new RuntimeException('Expected native negative diagnostics without extension fallback; inspect '
        .$workspace);
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
        throw new RuntimeException('Unexpected diagnostics outside query scenarios; inspect '.$workspace);
    }
}

check_query_projections($cases, $command, $workspace);

// Changed installed contracts must survive inference and new workers.
$changed = str_replace('@return mixed', '@return int', $framework);
$changed = str_replace('Collection<array-key, mixed>', 'Collection<int, int>', $changed);
file_put_contents($workspace.'/framework.php', $changed);
check_query_projections(
    [
        'changed installed value contract deferred' => [
            'return ProjectionRecord::value("name");',
            '?string',
            ['invalid-return-statement'],
        ],
        'changed installed pluck contract deferred' => [
            'return ProjectionRecord::pluck("name");',
            'Collection<int, string>',
            ['invalid-return-statement'],
        ],
        'compatible installed pluck contract' => ['return ProjectionRecord::pluck("id");', 'Collection<int, int>', []],
        'installed aggregate integer contract' => ['return ProjectionRecord::sum("amount");', 'int', []],
    ],
    $command,
    $workspace,
);

file_put_contents($workspace.'/framework.php', $framework);
unset($config['extension-hosts']);
file_put_contents($workspace.'/mago.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
check_query_projections(
    [
        'disabled static value control' => [
            'return ProjectionRecord::value("name");',
            '?string',
            ['non-documented-method', 'mixed-return-statement'],
        ],
        'disabled static pluck control' => [
            'return ProjectionRecord::pluck("name");',
            'Collection<int, string>',
            ['non-documented-method', 'mixed-return-statement'],
        ],
        'disabled model property control' => [
            'return (new ProjectionRecord)->name;',
            'string',
            ['non-documented-property', 'mixed-return-statement'],
        ],
    ],
    $command,
    $workspace,
);

$root = realpath($workspace);
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
);
foreach ($files as $file) {
    $path = $file->getRealPath();
    if ($root === false || $path === false || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Refusing cleanup outside test workspace.');
    }
    $file->isDir() ? rmdir($path) : unlink($path);
}
rmdir($workspace);
