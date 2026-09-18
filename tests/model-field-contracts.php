<?php

declare(strict_types=1);

// Verify opt-in fillable validation through the real SDK worker.
$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago model fields '.bin2hex(random_bytes(8));
mkdir($workspace);
$fixture = file_get_contents(__DIR__.'/fixtures/analysis/model-field-contracts.php.stub');
if ($fixture === false) {
    throw new RuntimeException('Cannot read model field fixture.');
}
file_put_contents($workspace.'/models.php', $fixture);
$framework = $workspace.'/vendor/laravel/framework/src/Illuminate/Database/Eloquent';
mkdir($framework.'/Concerns', recursive: true);
mkdir($framework.'/Attributes', recursive: true);
copy(__DIR__.'/fixtures/analysis/model-field-model.php.stub', $framework.'/Model.php');
copy(__DIR__.'/fixtures/analysis/model-field-guards.php.stub', $framework.'/Concerns/GuardsAttributes.php');
copy(
    __DIR__.'/fixtures/analysis/model-field-fillable-attribute.php.stub',
    $framework.'/Attributes/Fillable.php',
);
$complete = static fn (array $fields): array => ['complete' => true, 'fields' => $fields];
file_put_contents($workspace.'/composer.json', json_encode([
    'extra' => [
        'laramago' => [
            'model-fields' => [
                'Example\\CompleteRecord' => $complete([
                    'known',
                    'profile->enabled',
                    'nullable_value',
                    'virtual',
                ]),
                'Example\\CompleteGuardedRecord' => $complete(['known', 'title']),
                'Example\\GuardedWildcardRecord' => $complete([]),
                'Example\\GuardedMixedWildcardRecord' => $complete([]),
                'Example\\DynamicGuardedRecord' => $complete([]),
                'Example\\DynamicRecord' => $complete(['known']),
                'Example\\UnpackedRecord' => $complete([]),
                'Example\\CustomConnectionRecord' => $complete(['remote_only']),
                'Example\\CustomGetFillableRecord' => $complete([]),
                'Example\\CustomGetGuardedRecord' => $complete([]),
                'Example\\CustomIsGuardedRecord' => $complete([]),
                'Example\\CustomIsFillableRecord' => $complete([]),
                'Example\\CustomFillRecord' => $complete([]),
                'Example\\CustomMassAssignmentRecord' => $complete([]),
                'Example\\MalformedCatalogRecord' => ['complete' => true, 'fields' => [false]],
                'Example\\DuplicateCatalogRecord' => $complete([]),
                'example\\duplicatecatalogrecord' => $complete([]),
                'Example\\AttributeRecord' => $complete([]),
            ],
        ],
    ],
], JSON_THROW_ON_ERROR));
file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => [
        'paths' => ['models.php'],
        'includes' => [
            'vendor/laravel/framework/src/Illuminate/Database/Eloquent/Model.php',
            'vendor/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/GuardsAttributes.php',
            'vendor/laravel/framework/src/Illuminate/Database/Eloquent/Attributes/Fillable.php',
        ],
    ],
    'extension-hosts' => [
        'laramago' => [
            'command' => [
                PHP_BINARY,
                $package.'/bin/laramago-worker.php',
                $package.'/vendor/autoload.php',
                $workspace,
            ],
            'workers' => 2,
        ],
    ],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

$run = static function (array $command, string $workspace, string $output, string $log): array {
    $process = proc_open(
        [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [0 => ['pipe', 'r'], 1 => ['file', $output, 'w'], 2 => ['file', $log, 'w']],
        $pipes,
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start Mago.');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $stderr = file_get_contents($log);
    if (
        $exit !== 0
        || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $stderr)
    ) {
        throw new RuntimeException('Expected successful analysis; inspect '.$workspace);
    }

    return json_decode(file_get_contents($output), true, flags: JSON_THROW_ON_ERROR);
};

$report = $run($command, $workspace, $workspace.'/report.json', $workspace.'/stderr.log');
$actual = [];
foreach ($report['issues'] ?? [] as $issue) {
    $primary = array_values(array_filter(
        $issue['annotations'],
        static fn (array $annotation): bool => $annotation['kind'] === 'Primary',
    ))[0];
    $actual[$primary['span']['start']['line'] + 1][] = $issue['code'];
}
$expected = [];
foreach (explode("\n", $fixture) as $offset => $line) {
    if (
        str_contains($line, '// complete-missing')
        || str_contains($line, '// literal-missing')
        || str_contains($line, '// guarded-missing')
        || str_contains($line, '// guarded-mixed-missing')
    ) {
        $expected[$offset + 1] = ['ichinya/laramago/laramago-missing-model-field'];
    }
}
if ($actual !== $expected) {
    throw new RuntimeException(
        'Expected only proven missing fillable names: '
        .json_encode($expected)
        .', got '
        .json_encode($actual)
        .'; inspect '
        .$workspace,
    );
}
echo "PASS: complete catalogs validate only safe literal fillable and guarded names\n";

$nativeConfig = json_decode(file_get_contents($workspace.'/mago.json'), true, flags: JSON_THROW_ON_ERROR);
unset($nativeConfig['extension-hosts']);
file_put_contents($workspace.'/mago.json', json_encode($nativeConfig, JSON_THROW_ON_ERROR));
$native = $run($command, $workspace, $workspace.'/native.json', $workspace.'/native.log');
if (($native['issues'] ?? []) !== []) {
    throw new RuntimeException('Expected no native diagnostics; inspect '.$workspace);
}
echo "PASS: provider-disabled comparison has no diagnostics\n";

$resolvedWorkspace = realpath($workspace);
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
);
foreach ($files as $file) {
    $resolvedFile = $file->getRealPath();
    if (
        $resolvedWorkspace === false
        || $resolvedFile === false
        || ! str_starts_with($resolvedFile, $resolvedWorkspace.DIRECTORY_SEPARATOR)
    ) {
        throw new RuntimeException('Refusing cleanup outside the test workspace.');
    }
    $file->isDir() ? rmdir($resolvedFile) : unlink($resolvedFile);
}
rmdir($workspace);
