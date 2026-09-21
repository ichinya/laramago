<?php

declare(strict_types=1);

/** Exercise the actual Composer bin proxy with an offline, custom-vendor install. */
$package = dirname(__DIR__);
$fixture = str_replace('\\', '/', sys_get_temp_dir()).'/laramago metadata cli '.bin2hex(random_bytes(8));
mkdir($fixture.'/config', 0777, true);
file_put_contents($fixture.'/autoload-trap.php', '<?php file_put_contents(__DIR__."/autoload-executed", "yes");');
file_put_contents($fixture.'/config/app.php', <<<'PHP'
    <?php
    return [
        'name' => 'private-value-must-not-export',
        'greeting' => 'private-value-must-not-export :person',
        'nested' => ['leaf' => env('APP_LEAF')],
        'side_effect' => file_put_contents(__DIR__.'/../config-executed', 'yes'),
        'literal.dot' => 1,
    ];
    PHP);
file_put_contents($fixture.'/config/broken.php', '<?php return ["private-parse-value" => ;');
file_put_contents($fixture.'/config/123.php', "<?php return ['label' => 'numeric-file'];");
file_put_contents($fixture.'/composer.json', json_encode(
    [
        'name' => 'fixture/offline-app',
        'require' => ['ichinya/laramago' => '^1.0'],
        'replace' => ['carthage-software/mago' => '*', 'doctrine/inflector' => '*'],
        'autoload' => ['files' => ['autoload-trap.php']],
        'repositories' => [
            [
                'type' => 'path',
                'url' => $package,
                'options' => ['symlink' => false, 'versions' => ['ichinya/laramago' => '1.0.0']],
            ],
            [
                'type' => 'path',
                'url' => $package.'/vendor/nikic/php-parser',
                'options' => ['symlink' => false, 'versions' => ['nikic/php-parser' => '5.8.0']],
            ],
        ],
        'config' => ['vendor-dir' => 'custom vendor'],
    ],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
));

