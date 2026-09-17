<?php

declare(strict_types=1);

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago predicates '.bin2hex(random_bytes(8));
mkdir($workspace);
foreach (['framework', 'queries', 'predicates'] as $fixture) {
    copy(__DIR__.'/fixtures/analysis/'.$fixture.'.php.stub', $workspace.'/'.$fixture.'.php');
}
file_put_contents($workspace.'/bootstrap.php', '<?php throw new RuntimeException("Application must not boot.");');
file_put_contents($workspace.'/composer.json', json_encode([
    'autoload' => ['files' => ['bootstrap.php']],
], JSON_THROW_ON_ERROR));
$config = [
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => ['paths' => ['cases.php'], 'includes' => ['framework.php', 'queries.php', 'predicates.php']],
    'extension-hosts' => [
        'laramago' => [
            'command' => [PHP_BINARY, $package.'/bin/laramago-worker.php', $package.'/vendor/autoload.php', $workspace],
            'workers' => 3,
        ],
    ],
];
file_put_contents($workspace.'/mago.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$calls = [
    'whereKey' => '1',
    'whereKeyNot' => '[1, 2]',
    'whereIn' => '"id", [1, 2]',
    'orWhereIn' => '"id", [1, 2]',
    'whereNotIn' => '"id", [1, 2]',
    'orWhereNotIn' => '"id", [1, 2]',
    'whereNull' => '"label"',
    'orWhereNull' => '"label"',
    'whereNotNull' => '"label"',
    'orWhereNotNull' => '"label"',
    'whereBetween' => '"id", [1, 2]',
    'orWhereBetween' => '"id", [1, 2]',
    'whereNotBetween' => '"id", [1, 2]',
    'orWhereNotBetween' => '"id", [1, 2]',
    'whereDate' => '"created_at", "2026-01-01"',
    'orWhereDate' => '"created_at", "2026-01-01"',
    'whereTime' => '"created_at", "12:00"',
    'orWhereTime' => '"created_at", "12:00"',
    'whereDay' => '"created_at", 1',
    'orWhereDay' => '"created_at", 1',
    'whereMonth' => '"created_at", 2',
    'orWhereMonth' => '"created_at", 2',
    'whereYear' => '"created_at", 2026',
    'orWhereYear' => '"created_at", 2026',
];
$cases = [];
foreach ($calls as $name => $arguments) {
    $cases['model '.$name] = ['return Record::'.$name.'('.$arguments.');', 'Builder<Record>', []];
    $cases['builder '.$name] = ['return $builder->'.$name.'('.$arguments.')->firstOrFail();', 'Record', []];
}
$cases += [
    'instance predicate' => ['return $record->whereIn("id", [1]);', 'Builder<Record>', []],
    'inherited predicate' => ['return ChildRecord::whereMonth("created_at", 2)->firstOrFail();', 'ChildRecord', []],
    'class string predicate' => ['return $class::whereKey(1)->firstOrFail();', 'Record', []],
    'case insensitive predicate' => ['return Record::WHEREIN("id", [1]);', 'Builder<Record>', []],
    'named predicate arguments' => ['return Record::whereIn(values: [1], column: "id");', 'Builder<Record>', []],
    'named date arguments' => [
        'return Record::whereDate(operator: "=", value: "2026-01-01", column: "created_at");',
        'Builder<Record>',
        [],
    ],
    'named null columns' => ['return Record::whereNull(columns: ["label"]);', 'Builder<Record>', []],
    'named null column' => ['return Record::orWhereNull(column: "label");', 'Builder<Record>', []],
    'named key' => ['return Record::whereKey(id: [1, 2]);', 'Builder<Record>', []],
    'mixed values contract preserved' => ['return Record::whereIn("id", new stdClass);', 'Builder<Record>', []],
    'subquery values' => ['return Record::whereIn("id", Record::query());', 'Builder<Record>', []],
    'callback values' => ['return Record::whereIn("id", fn (QueryBuilder $query) => $query);', 'Builder<Record>', []],
    'query expression column' => ['return Record::whereIn(new QueryExpression, [1]);', 'Builder<Record>', []],
    'date object' => ['return Record::whereDate("created_at", new DateTimeImmutable);', 'Builder<Record>', []],
    'range subquery retains native generic check' => [
        'return Record::whereBetween(Record::query(), [1, 2]);',
        'Builder<Record>',
        ['less-specific-nested-argument-type'],
    ],
    'iterable range' => ['return Record::whereBetween("id", new ArrayIterator([1, 2]));', 'Builder<Record>', []],
    'collection result' => ['return Record::whereIn("id", [1])->get();', 'Collection<int, Record>', []],
    'custom collection result' => ['return CustomCollectionRecord::whereIn("id", [1])->get();', 'RecordCollection', []],
    'multiple predicate chain' => [
        'return Record::whereIn("id", [1])->whereNull("label")->whereMonth("created_at", 2)->firstOrFail();',
        'Record',
        [],
    ],
    'filtered null property stays nullable' => [
        'return Record::whereNotNull("label")->firstOrFail()->label;',
        '?string',
        [],
    ],
    'filtered key result still nullable' => ['return Record::whereKey(1)->first();', '?Record', []],
    'nullable result error' => [
        'return Record::whereKey(1)->first();',
        'Record',
        ['invalid-return-statement', 'nullable-return-statement'],
    ],
    'wrong result model' => [
        'return Record::whereDate("created_at", "2026-01-01")->firstOrFail();',
        'OtherRecord',
        ['invalid-return-statement'],
    ],
    'property typo' => ['return Record::whereKey(1)->firstOrFail()->idd;', 'mixed', ['non-documented-property']],
    'invalid property assignment' => [
        'Record::whereKey(1)->firstOrFail()->id = "wrong";',
        'void',
        ['invalid-property-assignment-value'],
    ],
    'collection property remains invalid' => [
        'return Record::whereIn("id", [1])->get()->id;',
        'mixed',
        ['non-existent-property'],
    ],
    'instance refinements are not copied' => [
        '$record->label = "known"; acceptString($record->whereKey(1)->firstOrFail()->label);',
        'void',
        ['possibly-null-argument'],
    ],
    'first class predicate' => ['return Record::whereIn(...);', 'Closure', []],
    'first class key' => ['return Record::whereKey(...);', 'Closure', []],
    'missing list values' => ['Record::whereIn("id");', 'void', ['too-few-arguments']],
    'missing key' => ['Record::whereKey();', 'void', ['too-few-arguments']],
    'missing date value' => ['Record::whereDate("created_at");', 'void', ['too-few-arguments']],
    'extra key argument' => ['Record::whereKey(1, 2);', 'void', ['too-many-arguments']],
    'extra or predicate argument' => ['Record::orWhereIn("id", [1], "and");', 'void', ['too-many-arguments']],
    'invalid named predicate argument' => [
        'Record::whereIn("id", [1], typo: true);',
        'void',
        ['invalid-named-argument'],
    ],
    'invalid column argument' => ['Record::whereIn(new stdClass, [1]);', 'void', ['possibly-invalid-argument']],
    'invalid date argument' => [
        'Record::whereDate("created_at", new stdClass);',
        'void',
        ['possibly-invalid-argument'],
    ],
    'invalid month argument' => [
        'Record::whereMonth("created_at", new stdClass);',
        'void',
        ['possibly-invalid-argument'],
    ],
    'invalid range argument' => ['Record::whereBetween("id", 123);', 'void', ['invalid-argument']],
    'invalid flag' => ['Record::whereIn("id", [1], not: new stdClass);', 'void', ['invalid-argument']],
    'builder argument checks' => ['$builder->whereIn(new stdClass, [1]);', 'void', ['possibly-invalid-argument']],
    'native query argument checks' => [
        '(new QueryBuilder)->whereIn(new stdClass, [1]);',
        'void',
        ['possibly-invalid-argument'],
    ],
    'native query type preserved' => ['return (new QueryBuilder)->whereIn("id", [1]);', 'QueryBuilder', []],
    'predicate typo' => ['Record::whereInn("id", [1]);', 'void', ['non-documented-method']],
    'unsupported predicate' => ['Record::whereRaw("id = 1");', 'void', ['non-documented-method']],
    'declared predicate' => ['return DeclaredPredicateRecord::whereIn("id", [1]);', 'string', []],
    'inherited declaration' => ['return InheritedPredicateRecord::whereIn("id", [1]);', 'string', []],
    'documented predicate' => ['return DocumentedPredicateRecord::whereIn("id", [1]);', 'string', []],
    'inherited documentation' => ['return InheritedDocumentedPredicateRecord::whereIn("id", [1]);', 'string', []],
    'trait predicate' => ['return TraitPredicateRecord::whereIn("id", [1]);', 'string', []],
    'private predicate stays inaccessible' => [
        'PrivatePredicateRecord::whereIn("id", [1]);',
        'void',
        ['invalid-method-access'],
    ],
    'direct attribute scope stays inaccessible' => [
        'AttributedPredicateScopeRecord::whereIn("id", [1]);',
        'void',
        ['invalid-method-access', 'invalid-static-method-access'],
    ],
    'scope predicate' => ['return PredicateScopeRecord::whereIn("id", [1]);', 'string', []],
    'inherited scope predicate' => ['return InheritedPredicateScopeRecord::whereIn("id", [1]);', 'string', []],
    'builder scope predicate' => ['return PredicateScopeRecord::query()->whereIn("id", [1]);', 'string', []],
    'builder attribute scope predicate' => [
        'return AttributedPredicateScopeRecord::query()->whereIn("id", [1]);',
        'string',
        [],
    ],
    'native key precedes scope' => ['return KeyScopeRecord::whereKey(1);', 'Builder<KeyScopeRecord>', []],
    'custom builder method' => ['return PredicateBuilderRecord::whereIn("id", [1]);', 'string', []],
    'custom builder direct' => ['return (new PredicateBuilder)->whereIn("id", [1]);', 'string', []],
    'custom builder inherited query defers' => [
        'CustomBuilderRecord::whereIn("id", [1]);',
        'void',
        ['non-documented-method'],
    ],
    'custom query defers' => ['CustomQueryRecord::whereIn("id", [1]);', 'void', ['non-documented-method']],
    'custom static dispatch defers' => ['CustomMagicRecord::whereIn("id", [1]);', 'void', ['non-documented-method']],
    'custom instance dispatch defers' => [
        '(new CustomInstanceDispatchRecord)->whereIn("id", [1]);',
        'void',
        ['non-documented-method'],
    ],
    'custom scope dispatch defers' => [
        'CustomScopeDispatchRecord::whereIn("id", [1]);',
        'void',
        ['non-documented-method'],
    ],
    'custom attribute dispatch defers' => [
        'PredicateScopeDispatchRecord::whereIn("id", [1]);',
        'void',
        ['non-documented-method'],
    ],
    'unrelated class' => ['return UnrelatedPredicate::whereIn("id", [1]);', 'string', []],
];

