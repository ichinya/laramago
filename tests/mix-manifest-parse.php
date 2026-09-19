<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago mix parse '.bin2hex(random_bytes(8));
$framework = $workspace.'/dependencies with spaces/laravel/framework/src/Illuminate/Foundation';
mkdir($framework, 0777, true);
mkdir($workspace.'/public', 0777, true);
copy(__DIR__.'/fixtures/analysis/mix-native.php.stub', $framework.'/Mix.php');
file_put_contents($framework.'/helpers.php', <<<'PHP'
    <?php
    use Illuminate\Foundation\Mix;
    use Illuminate\Container\Container;
    use Illuminate\Support\HtmlString;
    function app($abstract = null, array $parameters = [])
    {
        if (is_null($abstract)) {
            return Container::getInstance();
        }
        return Container::getInstance()->make($abstract, $parameters);
    }
    function mix($path, $manifestDirectory = ''): HtmlString|string
    {
        return app(Mix::class)(...func_get_args());
    }
    PHP);

$manifest = $workspace.'/public/mix-manifest.json';
$composer = static function (bool $configured = true) use ($workspace): void {
    file_put_contents($workspace.'/composer.json', json_encode([
        'extra' => [
            'laramago' => [
                'reference-catalogs' => ['mix-manifests' => [
                    'files' => $configured ? [['directory' => '', 'path' => 'public/mix-manifest.json']] : [],
                ]],
            ],
        ],
    ], JSON_THROW_ON_ERROR));
};
$composer();
$config = [
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => [
        'paths' => ['cases.php'],
        'includes' => [
            'dependencies with spaces/laravel/framework/src/Illuminate/Foundation/helpers.php',
            'dependencies with spaces/laravel/framework/src/Illuminate/Foundation/Mix.php',
        ],
    ],
    'extension-hosts' => [
        'laramago' => [
            'command' => [
                PHP_BINARY,
                '-d',
                'opcache.enable_cli=0',
                $package.'/bin/laramago-worker.php',
                $package.'/vendor/autoload.php',
                $workspace,
            ],
            'workers' => 1,
        ],
    ],
];
file_put_contents($workspace.'/mago.json', json_encode($config, JSON_THROW_ON_ERROR));
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$analyze = static function (string $label, string $code) use ($workspace, $command): array {
    file_put_contents($workspace.'/cases.php', '<?php '.$code);
    $process = proc_open(
        [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $workspace.'/'.$label.'.json', 'w'],
            2 => ['file', $workspace.'/'.$label.'.log', 'w'],
        ],
        $pipes,
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start Mago.');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $log = file_get_contents($workspace.'/'.$label.'.log');
    if (preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)) {
        throw new RuntimeException($label.': worker failed; inspect '.$workspace.' '.$log);
    }
    /** @var array<string, mixed> $report */
    $report = json_decode(file_get_contents($workspace.'/'.$label.'.json'), true, flags: JSON_THROW_ON_ERROR);
    $codes = array_column($report['issues'] ?? [], 'code');

    return [$exit, $codes, $report];
};
$check = static function (string $label, string $json, string $code, array $expected) use ($manifest, $analyze): void {
    file_put_contents($manifest, $json);
    [, $codes, $report] = $analyze($label, $code);
    $actual = array_values(array_filter($codes, static fn (string $issue): bool => str_contains(
        $issue,
        'mix-manifest',
    )));
    if ($actual !== $expected) {
        throw new RuntimeException(
            $label.': expected '.json_encode($expected).' got '.json_encode($actual).' in '.json_encode($report),
        );
    }
    foreach ($report['issues'] ?? [] as $issue) {
        if (! in_array($issue['code'], $expected, true)) {
            continue;
        }
        $primary = $issue['annotations'][0]['span']['file_id']['name'] ?? null;
        if ($primary !== 'cases.php' || ! str_contains($issue['message'], 'public/mix-manifest.json')) {
            throw new RuntimeException($label
            .': manifest issue must point to the analyzed callsite and name the configured path.');
        }
        if (! in_array(
            'This warning describes the configured manifest snapshot; it does not assert a runtime exception.',
            $issue['notes'] ?? [],
            true,
        )) {
            throw new RuntimeException($label
            .': manifest issue must distinguish catalog status from runtime behavior.');
        }
    }
};
$invalidJson = ['ichinya/laramago/laramago-mix-manifest-invalid-json'];
$invalidShape = ['ichinya/laramago/laramago-mix-manifest-invalid-shape'];
$check('invalid-json', '{oops', 'mix("app.js");', $invalidJson);
$check('invalid-shape', '{"/app.js":null}', 'mix("app.js");', $invalidShape);
$check('valid', '{"/app.js":"/app.js?id=1"}', 'mix("app.js");', []);
$check('dynamic-path', '{oops', '$path = "app.js"; mix($path);', []);
$check('dynamic-directory', '{oops', '$directory = ""; mix("app.js", $directory);', []);
$check('unconfigured-directory', '{oops', 'mix("app.js", "dist");', []);
file_put_contents($workspace.'/public/hot', 'http://localhost:8080');
$check('hot', '{oops', 'mix("app.js");', []);
unlink($workspace.'/public/hot');
$composer(false);
$check('no-catalog', '{oops', 'mix("app.js");', []);
$composer();
$check(
    'custom-helper',
    '{oops',
    'namespace App; function mix(string $path): string { return $path; } mix("app.js");',
    [],
);

echo "PASS: malformed Mix catalog warnings remain callsite-bound and suppress uncertain/runtime-hot cases\n";
