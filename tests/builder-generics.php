<?php

declare(strict_types=1);

// Check custom builder entry points through the real SDK worker.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago builder generics '.bin2hex(random_bytes(8));
mkdir($workspace);
copy(__DIR__.'/fixtures/analysis/framework.php.stub', $workspace.'/framework.php');
copy(__DIR__.'/fixtures/analysis/builder-generics.php.stub', $workspace.'/models.php');
$cases = [
    'reference parameter cannot cross model dispatch' => [
        'GenericForwardRecord::reference(new GenericForwardRecord);',
        'void',
        ['non-documented-method'],
    ],
    'reference return cannot cross model dispatch' => [
        'GenericForwardRecord::referenceResult();',
        'void',
        ['non-documented-method'],
    ],
    'invalid factory template constraint remains unknown' => [
        'InvalidGenericForwardRecord::label();',
        'void',
        ['non-documented-method'],
    ],
    'native generic builder override wins' => ['return GenericForwardRecord::create();', 'string', []],
    'nested generic object return' => ['return GenericForwardRecord::copy()->record();', 'GenericForwardRecord', []],
    'unsupported template container stays unknown' => [
        'GenericForwardRecord::unsupportedCallback(fn () => true);',
        'void',
        ['non-documented-method'],
    ],
    'direct class template return' => ['return GenericForwardRecord::record();', 'GenericForwardRecord', []],
    'reordered scalar template return' => ['return GenericForwardRecord::label();', 'string', []],
    'instance class template return' => ['return (new GenericForwardRecord)->record();', 'GenericForwardRecord', []],
    'fixed factory inheritance' => ['return ChildForwardRecord::record();', 'GenericForwardRecord', []],
    'generic fluent builder' => [
        'return GenericForwardRecord::active();',
        'ForwardedGenericBuilder<string, GenericForwardRecord>',
        [],
    ],
    'generic fluent chain' => ['return GenericForwardRecord::active()->record();', 'GenericForwardRecord', []],
    'generic parameter' => ['GenericForwardRecord::accept(new GenericForwardRecord);', 'void', []],
    'named arguments reorder' => [
        'GenericForwardRecord::accept(enabled: true, record: new GenericForwardRecord);',
        'void',
        [],
    ],
    'invalid generic parameter' => [
        'GenericForwardRecord::accept(new OtherForwardRecord);',
        'void',
        ['invalid-argument'],
    ],
    'invalid named generic parameter' => [
        'GenericForwardRecord::accept(record: new OtherForwardRecord);',
        'void',
        ['invalid-argument'],
    ],
    'invalid native parameter' => [
        'GenericForwardRecord::accept(new GenericForwardRecord, "bad");',
        'void',
        ['invalid-argument'],
    ],
    'wrong generic return' => [
        'return GenericForwardRecord::record();',
        'OtherForwardRecord',
        ['invalid-return-statement'],
    ],
    'wrong scalar return' => ['return GenericForwardRecord::label();', 'int', ['invalid-return-statement']],
    'nested list return' => ['return GenericForwardRecord::records();', 'list<GenericForwardRecord>', []],
    'nested list parameter' => ['GenericForwardRecord::acceptMany([new GenericForwardRecord]);', 'void', []],
    'wrong nested list parameter' => [
        'GenericForwardRecord::acceptMany([new OtherForwardRecord]);',
        'void',
        ['possibly-invalid-argument'],
    ],
    'nested shape return' => [
        'return GenericForwardRecord::payload();',
        'array{record: GenericForwardRecord, label: string}',
        [],
    ],
    'nullable template return' => ['return GenericForwardRecord::optional();', '?GenericForwardRecord', []],
    'variadic template parameter' => [
        'GenericForwardRecord::acceptVariadic(new GenericForwardRecord, new GenericForwardRecord);',
        'void',
        [],
    ],
    'invalid variadic parameter' => [
        'GenericForwardRecord::acceptVariadic(new GenericForwardRecord, new OtherForwardRecord);',
        'void',
        ['invalid-argument'],
    ],
    'late static factory argument' => ['return LateForwardRecord::record();', 'LateForwardRecord', []],
    'late static factory inherited argument' => [
        'return ChildLateForwardRecord::record();',
        'ChildLateForwardRecord',
        [],
    ],
    'late static query argument' => ['return ChildLateForwardRecord::query()->record();', 'ChildLateForwardRecord', []],
    'late static argument validation' => [
        'ChildLateForwardRecord::accept(new LateForwardRecord);',
        'void',
        ['less-specific-argument'],
    ],
    'native model declaration wins' => ['return NativeGenericForwardRecord::record();', 'int', []],
    'documented model declaration wins' => ['return DocumentedGenericForwardRecord::record();', 'int', []],
    'custom dispatch remains unknown' => ['DynamicGenericForwardRecord::record();', 'void', ['non-documented-method']],
    'unbound selector remains unknown' => ['UnboundGenericForwardRecord::record();', 'void', ['non-documented-method']],
    'ancestor template remapping stays unknown' => [
        'InheritedGenericForwardRecord::record();',
        'void',
        ['non-documented-method'],
    ],
    'method templates stay unknown' => ['GenericForwardRecord::identity(1);', 'void', ['non-documented-method']],
    'unknown named argument' => [
        'GenericForwardRecord::accept(new GenericForwardRecord, typo: true);',
        'void',
        ['invalid-named-argument'],
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
$configuration = json_decode(file_get_contents($workspace.'/mago.json'), true, flags: JSON_THROW_ON_ERROR);
unset($configuration['extension-hosts']);
file_put_contents($workspace.'/mago.json', json_encode($configuration, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
file_put_contents(
    $workspace.'/cases.php',
    '<?php function disabledControl(): void { GenericForwardRecord::record(); }',
);
$disabled = proc_open(
    [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
    [
        0 => ['pipe', 'r'],
        1 => ['file', $workspace.'/disabled.json', 'w'],
        2 => ['file', $workspace.'/disabled.log', 'w'],
    ],
    $pipes,
);
if (! is_resource($disabled)) {
    throw new RuntimeException('Cannot start disabled-provider control.');
}
fclose($pipes[0]);
if (! in_array(proc_close($disabled), [0, 1], true)) {
    throw new RuntimeException('Expected native diagnostics with providers disabled; inspect '.$workspace);
}
$baseline = json_decode(file_get_contents($workspace.'/disabled.json'), true, flags: JSON_THROW_ON_ERROR);
if (array_column($baseline['issues'] ?? [], 'code') !== ['non-documented-method']) {
    throw new RuntimeException('Disabled-provider control must expose the unknown method; inspect '.$workspace);
}
echo 'PASS: disabled provider preserves native unknown method'."\n";
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
