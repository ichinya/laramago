<?php

declare(strict_types=1);

// Check custom builder entry points through the real SDK worker.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago generics '.bin2hex(random_bytes(8));
mkdir($workspace);
copy(__DIR__.'/fixtures/analysis/framework.php.stub', $workspace.'/framework.php');
copy(__DIR__.'/fixtures/analysis/generics.php.stub', $workspace.'/models.php');
$cases = [
    'reordered builder model parameter' => ['return ReorderedRecord::query()->record();', 'ReorderedRecord', []],
    'reordered builder scalar parameter' => ['return ReorderedRecord::query()->label();', 'string', []],
    'reordered inherited return' => ['return ReorderedRecord::query()->firstOrFail();', 'ReorderedRecord', []],
    'unknown generic argument stays native' => [
        'return UnknownArgumentRecord::query();',
        'Builder<UnknownArgumentRecord>',
        [],
    ],
    'unbound collection stays unknown' => ['UnboundCollectionRecord::get();', 'void', ['non-documented-method']],
    'explicit generic builder' => ['return GenericRecord::query();', 'TypedBuilder<GenericRecord>', []],
    'builder template result' => ['return GenericRecord::query()->firstOrFail();', 'GenericRecord', []],
    'builder custom template result' => ['return GenericRecord::query()->record();', 'GenericRecord', []],
    'builder wrong model return' => [
        'return GenericRecord::query()->record();',
        'OtherRecord',
        ['invalid-return-statement'],
    ],
    'builder typo' => ['GenericRecord::query()->recrod();', 'void', ['non-documented-method']],
    'builder concrete parameter' => ['GenericRecord::query()->accept(new OtherRecord);', 'void', ['invalid-argument']],
    'explicit generic collection' => ['return CollectionRecord::get();', 'TypedCollection<int, CollectionRecord>', []],
    'collection template result' => ['return CollectionRecord::get()->record();', 'CollectionRecord', []],
    'collection wrong model return' => [
        'return CollectionRecord::get()->record();',
        'OtherRecord',
        ['invalid-return-statement'],
    ],
    'collection typo' => ['CollectionRecord::get()->recrod();', 'void', ['non-existent-method']],
    'inherited fixed builder contract' => ['return ChildGenericRecord::query()->record();', 'GenericRecord', []],
    'inherited fixed collection contract' => ['return ChildCollectionRecord::get()->record();', 'CollectionRecord', []],
    'unbound builder stays native' => ['return UnboundRecord::query();', 'Builder<UnboundRecord>', []],
    'attribute without generic contract stays native' => [
        'return PropertyRecord::query();',
        'Builder<PropertyRecord>',
        [],
    ],
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
