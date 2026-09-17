<?php

declare(strict_types=1);

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));

$modes = [
    'enabled' => [
        'locale' => "'en'",
        'catalog' => 'lang',
        'extension' => true,
        'nativeFacade' => true,
        'configBinding' => false,
    ],
    'unknown-locale' => [
        'locale' => "env('APP_LOCALE', 'en')",
        'catalog' => 'lang',
        'extension' => true,
        'nativeFacade' => true,
        'configBinding' => false,
    ],
    'custom-path' => [
        'locale' => "'en'",
        'catalog' => 'custom-lang',
        'extension' => true,
        'nativeFacade' => true,
        'configBinding' => false,
    ],
    'facade-override' => [
        'locale' => "'en'",
        'catalog' => 'lang',
        'extension' => true,
        'nativeFacade' => false,
        'configBinding' => false,
    ],
    'config-rebound' => [
        'locale' => "'en'",
        'catalog' => 'lang',
        'extension' => true,
        'nativeFacade' => true,
        'configBinding' => true,
    ],
    'disabled' => [
        'locale' => "'en'",
        'catalog' => 'lang',
        'extension' => false,
        'nativeFacade' => true,
        'configBinding' => false,
    ],
];

foreach ($modes as $mode => $settings) {
    $workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago framework contracts '.bin2hex(random_bytes(8));
    $framework = $workspace.'/vendor/laravel/framework/src/Illuminate';
    mkdir($framework.'/Foundation', 0777, true);
    mkdir($framework.'/Config', 0777, true);
    mkdir($framework.'/Routing', 0777, true);
    mkdir($framework.'/Support/Facades', 0777, true);
    mkdir($workspace.'/config', 0777, true);
    mkdir($workspace.'/bootstrap', 0777, true);
    mkdir($workspace.'/'.$settings['catalog'].'/en', 0777, true);
    copy(__DIR__.'/fixtures/analysis/framework-contracts.php.stub', $framework.'/Foundation/helpers.php');
    copy(__DIR__.'/fixtures/analysis/configuration-facade.php.stub', $framework.'/Support/Facades/Config.php');
    copy(__DIR__.'/fixtures/analysis/configuration-repository.php.stub', $framework.'/Config/Repository.php');
    copy(__DIR__.'/fixtures/analysis/route-parameters.php.stub', $framework.'/Routing/Router.php');
    copy(__DIR__.'/fixtures/analysis/route-facade-base.php.stub', $framework.'/Support/Facades/Facade.php');
    copy(__DIR__.'/fixtures/analysis/route-facade.php.stub', $framework.'/Support/Facades/Route.php');
    if (! $settings['nativeFacade']) {
        $path = $framework.'/Support/Facades/Route.php';
        file_put_contents($path, str_replace("return 'router';", "return 'custom.router';", file_get_contents($path)));
    }
    file_put_contents($workspace.'/config/app.php', '<?php return ["locale" => '.$settings['locale'].'];');
    file_put_contents(
        $workspace.'/composer.json',
        json_encode([
            'extra' => [
                'laramago' => [
                    'binding-files' => $settings['configBinding'] ? ['bootstrap/bindings.php'] : [],
                ],
            ],
        ], JSON_THROW_ON_ERROR),
    );
    file_put_contents(
        $workspace.'/bootstrap/bindings.php',
        $settings['configBinding']
            ? '<?php \\app()->bind("config", \\stdClass::class);'
            : '<?php',
    );
    file_put_contents($workspace.'/config/example.php', <<<'PHP'
        <?php
        return [
            'present' => 'configured',
            'dynamic' => env('EXAMPLE_VALUE', 'fallback'),
        ];
        PHP);
    file_put_contents($workspace.'/'.$settings['catalog'].'/en/messages.php', <<<'PHP'
        <?php
        return ['welcome' => 'Welcome'];
        PHP);

    $cases = [
        'imported facade alias' => [
            'Route::get("/{item}/{item}");',
            'void',
            $settings['extension'] && $settings['nativeFacade']
                ? ['ichinya/laramago/laramago-duplicate-route-parameter']
                : [],
        ],
        'renamed facade alias' => [
            'LaravelRoute::get("/{item}/{item}");',
            'void',
            $settings['extension'] && $settings['nativeFacade']
                ? ['ichinya/laramago/laramago-duplicate-route-parameter']
                : [],
        ],
        'namespace facade alias' => [
            'Facades\\Route::get("/{item}/{item}");',
            'void',
            $settings['extension'] && $settings['nativeFacade']
                ? ['ichinya/laramago/laramago-duplicate-route-parameter']
                : [],
        ],
        'implicit configured locale' => [
            'return \\trans("messages.welcome");',
            'string',
            $settings['extension']
            && ! $settings['configBinding']
            && $settings['catalog'] === 'lang'
            && $settings['locale'] === "'en'"
                ? []
                : ['invalid-return-statement'],
        ],
        'implicit configured locale through double underscore' => [
            'return \\__("messages.welcome");',
            'string',
            $settings['extension']
            && ! $settings['configBinding']
            && $settings['catalog'] === 'lang'
            && $settings['locale'] === "'en'"
                ? []
                : ['invalid-return-statement'],
        ],
        'explicit null uses configured locale' => [
            'return \\trans("messages.welcome", [], null);',
            'string',
            $settings['extension']
            && ! $settings['configBinding']
            && $settings['catalog'] === 'lang'
            && $settings['locale'] === "'en'"
                ? []
                : ['invalid-return-statement'],
        ],
        'falsy zero locale uses configured locale' => [
            'return \\trans("messages.welcome", [], "0");',
            'string',
            $settings['extension']
            && ! $settings['configBinding']
            && $settings['catalog'] === 'lang'
            && $settings['locale'] === "'en'"
                ? []
                : ['invalid-return-statement'],
        ],
        'explicit locale independent of app locale' => [
            'return \\trans("messages.welcome", [], "en");',
            'string',
            $settings['extension'] && $settings['catalog'] === 'lang'
                ? []
                : ['invalid-return-statement'],
        ],
        'unknown explicit locale defers' => [
            '$locale = (string) random_int(1, 2); return \\trans("messages.welcome", [], $locale);',
            'string',
            ['invalid-return-statement'],
        ],
        'existing configuration beats default' => [
            "return \\config('example.present', 42);",
            'string',
            $settings['extension'] && ! $settings['configBinding'] ? [] : ['mixed-return-statement'],
        ],
        'default does not type existing configuration' => [
            "return \\config('example.present', 42);",
            'int',
            [
                $settings['extension'] && ! $settings['configBinding']
                    ? 'invalid-return-statement'
                    : 'mixed-return-statement',
            ],
        ],
        'default does not type dynamic configuration' => [
            "return \\config('example.dynamic', 'fallback');",
            'string',
            ['mixed-return-statement'],
        ],
        'config facade respects core binding' => [
            "return \\Illuminate\\Support\\Facades\\Config::get('example.present');",
            'string',
            $settings['extension'] && ! $settings['configBinding'] ? [] : ['mixed-return-statement'],
        ],
    ];
    $source = <<<'PHP'
        <?php
        namespace FrameworkContracts\Imported;
        use Illuminate\Support\Facades\Route;
        use Illuminate\Support\Facades\Route as LaravelRoute;
        use Illuminate\Support\Facades as Facades;
        PHP;
    $lines = [];
    foreach ($cases as $name => [$body, $return, $codes]) {
        $source .= '/** @return '.$return.' */'."\n";
        $source .= 'function scenario'.count($lines).'() { '.$body.' }'."\n";
        $lines[substr_count($source, "\n")] = [$name, $codes];
    }
    $source .= <<<'PHP'

        namespace FrameworkContracts\Shadowed;
        final class Route
        {
            public static function get(string $uri): void {}
        }
        function localRouteShadow(): void { Route::get('/{item}/{item}'); }
        PHP;
    foreach (explode("\n", $source) as $index => $line) {
        if (str_contains($line, 'function localRouteShadow')) {
            $lines[$index + 1] = ['local route class shadows facade name', []];
        }
    }
    file_put_contents($workspace.'/cases.php', $source);
    $configuration = [
        'extends' => $package.'/presets/laravel.toml',
        'php-version' => '8.2',
        'source' => [
            'paths' => ['cases.php'],
            'includes' => [
                'vendor/laravel/framework/src/Illuminate/Foundation/helpers.php',
                'vendor/laravel/framework/src/Illuminate/Config/Repository.php',
                'vendor/laravel/framework/src/Illuminate/Routing/Router.php',
                'vendor/laravel/framework/src/Illuminate/Support/Facades/Config.php',
                'vendor/laravel/framework/src/Illuminate/Support/Facades/Facade.php',
                'vendor/laravel/framework/src/Illuminate/Support/Facades/Route.php',
            ],
        ],
    ];
    if ($settings['extension']) {
        $configuration['extension-hosts'] = [
            'laramago' => [
                'command' => [
                    PHP_BINARY,
                    $package.'/bin/laramago-worker.php',
                    $package.'/vendor/autoload.php',
                    $workspace,
                ],
                'workers' => 2,
            ],
        ];
    }
    file_put_contents(
        $workspace.'/mago.json',
        json_encode($configuration, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
    );
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
    if ($exit > 1 || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)) {
        throw new RuntimeException('Unexpected worker result for '.$mode.'; inspect '.$workspace);
    }
    $report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
    $actual = [];
    foreach ($report['issues'] ?? [] as $issue) {
        $primary = array_values(array_filter(
            $issue['annotations'],
            static fn (array $annotation): bool => $annotation['kind'] === 'Primary',
        ))[0];
        $actual[$primary['span']['start']['line'] + 1][] = $issue['code'];
    }
    foreach ($lines as $line => [$name, $expected]) {
        $codes = $actual[$line] ?? [];
        sort($codes);
        sort($expected);
        if ($codes !== $expected) {
            throw new RuntimeException(
                $mode
                .' '
                .$name
                .': expected '
                .json_encode($expected)
                .', got '
                .json_encode($codes)
                .'; inspect '
                .$workspace,
            );
        }
        unset($actual[$line]);
        echo 'PASS: '.$mode.' '.$name."\n";
    }
    if ($actual !== []) {
        throw new RuntimeException($mode.' produced diagnostics outside the contract cases; inspect '.$workspace);
    }
    if (is_file($workspace.'/config/executed.txt') || is_file($workspace.'/'.$settings['catalog'].'/en/executed.txt')) {
        throw new RuntimeException('Static framework contract analysis executed application source.');
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    $resolvedWorkspace = realpath($workspace);
    foreach ($iterator as $entry) {
        $resolvedEntry = realpath($entry->getPathname());
        if (
            $resolvedWorkspace === false
            || $resolvedEntry === false
            || ! str_starts_with($resolvedEntry, $resolvedWorkspace.DIRECTORY_SEPARATOR)
        ) {
            throw new RuntimeException('Refusing cleanup outside the framework contract workspace.');
        }
        $entry->isDir() ? rmdir($resolvedEntry) : unlink($resolvedEntry);
    }
    rmdir($workspace);
}
