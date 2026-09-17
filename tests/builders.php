<?php

declare(strict_types=1);

// Check custom builder entry points through the real SDK worker.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago builders '.bin2hex(random_bytes(8));
mkdir($workspace);
copy(__DIR__.'/fixtures/analysis/framework.php.stub', $workspace.'/framework.php');
copy(__DIR__.'/fixtures/analysis/builders.php.stub', $workspace.'/models.php');
$cases = [
    'builder property' => ['return BuilderRecord::query();', 'RecordBuilder', []],
    'builder custom method' => ['return BuilderRecord::query()->active()->label();', 'string', []],
    'builder native model result' => ['return BuilderRecord::query()->active()->firstOrFail();', 'BuilderRecord', []],
    'builder inherited property' => ['return ChildBuilderRecord::query();', 'RecordBuilder', []],
    'builder attribute' => ['return AttributeBuilderRecord::query();', 'RecordBuilder', []],
    'builder named attribute' => ['return NamedAttributeBuilderRecord::query();', 'RecordBuilder', []],
    'builder inherited attribute' => ['return ChildAttributeBuilderRecord::query();', 'RecordBuilder', []],
    'builder factory' => ['return FactoryBuilderRecord::query();', 'RecordBuilder', []],
    'builder inherited factory' => ['return ChildFactoryBuilderRecord::query();', 'RecordBuilder', []],
    'builder nearest attribute' => ['return OverrideAttributeBuilderRecord::query()->alternate();', 'int', []],
    'builder factory priority' => ['return FactoryPriorityRecord::query();', 'RecordBuilder', []],
    'instance new query' => ['return (new BuilderRecord)->newQuery();', 'RecordBuilder', []],
    'instance model query' => ['return (new BuilderRecord)->newModelQuery();', 'RecordBuilder', []],
    'instance query without scopes' => ['return (new BuilderRecord)->newQueryWithoutScopes();', 'RecordBuilder', []],
    'instance query without relationships' => [
        'return (new BuilderRecord)->newQueryWithoutRelationships();',
        'RecordBuilder',
        [],
    ],
    'native parameter validation' => ['BuilderRecord::query(1);', 'void', ['too-many-arguments']],
    'custom argument validation' => ['BuilderRecord::query()->active("bad");', 'void', ['invalid-argument']],
    'custom method typo' => ['BuilderRecord::query()->lable();', 'void', ['non-documented-method']],
    'generic builder stays native' => ['return GenericBuilderRecord::query();', 'Builder<GenericBuilderRecord>', []],
    'invalid builder stays native' => ['return InvalidBuilderRecord::query();', 'Builder<InvalidBuilderRecord>', []],
    'unknown factory stays native' => ['return UnknownFactoryRecord::query();', 'Builder<UnknownFactoryRecord>', []],
    'custom resolver stays native' => ['return CustomResolverRecord::query();', 'Builder<CustomResolverRecord>', []],
    'declared query wins' => ['return DeclaredQueryRecord::query();', 'AlternateBuilder', []],
    'custom construction stays native' => [
        'return CustomConstructionRecord::query();',
        'Builder<CustomConstructionRecord>',
        [],
    ],
    'first class query' => ['return BuilderRecord::query(...);', 'Closure', []],
];
$source = <<<'PHP'
    <?php
    use Illuminate\Database\Eloquent\Builder;
    PHP;
$lines = [];
foreach ($cases as $name => [$body, $return, $codes]) {
    $source .= '/** @return '.$return.' */'."\n";
    $source .= 'function scenario'.count($lines).'() { '.$body.' }'."\n";
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
    throw new RuntimeException('Unexpected diagnostics outside builder scenarios; inspect '.$workspace);
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
