<?php

declare(strict_types=1);

// Check custom builder entry points through the real SDK worker.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago builder forwarding '.bin2hex(random_bytes(8));
mkdir($workspace);
copy(__DIR__.'/fixtures/analysis/framework.php.stub', $workspace.'/framework.php');
copy(__DIR__.'/fixtures/analysis/builder-forwarding.php.stub', $workspace.'/models.php');
$cases = [
    'native override outranks inherited return documentation' => [
        'return NativeOverrideRecord::create();',
        'string',
        [],
    ],
    'declared builder method precedes local scope' => ['return ScopedForwardingRecord::active();', 'RecordBuilder', []],
    'static scalar method' => ['return BuilderRecord::label();', 'string', []],
    'static fluent builder' => ['return BuilderRecord::active();', 'RecordBuilder', []],
    'static fluent chain' => ['return BuilderRecord::active()->label();', 'string', []],
    'static model result chain' => ['return BuilderRecord::active()->firstOrFail();', 'BuilderRecord', []],
    'instance forwarding' => ['return (new BuilderRecord)->label();', 'string', []],
    'inherited builder' => ['return ChildBuilderRecord::active();', 'RecordBuilder', []],
    'attribute builder' => ['return AttributeBuilderRecord::active();', 'RecordBuilder', []],
    'factory builder' => ['return FactoryBuilderRecord::active();', 'RecordBuilder', []],
    'named argument' => ['return BuilderRecord::active(enabled: true);', 'RecordBuilder', []],
    'wrong argument' => ['BuilderRecord::active("bad");', 'void', ['invalid-argument']],
    'extra argument' => ['BuilderRecord::label(1);', 'void', ['too-many-arguments']],
    'typo remains' => ['BuilderRecord::lable();', 'void', ['non-documented-method']],
    'wrong return remains' => ['return BuilderRecord::label();', 'int', ['invalid-return-statement']],
    'unknown builder remains' => ['UnknownFactoryRecord::label();', 'void', ['non-documented-method']],
    'custom resolver remains' => ['CustomResolverRecord::label();', 'void', ['non-documented-method']],
    'native model wins' => ['return NativeForwardingRecord::label();', 'int', []],
    'documented model wins' => ['return DocumentedForwardingRecord::label();', 'int', []],
    'custom model dispatch remains' => ['CustomForwardingRecord::label();', 'void', ['non-documented-method']],
    'private method remains' => ['SecureForwardingRecord::secret();', 'void', ['non-documented-method']],
    'protected method remains' => ['SecureForwardingRecord::internal();', 'void', ['non-documented-method']],
    'inherited builder fluent' => ['return SecureForwardingRecord::active();', 'SecureRecordBuilder', []],
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
