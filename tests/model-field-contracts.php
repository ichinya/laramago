<?php

declare(strict_types=1);

// Verify opt-in model field-list validation through the real SDK worker.
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
copy(__DIR__.'/fixtures/analysis/model-field-hides.php.stub', $framework.'/Concerns/HidesAttributes.php');
copy(__DIR__.'/fixtures/analysis/model-field-serialization.php.stub', $framework.'/Concerns/HasAttributes.php');
copy(
    __DIR__.'/fixtures/analysis/model-field-fillable-attribute.php.stub',
    $framework.'/Attributes/Fillable.php',
);
copy(
    __DIR__.'/fixtures/analysis/model-field-guarded-attribute.php.stub',
    $framework.'/Attributes/Guarded.php',
);
copy(
    __DIR__.'/fixtures/analysis/model-field-hidden-attribute.php.stub',
    $framework.'/Attributes/Hidden.php',
);
copy(
    __DIR__.'/fixtures/analysis/model-field-visible-attribute.php.stub',
    $framework.'/Attributes/Visible.php',
);
copy(
    __DIR__.'/fixtures/analysis/model-field-appends-attribute.php.stub',
    $framework.'/Attributes/Appends.php',
);
copy(
    __DIR__.'/fixtures/analysis/model-field-unguarded-attribute.php.stub',
    $framework.'/Attributes/Unguarded.php',
);
$complete = static fn (array $fields): array => ['complete' => true, 'fields' => $fields];
$serializable = static fn (array $fields, array $keys): array => [
    ...$complete($fields),
    'serialization' => ['complete' => true, 'keys' => $keys],
];
$appendable = static fn (array $keys): array => ['appends' => ['complete' => true, 'keys' => $keys]];
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
                'Example\\CompleteHiddenRecord' => $serializable(
                    ['known'],
                    ['userProfile', 'display_name'],
                ),
                'Example\\OpenHiddenRecord' => $complete([]),
                'Example\\DynamicHiddenRecord' => $serializable([], []),
                'Example\\MalformedSerializationRecord' => [
                    ...$complete([]),
                    'serialization' => ['complete' => true, 'keys' => [false]],
                ],
                'Example\\CompleteVisibleRecord' => $serializable(
                    ['known'],
                    ['userProfile', 'display_name'],
                ),
                'Example\\EmptyVisibleRecord' => $serializable([], []),
                'Example\\OpenVisibleRecord' => $complete([]),
                'Example\\DynamicVisibleRecord' => $serializable([], []),
                'Example\\CompleteAppendsRecord' => [
                    ...$serializable(['known', 'primitive_value'], [
                        'userProfile',
                        'legacy_value',
                        'modern_value',
                        'class_value',
                        'primitive_value',
                    ]),
                    ...$appendable(['legacy_value', 'modern_value', 'class_value']),
                ],
                'Example\\AppendsOnlyRecord' => $appendable(['legacy_value']),
                'Example\\IncompleteAppendsRecord' => [
                    ...$complete([]),
                    'appends' => ['complete' => false, 'keys' => []],
                ],
                'Example\\DynamicAppendsRecord' => [...$complete([]), ...$appendable([])],
                'Example\\MalformedAppendsRecord' => [
                    ...$complete([]),
                    'appends' => ['complete' => true, 'keys' => [false]],
                ],
                'Example\\CustomGetAppendsRecord' => [...$complete([]), ...$appendable([])],
                'Example\\CustomAppendMutationRecord' => [...$complete([]), ...$appendable([])],
                'Example\\DynamicRecord' => $complete(['known']),
                'Example\\UnpackedRecord' => $complete([]),
                'Example\\CustomConnectionRecord' => $complete(['remote_only']),
                'Example\\CustomGetFillableRecord' => $complete([]),
                'Example\\CustomGetGuardedRecord' => $complete([]),
                'Example\\CustomIsGuardedRecord' => $complete([]),
                'Example\\CustomIsFillableRecord' => $complete([]),
                'Example\\CustomGetHiddenRecord' => $serializable([], []),
                'Example\\CustomGetVisibleRecord' => $serializable([], []),
                'Example\\CustomToArrayRecord' => $serializable([], []),
                'Example\\CustomArrayableItemsRecord' => $serializable([], []),
                'Example\\CustomFillRecord' => $complete([]),
                'Example\\CustomMassAssignmentRecord' => $complete([]),
                'Example\\MalformedCatalogRecord' => ['complete' => true, 'fields' => [false]],
                'Example\\DuplicateCatalogRecord' => $complete([]),
                'example\\duplicatecatalogrecord' => $complete([]),
                'Example\\AttributeFillableRecord' => $complete(['known']),
                'Example\\AttributeVariadicFillableRecord' => $complete(['known']),
                'Example\\AttributeGuardedRecord' => $complete(['title']),
                'Example\\AttributeGuardedWildcardRecord' => $complete([]),
                'Example\\AttributeHiddenRecord' => $serializable(['known'], ['userProfile']),
                'Example\\AttributeVisibleRecord' => $serializable(['known'], ['display_name']),
                'Example\\AttributeAppendsRecord' => $appendable(['legacy_value']),
                'Example\\AttributeAndPropertyFillableRecord' => $complete([]),
                'Example\\AttributeAndPropertyGuardedRecord' => $complete([]),
                'Example\\AttributeNonDefaultGuardedRecord' => $complete([]),
                'Example\\AttributeUnguardedRecord' => $complete([]),
                'Example\\AttributeGuardedChildRecord' => $complete([]),
                'Example\\AttributeUnguardedChildRecord' => $complete([]),
                'Example\\AttributeArrayFirstRecord' => $complete(['known']),
                'Example\\AttributeDynamicFirstRecord' => $complete(['known']),
                'Example\\AttributeKeyedArrayRecord' => $complete([]),
                'Example\\AttributeCustomConstructorRecord' => $complete([]),
                'Example\\AttributeCustomInitializerRecord' => $serializable([], []),
                'Example\\AttributeCustomResolverRecord' => $appendable([]),
                'Example\\AttributeCustomSetAppendsRecord' => $appendable([]),
                'Example\\CustomAttributeRecord' => $complete([]),
                'Example\\AttributeChildRecord' => $serializable([], []),
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
            'vendor/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HidesAttributes.php',
            'vendor/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasAttributes.php',
            'vendor/laravel/framework/src/Illuminate/Database/Eloquent/Attributes/Fillable.php',
            'vendor/laravel/framework/src/Illuminate/Database/Eloquent/Attributes/Guarded.php',
            'vendor/laravel/framework/src/Illuminate/Database/Eloquent/Attributes/Hidden.php',
            'vendor/laravel/framework/src/Illuminate/Database/Eloquent/Attributes/Visible.php',
            'vendor/laravel/framework/src/Illuminate/Database/Eloquent/Attributes/Appends.php',
            'vendor/laravel/framework/src/Illuminate/Database/Eloquent/Attributes/Unguarded.php',
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
        || str_contains($line, '// hidden-output-key')
        || str_contains($line, '// hidden-missing')
        || str_contains($line, '// visible-output-key')
        || str_contains($line, '// visible-case-mismatch')
        || str_contains($line, '// visible-missing')
        || str_contains($line, '// appends-')
        || str_contains($line, '// attribute-')
    ) {
        $expected[$offset + 1] = [
            match (true) {
                str_contains($line, '// appends-'),
                str_contains($line, '// attribute-appends-'),
                    => 'ichinya/laramago/laramago-missing-model-appendable-key',
                str_contains($line, '// hidden-'),
                str_contains($line, '// visible-'),
                str_contains($line, '// attribute-hidden-'),
                str_contains($line, '// attribute-visible-'),
                    => 'ichinya/laramago/laramago-missing-model-serialization-key',
                default => 'ichinya/laramago/laramago-missing-model-field',
            },
        ];
    }
}
ksort($actual);
ksort($expected);
if ($actual !== $expected) {
    throw new RuntimeException(
        'Expected only proven missing model field-list names: '
        .json_encode($expected)
        .', got '
        .json_encode($actual)
        .'; inspect '
        .$workspace,
    );
}
echo "PASS: complete catalogs validate safe literal property and attribute field-list names\n";

