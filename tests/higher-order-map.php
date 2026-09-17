<?php

declare(strict_types=1);

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago higher order map '.bin2hex(random_bytes(8));
mkdir($workspace);
copy(__DIR__.'/fixtures/analysis/higher-order-map.php.stub', $workspace.'/framework.php');
file_put_contents($workspace.'/bootstrap.php', '<?php throw new RuntimeException("Never bootstrap the application.");');
file_put_contents($workspace.'/composer.json', json_encode([
    'autoload' => ['files' => ['bootstrap.php']],
], JSON_THROW_ON_ERROR));
$config = [
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => ['paths' => ['cases.php'], 'includes' => ['framework.php']],
    'extension-hosts' => [
        'laramago' => [
            'command' => [PHP_BINARY, $package.'/bin/laramago-worker.php', $package.'/vendor/autoload.php', $workspace],
            'workers' => 3,
        ],
    ],
];
file_put_contents($workspace.'/mago.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$cases = [
    'proxy documentation wins' => ['return $models->map->documented();', 'string', []],
    'original attributes remain a collection' => [
        'return $models->map->getRawOriginal();',
        'Collection<int, array<string, mixed>>',
        [],
    ],
    'original attribute arrays' => [
        'return $models->map->getRawOriginal()->all();',
        'array<int, array<string, mixed>>',
        [],
    ],
    'named null key' => [
        'return $models->map->getRawOriginal(key: null)->all();',
        'array<int, array<string, mixed>>',
        [],
    ],
    'named default leaves key null' => [
        'return $models->map->getRawOriginal(default: "missing")->all();',
        'array<int, array<string, mixed>>',
        [],
    ],
    'field value stays mixed' => [
        'acceptInt($models->map->getRawOriginal("amount")->all()[0]);',
        'void',
        ['mixed-argument'],
    ],
    'unknown key stays mixed' => [
        'acceptInt($models->map->getRawOriginal($key)->all()[0]);',
        'void',
        ['mixed-argument'],
    ],
    'unpacked conditional stays conservative' => [
        'acceptInt($models->map->getRawOriginal(...$args)->all()[0]);',
        'void',
        ['mixed-argument'],
    ],
    'array shapes' => ['return $models->map->row()->all();', 'array<int, array{id: int, label?: string}>', []],
    'string keys retained' => [
        'return $keyed->map->row()->all();',
        'array<string, array{id: int, label?: string}>',
        [],
    ],
    'support collections' => ['return $support->map->label()->all();', 'array<string, string>', []],
    'scalar return' => ['return $models->map->total(2)->all();', 'array<int, int>', []],
    'nullable value' => ['return $models->map->maybe()->all();', 'array<int, string|null>', []],
    'void callback maps null' => ['return $models->map->touch()->all();', 'array<int, null>', []],
    'never callback can return empty collection' => ['return $models->map->fail()->all();', 'array<int, never>', []],
    'late static model collection' => ['return $models->map->copy();', 'EloquentCollection<int, Record>', []],
    'model collection methods preserved' => ['return $models->map->copy()->modelCollectionOnly();', 'bool', []],
    'nullable model uses base collection' => ['return $models->map->fresh();', 'Collection<int, Record|null>', []],
    'support stays support for model results' => ['return $support->map->copy();', 'Collection<string, Record>', []],
    'inherited model result' => ['return $children->map->copy();', 'EloquentCollection<int, ChildRecord>', []],
    'inherited attribute method' => [
        'return $children->map->getRawOriginal()->all();',
        'array<int, array<string, mixed>>',
        [],
    ],
    'override contract' => ['return $overrides->map->row()->all();', 'array<int, array{custom: bool}>', []],
    'named argument' => ['return $models->map->label(prefix: "test");', 'Collection<int, string>', []],
    'case insensitive method' => ['return $models->map->LABEL();', 'Collection<int, string>', []],
    'conditional default' => ['return $models->map->conditional()->all();', 'array<int, int>', []],
    'conditional then' => ['return $models->map->conditional(true)->all();', 'array<int, string>', []],
    'conditional unknown branch' => ['return $models->map->conditional($flag)->all();', 'array<int, string|int>', []],
    'negated conditional default' => ['return $models->map->negated()->all();', 'array<int, int>', []],
    'negated conditional then' => ['return $models->map->negated("field")->all();', 'array<int, string>', []],
    'nested conditional' => ['return $models->map->nested(key: null, flag: true)->all();', 'array<int, int>', []],
    'nested unknown condition' => ['return $models->map->nested($flag)->all();', 'array<int, int|string>', []],
    'wrong returned scalar' => [
        'return $models->map->total(2)->all();',
        'array<int, string>',
        ['invalid-return-statement'],
    ],
    'wrong key type' => ['return $keyed->map->label()->all();', 'array<int, string>', ['invalid-return-statement']],
    'wrong nullable result' => [
        'return $models->map->maybe()->all();',
        'array<int, string>',
        ['invalid-return-statement'],
    ],
    'nullable first result' => ['acceptString($models->map->label()->first());', 'void', ['possibly-null-argument']],
    'array result has no model methods' => [
        '$models->map->row()->modelCollectionOnly();',
        'void',
        ['non-existent-method'],
    ],
    'wrong argument' => ['$models->map->total("bad");', 'void', ['invalid-argument']],
    'missing argument' => ['$models->map->total();', 'void', ['too-few-arguments']],
    'extra argument' => ['$models->map->total(2, 3);', 'void', ['too-many-arguments']],
    'unknown named argument' => ['$models->map->label(typo: "test");', 'void', ['invalid-named-argument']],
    'protected method stays inaccessible' => [
        '$models->map->secret();',
        'void',
        ['invalid-method-access', 'non-documented-method'],
    ],
    'method typo' => ['$models->map->missingMethod();', 'void', ['non-documented-method']],
    'first class method reference' => ['return $models->map->label(...);', 'Closure', []],
    'direct model call unchanged' => ['return $record->getRawOriginal();', 'array<string, mixed>', []],
    'ordinary map callback unchanged' => [
        'return $support->map(fn (Record $item): string => $item->label())->all();',
        'array<string, string>',
        [],
    ],
    // Assert the unchanged native result when inference is deliberately deferred.
    'other operation deferred' => ['return $models->each->label();', 'string', []],
    'custom collection deferred' => ['return $custom->map->label();', 'string', []],
    'custom proxy deferred' => [
        'return $customProxy->label();',
        'string',
        ['mixed-return-statement', 'non-documented-method'],
    ],
    'plain objects deferred' => ['return $objects->map->label();', 'string', []],
    'static method deferred' => ['return $models->map->staticLabel();', 'string', []],
    'method templates deferred' => ['return $models->map->identity("text");', 'string', []],
    'nested contextual return deferred' => ['return $models->map->nestedContext();', 'array', []],
    'application trait deferred' => ['return $traits->map->fromTrait();', 'int', []],
];

