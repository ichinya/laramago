<?php

declare(strict_types=1);

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago query chains '.bin2hex(random_bytes(8));
mkdir($workspace.'/database/migrations', 0777, true);
$framework = file_get_contents(__DIR__.'/fixtures/analysis/framework.php.stub');
$methods = substr(file_get_contents(__DIR__.'/fixtures/analysis/query-projections-framework.php.stub'), 5);
$framework = preg_replace_callback(
    '/class Builder\n\{/',
    static fn (): string => "class Builder\n{".$methods,
    str_replace("\r\n", "\n", $framework),
    1,
);
$framework = str_replace(
    "class Builder\n{",
    "class Builder\n{".substr(file_get_contents(__DIR__.'/fixtures/analysis/query-chains-framework.php.stub'), 5),
    $framework,
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
file_put_contents(
    $workspace.'/models.php',
    substr(file_get_contents(__DIR__.'/fixtures/analysis/query-chains.php.stub'), 5),
    FILE_APPEND,
);
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
file_put_contents(
    $workspace.'/database/migrations/002_keys.php',
    file_get_contents(__DIR__.'/fixtures/analysis/query-chains-migration.php.stub'),
);
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
    'qualified value' => ['return ProjectionRecord::value("projection_records.name");', '?string', []],
    'qualified cast value' => ['return ProjectionRecord::value("projection_records.active");', '?bool', []],
    'qualified accessor value' => ['return ProjectionRecord::value("projection_records.label");', '?int', []],
    'qualified missing field' => [
        'return ProjectionRecord::value("projection_records.nmae");',
        '?string',
        ['mixed-return-statement'],
    ],
    'foreign table refused' => [
        'return ProjectionRecord::value("other_records.name");',
        '?string',
        ['mixed-return-statement'],
    ],
    'qualified pluck value' => [
        'return ProjectionRecord::pluck("projection_records.name");',
        'Collection<int, string>',
        [],
    ],
    'qualified keyed pluck' => [
        'return ProjectionRecord::pluck("projection_records.name", "projection_records.id");',
        'Collection<int, string>',
        [],
    ],
    'named keyed pluck' => [
        'return ProjectionRecord::pluck(key: "id", column: "name");',
        'Collection<int, string>',
        [],
    ],
    'nullable keyed values' => [
        'return ProjectionRecord::pluck("nickname", "id");',
        'Collection<int, string|null>',
        [],
    ],
    'cast keyed values' => ['return ProjectionRecord::pluck("active", "id");', 'Collection<int, bool>', []],
    'accessor keyed values' => ['return ProjectionRecord::pluck("label", "id");', 'Collection<int, int>', []],
    'string keys allow numeric coercion' => [
        'return ProjectionRecord::pluck("id", "name");',
        'Collection<array-key, int>',
        [],
    ],
    'string keys cannot promise strings only' => [
        'return ProjectionRecord::pluck("id", "name");',
        'Collection<string, int>',
        ['invalid-return-statement'],
    ],
    'nullable key uses PHP empty string' => [
        'return ChainRecord::pluck("name", "optional_id");',
        'Collection<int|string, string>',
        [],
    ],
    'nullable key cannot promise integers only' => [
        'return ChainRecord::pluck("name", "optional_id");',
        'Collection<int, string>',
        ['invalid-return-statement'],
    ],
    'boolean key coerced to integer' => [
        'return ProjectionRecord::pluck("name", "active");',
        'Collection<int, string>',
        [],
    ],
    'decimal key retains integer and string forms' => [
        'return ProjectionRecord::pluck("name", "amount");',
        'Collection<array-key, string>',
        [],
    ],
    'cast does not change raw key' => [
        'return ChainRecord::pluck("name", "key_text");',
        'Collection<array-key, string>',
        [],
    ],
    'integer cast cannot narrow raw string key' => [
        'return ChainRecord::pluck("name", "key_text");',
        'Collection<int, string>',
        ['invalid-return-statement'],
    ],
    'accessor does not change raw key' => [
        'return ProjectionRecord::pluck("name", "label");',
        'Collection<array-key, string>',
        [],
    ],
    'missing key deferred' => [
        'return ProjectionRecord::pluck("name", "idd");',
        'Collection<int, string>',
        ['less-specific-return-statement'],
    ],
    'dynamic key deferred' => [
        'return ProjectionRecord::pluck("name", (string) rand());',
        'Collection<int, string>',
        ['less-specific-return-statement'],
    ],
    'foreign qualified key deferred' => [
        'return ProjectionRecord::pluck("name", "other_records.id");',
        'Collection<int, string>',
        ['less-specific-return-statement'],
    ],
    'key SQL expression deferred' => [
        'return ProjectionRecord::pluck("name", "id + 1");',
        'Collection<int, string>',
        ['less-specific-return-statement'],
    ],
    'native keyed declaration wins' => ['return ChainNative::pluck("name", "id");', 'int', []],
    'documented keyed declaration wins' => ['return ChainDocumented::pluck("name", "id");', 'int', []],
    'custom hydration value deferred' => [
        'return ChainHydration::value("projection_records.name");',
        '?string',
        ['mixed-return-statement'],
    ],
    'custom hydration pluck deferred' => [
        'return ChainHydration::pluck("name", "id");',
        'Collection<int, string>',
        ['less-specific-return-statement'],
    ],
    'qualified terminal alias' => [
        'return ProjectionRecord::firstOrFail(["projection_records.name as selected_name"])->selected_name;',
        'string',
        [],
    ],
    'qualified nullable terminal alias' => [
        'return ProjectionRecord::firstOrFail(["projection_records.nickname as selected_name"])->selected_name;',
        '?string',
        [],
    ],
    'qualified cast alias stays raw' => [
        'return ProjectionRecord::firstOrFail(["projection_records.amount as selected_amount"])->selected_amount;',
        'int|float|numeric-string',
        [],
    ],
    'qualified selection and wildcard alias' => [
        'return ProjectionRecord::firstOrFail(["projection_records.*", "projection_records.id", "name as selected_name"])->selected_name;',
        'string',
        [],
    ],
    'qualified alias typo retained' => [
        'return ProjectionRecord::firstOrFail(["projection_records.name as selected_name"])->selected_nmae;',
        'string',
        ['non-documented-property', 'mixed-return-statement'],
    ],
    'qualified alias scope isolation' => [
        'ProjectionRecord::firstOrFail(["projection_records.name as selected_name"]); return (new ProjectionRecord)->selected_name;',
        'string',
        ['non-documented-property', 'mixed-return-statement'],
    ],
    'unknown selection invalidates aliases' => [
        'return ProjectionRecord::firstOrFail(["missing", "name as selected_name"])->selected_name;',
        'string',
        ['non-documented-property', 'mixed-return-statement'],
    ],
    'foreign table alias deferred' => [
        'return ProjectionRecord::firstOrFail(["other_records.name as selected_name"])->selected_name;',
        'string',
        ['non-documented-property', 'mixed-return-statement'],
    ],
    'fresh select provenance unavailable' => [
        'return ProjectionRecord::query()->select(["name as selected_name"])->firstOrFail()->selected_name;',
        'string',
        ['non-documented-property', 'mixed-return-statement'],
    ],
    'fresh addSelect provenance unavailable' => [
        'return ProjectionRecord::query()->addSelect(["name as selected_name"])->firstOrFail()->selected_name;',
        'string',
        ['non-documented-property', 'mixed-return-statement'],
    ],
    'fresh aggregate alias provenance unavailable' => [
        'return ProjectionRecord::query()->withCount("items as selected_count")->firstOrFail()->selected_count;',
        'int',
        ['non-documented-property', 'mixed-return-statement'],
    ],
    'fresh sum alias provenance unavailable' => [
        'return ProjectionRecord::query()->withSum("items as selected_sum", "amount")->firstOrFail()->selected_sum;',
        'int|float|string|null',
        ['non-documented-property', 'mixed-return-statement'],
    ],
    'mutable selected builder remains unknown' => [
        '$query = ProjectionRecord::query(); $query->select(["name as selected_name"]); return $query->firstOrFail()->selected_name;',
        'string',
        ['non-documented-property', 'mixed-return-statement'],
    ],
    'mutable keyed builder remains unknown' => [
        '$query = ProjectionRecord::query(); $query->select(["name"]); return $query->pluck("name", "id");',
        'Collection<int, string>',
        ['less-specific-return-statement'],
    ],
];
function check_query_chains(array $cases, array $command, string $workspace): void
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

