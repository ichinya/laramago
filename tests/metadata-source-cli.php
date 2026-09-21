<?php

declare(strict_types=1);

$package = dirname(__DIR__);
$fixture = sys_get_temp_dir().'/laramago source metadata '.bin2hex(random_bytes(8));
mkdir($fixture);
mkdir($fixture.'/routes');
mkdir($fixture.'/lang');
mkdir($fixture.'/vendor');
file_put_contents($fixture.'/lang/en.json', '{"Greeting":"PRIVATE_TRANSLATED_VALUE"}');
file_put_contents($fixture.'/app.js', 'const name = import.meta.env.VITE_APP_NAME;');
file_put_contents(
    $fixture.'/.env.example',
    'SOURCE=PRIVATE_TEMPLATE_VALUE'."\n".'TARGET="${SOURCE}"'."\n".'SOURCE=PRIVATE_TEMPLATE_VALUE',
);
file_put_contents($fixture.'/.env', 'SECRET=PRIVATE_ENV_VALUE');
file_put_contents(
    $fixture.'/vendor/autoload.php',
    '<?php throw new RuntimeException("Application autoload executed");',
);
file_put_contents($fixture.'/routes/web.php', <<<'PHP'
    <?php
    use Illuminate\Support\Facades\Route;
    Route::get('/profile', 'ProfileController')->name('profile.show');
    Route::get('/other-profile', 'ProfileController')->name('profile.show');
    throw new RuntimeException('Route source executed');
    PHP);
file_put_contents($fixture.'/lang/messages.php', <<<'PHP'
    <?php
    return ['greeting' => 'PRIVATE_TRANSLATED_VALUE :person', 'nested' => ['title' => sideEffect()]];
    PHP);

$run = static function (array $options) use ($package, $fixture): array {
    $process = proc_open(
        [PHP_BINARY, '-d', 'opcache.enable_cli=0', $package.'/bin/laramago-metadata', ...$options],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $fixture,
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start metadata CLI.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $stdout, $stderr];
};

