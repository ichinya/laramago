<?php

declare(strict_types=1);

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago collection contracts '.bin2hex(random_bytes(8));
mkdir($workspace);
copy(__DIR__.'/fixtures/analysis/collection-contracts.php.stub', $workspace.'/framework.php');
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
    'integer sum permits overflow' => ['return $models->sum("quantity");', 'int|float', []],
    'float sum includes empty zero' => ['return $models->sum("price");', 'int|float', []],
    'numeric string sum' => ['return $models->sum("numeric");', 'int|float', []],
    'nullable cast sum' => ['return $models->sum("rating");', 'int|float', []],
    'accessor sum' => ['return $models->sum("score");', 'int|float', []],
    'min property' => ['return $models->min("quantity");', 'int|null', []],
    'max property' => ['return $models->max(callback: "price");', 'float|null', []],
    'nullable max' => ['return $models->max("label");', 'string|null', []],
    'union properties' => ['return $union->min("quantity");', 'int|float|null', []],
    'null items data get' => ['return $nullable->sum("quantity");', 'int|float', []],
    'shape sum' => ['return $rows->sum("quantity");', 'int|float', []],
    'shape optional max' => ['return $rows->max("optional");', 'string|null', []],
    'array fallback min' => ['return $arrays->min("value");', 'float|null', []],
    'missing shape key null' => ['return $rows->max("absent");', 'null', []],
    'empty sum zero' => ['return $empty->sum();', '0', []],
    'empty minimum null' => ['return $empty->min();', 'null', []],
    'null sum zero' => ['return $nulls->sum();', '0', []],
    'integer scalar sum' => ['return $integers->sum();', 'int|float', []],
    'tuple key aggregate' => ['return $tuples->sum("0");', 'int|float', []],
    'tuple key map' => ['return $tuples->map->{"0"}->all();', 'array<int, int>', []],
    'shape map keys' => ['return $rows->map->quantity->all();', 'array<string, int>', []],
    'union map methods' => ['return $union->map->amount()->all();', 'array<int, int|float>', []],
    'eloquent filter nullable values' => [
        'return $eloquentNullable->whereNotNull();',
        'EloquentCollection<int, Record>',
        [],
    ],
    'union map properties' => ['return $union->map->quantity->all();', 'array<int, int|float>', []],
    'native override sum retains analyzer contract' => [
        'return $override->sum("quantity");',
        'string',
        ['mixed-return-statement'],
    ],
    'documented override min' => ['return $override->min("quantity");', 'string', []],
    'wrong aggregate return detected' => [
        'return $models->min("quantity");',
        'string',
        ['invalid-return-statement', 'nullable-return-statement'],
    ],
    'overflow cannot claim int' => ['return $models->sum("quantity");', 'int', ['invalid-return-statement']],
    'empty max cannot claim nonnull' => [
        'return $models->max("quantity");',
        'int',
        ['invalid-return-statement', 'nullable-return-statement'],
    ],
    'arbitrary string sum deferred' => ['return $models->sum("label");', 'int|float', ['mixed-return-statement']],
    'unknown model property deferred' => ['return $models->max("unknown");', 'int', ['mixed-return-statement']],
    'protected property deferred' => ['return $models->max("secret");', 'int', ['mixed-return-statement']],
    'unknown union branch deferred' => ['return $unsafe->max("quantity");', 'int', ['mixed-return-statement']],
    'bare generic deferred' => ['return $bare->sum("quantity");', 'int', ['mixed-return-statement']],
    'dynamic key deferred' => ['return $models->sum($key);', 'int', ['mixed-return-statement']],
    'nested key deferred' => ['return $rows->min("quantity.value");', 'int', ['mixed-return-statement']],
    'wrong shape keys detected' => [
        'return $rows->map->quantity->all();',
        'array<int, int>',
        ['invalid-return-statement'],
    ],
];
function check_collection_contracts(array $cases, array $command, string $workspace): void
{
    $source = <<<'PHP'
        <?php
        use Illuminate\Support\Collection;
        use Illuminate\Database\Eloquent\Collection as EloquentCollection;
        use CollectionContracts\{Record, OtherRecord, MissingRecord, OverrideCollection};

        PHP;
    $doc = <<<'PHP'
        /**
         * @param EloquentCollection<int, Record> $models
         * @param Collection<int, Record|OtherRecord> $union
         * @param Collection<int, Record|MissingRecord> $unsafe
         * @param EloquentCollection<int, Record|null> $eloquentNullable
         * @param Collection<int, Record|null> $nullable
         * @param Collection<string, array{quantity: int, optional?: string}> $rows
         * @param Collection<int, list{int, string}> $tuples
         * @param Collection<int, array<string, float>> $arrays
         * @param Collection<int, never> $empty
         * @param Collection<int, null> $nulls
         * @param Collection<int, int> $integers
         * @param Collection<int, mixed> $bare
        PHP;
    $lines = [];
    foreach ($cases as $name => [$body, $return, $codes]) {
        $source .= $doc."\n * @return ".$return."\n */\n";
        $source .=
            'function scenario'
            .count($lines)
            .'(EloquentCollection $models, EloquentCollection $eloquentNullable, Collection $union, Collection $unsafe, Collection $nullable, Collection $rows, Collection $tuples, Collection $arrays, Collection $empty, Collection $nulls, Collection $integers, Collection $bare, OverrideCollection $override, string $key) { '
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

check_collection_contracts($cases, $command, $workspace);

// The same declarations expose the native failure when providers are disabled.
$config['analyzer'] = ['disable-default-plugins' => true];
file_put_contents($workspace.'/mago.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
check_collection_contracts(
    [
        'disabled sum is mixed' => ['return $models->sum("quantity");', 'int|float', ['mixed-return-statement']],
        'disabled min is mixed' => ['return $models->min("quantity");', 'int|null', ['mixed-return-statement']],
        'disabled native override' => ['return $override->sum("quantity");', 'string', ['mixed-return-statement']],
        'disabled unknown property' => ['return $models->max("unknown");', 'int', ['mixed-return-statement']],
    ],
    $command,
    $workspace,
);
// Explicit altered framework declarations retain their selected native/PHPDoc branch.
unset($config['analyzer']);
file_put_contents($workspace.'/mago.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$fixture = file_get_contents(__DIR__.'/fixtures/analysis/collection-contracts.php.stub');
file_put_contents($workspace.'/framework.php', str_replace(
    '($callback is callable ? ?TMinResult : ($callback is null ? ?TValue : mixed))',
    '($callback is null ? string : mixed)',
    $fixture,
));
check_collection_contracts(
    [
        'altered omitted null branch preserved' => ['return $integers->min();', 'string', []],
        'altered explicit null branch preserved' => ['return $integers->min(null);', 'string', []],
        'altered null branch wrong type' => ['return $integers->min();', 'int', ['invalid-return-statement']],
    ],
    $command,
    $workspace,
);
$declaration = 'public function sum($callback = null)';
$position = strpos($fixture, $declaration);
if ($position === false) {
    throw new RuntimeException('Aggregate declaration missing from fixture.');
}
file_put_contents($workspace.'/framework.php', substr_replace(
    $fixture,
    ': string',
    $position + strlen($declaration),
    0,
));
check_collection_contracts(
    [
        'concrete native declaration blocks refinement' => [
            'return $models->sum("quantity");',
            'int|float',
            ['mixed-return-statement'],
        ],
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