function check_predicates(array $cases, array $command, string $workspace): void
{
    $source = <<<'PHP'
        <?php
        use Illuminate\Database\Eloquent\Builder;
        use Illuminate\Database\Eloquent\Collection;
        use Illuminate\Database\Query\Builder as QueryBuilder;
        function acceptString(string $value): void {}

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
            static fn (array $a): bool => $a['kind'] === 'Primary',
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
}

check_predicates($cases, $command, $workspace);

// Installed framework signatures remain the authority for parameter validation.
$framework = file_get_contents($workspace.'/framework.php');
$updated = str_replace('@param string $boolean', "@param \\PredicateBoolean|'and'|'or' \$boolean", $framework);
$updated = str_replace('function whereDate(', 'function unavailableWhereDate(', $updated);
file_put_contents($workspace.'/framework.php', $updated);
check_predicates(
    [
        'installed enum flag' => [
            'return Record::whereIn("id", [1], boolean: PredicateBoolean::Or);',
            'Builder<Record>',
            [],
        ],
        'installed literal flag' => ['Record::whereIn("id", [1], boolean: "xor");', 'void', ['invalid-argument']],
        'unavailable predicate defers' => [
            'Record::whereDate("created_at", "2026-01-01");',
            'void',
            ['non-documented-method'],
        ],
    ],
    $command,
    $workspace,
);

