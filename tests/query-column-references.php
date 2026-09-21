<?php

declare(strict_types=1);

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, '-d', 'opcache.enable_cli=0', $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago query columns '.bin2hex(random_bytes(8));
mkdir($workspace);

$framework = file_get_contents(__DIR__.'/fixtures/analysis/framework.php.stub');
$unsafe = <<<'PHP'

        /** @return $this */
        public function join($table, $first, $operator = null, $second = null) {}
        /** @return $this */
        public function from($table) {}
        /** @return $this */
        public function select($columns = ['*']) {}
        /** @return $this */
        public function addSelect($column) {}
        /** @return $this */
        public function whereRaw(string $sql) {}

    PHP;
$framework = str_replace(
    '    public function __call(string $method, array $parameters): mixed {}',
    $unsafe.'    public function __call(string $method, array $parameters): mixed {}',
    $framework,
);
file_put_contents($workspace.'/framework.php', $framework);
file_put_contents($workspace.'/models.php', <<<'PHP'
    <?php

    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\Builder;
    use Illuminate\Database\Eloquent\Attributes\Scope;

    class QueryColumnRecord extends Model {}
    class QueryColumnChildRecord extends QueryColumnRecord {}
    class QueryColumnIncompleteRecord extends Model {}
    class QueryColumnMalformedRecord extends Model {}
    class QueryColumnDuplicateRecord extends Model {}
    class QueryColumnEmptyRecord extends Model {}
    class QueryColumnUnassertedRecord extends Model {}
    class QueryColumnChangedSemanticsRecord extends Model {}
    class QueryColumnFactory
    {
    /** @return Builder<QueryColumnRecord> */
    public static function query() {}
    }
    class QueryColumnScopeRecord extends Model
    {
    public function scopeJoined($query) { return $query; }
    }
    class QueryColumnCollidingScopeRecord extends Model
    {
    #[Scope]
    protected function whereIn($query, string $column, array $values): void {}
    }
    class QueryColumnCustomRecord extends Model
    {
        /** @return Builder<QueryColumnCustomRecord> */
        public function newQuery() { throw new RuntimeException('Queries must not run.'); }
    }
    PHP);
file_put_contents($workspace.'/bootstrap.php', '<?php throw new RuntimeException("Application must not boot.");');