function check_higher_order_map(array $cases, array $command, string $workspace): void
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
         * @param Collection<int, PlainObject> $objects
         * @param EloquentCollection<int, TraitRecord> $traits
         * @param list<mixed> $args
        PHP;
    $lines = [];
    foreach ($cases as $name => [$body, $return, $codes]) {
        $source .= $doc."\n * @return ".$return."\n */\n";
        $source .=
            'function scenario'
            .count($lines)
            .'(EloquentCollection $models, EloquentCollection $keyed, Collection $support, EloquentCollection $children, EloquentCollection $overrides, Collection $objects, EloquentCollection $traits, CustomCollection $custom, CustomProxy $customProxy, Record $record, ?string $key, bool $flag, array $args) { '
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

check_higher_order_map($cases, $command, $workspace);

// The same declarations expose the native failure when providers are disabled.
$config['analyzer'] = ['disable-default-plugins' => true];
file_put_contents($workspace.'/mago.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
check_higher_order_map(
    [
        'native proxy array chain failure' => [
            'return $models->map->getRawOriginal()->all();',
            'array<int, array<string, mixed>>',
            ['invalid-method-access', 'mixed-return-statement'],
        ],
        'native scalar result failure' => [
            'return $models->map->label();',
            'Collection<int, string>',
            ['invalid-return-statement'],
        ],
        'native direct call unchanged' => ['return $record->getRawOriginal();', 'array<string, mixed>', []],
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