check_query_chains($cases, $command, $workspace);

// Changed installed contracts must survive inference and new workers.
$changed = str_replace('@return mixed', '@return int', $framework);
$changed = str_replace('Collection<array-key, mixed>', 'Collection<int, int>', $changed);
file_put_contents($workspace.'/framework.php', $changed);
check_query_chains(
    [
        'changed installed value contract deferred' => [
            'return ProjectionRecord::value("name");',
            '?string',
            ['invalid-return-statement'],
        ],
        'changed installed pluck contract deferred' => [
            'return ProjectionRecord::pluck("name", "id");',
            'Collection<int, string>',
            ['invalid-return-statement'],
        ],
        'compatible installed pluck contract' => [
            'return ProjectionRecord::pluck("id", "id");',
            'Collection<int, int>',
            [],
        ],
        'changed installed key contract deferred' => [
            'return ProjectionRecord::pluck("id", "name");',
            'Collection<array-key, int>',
            ['less-specific-return-statement'],
        ],
        'installed aggregate integer contract' => ['return ProjectionRecord::sum("amount");', 'int', []],
    ],
    $command,
    $workspace,
);

file_put_contents($workspace.'/framework.php', $framework);
unset($config['extension-hosts']);
file_put_contents($workspace.'/mago.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
check_query_chains(
    [
        'disabled static value control' => [
            'return ProjectionRecord::value("projection_records.name");',
            '?string',
            ['non-documented-method', 'mixed-return-statement'],
        ],
        'disabled static pluck control' => [
            'return ProjectionRecord::pluck("name", "id");',
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