try {
    foreach ([
        'routes' => 'routes/web.php',
        'translations' => 'lang/messages.php',
        'translations-json' => 'lang/en.json',
    ] as $kind => $file) {
        [$exit, $stdout, $stderr] = $run(['--kind', $kind, '--source', $file]);
        $data = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        if (
            $exit !== 0
            || $stderr !== ''
            || $data['scope'] !== ['kind' => $kind, 'evidence' => 'source-only']
            || $data['schemaVersion'] !== 1
            || $data['declarations'] === []
            || $data['errors'] !== []
            || $data['truncated']
            || str_contains($stdout, 'PRIVATE_TRANSLATED_VALUE')
        ) {
            throw new RuntimeException('Invalid source-only CLI export for '.$kind.': '.$stderr);
        }
        [$exit, $stdout] = $run(['--kind', $kind, '--source', 'missing.php']);
        $data = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        if ($exit !== 0 || $data['errors'] === [] || $data['declarations'] !== []) {
            throw new RuntimeException('Missing source must be disclosed in partial JSON.');
        }
        echo 'PASS: '.$kind." CLI dispatch and source errors\n";
    }
    [$exit, $stdout, $stderr] = $run(['--kind', 'route-name-duplicates', '--source', 'routes/web.php']);
    $duplicates = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
    if (
        $exit !== 0
        || $stderr !== ''
        || $duplicates['scope']['exhaustive'] !== false
        || array_column($duplicates['candidates'], 'name') !== ['profile.show']
        || $duplicates['candidates'][0]['activeRouteConflict'] !== 'unknown'
        || $duplicates['candidates'][0]['firstLocation']['start'] >= $duplicates['candidates'][0]['start']
    ) {
        throw new RuntimeException(
            'Route duplicate candidates must preserve locations without claiming active conflicts.',
        );
    }
    echo "PASS: advisory route duplicate CLI dispatch\n";
    [$exit, $stdout, $stderr] = $run(['--kind', 'vite-environment-references', '--source', 'app.js']);
    $vite = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
    if (
        $exit !== 0
        || $stderr !== ''
        || array_column($vite['references'], 'name') !== ['VITE_APP_NAME']
        || $vite['references'][0]['confidence'] !== 'completion-candidate'
    ) {
        throw new RuntimeException('Vite CLI must export completion candidates only.');
    }
    echo "PASS: Vite lexical candidate CLI dispatch\n";
    [$exit, $stdout, $stderr] = $run(['--kind', 'translation-placeholders', '--source', 'lang/messages.php']);
    $placeholders = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
    if (
        $exit !== 0
        || $stderr !== ''
        || $placeholders['scope']['kind'] !== 'translation-placeholders'
        || $placeholders['scope']['exhaustive'] !== false
        || array_column($placeholders['candidates'], 'name') !== ['person']
        || str_contains($stdout, 'PRIVATE_TRANSLATED_VALUE')
    ) {
        throw new RuntimeException('Placeholder mode must export partial candidates without message values.');
    }
    echo "PASS: placeholder CLI dispatch and value privacy\n";
    [$exit, $stdout, $stderr] = $run(['--kind', 'environment-references', '--source', '.env.example']);
    $environment = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
    if (
        $exit !== 0
        || $stderr !== ''
        || $environment['scope']['kind'] !== 'environment-references'
        || array_column($environment['references'], 'name') !== ['SOURCE']
        || str_contains($stdout, 'PRIVATE_TEMPLATE_VALUE')
        || str_contains($stdout, 'PRIVATE_ENV_VALUE')
    ) {
        throw new RuntimeException('Environment mode must expose names only from selected templates.');
    }
    [$exit, $stdout, $stderr] = $run(['--kind', 'environment-duplicates', '--source', '.env.example']);
    $duplicates = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
    if (
        $exit !== 0
        || $stderr !== ''
        || $duplicates['scope']['kind'] !== 'environment-duplicates'
        || array_column($duplicates['candidates'], 'name') !== ['SOURCE']
        || $duplicates['candidates'][0]['firstLocation']['start'] >= $duplicates['candidates'][0]['start']
        || str_contains($stdout, 'PRIVATE_TEMPLATE_VALUE')
    ) {
        throw new RuntimeException('Duplicate mode must export ordered name locations without values.');
    }
    echo "PASS: duplicate template CLI dispatch and value privacy\n";
    [$exit, $stdout] = $run(['--kind', 'environment-references', '--source', '.env']);
    $environment = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
    if ($exit !== 0 || $environment['errors'] === [] || $environment['declarations'] !== []) {
        throw new RuntimeException('Environment reference mode must reject actual environment files.');
    }
    echo "PASS: environment CLI dispatch and template boundary\n";
    foreach ([
        ['--kind', 'unknown'],
        ['--kind', 'routes'],
        ['--source', 'routes/web.php'],
        ['--kind', 'routes', '--source', 'routes/web.php', '--config-key', 'app.name'],
        ['--kind', 'translation-placeholders', '--source', 'lang/messages.php', '--watch'],
    ] as $options) {
        [$exit, $stdout, $stderr] = $run($options);
        if ($exit !== 2 || $stdout !== '' || $stderr === '') {
            throw new RuntimeException('Unsupported option combination must fail before exporting.');
        }
    }
    echo "PASS: incompatible metadata modes are rejected\n";
} finally {
    foreach ([
        'routes/web.php',
        'app.js',
        'lang/messages.php',
        'lang/en.json',
        'vendor/autoload.php',
        '.env.example',
        '.env',
    ] as $file) {
        unlink($fixture.'/'.$file);
    }
    foreach (['routes', 'lang', 'vendor'] as $directory) {
        rmdir($fixture.'/'.$directory);
    }
    rmdir($fixture);
}
