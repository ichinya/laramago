<?php

declare(strict_types=1);

// Check native method signatures and conditional lookup results together.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago lookups '.bin2hex(random_bytes(8));
mkdir($workspace);
copy(__DIR__.'/fixtures/analysis/framework.php.stub', $workspace.'/framework.php');
copy(__DIR__.'/fixtures/analysis/find.php.stub', $workspace.'/models.php');
$cases = [
    'custom collection method' => ['return CustomCollectionRecord::findMany([1])->marker();', 'string', []],
    'custom builder lookup collection' => [
        'return CollectionPropertyRecord::query()->find([1]);',
        'RecordCollection',
        [],
    ],
    'custom collection scalar lookup' => ['return CustomCollectionRecord::find(1);', 'CustomCollectionRecord|null', []],
    'custom collection unknown id' => [
        'return CustomCollectionRecord::find($unknown);',
        'CustomCollectionRecord|RecordCollection|null',
        [],
    ],
    'custom collection invalid property' => [
        'CollectionPropertyRecord::findMany([1])->id;',
        'void',
        ['non-existent-property', 'unused-statement'],
    ],
    'custom collection typo method' => [
        'CollectionPropertyRecord::findMany([1])->markerr();',
        'void',
        ['non-existent-method'],
    ],
    'generic collection deferred' => ['GenericCollectionRecord::findMany([1]);', 'void', ['non-documented-method']],
    'invalid collection class deferred' => [
        'InvalidCollectionRecord::findMany([1]);',
        'void',
        ['non-documented-method'],
    ],
    'untyped collection method deferred' => [
        'UnknownCollectionRecord::findMany([1]);',
        'void',
        ['non-documented-method'],
    ],
    'inherited collection method' => ['return InheritedCollectionMethodRecord::findMany([1]);', 'RecordCollection', []],
    'nearest collection attribute wins' => [
        'return OverriddenCollectionAttributeRecord::findMany([1])->alternate();',
        'int',
        [],
    ],
    'collection method beats attribute' => ['return MethodPriorityRecord::findMany([1])->marker();', 'string', []],
    'scalar find' => ['return Record::find(1);', 'Record|null', []],
    'string find' => ['return Record::find("example-id");', 'Record|null', []],
    'null find' => ['return Record::find(null);', 'Record|null', []],
    'scalar find or fail' => ['return Record::findOrFail(1);', 'Record', []],
    'scalar find or new' => ['return Record::findOrNew(1);', 'Record', []],
    'sole returns one model' => ['return Record::findSole([1, 2]);', 'Record', []],
    'array find' => ['return Record::find([1, 2]);', 'Collection<int, Record>', []],
    'empty array find' => ['return Record::find([]);', 'Collection<int, Record>', []],
    'array or fail' => ['return Record::findOrFail([1]);', 'Collection<int, Record>', []],
    'array or new' => ['return Record::findOrNew([1]);', 'Collection<int, Record>', []],
    'find many' => ['return Record::findMany([1]);', 'Collection<int, Record>', []],
    'arrayable ids' => ['return Record::find(new RecordIds);', 'Collection<int, Record>', []],
    'arrayable many' => ['return Record::findMany(new RecordIds);', 'Collection<int, Record>', []],
    'arrayable argument' => ['return Record::find($ids);', 'Collection<int, Record>', []],
    'case insensitive lookup' => ['return Record::FINDORFAIL(1);', 'Record', []],
    'instance lookup' => ['return (new Record)->findOrFail(1);', 'Record', []],
    'named lookup' => ['return Record::findOrFail(columns: ["id"], id: 1);', 'Record', []],
    'named many' => ['return Record::findMany(columns: "id", ids: [1]);', 'Collection<int, Record>', []],
    'inherited model type' => ['return ChildRecord::findOrFail(1);', 'ChildRecord', []],
    'query scalar' => ['return Record::query()->find(1);', 'Record|null', []],
    'query or fail' => ['return Record::query()->findOrFail(1);', 'Record', []],
    'query array' => ['return Record::query()->find([1]);', 'Collection<int, Record>', []],
    'query arrayable' => ['return Record::query()->findOrFail(new RecordIds);', 'Collection<int, Record>', []],
    'query variable' => ['$query = Record::query(); return $query->findOrFail(1);', 'Record', []],
    'where chain' => ['return Record::where("active", true)->findOrFail(1);', 'Record', []],
    'typed builder variable' => ['return $builder->findOrFail(1);', 'Record', []],
    'union model builder' => ['return $models->findOrFail(1);', 'Record|OtherRecord', []],
    'nullable scalar id' => ['return Record::find($nullable);', 'Record|null', []],
    'unknown find keeps all branches' => ['return Record::find($unknown);', 'Record|Collection<int, Record>|null', []],
    'union id keeps both branches' => ['return Record::findOrFail($oneOrMany);', 'Record|Collection<int, Record>', []],
    'mixed lookup stays ambiguous' => [
        'acceptRecord(Record::findOrFail($unknown));',
        'void',
        ['possibly-invalid-argument'],
    ],
    'union lookup stays ambiguous' => [
        'acceptRecord(Record::findOrFail($oneOrMany));',
        'void',
        ['possibly-invalid-argument'],
    ],
    'unpacked lookup stays ambiguous' => [
        'acceptRecord(Record::findOrFail(...[1]));',
        'void',
        ['possibly-invalid-argument'],
    ],
    'nullable lookup stays nullable' => ['acceptRecord(Record::find(1));', 'void', ['possibly-null-argument']],
    'property after lookup' => ['return Record::findOrFail(1)->id;', 'int', []],
    'nullable property access' => ['echo Record::find(1)->id;', 'void', ['possibly-null-property-access']],
    'collection property access' => ['Record::find([1])->id;', 'void', ['non-existent-property', 'unused-statement']],
    'property typo' => ['Record::findOrFail(1)->idd;', 'void', ['non-documented-property', 'unused-statement']],
    'missing id' => ['Record::find();', 'void', ['too-few-arguments']],
    'extra argument' => ['Record::find(1, ["*"], 3);', 'void', ['too-many-arguments']],
    'invalid columns' => ['Record::find(1, new stdClass);', 'void', ['invalid-argument']],
    'invalid many ids' => ['Record::findMany(1);', 'void', ['invalid-argument']],
    'invalid named argument' => ['Record::find(id: 1, typo: []);', 'void', ['invalid-named-argument']],
    'native query argument checks' => ['Record::query()->find(1, false);', 'void', ['false-argument']],
    'missing method remains invalid' => ['Record::findd(1);', 'void', ['non-documented-method']],
    'declared method preserved' => ['return DeclaredRecord::find(1);', 'string', []],
    'inherited declaration preserved' => ['return InheritedDeclaredRecord::find(1);', 'string', []],
    'documented method preserved' => ['return DocumentedRecord::find(1);', 'string', []],
    'inherited documentation preserved' => ['return InheritedDocumentedRecord::find(1);', 'string', []],
    'custom query deferred' => ['CustomQueryRecord::find(1);', 'void', ['non-documented-method']],
    'inherited custom query deferred' => ['InheritedCustomQueryRecord::find(1);', 'void', ['non-documented-method']],
    'custom magic deferred' => ['CustomMagicRecord::find(1);', 'void', ['non-documented-method']],
    'custom builder property forwarded' => ['return CustomBuilderRecord::find(1);', 'string', []],
    'inherited custom builder forwarded' => [
        'return InheritedCustomBuilderRecord::find(1);',
        'string',
        [],
    ],
    'custom builder attribute forwarded' => ['return AttributedRecord::find(1);', 'string', []],
    'custom builder declaration preserved' => ['return (new CustomBuilder)->find(1);', 'string', []],
    'custom collection contract' => ['return CustomCollectionRecord::find([1]);', 'RecordCollection', []],
    'collection property contract' => ['return CollectionPropertyRecord::find([1]);', 'RecordCollection', []],
    'unrelated lookup preserved' => ['return UnrelatedLookup::find(1);', 'string', []],
    'first class lookup stays callable' => ['return Record::find(...);', 'Closure', []],
    'first class many stays callable' => ['return Record::findMany(...);', 'Closure', []],
    'first class sole stays callable' => ['return Record::findSole(...);', 'Closure', []],
    'object id stays ambiguous' => [
        'acceptRecord(Record::findOrFail($object));',
        'void',
        ['possibly-invalid-argument'],
    ],
    'nullable union keeps null' => [
        'acceptRecord(Record::find($oneOrMany));',
        'void',
        ['possibly-invalid-argument', 'possibly-null-argument'],
    ],
    'array results cannot be used as one model' => [
        'acceptRecord(Record::findOrFail([1]));',
        'void',
        ['invalid-argument'],
    ],
    'collection attribute contract' => ['return CollectionAttributeRecord::findMany([1]);', 'RecordCollection', []],
    'inherited collection attribute contract' => [
        'return InheritedCollectionAttributeRecord::findMany([1]);',
        'RecordCollection',
        [],
    ],
    'collection resolver deferred' => ['CollectionResolverRecord::findMany([1]);', 'void', ['non-documented-method']],
];
$source = <<<'PHP'
    <?php
    use Illuminate\Database\Eloquent\Builder;
    use Illuminate\Database\Eloquent\Collection;
    use Illuminate\Contracts\Support\Arrayable;
    function acceptRecord(Record $record): void {}

    PHP;