$contracts = [
    'QueryColumnRecord' => ['complete' => true, 'columns' => ['id', 'label', 'query_records.id']],
    'QueryColumnScopeRecord' => ['complete' => true, 'columns' => ['id']],
    'QueryColumnCollidingScopeRecord' => ['complete' => true, 'columns' => ['id']],
    'QueryColumnCustomRecord' => ['complete' => true, 'columns' => ['id']],
    'QueryColumnIncompleteRecord' => ['complete' => false, 'columns' => ['id']],
    'QueryColumnMalformedRecord' => ['complete' => true, 'columns' => ['id', false]],
    'QueryColumnDuplicateRecord' => ['complete' => true, 'columns' => ['id']],
    'querycolumnduplicaterecord' => ['complete' => true, 'columns' => ['id']],
    'QueryColumnEmptyRecord' => ['complete' => true, 'columns' => []],
];
foreach ($contracts as &$contract) {
    $contract['native-column-semantics'] = true;
}
unset($contract);
$contracts['QueryColumnUnassertedRecord'] = ['complete' => true, 'columns' => ['id']];
$contracts['QueryColumnChangedSemanticsRecord'] = [
    'complete' => true,
    'native-column-semantics' => false,
    'columns' => ['id'],
];
file_put_contents($workspace.'/composer.json', json_encode([
    'autoload' => ['files' => ['bootstrap.php']],
    'extra' => ['laramago' => ['query-sources' => $contracts]],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

$config = [
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => ['paths' => ['cases.php'], 'includes' => ['framework.php', 'models.php']],
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
            'workers' => 1,
        ],
    ],
];
file_put_contents($workspace.'/mago.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

$missing = ['ichinya/laramago/laramago-query-source-missing-column'];
$cases = [
    'direct static forwarding defers' => ['QueryColumnRecord::where("missing", 1)->get();', []],
    'query fresh where' => ['QueryColumnRecord::query()->where("missing", 1)->first();', $missing],
    'valid unqualified' => ['QueryColumnRecord::query()->where("id", 1)->get();', []],
    'valid exact qualification' => ['QueryColumnRecord::query()->where("query_records.id", 1)->get();', []],
    'unknown qualification' => ['QueryColumnRecord::query()->where("other.id", 1)->get();', $missing],
    'both whereColumn operands' => [
        'QueryColumnRecord::query()->whereColumn("missing_left", "missing_right")->get();',
        [$missing[0], $missing[0]],
    ],
    'orderBy column' => ['QueryColumnRecord::query()->orderBy("missing")->get();', $missing],
    'nonterminal mutable query' => ['QueryColumnRecord::where("missing", 1);', []],
    'variable builder' => ['$query = QueryColumnRecord::query(); $query->where("missing", 1)->get();', []],
    'unrelated query factory' => ['QueryColumnFactory::query()->where("missing", 1)->get();', []],
    'join before predicate' => [
        'QueryColumnRecord::query()->join("other", "other.id", "=", "query_records.id")->where("missing", 1)->get();',
        [],
    ],
    'later join can add source' => [
        'QueryColumnRecord::where("missing", 1)->join("other", "other.id", "=", "query_records.id")->get();',
        [],
    ],
    'from replacement' => ['QueryColumnRecord::query()->from("other")->where("missing", 1)->get();', []],
    'select state' => ['QueryColumnRecord::query()->select(["id"])->where("missing", 1)->get();', []],
    'raw state' => ['QueryColumnRecord::query()->whereRaw("1 = 1")->where("missing", 1)->get();', []],
    'named scope state' => ['QueryColumnScopeRecord::query()->joined()->where("missing", 1)->get();', []],
    'attribute scope shadows forwarded predicate' => [
        'QueryColumnCollidingScopeRecord::query()->whereIn("missing", [1])->get();',
        [],
    ],
    'custom query factory' => ['QueryColumnCustomRecord::query()->where("missing", 1)->get();', []],
    'dynamic column' => ['$column = "missing"; QueryColumnRecord::where($column, 1)->get();', []],
    'array where' => ['QueryColumnRecord::where([["missing", 1]])->get();', []],
    'terminal projection arguments' => ['QueryColumnRecord::where("missing", 1)->get(["id"]);', []],
    'contracts are not inherited' => ['QueryColumnChildRecord::query()->where("missing", 1)->get();', []],
    'incomplete contract' => ['QueryColumnIncompleteRecord::query()->where("missing", 1)->get();', []],
    'unasserted native semantics' => ['QueryColumnUnassertedRecord::query()->where("missing", 1)->get();', []],
    'changed native semantics' => ['QueryColumnChangedSemanticsRecord::query()->where("missing", 1)->get();', []],
    'malformed contract' => ['QueryColumnMalformedRecord::query()->where("missing", 1)->get();', []],
    'duplicate normalized contract' => ['QueryColumnDuplicateRecord::query()->where("missing", 1)->get();', []],
    'complete empty contract' => ['QueryColumnEmptyRecord::query()->where("missing", 1)->get();', $missing],
];

$source = "<?php\n";
$lines = [];
foreach ($cases as $name => [$body, $codes]) {
    $source .= 'function scenario'.count($lines).'(): void { '.$body.' }'."\n";
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
if (
    ! in_array($exit, [0, 1], true)
    || preg_match('/provider failed|rejected request/i', file_get_contents($workspace.'/stderr.log'))
) {
    throw new RuntimeException('Expected analyzer diagnostics without extension fallback; inspect '.$workspace);
}
$report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
$actual = [];
foreach ($report['issues'] ?? [] as $issue) {
    $primary = array_values(array_filter(
        $issue['annotations'],
        static fn (array $annotation): bool => $annotation['kind'] === 'Primary',
    ))[0];
    $actual[$primary['span']['start']['line'] + 1][] = $issue['code'];
}
$failures = [];
foreach ($lines as $line => [$name, $expected]) {
    $codes = $actual[$line] ?? [];
    sort($codes);
    sort($expected);
    unset($actual[$line]);
    if ($codes !== $expected) {
        $failures[] = $name.': expected '.json_encode($expected).', got '.json_encode($codes);
        continue;
    }
    echo 'PASS: '.$name."\n";
}
if ($failures !== [] || $actual !== []) {
    throw new RuntimeException(
        implode("\n", $failures).'; extra diagnostics: '.json_encode($actual).'; inspect '.$workspace,
    );
}

if (is_file($workspace.'/.env')) {
    throw new RuntimeException('The offline fixture must not have an environment file.');
}
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
