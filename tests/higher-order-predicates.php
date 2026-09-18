<?php

declare(strict_types=1);

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago higher order predicates '.bin2hex(random_bytes(8));
mkdir($workspace);
copy(__DIR__.'/fixtures/analysis/higher-order-predicates.php.stub', $workspace.'/framework.php');
file_put_contents(
    $workspace.'/bootstrap.php',
    '<?php throw new RuntimeException("Never bootstrap the application.");',
);
file_put_contents($workspace.'/composer.json', json_encode([
    'autoload' => ['files' => ['bootstrap.php']],
], JSON_THROW_ON_ERROR));
$config = [
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => ['paths' => ['cases.php'], 'includes' => ['framework.php']],
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
    'filter property preserves eloquent' => ['return $models->filter->name;', 'EloquentCollection<int, Record>', []],
    'reject property preserves eloquent' => [
        'return $models->reject->subtitle;',
        'EloquentCollection<int, Record>',
        [],
    ],
    'filter string keys' => ['return $keyed->filter->name->all();', 'array<string, Record>', []],
    'support retains support' => ['return $support->reject->name;', 'Collection<string, Record>', []],
    'model methods retained' => ['return $models->filter->name->modelCollectionOnly();', 'bool', []],
    'filter method preserves values' => ['return $models->filter->label();', 'EloquentCollection<int, Record>', []],
    'reject method accepts arguments' => ['return $keyed->reject->total(2);', 'EloquentCollection<string, Record>', []],
    'void predicate preserves values' => ['return $models->filter->touch();', 'EloquentCollection<int, Record>', []],
    'nullable first remains' => ['return $models->filter->name->first();', 'Record|null', []],
    'empty result remains possible' => [
        'return $models->reject->label()->first();',
        'Record',
        ['invalid-return-statement', 'nullable-return-statement'],
    ],
    'wrong projected result detected' => [
        'return $models->filter->name->all();',
        'array<int, string>',
        ['invalid-return-statement'],
    ],
    'wrong key detected' => ['return $keyed->reject->name->all();', 'array<int, Record>', ['invalid-return-statement']],
    'method argument errors retained' => ['$models->filter->total("wrong");', 'void', ['invalid-argument']],
    'missing arguments retained' => ['$models->reject->total();', 'void', ['too-few-arguments']],
    'unknown property deferred' => [
        '$models->filter->unknownProperty;',
        'void',
        ['non-documented-property', 'unused-statement'],
    ],
    'custom collection deferred' => ['return $custom->filter->name;', 'string', []],
    'nullable item deferred' => ['return $nullable->reject->name;', 'string', []],
    'required shape retains whole shape' => [
        'return $shapes->filter->enabled->all();',
        'array<string, array{enabled: bool, name: string|null}>',
        [],
    ],
    'shape property remains nullable' => [
        'return $shapes->reject->name->map->name->all();',
        'array<string, string|null>',
        [],
    ],
    'shape does not narrow property' => [
        'return $shapes->filter->name->map->name->all();',
        'array<string, string>',
        ['invalid-return-statement'],
    ],
    'union method retains both models' => [
        'return $union->filter->label();',
        'EloquentCollection<string, Record|ChildRecord>',
        [],
    ],
    'union property retains both models' => [
        'return $union->reject->name;',
        'EloquentCollection<string, Record|ChildRecord>',
        [],
    ],
    'direct predicate remains native' => ['return $models->filter();', 'EloquentCollection<int, Record>', []],
    'proxy documentation wins' => ['return $models->reject->documentedProxy;', 'bool', []],
];

function check_higher_order_predicates(array $cases, array $command, string $workspace): void
{
    $source = <<<'PHP'
        <?php
        use Illuminate\Support\Collection;
        use Illuminate\Database\Eloquent\Collection as EloquentCollection;
        use ProxyFixtures\{Record, ChildRecord, OverrideRecord, TraitRecord, CustomCollection, CustomProxy, PlainObject};
        function acceptInt(int $value): void {}
        function acceptString(string $value): void {}

        PHP;
    $doc = <<<'PHP'
        /**
         * @param EloquentCollection<int, Record> $models
         * @param EloquentCollection<string, Record> $keyed
         * @param Collection<string, Record> $support
         * @param EloquentCollection<int, ChildRecord> $children
         * @param EloquentCollection<int, OverrideRecord> $overrides
         * @param Collection<string, Record|null> $nullable
         * @param Collection<int, PlainObject> $objects
         * @param EloquentCollection<int, TraitRecord> $traits
         * @param Collection<string, array{enabled: bool, name: string|null}> $shapes
         * @param EloquentCollection<string, Record|ChildRecord> $union
         * @param list<mixed> $args
        PHP;
    $lines = [];
    foreach ($cases as $name => [$body, $return, $codes]) {
        $source .= $doc."\n * @return ".$return."\n */\n";
        $source .=
            'function scenario'
            .count($lines)
            .'(EloquentCollection $models, EloquentCollection $keyed, Collection $support, EloquentCollection $children, EloquentCollection $overrides, Collection $objects, Collection $nullable, EloquentCollection $traits, CustomCollection $custom, CustomProxy $customProxy, Record $record, ?string $key, bool $flag, array $args, Collection $shapes, EloquentCollection $union) { '
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

check_higher_order_predicates($cases, $command, $workspace);

// The same declarations expose the native failure when providers are disabled.
$config['analyzer'] = ['disable-default-plugins' => true];
file_put_contents($workspace.'/mago.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
check_higher_order_predicates(
    [
        'native property loses collection' => [
            'return $models->filter->name;',
            'EloquentCollection<int, Record>',
            ['invalid-return-statement'],
        ],
        'native method loses collection' => [
            'return $models->reject->label();',
            'EloquentCollection<int, Record>',
            ['invalid-return-statement'],
        ],
        'native argument error retained' => ['$models->filter->total("wrong");', 'void', ['invalid-argument']],
        'native custom unchanged' => ['return $custom->filter->name;', 'string', []],
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
