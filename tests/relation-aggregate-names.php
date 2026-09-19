<?php

declare(strict_types=1);

// Validate relation references through the real worker without executing model code.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, '-d', 'opcache.enable_cli=0', $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago aggregate names '.bin2hex(random_bytes(8));
$vendor = $workspace.'/vendor/laravel/framework/src/Illuminate/Database/Eloquent';
mkdir($vendor.'/Concerns', 0777, true);
$customSource = in_array('--custom-source', $argv, true);
$customHelper = in_array('--custom-helper', $argv, true);
$disabled = in_array('--disabled', $argv, true);
copy(__DIR__.'/fixtures/analysis/relation-aggregate-models.php.stub', $workspace.'/models.php');
$builder = file_get_contents(__DIR__.'/fixtures/analysis/relation-aggregate-builder.php.stub');
if ($customHelper) {
    $builder = str_replace(
        'use Concerns\\QueriesRelationships;',
        'use Concerns\\QueriesRelationships;
        public function withAggregate($relations, $column, $function = null) { return $this; }',
        $builder,
    );
}
file_put_contents($vendor.'/Builder.php', $builder);
copy(
    __DIR__.'/fixtures/analysis/relation-aggregate-queries.php.stub',
    $customSource ? $workspace.'/custom-queries.php' : $vendor.'/Concerns/QueriesRelationships.php',
);
file_put_contents($workspace.'/bootstrap.php', '<?php throw new RuntimeException("Never bootstrap the application");');
$complete = ['complete' => true, 'dynamic' => ['registered']];
file_put_contents($workspace.'/composer.json', json_encode([
    'autoload' => ['files' => ['bootstrap.php']],
    'extra' => [
        'laramago' => ['relation-names' => [
            'RelationNameRecord' => $complete,
            'CustomQueryRelationRecord' => $complete,
            'DynamicRelationNameRecord' => $complete,
            'ResolverRelationRecord' => $complete,
            'DocumentedAggregateRecord' => $complete,
            'InheritedDocumentedAggregateRecord' => $complete,
        ]],
    ],
], JSON_THROW_ON_ERROR));
file_put_contents($workspace.'/custom-models.php', <<<'PHP'
    <?php
    /** @method static int withCount(string $relations) */
    class DocumentedAggregateRecord extends RelationNameRecord {}
    class InheritedDocumentedAggregateRecord extends DocumentedAggregateRecord {}
    class DeclaredAggregateRecord extends RelationNameRecord {
        public static function withCount(string $relations): int { return 1; }
    }
    PHP);
