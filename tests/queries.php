<?php

declare(strict_types=1);

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago queries '.bin2hex(random_bytes(8));
mkdir($workspace);
copy(__DIR__.'/fixtures/analysis/framework.php.stub', $workspace.'/framework.php');
copy(__DIR__.'/fixtures/analysis/queries.php.stub', $workspace.'/models.php');
file_put_contents(
    $workspace.'/bootstrap.php',
    '<?php throw new RuntimeException("Do not bootstrap the application.");',
);
file_put_contents($workspace.'/composer.json', json_encode([
    'autoload' => ['files' => ['bootstrap.php']],
], JSON_THROW_ON_ERROR));
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
$cases = [
    'custom builder get collection' => ['return CollectionPropertyRecord::query()->get();', 'RecordCollection', []],
    'custom sorted get collection' => ['return CustomCollectionRecord::latest()->get();', 'RecordCollection', []],
    'custom attribute get collection' => ['return CollectionAttributeRecord::query()->get();', 'RecordCollection', []],
    'first remains nullable' => ['return Record::first();', 'Record|null', []],
    'first or fail' => ['return Record::firstOrFail();', 'Record', []],
    'sole' => ['return Record::sole();', 'Record', []],
    'get models' => ['return Record::get();', 'Collection<int, Record>', []],
    'latest builder' => ['return Record::latest();', 'Builder<Record>', []],
    'oldest builder' => ['return Record::oldest();', 'Builder<Record>', []],
    'order by builder' => ['return Record::orderBy("id");', 'Builder<Record>', []],
    'order descending builder' => ['return Record::orderByDesc("id");', 'Builder<Record>', []],
    'count' => ['return Record::count();', 'int', []],
    'sum retains native mixed' => ['acceptInt(Record::sum("amount"));', 'void', ['mixed-argument']],
    'exists' => ['return Record::exists();', 'bool', []],
    'does not exist' => ['return Record::doesntExist();', 'bool', []],
    'named selection' => ['return Record::firstOrFail(columns: ["id"]);', 'Record', []],
    'named sort reversed' => ['return Record::orderBy(direction: "desc", column: "id");', 'Builder<Record>', []],
    'named count' => ['return Record::count(columns: "id");', 'int', []],
    'expression count' => ['return Record::count(new QueryExpression);', 'int', []],
    'expression sorting' => ['return Record::latest(new QueryExpression);', 'Builder<Record>', []],
    'subquery sorting' => ['return Record::orderBy(Record::query());', 'Builder<Record>', []],
    'callback sorting' => [
        'return Record::orderBy(fn (QueryBuilder $query): QueryBuilder => $query);',
        'Builder<Record>',
        [],
    ],
    'inherited result' => ['return ChildRecord::sole();', 'ChildRecord', []],
    'late static result' => ['return InlineRead::single();', 'InlineRead', []],
    'inherited collection' => ['return ChildRecord::get();', 'Collection<int, ChildRecord>', []],
    'instance result' => ['return $record->firstOrFail();', 'Record', []],
    'case insensitive query' => ['return Record::FIRSTORFAIL();', 'Record', []],
    'class string query' => ['return $class::firstOrFail();', 'Record', []],
    'sort then lookup' => ['return Record::orderBy("id")->findOrFail(1);', 'Record', []],
    'sort then collection' => ['return Record::orderBy("id")->get();', 'Collection<int, Record>', []],
    'sort then first' => ['return Record::latest()->first();', 'Record|null', []],
    'multiple sorts' => ['return Record::orderBy("id")->orderByDesc("label")->get();', 'Collection<int, Record>', []],
    'where then sorting' => ['return Record::where("id", 1)->orderBy("id")->firstOrFail();', 'Record', []],
    'typed builder sorting' => ['return $builder->orderBy("id");', 'Builder<Record>', []],
    'typed builder sorted result' => ['return $builder->orderByDesc("id")->firstOrFail();', 'Record', []],
    'native builder first' => ['return $builder->first();', 'Record|null', []],
    'native builder sole' => ['return $builder->sole();', 'Record', []],
    'native builder get' => ['return $builder->get();', 'Collection<int, Record>', []],
    'first property' => ['return Record::firstOrFail()->id;', 'int', []],
    'nullable property' => [
        'return Record::first()->id;',
        'int',
        ['invalid-return-statement', 'nullable-return-statement', 'possibly-null-property-access'],
    ],
    'wrong model return' => ['return Record::firstOrFail();', 'OtherRecord', ['invalid-return-statement']],
    'wrong count return' => ['return Record::count();', 'string', ['invalid-return-statement']],
    'nullable model return' => [
        'return Record::first();',
        'Record',
        ['invalid-return-statement', 'nullable-return-statement'],
    ],
    'nullable argument' => ['acceptRecord(Record::first());', 'void', ['possibly-null-argument']],
    'collection is not a model' => ['acceptRecord(Record::get());', 'void', ['invalid-argument']],
    'collection property stays invalid' => [
        'Record::get()->id;',
        'void',
        ['non-existent-property', 'unused-statement'],
    ],
    'unknown model property' => ['Record::sole()->idd;', 'void', ['non-documented-property', 'unused-statement']],
    'invalid model property write' => ['Record::sole()->id = "wrong";', 'void', ['invalid-property-assignment-value']],
    'fresh result refinements' => [
        '$record->label = "known"; acceptString($record->sole()->label);',
        'void',
        ['possibly-null-argument'],
    ],
    'first class first' => ['return Record::first(...);', 'Closure', []],
    'first class sorting' => ['return Record::orderBy(...);', 'Closure', []],
    'first class count' => ['return Record::count(...);', 'Closure', []],
    'missing sort column' => ['Record::orderBy();', 'void', ['too-few-arguments']],
    'missing sum column' => ['Record::sum();', 'void', ['too-few-arguments']],
    'extra sort argument' => ['Record::orderBy("id", "asc", true);', 'void', ['too-many-arguments']],
    'extra exists argument' => ['Record::exists(1);', 'void', ['too-many-arguments']],
    'invalid named read argument' => ['Record::get(typo: []);', 'void', ['invalid-named-argument']],
    'invalid columns' => ['Record::firstOrFail(new stdClass);', 'void', ['invalid-argument']],
    'invalid sort direction' => ['Record::orderBy("id", new stdClass);', 'void', ['invalid-argument']],
    'invalid count column' => ['Record::count(new stdClass);', 'void', ['possibly-invalid-argument']],
    'native count argument check' => [
        '(new QueryBuilder)->count(new stdClass);',
        'void',
        ['possibly-invalid-argument'],
    ],
    'native sorting argument check' => ['$builder->orderBy("id", new stdClass);', 'void', ['invalid-argument']],
    'query method typo' => ['Record::orderB();', 'void', ['non-documented-method']],
    'unknown scope unchanged' => ['Record::active();', 'void', ['non-documented-method']],
    'declared method preserved' => ['return DeclaredRecord::first();', 'string', []],
    'inherited method preserved' => ['return InheritedDeclaredRecord::first();', 'string', []],
    'documented method preserved' => ['return DocumentedRecord::first();', 'string', []],
    'inherited documentation preserved' => ['return InheritedDocumentedRecord::first();', 'string', []],
    'trait method preserved' => ['return TraitRecord::first();', 'string', []],
    'custom query deferred' => ['CustomQueryRecord::get();', 'void', ['non-documented-method']],
    'inherited custom query deferred' => ['InheritedCustomQueryRecord::get();', 'void', ['non-documented-method']],
    'custom magic deferred' => ['CustomMagicRecord::first();', 'void', ['non-documented-method']],
    'custom builder deferred' => ['CustomBuilderRecord::orderBy("id");', 'void', ['non-documented-method']],
    'inherited builder deferred' => ['InheritedCustomBuilderRecord::orderBy("id");', 'void', ['non-documented-method']],
    'builder attribute forwarded' => ['return AttributedRecord::first();', 'string', []],
    'custom builder native contract' => ['return (new CustomBuilder)->first();', 'string', []],
    'custom collection contract' => ['return CustomCollectionRecord::get();', 'RecordCollection', []],
    'custom collection scalar result' => ['return CustomCollectionRecord::first();', 'CustomCollectionRecord|null', []],
    'collection property contract' => ['return CollectionPropertyRecord::get();', 'RecordCollection', []],
    'collection attribute contract' => ['return CollectionAttributeRecord::get();', 'RecordCollection', []],
    'inherited collection attribute contract' => [
        'return InheritedCollectionAttributeRecord::get();',
        'RecordCollection',
        [],
    ],
    'custom hydration deferred' => ['CustomHydrationRecord::first();', 'void', ['non-documented-method']],
    'custom instance deferred' => ['CustomInstanceRecord::first();', 'void', ['non-documented-method']],
    'custom collection sorting' => ['return CustomCollectionRecord::latest();', 'Builder<CustomCollectionRecord>', []],
    'sorting scope collision' => ['return OrderingScopeRecord::orderBy("id");', 'string', []],
    'inherited scope collision' => ['return InheritedOrderingScopeRecord::orderBy("id");', 'string', []],
    'count scope collision' => ['return CountingScopeRecord::count();', 'string', []],
    'scope dispatcher deferred' => ['CustomScopeDispatchRecord::count();', 'void', ['non-documented-method']],
    'unrelated class' => ['return UnrelatedQuery::first();', 'string', []],
];