copy(
    __DIR__.'/fixtures/analysis/model-field-guards-legacy.php.stub',
    $framework.'/Concerns/GuardsAttributes.php',
);
$legacyReport = $run($command, $workspace, $workspace.'/legacy.json', $workspace.'/legacy.log');
$legacyActual = [];
foreach ($legacyReport['issues'] ?? [] as $issue) {
    $primary = array_values(array_filter(
        $issue['annotations'],
        static fn (array $annotation): bool => $annotation['kind'] === 'Primary',
    ))[0];
    $legacyActual[$primary['span']['start']['line'] + 1][] = $issue['code'];
}
$legacyExpected = $expected;
foreach (explode("\n", $fixture) as $offset => $line) {
    if (
        str_contains($line, '// attribute-fillable-')
        || str_contains($line, '// attribute-guarded-missing')
        || str_contains($line, '// attribute-combined-fillable-')
    ) {
        unset($legacyExpected[$offset + 1]);
    }
}
ksort($legacyActual);
ksort($legacyExpected);
if ($legacyActual !== $legacyExpected) {
    throw new RuntimeException(
        'Expected an older framework without the native guard initializer to retain property diagnostics only: '
        .json_encode($legacyExpected)
        .', got '
        .json_encode($legacyActual)
        .'; inspect '
        .$workspace,
    );
}
echo "PASS: unsupported framework attribute lifecycle defers while property diagnostics remain\n";

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
