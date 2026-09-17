<?php

declare(strict_types=1);

// Exercise magic model dispatch through the real SDK worker and native argument checks.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago creation '.bin2hex(random_bytes(8));
mkdir($workspace);
copy(__DIR__.'/fixtures/analysis/framework.php.stub', $workspace.'/framework.php');
copy(__DIR__.'/fixtures/analysis/create.php.stub', $workspace.'/models.php');
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
    'create' => ['return Record::create(["label" => "example"]);', 'Record', []],
    'create quietly' => ['return Record::createQuietly();', 'Record', []],
    'force create' => ['return Record::forceCreate([]);', 'Record', []],
    'force create quietly' => ['return Record::forceCreateQuietly();', 'Record', []],
    'first or new' => ['return Record::firstOrNew();', 'Record', []],
    'first or create' => ['return Record::firstOrCreate();', 'Record', []],
    'create or first' => ['return Record::createOrFirst();', 'Record', []],
    'update or create' => ['return Record::updateOrCreate([]);', 'Record', []],
    'create defaults' => ['return Record::create();', 'Record', []],
    'named creation' => ['return Record::create(attributes: []);', 'Record', []],
    'named values reordered' => ['return Record::updateOrCreate(values: [], attributes: []);', 'Record', []],
    'closure values' => ['return Record::firstOrCreate([], fn (): array => ["label" => "example"]);', 'Record', []],
    'new closure values' => ['return Record::firstOrNew([], fn (): array => []);', 'Record', []],
    'create first closure values' => ['return Record::createOrFirst([], fn (): array => []);', 'Record', []],
    'update closure values' => ['return Record::updateOrCreate([], fn (): array => []);', 'Record', []],
    'inherited model' => ['return ChildRecord::create();', 'ChildRecord', []],
    'instance creation' => ['return $record->create();', 'Record', []],
    'static context' => ['return Record::makeRecord();', 'Record', []],
    'inherited static context' => ['return ChildRecord::makeRecord();', 'ChildRecord', []],
    'analyzed late static creation' => ['return InlineCreation::make();', 'InlineCreation', []],
    'case insensitive method' => ['return Record::CREATE();', 'Record', []],
    'class string model' => ['return $class::create();', 'Record', []],
    'unpacked attributes' => ['return Record::create(...[[]]);', 'Record', []],
    'typed builder' => ['return $builder->create();', 'Record', []],
    'query creation' => ['return Record::query()->create();', 'Record', []],
    'where creation' => ['return Record::where("label", "example")->create();', 'Record', []],
    'query first or create' => ['return Record::query()->firstOrCreate();', 'Record', []],
    'query update or create' => ['return Record::query()->updateOrCreate([]);', 'Record', []],
    'created property' => ['return Record::create()->id;', 'int', []],
    'created property typo' => ['Record::create()->idd;', 'void', ['non-documented-property', 'unused-statement']],
    'created property write' => ['Record::create()->id = "wrong";', 'void', ['invalid-property-assignment-value']],
    'created missing method' => ['Record::create()->missingMethod();', 'void', ['non-documented-method']],
    'model is not collection' => ['acceptCollection(Record::create());', 'void', ['invalid-argument']],
    'creation is not nullable' => ['return Record::create() === null;', 'bool', ['redundant-comparison']],
    'fresh property refinements' => [
        '$record->label = "known"; acceptString($record->create()->label);',
        'void',
        ['possibly-null-argument'],
    ],
    'invalid attributes' => ['Record::create("wrong");', 'void', ['invalid-argument']],
    'invalid attribute object' => ['Record::create(new stdClass);', 'void', ['invalid-argument']],
    'null attributes' => ['Record::create(null);', 'void', ['null-argument']],
    'mixed attributes' => ['Record::create($unknown);', 'void', ['mixed-argument']],
    'missing force attributes' => ['Record::forceCreate();', 'void', ['too-few-arguments']],
    'missing update attributes' => ['Record::updateOrCreate();', 'void', ['too-few-arguments']],
    'extra argument' => ['Record::create([], []);', 'void', ['too-many-arguments']],
    'invalid named argument' => ['Record::create(typo: []);', 'void', ['invalid-named-argument']],
    'invalid values' => ['Record::firstOrCreate([], "wrong");', 'void', ['possibly-invalid-argument']],
    'invalid callback result' => [
        'Record::firstOrCreate([], fn (): string => "wrong");',
        'void',
        ['possibly-invalid-argument'],
    ],
    'native callback result check' => [
        'Record::query()->firstOrCreate([], fn (): string => "wrong");',
        'void',
        ['possibly-invalid-argument'],
    ],
    'native query argument check' => ['Record::query()->create("wrong");', 'void', ['invalid-argument']],
    'method typo' => ['Record::creat();', 'void', ['non-documented-method']],
    'declared creation' => ['return DeclaredRecord::create();', 'string', []],
    'inherited creation' => ['return InheritedDeclaredRecord::create();', 'string', []],
    'documented creation' => ['return DocumentedRecord::create();', 'string', []],
    'inherited documented creation' => ['return InheritedDocumentedRecord::create();', 'string', []],
    'trait creation' => ['return TraitRecord::create();', 'string', []],
    'declared method argument check' => ['DeclaredRecord::create("wrong");', 'void', ['invalid-argument']],
    'custom query deferred' => ['CustomQueryRecord::create();', 'void', ['non-documented-method']],
    'inherited query deferred' => ['InheritedCustomQueryRecord::create();', 'void', ['non-documented-method']],
    'custom static magic deferred' => ['CustomMagicRecord::create();', 'void', ['non-documented-method']],
    'custom instance magic deferred' => ['CustomInstanceMagicRecord::create();', 'void', ['non-documented-method']],
    'custom builder forwarded' => ['return CustomBuilderRecord::create();', 'string', []],
    'inherited builder forwarded' => ['return InheritedCustomBuilderRecord::create();', 'string', []],
    'builder attribute forwarded' => ['return AttributedRecord::create();', 'string', []],
    'custom builder declaration' => ['return (new CustomBuilder)->create();', 'string', []],
    'custom instance deferred' => ['CustomInstanceRecord::create();', 'void', ['non-documented-method']],
    'inherited instance deferred' => ['InheritedCustomInstanceRecord::create();', 'void', ['non-documented-method']],
    'custom hydration deferred' => ['CustomHydrationRecord::firstOrCreate();', 'void', ['non-documented-method']],
    'custom events deferred' => ['CustomEventsRecord::createQuietly();', 'void', ['non-documented-method']],
    'custom guards deferred' => ['CustomGuardRecord::forceCreate();', 'void', ['non-documented-method']],
    'force respects overridden create' => ['DeclaredRecord::forceCreate([]);', 'void', ['non-documented-method']],
    'custom collection still creates model' => [
        'return CustomCollectionRecord::create();',
        'CustomCollectionRecord',
        [],
    ],
    'collection attribute still creates model' => [
        'return CollectionAttributeRecord::create();',
        'CollectionAttributeRecord',
        [],
    ],
    'unrelated method' => ['return UnrelatedCreation::create();', 'string', []],
    'first class creation' => ['return Record::create(...);', 'Closure', []],
    'first class update creation' => ['return Record::updateOrCreate(...);', 'Closure', []],
];

