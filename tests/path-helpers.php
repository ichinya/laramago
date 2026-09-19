<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago-path-helpers-'.bin2hex(random_bytes(8));
$framework = $workspace.'/vendor/laravel/framework/src/Illuminate/Foundation';
mkdir($framework, 0777, true);
$helpers = <<<'PHP'
    <?php
    class TestApplication {
        private function joinPaths(string $basePath, string $path = ''): string {
            return $basePath.($path === '' ? '' : DIRECTORY_SEPARATOR.ltrim($path, DIRECTORY_SEPARATOR));
        }
        public function basePath($path = ''): string { return $this->joinPaths('C:/project', $path); }
        public function path($path = ''): string { return $this->joinPaths('C:/custom/app', $path); }
        public function configPath($path = ''): string { return $this->joinPaths('C:/custom/config', $path); }
        public function databasePath($path = ''): string { return $this->joinPaths('C:/custom/database', $path); }
        public function publicPath($path = ''): string { return $this->joinPaths('C:/custom/public', $path); }
        public function resourcePath($path = ''): string { return $this->joinPaths('C:/project/resources', $path); }
        public function storagePath($path = ''): string { return $this->joinPaths('C:/custom/storage', $path); }
        public function langPath($path = ''): string { return $this->joinPaths('C:/custom/lang', $path); }
    }
    function app(): TestApplication { return new TestApplication; }
    function base_path($path = ''): string { return app()->basePath($path); }
    function app_path($path = ''): string { return app()->path($path); }
    function config_path($path = ''): string { return app()->configPath($path); }
    function database_path($path = ''): string { return app()->databasePath($path); }
    function public_path($path = ''): string { return app()->publicPath($path); }
    function resource_path($path = ''): string { return app()->resourcePath($path); }
    function storage_path($path = ''): string { return app()->storagePath($path); }
    function lang_path($path = ''): string { return app()->langPath($path); }
    PHP;
$cases = <<<'PHP'
    <?php
    /** @return '{BASE}' */
    function exactBase(): string { return base_path(); }
    /** @return '{BASE}' */
    function emptyBase(): string { return base_path(''); }
    /** @return '{BASE}{SEP}name' */
    function exactChild(): string { return base_path('name'); }
    /** @return '{BASE}{SEP}name' */
    function namedChild(): string { return base_path(path: 'name'); }
    /** @return '{BASE}{SEP}0' */
    function zeroChild(): string { return base_path('0'); }
    /** @return '{BASE}{SEP}name' */
    function leadingChild(): string { return base_path('{SEP}name'); }
    /** @return 'C:/custom/app' */
    function customApp(): string { return app_path(); }
    /** @return 'C:/custom/database' */
    function customDatabase(): string { return database_path(); }
    /** @return 'C:/custom/public' */
    function customPublic(): string { return public_path(); }
    /** @return 'C:/project/resources' */
    function conventionalResources(): string { return resource_path(); }
    /** @return 'C:/custom/storage' */
    function customStorage(): string { return storage_path(); }
    /** @return 'C:/custom/lang' */
    function customLang(): string { return lang_path(); }
    /** @return 'C:/custom/config' */
    function unassertedConfig(): string { return config_path(); }
    /** @return 'C:/project\unknown' */
    /** @return '{BASE}{SEP}unknown' */
    function dynamic(string $path): string { return base_path($path); }
    PHP;