$missing = ['ichinya/laramago/laramago-missing-relation'];
$invalid = ['ichinya/laramago/laramago-invalid-relation'];
$cases = [];
foreach (['withCount', 'withSum', 'withAvg', 'withMin', 'withMax', 'withExists', 'withAggregate'] as $method) {
    $column = in_array($method, ['withCount', 'withExists'], true) ? '' : ', "amount"';
    $cases[$method.' valid'] = ['RelationNameRecord::query()->'.$method.'("children"'.$column.');', 'void', []];
    $cases[$method.' missing'] = ['RelationNameRecord::query()->'.$method.'("missing"'.$column.');', 'void', $missing];
    $cases[$method.' nonrelation'] = [
        'RelationNameRecord::query()->'.$method.'("label"'.$column.');',
        'void',
        $invalid,
    ];
}
$cases += [
    'static missing' => ['RelationNameRecord::withCount("missing");', 'void', [...$missing, 'non-documented-method']],
    'named relation argument' => [
        'RelationNameRecord::query()->withSum(column: "amount", relation: "missing");',
        'void',
        $missing,
    ],
    'named relations argument' => [
        'RelationNameRecord::query()->withAggregate(column: "amount", relations: "missing");',
        'void',
        $missing,
    ],
    'alias valid' => ['RelationNameRecord::query()->withCount("children as missing");', 'void', []],
    'alias missing relation' => ['RelationNameRecord::query()->withCount("missing AS total");', 'void', $missing],
    'dotted alias missing relation' => [
        'RelationNameRecord::query()->withCount("missing as report.total");',
        'void',
        $missing,
    ],
    'associative colon alias missing relation' => [
        'RelationNameRecord::query()->withCount(["missing as report:total" => fn () => null]);',
        'void',
        $missing,
    ],
    'quoted alias missing relation' => [
        'RelationNameRecord::query()->withCount("missing as `report-total`");',
        'void',
        $missing,
    ],
    'whitespace in quoted alias deferred' => [
        'RelationNameRecord::query()->withCount("missing as `report total`");',
        'void',
        [],
    ],
    'malformed alias deferred' => ['RelationNameRecord::query()->withCount("missing  as total");', 'void', []],
    'nested deferred' => ['RelationNameRecord::query()->withCount("children.missing");', 'void', []],
    'column selection deferred' => ['RelationNameRecord::query()->withCount("missing:id");', 'void', []],
    'list names' => ['RelationNameRecord::query()->withCount(["children", "missing"]);', 'void', $missing],
    'constraint key' => [
        'RelationNameRecord::query()->withCount(["missing as total" => fn () => null]);',
        'void',
        $missing,
    ],
    'extra count name' => [
        'RelationNameRecord::query()->withCount("children", "missing");',
        'void',
        [...$missing, 'too-many-arguments'],
    ],
    'array first ignores extra count name' => [
        'RelationNameRecord::query()->withCount(["children"], "missing");',
        'void',
        ['too-many-arguments'],
    ],
    'sum column is not a relation' => ['RelationNameRecord::query()->withSum("children", "missing");', 'void', []],
    'dynamic relation deferred' => [
        '$name = (string) rand(); RelationNameRecord::query()->withCount($name);',
        'void',
        [],
    ],
    'unpacked names deferred' => ['RelationNameRecord::query()->withCount(...["missing"]);', 'void', []],
    'unpacked array deferred' => ['RelationNameRecord::query()->withCount([... ["missing"]]);', 'void', []],
    'overwritten key' => [
        'RelationNameRecord::query()->withCount([0 => "missing", 0 => "children"]);',
        'void',
        ['duplicate-array-key'],
    ],
    'open catalog deferred' => ['OpenRelationRecord::query()->withCount("missing");', 'void', []],
    'runtime name deferred' => ['RelationNameRecord::query()->withCount("registered");', 'void', []],
    'custom query deferred' => ['CustomQueryRelationRecord::query()->withCount("missing");', 'void', []],
    'custom dispatch deferred' => ['DynamicRelationNameRecord::query()->withCount("missing");', 'void', []],
    'custom resolver deferred' => ['ResolverRelationRecord::query()->withCount("missing");', 'void', []],
    'documented static preserved' => ['DocumentedAggregateRecord::withCount("missing");', 'void', []],
    'inherited documented static preserved' => [
        'InheritedDocumentedAggregateRecord::withCount("missing");',
        'void',
        [],
    ],
    'declared static preserved' => ['DeclaredAggregateRecord::withCount("missing");', 'void', []],
    'unrelated native error retained' => [
        'RelationNameRecord::query()->unknownAggregate("missing");',
        'void',
        ['non-existent-method'],
    ],
];
foreach (['loadCount', 'loadSum', 'loadAvg', 'loadMin', 'loadMax', 'loadExists', 'loadAggregate'] as $method) {
    $column = in_array($method, ['loadCount', 'loadExists'], true) ? '' : ', "amount"';
    $cases[$method.' collection dispatch deferred'] = [
        '(new RelationNameRecord)->'.$method.'("missing"'.$column.');',
        'void',
        [],
    ];
}
foreach ($cases as $name => &$case) {
    if ($disabled || $customSource || $customHelper) {
        $case[2] = array_values(array_filter(
            $case[2],
            static fn (string $code): bool => ! str_starts_with($code, 'ichinya/laramago/'),
        ));
    }
    if ($disabled && $name === 'static missing') {
        $case[2] = ['non-documented-method'];
    }
}
unset($case);
$source = <<<'PHP'
    <?php
    use Illuminate\Database\Eloquent\Builder;
    use RelationNameRecord as RelationAlias;
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
    'source' => [
        'paths' => ['cases.php'],
        'includes' => ['models.php', 'custom-models.php', 'vendor', ...($customSource ? ['custom-queries.php'] : [])],
    ],
    'extension-hosts' => [
        'laramago' => [
            'command' => [
                PHP_BINARY,
                '-d',
                'opcache.enable_cli=0',
                $package.'/bin/laramago-worker.php',
                $package.'/vendor/autoload.php',
                $workspace,
            ],
            'workers' => 3,
        ],
    ],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
if ($disabled) {
    $config = json_decode(file_get_contents($workspace.'/mago.json'), true, flags: JSON_THROW_ON_ERROR);
    unset($config['extension-hosts']);
    file_put_contents($workspace.'/mago.json', json_encode($config, JSON_THROW_ON_ERROR));
}
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
if (
    ! in_array($exit, [0, 1], true)
    || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)
) {
    throw new RuntimeException('Expected completed analysis with extension diagnostics and no fallback; inspect '
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
    throw new RuntimeException('Unexpected diagnostics outside relation-name scenarios; inspect '.$workspace);
}
echo 'PASS: aggregate relation references ('.count($cases)." scenarios)\n";