function check_creation(array $cases, array $command, string $workspace): void
{
    $source = <<<'PHP'
        <?php
        use Illuminate\Database\Eloquent\Builder;
        use Illuminate\Database\Eloquent\Collection;
        function acceptString(string $value): void {}
        function acceptCollection(Collection $value): void {}
        class InlineCreation extends Record {
            /** @return static */
            public static function make() { return static::create(); }
        }

        PHP;
    $lines = [];
    foreach ($cases as $name => [$body, $return, $codes]) {
        $source .=
            '/**'
            ."\n"
            .' * @param Builder<Record> $builder'
            ."\n"
            .' * @param class-string<Record> $class'
            ."\n"
            .' * @return '
            .$return
            ."\n */\n";
        $source .=
            'function scenario'
            .count($lines)
            .'(Record $record, Builder $builder, mixed $unknown, string $class) { '
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
        throw new RuntimeException('Unexpected diagnostics outside creation scenarios; inspect '.$workspace);
    }
}

check_creation($cases, $command, $workspace);

// A new worker must honor the installed version's actual signature and method availability.
$framework = file_get_contents($workspace.'/framework.php');
$framework = str_replace('(\Closure(): array)|array $values', 'array $values', $framework);
$framework = str_replace('\Closure|array $values', 'array $values', $framework);
$framework = str_replace('function createOrFirst(', 'function unavailableCreateOrFirst(', $framework);
file_put_contents($workspace.'/framework.php', $framework);
check_creation(
    [
        'legacy array values' => ['return Record::firstOrNew([], []);', 'Record', []],
        'legacy closure rejected' => [
            'Record::firstOrNew([], fn (): array => []);',
            'void',
            ['possibly-invalid-argument'],
        ],
        'legacy builder closure rejected' => [
            'Record::query()->firstOrNew([], fn (): array => []);',
            'void',
            ['possibly-invalid-argument'],
        ],
        'unavailable method stays unknown' => ['Record::createOrFirst();', 'void', ['non-documented-method']],
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
