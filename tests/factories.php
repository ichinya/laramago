<?php

declare(strict_types=1);

// Native Mago integration: factory results keep both model identity and count state.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago factories '.bin2hex(random_bytes(8));
mkdir($workspace);
copy(__DIR__.'/fixtures/analysis/framework.php.stub', $workspace.'/framework.php');
copy(__DIR__.'/fixtures/analysis/factories.php.stub', $workspace.'/factories.php');
$cases = [
    'single create' => ['return Person::factory()->create();', 'Person', []],
    'single make' => ['return Person::factory()->make();', 'Person', []],
    'quiet create' => ['return Person::factory()->createQuietly();', 'Person', []],
    'factory variable' => ['$factory = Person::factory(); return $factory->create();', 'Person', []],
    'native state' => ['return Person::factory()->state([])->create();', 'Person', []],
    'custom state' => ['return Person::factory()->active()->create();', 'Person', []],
    'count zero is a collection' => ['return Person::factory(0)->create();', 'Collection<int, Person>', []],
    'count one is a collection' => ['return Person::factory(1)->create();', 'Collection<int, Person>', []],
    'count many' => ['return Person::factory(3)->make();', 'Collection<int, Person>', []],
    'explicit count' => ['return Person::factory()->count(3)->create();', 'Collection<int, Person>', []],
    'reset count' => ['return Person::factory(3)->count(null)->create();', 'Person', []],
    'count clone leaves original single' => [
        '$factory = Person::factory(); $factory->count(3); return $factory->create();',
        'Person',
        [],
    ],
    'custom count' => ['return Person::factory()->pair()->create();', 'Collection<int, Person>', []],
    'custom reset' => ['return Person::factory(3)->single()->create();', 'Person', []],
    'sequence preserves count' => ['return Person::factory()->sequence([], [])->make();', 'Person', []],
    'each sequence changes count' => [
        'return Person::factory()->forEachSequence([], [])->make();',
        'Collection<int, Person>',
        [],
    ],
    'named count' => ['return Person::factory(state: [], count: 2)->create();', 'Collection<int, Person>', []],
    'named state only' => ['return Person::factory(state: [])->create();', 'Person', []],
    'state as first argument' => ['return Person::factory([])->create();', 'Person', []],
    'create one resets count' => ['return Person::factory(3)->createOne();', 'Person', []],
    'make one resets count' => ['return Person::factory(3)->makeOne();', 'Person', []],
    'quiet one' => ['return Person::factory(3)->createOneQuietly();', 'Person', []],
    'create many' => ['return Person::factory()->createMany(2);', 'Collection<int, Person>', []],
    'quiet many' => ['return Person::factory()->createManyQuietly(2);', 'Collection<int, Person>', []],
    'make many' => ['return Person::factory()->makeMany(2);', 'Collection<int, Person>', []],
    'new model' => ['return Person::factory(3)->newModel();', 'Person', []],
    'factory new' => ['return PersonFactory::new()->create();', 'Person', []],
    'factory times' => ['return PersonFactory::times(2)->create();', 'Collection<int, Person>', []],
    'conventional discovery' => ['return Convention::factory()->create();', 'Convention', []],
    'factory attribute' => ['return Attributed::factory()->create();', 'Person', []],
    'factory property' => ['return Configured::factory()->create();', 'Person', []],
    'typed factory resolver' => ['return Resolved::factory()->create();', 'Person', []],
    'inherited factory owns model type' => ['return Employee::factory()->create();', 'Person', []],
    'model property after create' => ['return Person::factory()->create()->id;', 'int', []],
    'declared factory method preserved' => ['return CustomFactoryMethod::factory();', 'int', []],
    'declared create preserved' => ['return DeclaredFactory::new()->create();', 'int', []],
    'custom make preserved' => ['return CustomMakeFactory::new()->make();', 'int', []],
    'invalid factory argument' => ['Person::factory(new stdClass);', 'void', ['less-specific-nested-argument-type']],
    'invalid count argument' => ['Person::factory()->count("many");', 'void', ['invalid-argument']],
    'missing count argument' => ['Person::factory()->count();', 'void', ['too-few-arguments']],
    'invalid named argument' => ['Person::factory(typo: 3);', 'void', ['invalid-named-argument']],
    'invalid create argument' => ['Person::factory()->create(42);', 'void', ['invalid-argument']],
    'collection property remains invalid' => [
        'Person::factory(2)->create()->id;',
        'void',
        ['non-existent-property', 'unused-statement'],
    ],
    'typo remains invalid' => [
        'Person::factory()->create()->idd;',
        'void',
        ['non-documented-property', 'unused-statement'],
    ],
    'unknown count remains ambiguous' => [
        'acceptPerson(Person::factory($count)->create());',
        'void',
        ['possibly-invalid-argument'],
    ],
    'unknown custom state remains ambiguous' => [
        'acceptPerson(Person::factory()->unknown()->create());',
        'void',
        ['possibly-invalid-argument'],
    ],
    'untracked factory remains ambiguous' => [
        'acceptPerson($factory->create());',
        'void',
        ['possibly-invalid-argument'],
    ],
    'branch keeps both count states' => [
        '$choice = $flag ? Person::factory() : Person::factory(2); acceptPerson($choice->create());',
        'void',
        ['possibly-invalid-argument'],
    ],
    'factory property stays protected' => [
        '$factory = Person::factory(); echo $factory->count;',
        'void',
        ['invalid-property-read', 'no-value'],
    ],
    'missing factory stays invalid' => ['NoFactory::factory();', 'void', ['non-documented-method']],
    'unpacked count stays ambiguous' => [
        'acceptPerson(Person::factory(...[2])->create());',
        'void',
        ['possibly-invalid-argument'],
    ],
    'unpacked count setter stays ambiguous' => [
        'acceptPerson(Person::factory()->count(...[2])->create());',
        'void',
        ['possibly-invalid-argument'],
    ],
    'generic factory reset' => ['return $generic->count(null)->create();', 'Person', []],
    'generic factory collection' => ['return $generic->count(2)->create();', 'Collection<int, Person>', []],
    'union model generic stays ambiguous' => [
        'acceptPerson($union->count(null)->create());',
        'void',
        ['possibly-invalid-argument'],
    ],
    'custom count override stays ambiguous' => [
        'acceptPerson(CustomCountFactory::new()->create());',
        'void',
        ['possibly-invalid-argument'],
    ],
];
$source = <<<'PHP'
    <?php
    use App\Models\{Person, Employee, Convention, Attributed, Configured, Resolved, CustomFactoryMethod, NoFactory};
    use Database\Factories\{PersonFactory, DeclaredFactory, CustomMakeFactory, CustomCountFactory, MutableFactory, ConfiguredFactory};
    use Illuminate\Database\Eloquent\Collection;
    use Illuminate\Database\Eloquent\Factories\Factory;
    function acceptPerson(Person $person): void {}

    PHP;
