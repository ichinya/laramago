<?php

declare(strict_types=1);

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago relation predicates '.bin2hex(random_bytes(8));
mkdir($workspace);
foreach (['framework', 'relation-predicates'] as $fixture) {
    copy(__DIR__.'/fixtures/analysis/'.$fixture.'.php.stub', $workspace.'/'.$fixture.'.php');
}
file_put_contents($workspace.'/framework.php', str_replace(
    'class HasMany {}',
    'class HasMany { public function __call(string $method, array $arguments): mixed {} }',
    file_get_contents($workspace.'/framework.php'),
));
file_put_contents($workspace.'/bootstrap.php', '<?php throw new RuntimeException("Application must not boot.");');
file_put_contents($workspace.'/composer.json', json_encode([
    'autoload' => ['files' => ['bootstrap.php']],
], JSON_THROW_ON_ERROR));
$config = [
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => ['paths' => ['cases.php'], 'includes' => ['framework.php', 'relation-predicates.php']],
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
    $cases['relation '.$name] = ['return $relation->'.$name.'('.$arguments.');', 'HasMany<Record>', []];
    $cases['filtered result '.$name] = ['return $relation->'.$name.'('.$arguments.')->firstOrFail();', 'Record', []];
}
$cases += [
    'named arguments' => ['return $relation->whereIn(values: [1], column: "id");', 'HasMany<Record>', []],
    'multi predicate chain' => [
        'return $relation->whereKey(1)->whereNull("label")->whereMonth("created_at", 1)->firstOrFail();',
        'Record',
        [],
    ],
    'nullable property retained' => ['return $relation->whereNotNull("label")->firstOrFail()->label;', '?string', []],
    'nullable result retained' => ['return $relation->whereKey(1)->first();', '?Record', []],
    'collection retained' => ['return $relation->whereIn("id", [1])->get();', 'Collection<int, Record>', []],
    'case insensitive' => ['return $relation->WHEREIN("id", [1]);', 'HasMany<Record>', []],
    'first class call' => ['return $relation->whereIn(...);', 'Closure', []],
    'missing values' => ['$relation->whereIn("id");', 'void', ['too-few-arguments']],
    'extra key argument' => ['$relation->whereKey(1, 2);', 'void', ['too-many-arguments']],
    'invalid range' => ['$relation->whereBetween("id", 123);', 'void', ['invalid-argument']],
    'invalid named argument' => ['$relation->whereIn("id", [1], typo: true);', 'void', ['invalid-named-argument']],
    'wrong result' => ['return $relation->whereKey(1)->firstOrFail();', 'string', ['invalid-return-statement']],
    'unknown method' => ['$relation->whereInn("id", [1]);', 'void', ['non-documented-method']],
    'unsupported predicate' => ['$relation->whereRaw("id = 1");', 'void', ['non-documented-method']],
    'scope defers' => ['$scoped->whereIn("id", [1]);', 'void', ['non-documented-method']],
    'custom query defers' => ['$customQuery->whereIn("id", [1]);', 'void', ['non-documented-method']],
    'custom dispatch defers' => ['$customDispatch->whereIn("id", [1]);', 'void', ['non-documented-method']],
    'custom builder defers' => ['$customBuilder->whereIn("id", [1]);', 'void', ['non-documented-method']],
    'unknown generic remains' => [
        'return unknownRelation()->whereIn("id", [1])->firstOrFail();',
        'Record',
        ['less-specific-return-statement'],
    ],
    'inherited scope defers' => ['inheritedScopeRelation()->whereIn("id", [1]);', 'void', ['non-documented-method']],
    'key scope cannot override native method' => [
        'return keyScopeRelation()->whereKey(1);',
        'HasMany<KeyScopedRecord>',
        [],
    ],
    'custom relation contract' => ['return $customRelation->whereIn("id", [1]);', 'string', []],
];

function check_predicates(array $cases, array $command, string $workspace): void
{
    $source = <<<'PHP'
        <?php
        use Illuminate\Database\Eloquent\Relations\HasMany;
        use Illuminate\Database\Eloquent\Collection;
        use Illuminate\Database\Query\Builder as QueryBuilder;
        function acceptString(string $value): void {}

        PHP;
    $lines = [];
    foreach ($cases as $name => [$body, $return, $codes]) {
        $source .=
            "/**\n"
            .' * @param HasMany<Record> $relation'
            ."\n"
            .' * @param HasMany<ScopedRecord> $scoped'
            ."\n"
            .' * @param HasMany<CustomQueryRecord> $customQuery'
            ."\n"
            .' * @param HasMany<CustomDispatchRecord> $customDispatch'
            ."\n"
            .' * @param HasMany<CustomBuilderRecord> $customBuilder'
            ."\n"
            .' * @return '
            .$return
            ."\n */\n";
        $source .=
            'function scenario'
            .count($lines)
            .'(HasMany $relation, HasMany $scoped, HasMany $customQuery, HasMany $customDispatch, HasMany $customBuilder, CustomRelation $customRelation) { '
            .$body
            .' }'
            ."\n";
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

$framework = file_get_contents($workspace.'/framework.php');
file_put_contents($workspace.'/framework.php', str_replace(
    'function whereDate(',
    'function unavailableWhereDate(',
    $framework,
));
check_predicates(
    [
        'missing installed method' => [
            '$relation->whereDate("created_at", "2026-01-01");',
            'void',
            ['non-documented-method'],
        ],
    ],
    $command,
    $workspace,
);
file_put_contents($workspace.'/framework.php', str_replace(
    'public function whereKey($id) {}',
    'public function whereKey($id) {} public function whereIn($column, $values): int { return 1; }',
    $framework,
));
check_predicates(
    ['installed builder method defers' => ['$relation->whereIn("id", [1]);', 'void', ['non-documented-method']]],
    $command,
    $workspace,
);
file_put_contents($workspace.'/framework.php', str_replace(
    '@mixin \Illuminate\Database\Query\Builder',
    '@mixin \Illuminate\Database\Query\Builder'."\n".' * @method int whereIn(string $column, mixed $values)',
    $framework,
));
check_predicates(
    ['installed builder documentation defers' => ['$relation->whereIn("id", [1]);', 'void', ['non-documented-method']]],
    $command,
    $workspace,
);
file_put_contents($workspace.'/framework.php', $framework);
$config['analyzer'] = ['disable-default-plugins' => true];
file_put_contents($workspace.'/mago.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
check_predicates(
    ['native relation predicate unknown' => ['$relation->whereIn("id", [1]);', 'void', ['non-documented-method']]],
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