$lines = [];
foreach ($cases as $name => [$body, $return, $codes]) {
    $source .=
        '/**'
        ."\n"
        .' * @param Builder<Record> $builder'
        ."\n"
        .' * @param Builder<Record|OtherRecord> $models'
        ."\n"
        .' * @param Arrayable<int, int> $ids'
        ."\n"
        .' * @return '
        .$return
        .' */'
        ."\n";
    $source .=
        'function scenario'
        .count($lines)
        .'(mixed $unknown, int|array $oneOrMany, ?int $nullable, Builder $builder, Builder $models, Arrayable $ids, object $object) { '
        .$body
        .' }'
        ."\n";
    $lines[substr_count($source, "\n")] = [$name, $codes];
}
file_put_contents($workspace.'/cases.php', $source);
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
$process = proc_open(
    [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
    [0 => ['pipe', 'r'], 1 => ['file', $workspace.'/report.json', 'w'], 2 => ['file', $workspace.'/stderr.log', 'w']],
    $pipes,
);
if (! is_resource($process)) {
    throw new RuntimeException('Cannot start Mago.');
}
fclose($pipes[0]);
$exit = proc_close($process);
$log = file_get_contents($workspace.'/stderr.log');
if ($exit !== 1 || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)) {
    throw new RuntimeException('Expected native negative diagnostics without extension fallback; inspect '.$workspace);
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
    throw new RuntimeException('Unexpected diagnostics outside lookup scenarios; inspect '.$workspace);
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