file_put_contents($workspace.'/framework.php', $framework);
// A concrete installed Eloquent method takes precedence over the Query mixin.
$declared = str_replace(
    'public function whereKey($id) {}',
    'public function whereKey($id) {} public function whereIn($column, $values): int { return 1; }',
    $framework,
);
file_put_contents($workspace.'/framework.php', $declared);
check_predicates(
    [
        'installed Eloquent method wins' => ['return $builder->whereIn("id", [1]);', 'int', []],
        'installed Eloquent method defeats scope' => [
            'return PredicateScopeRecord::query()->whereIn("id", [1]);',
            'int',
            [],
        ],
        'installed Eloquent method is not assumed forwarded' => [
            'Record::whereIn("id", [1]);',
            'void',
            ['non-documented-method'],
        ],
    ],
    $command,
    $workspace,
);

$documented = str_replace(
    '@mixin \\Illuminate\\Database\\Query\\Builder',
    '@mixin \\Illuminate\\Database\\Query\\Builder'."\n".' * @method int whereIn(string $column, mixed $values)',
    $framework,
);
file_put_contents($workspace.'/framework.php', $documented);
check_predicates(
    [
        'installed Eloquent documentation wins' => ['return $builder->whereIn("id", [1]);', 'int', []],
        'installed documentation on scoped receiver' => [
            'return PredicateScopeRecord::query()->whereIn("id", [1]);',
            'int',
            [],
        ],
        'installed documentation is not assumed forwarded' => [
            'Record::whereIn("id", [1]);',
            'void',
            ['non-documented-method'],
        ],
    ],
    $command,
    $workspace,
);

file_put_contents($workspace.'/framework.php', $framework);
$config['analyzer'] = ['disable-default-plugins' => true];
file_put_contents($workspace.'/mago.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
check_predicates(
    [
        'native model predicate is unknown' => ['Record::whereIn("id", [1]);', 'void', ['non-documented-method']],
        'native model key is unknown' => ['Record::whereKey(1);', 'void', ['non-documented-method']],
        'native query remains typed' => ['return (new QueryBuilder)->whereIn("id", [1]);', 'QueryBuilder', []],
        'native range subquery generic check' => [
            'return (new QueryBuilder)->whereBetween($builder, [1, 2]);',
            'QueryBuilder',
            ['less-specific-nested-argument-type'],
        ],
    ],
    $command,
    $workspace,
);

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
