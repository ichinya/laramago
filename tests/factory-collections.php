<?php

declare(strict_types=1);

// Native Mago integration: counted factories honor explicit model collection classes.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago factory collections '.bin2hex(random_bytes(8));
mkdir($workspace);
copy(__DIR__.'/fixtures/analysis/framework.php.stub', $workspace.'/framework.php');
copy(__DIR__.'/fixtures/analysis/factories.php.stub', $workspace.'/factories.php');
copy(__DIR__.'/fixtures/analysis/factory-collections.php.stub', $workspace.'/factory-collections.php');
$cases = [
    'unknown count retains custom union' => [
        'return CustomPerson::factory($count)->create();',
        'CustomPerson|People',
        [],
    ],
    'state retains custom collection' => ['return CustomPerson::factory(2)->state([])->make();', 'People', []],
    'count clone preserves original single' => [
        '$factory = CustomPerson::factory(); $factory->count(2); return $factory->make();',
        'CustomPerson',
        [],
    ],
    'custom count create' => ['return CustomPerson::factory(2)->create();', 'People', []],
    'custom zero make' => ['return CustomPerson::factory(0)->make();', 'People', []],
    'custom count quiet create' => ['return CustomPerson::factory(1)->createQuietly();', 'People', []],
    'custom single remains model' => ['return CustomPerson::factory()->create();', 'CustomPerson', []],
    'custom reset remains model' => ['return CustomPerson::factory(2)->count(null)->make();', 'CustomPerson', []],
    'custom create one remains model' => ['return CustomPerson::factory(2)->createOne();', 'CustomPerson', []],
    'custom collection method' => ['return CustomPerson::factory(2)->make()->names();', 'string', []],
    'explicit new collection return' => ['return MethodPerson::factory(2)->make()->names();', 'string', []],
    'create many uses base collection' => [
        'return CustomPerson::factory()->createMany(2);',
        'Collection<int, CustomPerson>',
        [],
    ],
    'make many uses base collection' => [
        'return CustomPerson::factory()->makeMany(2);',
        'Collection<int, CustomPerson>',
        [],
    ],
    'quiet many uses base collection' => [
        'return CustomPerson::factory()->createManyQuietly(2);',
        'Collection<int, CustomPerson>',
        [],
    ],
    'create many cannot claim custom collection' => [
        'CustomPerson::factory()->createMany(2)->names();',
        'void',
        ['non-existent-method'],
    ],
    'unknown count remains union' => [
        'acceptCustom(CustomPerson::factory($count)->create());',
        'void',
        ['possibly-invalid-argument'],
    ],
    'unknown collection stays unknown' => [
        'UnknownPerson::factory(2)->create()->names();',
        'void',
        ['non-documented-method', 'non-existent-method'],
    ],
    'unknown collection single remains model' => ['return UnknownPerson::factory()->make();', 'UnknownPerson', []],
    'single cannot claim collection' => [
        'CustomPerson::factory()->make()->names();',
        'void',
        ['non-documented-method'],
    ],
    'collection property is invalid' => [
        'CustomPerson::factory(2)->create()->id;',
        'void',
        ['non-existent-property', 'unused-statement'],
    ],
    'collection method argument checked' => [
        'CustomPerson::factory(2)->make()->names(42);',
        'void',
        ['invalid-argument'],
    ],
    'custom create override preserved' => ['return OverriddenFactory::new()->create();', 'int', []],
];
$source = <<<'PHP'
    <?php
    use App\Models\{CustomPerson, MethodPerson, UnknownPerson, People};
    use Database\Factories\{OverriddenFactory};
    use Illuminate\Database\Eloquent\Collection;
    function acceptCustom(CustomPerson $person): void {}

    PHP;
$lines = [];
foreach ($cases as $name => [$body, $return, $codes]) {
    $source .= '/** @return '.$return.' */'."\n";
    $source .= 'function scenario'.count($lines).'(?int $count) { '.$body.' }'."\n";
    $lines[substr_count($source, "\n")] = [$name, $codes];
}
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => ['paths' => ['cases.php'], 'includes' => ['framework.php', 'factories.php', 'factory-collections.php']],
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
