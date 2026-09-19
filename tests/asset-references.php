<?php

declare(strict_types=1);

// Analyze generated source with the real Mago worker. Never execute application PHP.
// Laravel excerpts: Copyright (c) Taylor Otwell. See tests/fixtures/analysis/filesystem-factory-LICENSE.md.

$package = str_replace('\\', '/', dirname(__DIR__));
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];

foreach ([
    'complete',
    'incomplete',
    'disabled',
    'custom-helper',
    'changed-forward',
    'custom-binding',
    'changed-url',
    'custom-doc',
    'custom-app',
    'helper-doc',
    'helper-return',
    'app-doc',
    'class-doc',
    'url-default',
    'crlf',
    'native',
] as $mode) {
    $workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago asset references '.bin2hex(random_bytes(8));
    foreach ([
        'public/css',
        'vendor/laravel/framework/src/Illuminate/Foundation',
        'vendor/laravel/framework/src/Illuminate/Routing',
        'vendor/laravel/framework/src/Illuminate/Container',
    ] as $directory) {
        mkdir($workspace.'/'.$directory, 0777, true);
    }
    file_put_contents($workspace.'/public/css/site.css', '/* present */');
    $helperPath = $mode === 'custom-helper'
        ? 'custom-helpers.php'
        : 'vendor/laravel/framework/src/Illuminate/Foundation/helpers.php';
    $helpers = <<<'PHP'
        <?php
        use Illuminate\Container\Container;
        /**
         * @template TClass of object
         * @param string|class-string<TClass>|null $abstract
         * @return ($abstract is class-string<TClass> ? TClass : ($abstract is null ? \Illuminate\Foundation\Application : mixed))
         */
        function app($abstract = null, array $parameters = []) {
            if (is_null($abstract)) { return Container::getInstance(); }
            return Container::getInstance()->make($abstract, $parameters);
        }
        /** @param string $path @param bool|null $secure */
        function asset($path, $secure = null): string { return app('url')->asset($path, $secure); }
        /** @param string $path */
        function secure_asset($path): string { return asset($path, true); }
        PHP;
    if ($mode === 'custom-app') {
        $helpers = str_replace(
            'return Container::getInstance()->make($abstract, $parameters);',
            'return new \\Illuminate\\Routing\\UrlGenerator;',
            $helpers,
        );
    }
    if ($mode === 'helper-doc') {
        $helpers = str_replace(
            '/** @param string $path @param bool|null $secure */',
            '/** @param mixed $path @param bool|null $secure */',
            $helpers,
        );
    }
    if ($mode === 'helper-return') {
        $helpers = str_replace(
            'function asset($path, $secure = null): string',
            'function asset($path, $secure = null): mixed',
            $helpers,
        );
    }
    if ($mode === 'app-doc') {
        $helpers = str_replace(
            '($abstract is class-string<TClass> ? TClass : ($abstract is null ? \\Illuminate\\Foundation\\Application : mixed))',
            'mixed',
            $helpers,
        );
    }
    if ($mode === 'changed-forward') {
        $helpers = str_replace(
            "app('url')->asset(\$path, \$secure)",
            "app('url')->asset('other.css', \$secure)",
            $helpers,
        );
    }
    file_put_contents($workspace.'/'.$helperPath, $helpers);
    file_put_contents(
        $workspace.'/vendor/laravel/framework/src/Illuminate/Container/Container.php',
        '<?php namespace Illuminate\\Container; class Container { public static function getInstance(): self { return new self; } public function make($abstract, array $parameters = []) { return null; } }',
    );
    file_put_contents(
        $workspace.'/vendor/laravel/framework/src/Illuminate/Foundation/Application.php',
        '<?php namespace Illuminate\\Foundation; class Application extends \\Illuminate\\Container\\Container {}',
    );
    $url = <<<'PHP'
        <?php
        namespace Illuminate\Routing;
        class UrlGenerator {
            /**
             * Generate the URL to an application asset.
             * @param string $path
             * @param bool|null $secure
             * @return string
             */
            public function asset($path, $secure = null) {
                if ($this->isValidUrl($path)) { return $path; }
                $root = $this->assetRoot ?: $this->formatRoot($this->formatScheme($secure));
                return \Illuminate\Support\Str::finish($this->removeIndex($root), '/').trim($path, '/');
            }
        }
        PHP;
    if ($mode === 'changed-url') {
        $url = str_replace(
            'return \\Illuminate\\Support\\Str::finish',
            'return "other".\\Illuminate\\Support\\Str::finish',
            $url,
        );
    }
    if ($mode === 'custom-doc') {
        $url = str_replace('* @return string', '* @template T\n             * @return string', $url);
    }
    if ($mode === 'class-doc') {
        $url = str_replace(
            'class UrlGenerator {',
            '/** @method string asset(string $path) */ class UrlGenerator {',
            $url,
        );
    }
    if ($mode === 'url-default') {
        $url = str_replace(
            'public function asset($path, $secure = null)',
            'public function asset($path, $secure = true)',
            $url,
        );
    }
    if ($mode === 'crlf') {
        $url = str_replace("\n", "\r\n", $url);
    }
    file_put_contents($workspace.'/vendor/laravel/framework/src/Illuminate/Routing/UrlGenerator.php', $url);
    $catalog = ['complete' => $mode !== 'incomplete', 'paths' => ['public']];
    $configuration =
        $mode === 'disabled' || $mode === 'native'
            ? []
            : ['extra' => ['laramago' => ['reference-catalogs' => ['public-assets' => $catalog]]]];
    if ($mode === 'native') {
        $configuration = [
            'extra' => [
                'laramago' => [
                    'reference-catalogs' => ['public-assets' => ['complete' => true, 'paths' => ['public']]],
                ],
            ],
        ];
    }
    if ($mode === 'custom-binding') {
        mkdir($workspace.'/app', 0777, true);
        file_put_contents($workspace.'/app/bindings.php', "<?php app()->bind('url', fn () => new CustomUrl); ");
        $configuration['extra']['laramago']['binding-files'] = ['app/bindings.php'];
    }
    file_put_contents($workspace.'/composer.json', json_encode($configuration, JSON_THROW_ON_ERROR));
    $cases = [
        ['asset("css/site.css");',                                               false],
        ['asset("css/missing.css");',                                            true],
        ['asset(path: "css/missing.css", secure: true);',                        true],
        ['secure_asset("css/missing.css");',                                     true],
        ['(new \\Illuminate\\Routing\\UrlGenerator)->asset("css/missing.css");', true],
        ['asset("https://example.test/missing.css");',                           false],
        ['asset("//cdn.example.test/missing.css");',                             false],
        ['asset("css/missing.css?v=1");',                                        false],
        ['asset("css/missing.css#frag");',                                       false],
        ['asset("css/%6dissing.css");',                                          false],
        ['asset("css/../missing.css");',                                         false],
        ['asset("css/SITE.css");',                                               false],
        ['$path = "css/missing.css"; asset($path);',                             false],
        ['$args = ["css/missing.css"]; asset(...$args);',                        false],
        ['asset("css/missing.css", ...[]);',                                     false],
        ['asset(...);',                                                          false],
        ['asset("css/missing.css", secure: true);',                              true],
    ];
    $source = "<?php\nnamespace App;\n";
    $lines = [];
    foreach ($cases as $index => [$body, $expected]) {
        $source .= 'function scenario'.$index.'(): void { '.$body.' }'."\n";
        $lines[substr_count($source, "\n")] = $expected;
    }
    $source .= "namespace App\\Override;\nfunction asset(\$path): string { return \$path; }\nfunction custom(): void { asset('css/missing.css'); }\n";
    $source .= "namespace App\\Shadow;\nfunction app(\$key): string { return \$key; }\nfunction local(): void { \\asset('css/missing.css'); }\n";
    $shadowLine = substr_count($source, "\n");
    file_put_contents($workspace.'/cases.php', $source);
    $config = [
        'extends' => $package.'/presets/laravel.toml',
        'php-version' => '8.2',
        'source' => [
            'paths' => ['cases.php'],
            'includes' => [
                $helperPath,
                'vendor/laravel/framework/src/Illuminate/Container/Container.php',
                'vendor/laravel/framework/src/Illuminate/Foundation/Application.php',
                'vendor/laravel/framework/src/Illuminate/Routing/UrlGenerator.php',
            ],
        ],
    ];
    if ($mode !== 'native') {
        $config['extension-hosts'] = [
            'laramago' => [
                'command' => [
                    PHP_BINARY,
                    '-d',
                    'opcache.enable_cli=0',
                    $package.'/bin/laramago-worker.php',
                    $package.'/vendor/autoload.php',
                    $workspace,
                ],
                'workers' => 2,
            ],
        ];
    }
    file_put_contents($workspace.'/mago.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
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
    $stderr = file_get_contents($workspace.'/stderr.log');
    if (preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $stderr)) {
        throw new RuntimeException('Worker failed: '.$workspace."\n".$stderr);
    }
    $report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
    $actual = [];
    foreach ($report['issues'] ?? [] as $issue) {
        if ($issue['code'] !== 'ichinya/laramago/laramago-missing-public-asset') {
            continue;
        }
        $primary = array_values(array_filter(
            $issue['annotations'],
            static fn (array $item): bool => $item['kind'] === 'Primary',
        ))[0];
        $actual[$primary['span']['start']['line'] + 1] = true;
    }
    foreach ($lines as $line => $expected) {
        $directUrl = $line === 7;
        $shouldWarn =
            $expected
            && (
                in_array($mode, ['complete', 'crlf'], true)
                || $directUrl
                && in_array(
                    $mode,
                    [
                        'custom-helper',
                        'changed-forward',
                        'custom-binding',
                        'custom-app',
                        'helper-doc',
                        'helper-return',
                        'app-doc',
                    ],
                    true,
                )
            );
        if (($actual[$line] ?? false) !== $shouldWarn) {
            throw new RuntimeException("Unexpected asset diagnostic in {$mode} on line {$line}: ".$workspace);
        }
    }
    $shadowExpected = in_array($mode, ['complete', 'crlf'], true);
    if (($actual[$shadowLine] ?? false) !== $shadowExpected) {
        throw new RuntimeException('Unexpected namespace-shadow diagnostic in '.$mode.': '.$workspace);
    }
    $count = in_array($mode, ['complete', 'crlf'], true)
        ? 6
        : (
            in_array(
                $mode,
                [
                    'custom-helper',
                    'changed-forward',
                    'custom-binding',
                    'custom-app',
                    'helper-doc',
                    'helper-return',
                    'app-doc',
                ],
                true,
            )
                    ? 1
                    : 0
        );
    if (count($actual) !== $count) {
        throw new RuntimeException('Unexpected diagnostic count in '.$mode.': '.$workspace);
    }
    echo $mode." asset references passed (Mago exit {$exit}).\n";
}