$cases['mutable factory stays ambiguous'] = [
    '$changed = MutableFactory::new(); $changed->mutate(); acceptPerson($changed->create());',
    'void',
    ['possibly-invalid-argument'],
];
$cases['custom configuration stays ambiguous'] = [
    'acceptPerson(ConfiguredFactory::new()->create());',
    'void',
    ['possibly-invalid-argument'],
];
$cases['explicit reset after configuration'] = [
    'return ConfiguredFactory::new()->count(null)->create();',
    'Person',
    [],
];
$lines = [];
foreach ($cases as $name => [$body, $return, $codes]) {
    $source .=
        '/**'
        ."\n"
        .' * @param Factory<Person> $generic'
        ."\n"
        .' * @param Factory<Person|Convention> $union'
        ."\n"
        .' * @return '
        .$return
        ."\n */\n";
    $source .=
        'function scenario'
        .count($lines)
        .'(?int $count, bool $flag, PersonFactory $factory, Factory $generic, Factory $union) { '
        .$body
        .' }'
        ."\n";
    $lines[substr_count($source, "\n")] = [$name, $codes];
}
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => ['paths' => ['cases.php'], 'includes' => ['framework.php', 'factories.php']],
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
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
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
    throw new RuntimeException('Expected negative diagnostics without extension fallback; inspect '.$workspace);
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
    throw new RuntimeException('Unexpected diagnostics outside factory scenarios; inspect '.$workspace);
}
// Delete only generated files inside this test's resolved workspace.
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