$asserted = [
    'base_path' => 'C:/project',
    'app_path' => 'C:/custom/app',
    'database_path' => 'C:/custom/database',
    'public_path' => 'C:/custom/public',
    'resource_path' => 'C:/project/resources',
    'storage_path' => 'C:/custom/storage',
    'lang_path' => 'C:/custom/lang',
];
foreach ([
    'asserted',
    'trailing-root',
    'config-only',
    'unconfigured',
    'changed-forwarding',
    'forward-unpack',
    'custom-doc',
    'phpstan-doc',
    'psalm-doc',
] as $mode) {
    $base = $mode === 'trailing-root' ? 'C:/project/' : 'C:/project';
    file_put_contents($workspace.'/cases.php', str_replace(['{BASE}', '{SEP}'], [$base, DIRECTORY_SEPARATOR], $cases));
    file_put_contents($framework.'/helpers.php', match ($mode) {
        'changed-forwarding' => str_replace('app()->basePath($path)', 'app()->path($path)', $helpers),
        'forward-unpack' => str_replace('app()->basePath($path)', 'app()->basePath(...[$path])', $helpers),
        'trailing-root' => str_replace("joinPaths('C:/project',", "joinPaths('C:/project/',", $helpers),
        'custom-doc' => str_replace(
            'function base_path(',
            "/** @return non-empty-string */\nfunction base_path(",
            $helpers,
        ),
        'phpstan-doc' => str_replace(
            'function base_path(',
            "/** @phpstan-return non-empty-string */\nfunction base_path(",
            $helpers,
        ),
        'psalm-doc' => str_replace(
            'function base_path(',
            "/** @psalm-return non-empty-string */\nfunction base_path(",
            $helpers,
        ),
        default => $helpers,
    });
    $composer = ['extra' => ['laramago' => []]];
    if ($mode === 'config-only') {
        $composer['extra']['laramago']['path-helper-bases'] = ['config_path' => 'C:/custom/config'];
    } elseif ($mode !== 'unconfigured') {
        $asserted['base_path'] = $base;
        $composer['extra']['laramago']['path-helper-bases'] = $asserted;
    }
    file_put_contents($workspace.'/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
    $config = [
        'extends' => $package.'/presets/laravel.toml',
        'php-version' => '8.2',
        'source' => ['paths' => ['cases.php'], 'includes' => ['vendor', $framework.'/helpers.php']],
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
    $process = proc_open(
        [
            PHP_BINARY,
            '-d',
            'opcache.enable_cli=0',
            $package.'/vendor/bin/mago',
            '--workspace',
            $workspace,
            'analyze',
            '--reporting-format=json',
        ],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $workspace.'/report.json', 'w'],
            2 => ['file', $workspace.'/stderr.log', 'w'],
        ],
        $pipes,
    );
    fclose($pipes[0]);
    $exit = proc_close($process);
    $stderr = file_get_contents($workspace.'/stderr.log');
    if ($exit > 1 || preg_match('/provider failed|rejected request/i', $stderr)) {
        throw new RuntimeException($mode.': analyzer failed: '.$stderr);
    }
    $report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
    $actual = [];
    foreach ($report['issues'] as $issue) {
        if (($issue['code'] ?? '') !== 'invalid-return-statement') {
            continue;
        }
        if (preg_match('/for function `([^`]+)`/', $issue['message'], $matches)) {
            $actual[] = $matches[1];
        }
    }
    $expected = match ($mode) {
        'asserted', 'trailing-root' => ['unassertedConfig', 'dynamic'],
        'config-only' => [
            'exactBase',
            'emptyBase',
            'exactChild',
            'namedChild',
            'zeroChild',
            'leadingChild',
            'customApp',
            'customDatabase',
            'customPublic',
            'conventionalResources',
            'customStorage',
            'customLang',
            'dynamic',
        ],
        'unconfigured' => [
            'exactBase',
            'emptyBase',
            'exactChild',
            'namedChild',
            'zeroChild',
            'leadingChild',
            'customApp',
            'customDatabase',
            'customPublic',
            'conventionalResources',
            'customStorage',
            'customLang',
            'unassertedConfig',
            'dynamic',
        ],
        'changed-forwarding', 'forward-unpack', 'custom-doc', 'phpstan-doc', 'psalm-doc' => [
            'exactBase',
            'emptyBase',
            'exactChild',
            'namedChild',
            'zeroChild',
            'leadingChild',
            'unassertedConfig',
            'dynamic',
        ],
    };
    if ($actual !== $expected) {
        throw new RuntimeException($mode.': expected '.json_encode($expected).', got '.json_encode($actual));
    }
    echo $mode, ': passed', PHP_EOL;
}