/** @param list<string> $command @return array{int, string, string} */
$run = static function (array $command, string $directory): array {
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory);
    if (! is_resource($process)) {
        throw new RuntimeException('Unable to start metadata CLI fixture process.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $stdout, $stderr];
};

$composer = ['composer'];
if (PHP_OS_FAMILY === 'Windows') {
    exec('where.exe composer', $composerPaths, $whereExit);
    $composerPhar = $whereExit === 0 && isset($composerPaths[0])
        ? dirname(trim($composerPaths[0])).'/composer.phar'
        : '';
    if (! is_file($composerPhar)) {
        throw new RuntimeException('Composer PHAR is required for the Windows CLI fixture.');
    }
    $composer = [PHP_BINARY, $composerPhar];
}
[$exit, , $stderr] = $run([
    ...$composer,
    'install',
    '--no-scripts',
    '--no-plugins',
    '--no-interaction',
    '--no-progress',
], $fixture);
if ($exit !== 0) {
    throw new RuntimeException('Offline Composer fixture install failed: '.$stderr);
}

$bin = $fixture.'/custom vendor/bin/laramago-metadata';
if (! is_file($bin)) {
    throw new RuntimeException('Composer did not create the metadata bin proxy.');
}
$command = [PHP_BINARY, '-d', 'opcache.enable_cli=0', $bin, '--project-root', $fixture];
[$exit, $stdout, $stderr] = $run([
    ...$command,
    '--config-key',
    'app.nested.leaf',
    '--config-key',
    'app.absent',
    '--config-key',
    'broken.any',
], $fixture);
if ($exit !== 0) {
    throw new RuntimeException('Metadata CLI failed: '.$stderr);
}
$data = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
$requests = array_column($data['requests'], null, 'key');
if (
    $data['schemaVersion'] !== 1
    || $data['scope'] !== ['kind' => 'configuration', 'evidence' => 'source-only']
    || $requests['app.nested.leaf']['confidence'] !== 'known-positive'
    || $requests['app.nested.leaf']['declaration']['name'] !== 'leaf'
    || $requests['app.absent']['confidence'] !== 'complete-absent'
    || $requests['broken.any']['confidence'] !== 'unknown'
    || count($data['errors']) !== 1
    || $data['errors'][0]['code'] !== 'parse-failure'
    || str_contains($stdout, 'private-value-must-not-export')
    || str_contains($stdout, 'private-parse-value')
    || is_file($fixture.'/autoload-executed')
    || is_file($fixture.'/config-executed')
) {
    throw new RuntimeException('Metadata export violated its source-only contract or confidence schema.');
}

$leaf = $requests['app.nested.leaf']['declaration'];
$source = file_get_contents($fixture.'/config/app.php');
if (
    substr($source, $leaf['start'], $leaf['end'] - $leaf['start']) !== "'leaf'"
    || $leaf['contentHash'] !== hash('sha256', $source)
    || str_replace('\\', '/', $leaf['file']) !== str_replace('\\', '/', realpath($fixture.'/config/app.php'))
) {
    throw new RuntimeException('Exported declaration does not match the parsed source span.');
}

[$exit, $stdout, $stderr] = $run($command, $fixture);
if ($exit !== 0) {
    throw new RuntimeException('Unfiltered metadata export failed: '.$stderr);
}
$all = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
$catalogs = array_column($all['catalogs'], null, 'arrayKey');
if (
    $all['truncated']
    || ! isset($catalogs['app'], $catalogs['app.nested'])
    || $catalogs['app']['sourceComplete'] !== true
    || $catalogs[123]['arrayKey'] !== '123'
    || $catalogs['broken']['sourceComplete'] !== null
    || $all['errors'][0]['code'] !== 'parse-failure'
) {
    throw new RuntimeException('Unfiltered catalog traversal lost source metadata.');
}

[$exit, $stdout, $stderr] = $run([...$command, '--config-array', '123', '--config-key', '123.label'], $fixture);
$numeric = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
if (
    $exit !== 0
    || $numeric['catalogs'][0]['arrayKey'] !== '123'
    || $numeric['requests'][0]['confidence'] !== 'known-positive'
) {
    throw new RuntimeException('Numeric configuration filename lost its string namespace: '.$stderr);
}

$output = $fixture.'/metadata output.json';
[$exit, $stdout, $stderr] = $run([...$command, '--config-array', 'app.nested', '--output', $output], $fixture);
if ($exit !== 0 || $stdout !== '' || ! is_file($output)) {
    throw new RuntimeException('Explicit JSON output failed: '.$stderr);
}
$selected = json_decode(file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
if (count($selected['catalogs']) !== 1 || $selected['catalogs'][0]['arrayKey'] !== 'app.nested') {
    throw new RuntimeException('Filtered catalog export included unrequested arrays.');
}
[$exit, $stdout, $stderr] = $run([
    ...$command,
    '--config-array',
    'app',
    '--output',
    $fixture.'/missing/output.json',
], $fixture);
if ($exit !== 2 || $stdout !== '' || ! str_contains($stderr, 'Unable to write the requested output file.')) {
    throw new RuntimeException('Output write failure was not reported cleanly.');
}

$linkedPackage = $fixture.'/external metadata package';
mkdir($linkedPackage.'/bin', 0777, true);
mkdir($linkedPackage.'/src/Analyzer/StaticAnalysis', 0777, true);
mkdir($linkedPackage.'/src/Metadata', 0777, true);
copy($package.'/bin/laramago-metadata', $linkedPackage.'/bin/laramago-metadata');
foreach ([
    'RouteMetadataExport',
    'ControllerRouteContractExport',
    'PolicyClassSelectorCallExport',
    'PolicyModelArgumentContractExport',
    'MiddlewareParameterMetadataExport',
    'RouteNameDuplicateCandidates',
    'RouteParameterMetadataExport',
    'TranslationMetadataExport',
    'TranslationPlaceholderExport',
    'EnvironmentTemplateReferences',
    'EnvironmentTemplateDuplicates',
    'JsonTranslationMetadataExport',
    'ViteEnvironmentReferences',
] as $class) {
    copy($package.'/src/Metadata/'.$class.'.php', $linkedPackage.'/src/Metadata/'.$class.'.php');
}
foreach ([
    'ConfigurationIndex',
    'ConfigurationDeclaration',
    'ConfigurationDeclarationCatalog',
    'ConfigurationKeyCatalog',
    'MetadataConfidence',
    'PhpSource',
    'StaticMetadataExport',
    'UnknownValue',
] as $class) {
    copy(
        $package.'/src/Analyzer/StaticAnalysis/'.$class.'.php',
        $linkedPackage.'/src/Analyzer/StaticAnalysis/'.$class.'.php',
    );
}
$linkedProxy = $fixture.'/linked-proxy.php';
file_put_contents($linkedProxy, <<<'PHP'
    <?php
    $_composer_autoload_path = __DIR__.'/custom vendor/autoload.php';
    require __DIR__.'/external metadata package/bin/laramago-metadata';
    PHP);
[$exit, $stdout, $stderr] = $run([
    PHP_BINARY,
    '-d',
    'opcache.enable_cli=0',
    $linkedProxy,
    '--project-root',
    $fixture,
    '--config-key',
    'app.name',
], $fixture);
if (
    $exit !== 0
    || json_decode($stdout, true, 512, JSON_THROW_ON_ERROR)['requests'][0]['confidence'] !== 'known-positive'
) {
    throw new RuntimeException('Composer proxy dependency hint did not support an external package path: '.$stderr);
}

file_put_contents($fixture.'/routes.php', <<<'PHP'
    <?php
    use Illuminate\Support\Facades\Route;
    Route::get('/example', 'ExampleController')->name('example');
    Route::get('/other', 'ExampleController')->name('example');
    PHP);
file_put_contents(
    $fixture.'/.env.example',
    'SOURCE=private-value-must-not-export'."\n".'TARGET="${SOURCE}"'."\n".'SOURCE=PRIVATE_TEMPLATE_VALUE',
);
file_put_contents($fixture.'/translations.json', '{"Greeting":"private-value-must-not-export"}');
file_put_contents($fixture.'/app.js', 'const name = import.meta.env.VITE_APP_NAME;');
file_put_contents($fixture.'/controllers.php', <<<'PHP'
    <?php
    use Illuminate\Support\Facades\Route;
    (new Illuminate\Pipeline\Pipeline)->through('auth:admin,editor');
    Illuminate\Support\Facades\Gate::allows('view', [ExampleController::class, 42]);
    class ExamplePolicy { public function view(object $user, ExampleController $model): bool { return true; } }
    class ExampleAuthProvider extends Illuminate\Foundation\Support\Providers\AuthServiceProvider {
        protected $policies = [ExampleController::class => ExamplePolicy::class];
    }
    class ExampleController { public function show(string $id): void {} }
    Route::get('/{id}', [ExampleController::class, 'show']);
    PHP);
foreach ([$bin, $linkedProxy] as $entrypoint) {
    foreach ([
        'routes' => 'routes.php',
        'controller-route-contract-candidates' => 'controllers.php',
        'middleware-parameters' => 'controllers.php',
        'policy-class-selector-call-candidates' => 'controllers.php',
        'policy-model-argument-contract-candidates' => 'controllers.php',
        'route-name-duplicates' => 'routes.php',
        'route-parameters' => 'routes.php',
        'translations' => 'config/app.php',
        'translation-placeholders' => 'config/app.php',
        'environment-references' => '.env.example',
        'environment-duplicates' => '.env.example',
        'translations-json' => 'translations.json',
        'vite-environment-references' => 'app.js',
    ] as $kind => $file) {
        [$exit, $stdout, $stderr] = $run([
            PHP_BINARY,
            '-d',
            'opcache.enable_cli=0',
            $entrypoint,
            '--project-root',
            $fixture,
            '--kind',
            $kind,
            '--source',
            $file,
        ], $fixture);
        $metadata = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        if (
            $exit !== 0
            || $stderr !== ''
            || $metadata['scope']['kind'] !== $kind
            || (
                $metadata['declarations'] ?? $metadata['candidates'] ?? $metadata['references'] ?? $metadata['contracts']
                ?? $metadata['calls']
            )
                === []
            || $metadata['errors'] !== []
            || is_file($fixture.'/autoload-executed')
            || is_file($fixture.'/config-executed')
            || str_contains($stdout, 'private-value-must-not-export')
        ) {
            throw new RuntimeException('Source export failed through the custom-vendor Composer proxy.');
        }
    }
}

file_put_contents($fixture.'/config/binary.php', "<?php return ['".chr(255)."' => 1];");
[$exit, $stdout, $stderr] = $run([...$command, '--config-array', 'binary'], $fixture);
if ($exit !== 2 || $stdout !== '' || ! str_contains($stderr, 'Unable to encode static metadata as JSON.')) {
    throw new RuntimeException('Invalid UTF-8 key was silently changed in JSON output.');
}

[$exit, $stdout] = $run([...$command, '--project-root', $fixture.'/missing'], $fixture);
if ($exit !== 2 || $stdout !== '') {
    throw new RuntimeException('Invalid project root did not fail cleanly.');
}

echo "PASS: installed metadata CLI keeps application code idle and exports bounded source evidence\n";