function check_queries(array $cases, array $command, string $workspace): void
{
    $source = <<<'PHP'
        <?php
        use Illuminate\Database\Eloquent\Builder;
        use Illuminate\Database\Eloquent\Collection;
        use Illuminate\Database\Query\Builder as QueryBuilder;
        function acceptRecord(Record $record): void {}
        function acceptInt(int $value): void {}
        function acceptString(string $value): void {}
        class InlineRead extends Record {
            /** @return static */
            public static function single() { return static::firstOrFail(); }
        }

        PHP;
    $lines = [];
    foreach ($cases as $name => [$body, $return, $codes]) {
        $source .=
            "/**\n"
            .' * @param Builder<Record> $builder'
            ."\n"
            .' * @param class-string<Record> $class'
            ."\n"
            .' * @return '
            .$return
            ."\n */\n";
        $source .=
            'function scenario'.count($lines).'(Record $record, Builder $builder, string $class) { '.$body.' }'."\n";
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

check_queries($cases, $command, $workspace);

// A fresh worker must use the installed version's argument and aggregate contracts.
$framework = file_get_contents($workspace.'/framework.php');
$framework = str_replace('@param string $direction', "@param \\QueryDirection|'asc'|'desc' \$direction", $framework);
$framework = str_replace('@return int', '@return int<0, max>', $framework);
$framework = str_replace('function sole(', 'function unavailableSole(', $framework);
file_put_contents($workspace.'/framework.php', $framework);
check_queries(
    [
        'installed enum direction' => [
            'return Record::orderBy("id", QueryDirection::Descending);',
            'Builder<Record>',
            [],
        ],
        'installed direction literals' => ['Record::orderBy("id", "sideways");', 'void', ['invalid-argument']],
        'installed count refinement' => ['return Record::count();', 'int<0, max>', []],
        'unavailable read remains unknown' => ['Record::sole();', 'void', ['non-documented-method']],
    ],
    $command,
    $workspace,
);

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
