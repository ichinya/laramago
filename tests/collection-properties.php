<?php

declare(strict_types=1);

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago collection properties '.bin2hex(random_bytes(8));
mkdir($workspace);
copy(__DIR__.'/fixtures/analysis/collection-properties.php.stub', $workspace.'/framework.php');
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
    'declared scalar retains collection' => ['return $models->map->name;', 'Collection<int, string>', []],
    'declared scalar array' => ['return $models->map->name->all();', 'array<int, string>', []],
    'string keys retained' => ['return $keyed->map->name->all();', 'array<string, string>', []],
    'support remains support' => ['return $support->map->owner;', 'Collection<string, Record>', []],
    'model result retains eloquent' => ['return $models->map->owner;', 'EloquentCollection<int, Record>', []],
    'model collection methods retained' => ['return $models->map->owner->modelCollectionOnly();', 'bool', []],
    'nullable model uses support' => ['return $models->map->maybeOwner;', 'Collection<int, Record|null>', []],
    'nullable value retained' => ['return $models->map->subtitle->all();', 'array<int, string|null>', []],
    'inherited declared property' => ['return $children->map->name->all();', 'array<int, string>', []],
    'model documentation mapped' => ['return $models->map->documentedTitle->all();', 'array<int, string>', []],
    'proxy documentation wins' => ['return $models->map->documentedProxy;', 'bool', []],
    'accessor mapped' => ['return $models->map->caption->all();', 'array<int, string>', []],
    'nullable cast mapped' => ['return $models->map->rating->all();', 'array<int, int|null>', []],
    'direct declared property unchanged' => ['return $record->name;', 'string', []],
    'wrong result detected' => ['return $models->map->name->all();', 'array<int, int>', ['invalid-return-statement']],
    'wrong keys detected' => ['return $keyed->map->name->all();', 'array<int, string>', ['invalid-return-statement']],
    'nullable result detected' => [
        'return $models->map->subtitle->all();',
        'array<int, string>',
        ['invalid-return-statement'],
    ],
    'scalar loses model methods' => ['$models->map->name->modelCollectionOnly();', 'void', ['non-existent-method']],
    'unknown property deferred' => [
        '$models->map->unknownProperty;',
        'void',
        ['non-documented-property', 'unused-statement'],
    ],
    'protected property deferred' => [
        'return $models->map->secretProperty;',
        'string',
        ['mixed-return-statement', 'non-documented-property'],
    ],
    'nullable item deferred' => ['return $nullable->map->name;', 'string', []],
    'other operation deferred' => ['return $models->each->name;', 'string', []],
    'custom collection deferred' => ['return $custom->map->name;', 'string', []],
];
function check_collection_properties(array $cases, array $command, string $workspace): void
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
         * @param list<mixed> $args
        PHP;
    $lines = [];
    foreach ($cases as $name => [$body, $return, $codes]) {
        $source .= $doc."\n * @return ".$return."\n */\n";
        $source .=
            'function scenario'
            .count($lines)
            .'(EloquentCollection $models, EloquentCollection $keyed, Collection $support, EloquentCollection $children, EloquentCollection $overrides, Collection $objects, Collection $nullable, EloquentCollection $traits, CustomCollection $custom, CustomProxy $customProxy, Record $record, ?string $key, bool $flag, array $args) { '
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

check_collection_properties($cases, $command, $workspace);

// The same declarations expose the native failure when providers are disabled.
$config['analyzer'] = ['disable-default-plugins' => true];
file_put_contents($workspace.'/mago.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
check_collection_properties(
    [
        'native mapped scalar is not collection' => [
            'return $models->map->name;',
            'Collection<int, string>',
            ['invalid-return-statement'],
        ],
        'native unknown property diagnostic' => [
            '$models->map->unknownProperty;',
            'void',
            ['non-documented-property', 'unused-statement'],
        ],
        'native protected property diagnostic' => [
            'return $models->map->secretProperty;',
            'string',
            ['mixed-return-statement', 'non-documented-property'],
        ],
        'native nullable item unchanged' => ['return $nullable->map->name;', 'string', []],
        'native custom collection unchanged' => ['return $custom->map->name;', 'string', []],
        'native other operation unchanged' => ['return $models->each->name;', 'string', []],
        'native direct property unchanged' => ['return $record->name;', 'string', []],
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
