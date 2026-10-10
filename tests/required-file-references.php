<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()) . '/laramago-required-files-' . bin2hex(random_bytes(8));
require $package . '/vendor/autoload.php';
$pathKind = new ReflectionMethod(\Ichinya\Laramago\Analyzer\RequiredFileReferencesHook::class, 'absoluteLocalPath');
$pathCases = [
    'relative.php' => false,
    '\\relative.php' => false,
    'C:relative.php' => false,
    'php://filter/resource=file.php' => false,
];
if (PHP_OS_FAMILY === 'Windows') {
    $pathCases += [
        'C:/absolute.php' => true,
        '\\\\server\\share\\absolute.php' => true,
        '/root-relative.php' => false,
        '\\\\?\\C:\\extended.php' => false,
    ];
} else {
    $pathCases += [
        '/absolute.php' => true,
        'C:/relative-on-this-host.php' => false,
        '\\\\server\\share\\relative-on-this-host.php' => false,
    ];
}
foreach ($pathCases as $path => $expectedKind) {
    if ($pathKind->invoke(null, $path) !== $expectedKind) {
        throw new RuntimeException('Unexpected local absolute-path classification for ' . var_export($path, true));
    }
}
$framework = $workspace . '/vendor/laravel/framework/src/Illuminate/Foundation';
mkdir($framework, 0777, true);
file_put_contents($workspace . '/present.php', '<?php return true;');
file_put_contents($framework . '/helpers.php', <<<'PHP'
    <?php
    class TestApplication {
        public function basePath($path = ''): string {
            return '{BASE}'.($path === '' ? '' : DIRECTORY_SEPARATOR.ltrim($path, DIRECTORY_SEPARATOR));
        }
    }
    function app(): TestApplication { return new TestApplication; }
    function base_path($path = ''): string { return app()->basePath($path); }
    PHP);
$helpers = file_get_contents($framework . '/helpers.php');
file_put_contents($framework . '/helpers.php', str_replace('{BASE}', $workspace, $helpers));
$cases = <<<'PHP'
    <?php
    require '{BASE}/missing.php';
    require_once '{BASE}/also-missing.php';
    require '{BASE}/unknown-directory/missing.php';
    require '{BASE}/present.php';
    include '{BASE}/optional-missing.php';
    include_once '{BASE}/optional-once-missing.php';
    file_exists('{BASE}/check-only-missing.php');
    require 'relative-missing.php';
    require '\\relative-missing.php';
    require 'C:relative-missing.php';
    function dynamicRequire(string $dynamic): void { require $dynamic; require base_path($dynamic); }
    require 'php://filter/resource=missing.php';
    require base_path('helper-missing.php');
    require base_path('present.php');
    if (file_exists($maintenance = '{BASE}/maintenance.php')) {
        require $maintenance;
    }
    if (\file_exists('{BASE}/guarded-literal.php')) {
        require '{BASE}/guarded-literal.php';
    }
    if (is_file($optional = '{BASE}/guarded-once.php')) {
        require_once $optional;
    }
    if (! file_exists('{BASE}/negative-guard.php')) {
        require '{BASE}/negative-guard.php';
    }
    if (file_exists('{BASE}/other-guard.php')) {
        require '{BASE}/different-target.php';
    }
    if (file_exists($changed = '{BASE}/original-target.php')) {
        $changed = '{BASE}/changed-target.php';
        require $changed;
    }
    if (file_exists('{BASE}/else-target.php')) {
    } else {
        require '{BASE}/else-target.php';
    }
    PHP;
file_put_contents($workspace . '/cases.php', str_replace('{BASE}', $workspace, $cases));
$shadowCases = <<<'PHP'
    <?php
    namespace Shadow;
    function file_exists(string $path): bool { return true; }
    if (file_exists('{BASE}/shadowed-target.php')) {
        require '{BASE}/shadowed-target.php';
    }
    if (\file_exists('{BASE}/qualified-target.php')) {
        require '{BASE}/qualified-target.php';
    }
    namespace Imported;
    use function Shadow\file_exists as present;
    use function file_exists as nativePresent;
    if (present('{BASE}/imported-shadow.php')) {
        require '{BASE}/imported-shadow.php';
    }
    if (nativePresent('{BASE}/imported-native.php')) {
        require '{BASE}/imported-native.php';
    }
    PHP;
file_put_contents($workspace . '/shadow.php', str_replace('{BASE}', $workspace, $shadowCases));
$config = [
    'extends' => $package . '/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => ['paths' => ['cases.php', 'shadow.php'], 'includes' => [$framework . '/helpers.php']],
    'extension-hosts' => [
        'laramago' => [
            'command' => [
                PHP_BINARY,
                '-d',
                'opcache.enable_cli=0',
                $package . '/bin/laramago-worker.php',
                $package . '/vendor/autoload.php',
                $workspace,
            ],
            'workers' => 1,
        ],
    ],
];
file_put_contents($workspace . '/mago.json', json_encode($config, JSON_THROW_ON_ERROR));
$direct = [
    'Required file "' . $workspace . '/missing.php" is absent from the analyzed filesystem snapshot.',
    'Required file "' . $workspace . '/also-missing.php" is absent from the analyzed filesystem snapshot.',
];
foreach ([true, false] as $asserted) {
    file_put_contents($workspace . '/composer.json', json_encode([
        'extra' => ['laramago' => $asserted ? ['path-helper-bases' => ['base_path' => $workspace]] : []],
    ], JSON_THROW_ON_ERROR));
    $process = proc_open(
        [
            PHP_BINARY,
            '-d',
            'opcache.enable_cli=0',
            $package . '/vendor/bin/mago',
            '--workspace',
            $workspace,
            'analyze',
            '--reporting-format=json',
        ],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $workspace . '/report.json', 'w'],
            2 => ['file', $workspace . '/stderr.log', 'w'],
        ],
        $pipes,
    );
    fclose($pipes[0]);
    $exit = proc_close($process);
    $stderr = file_get_contents($workspace . '/stderr.log');
    if ($exit > 1 || preg_match('/provider failed|rejected request/i', $stderr)) {
        throw new RuntimeException('Analyzer failed: ' . $stderr . '; inspect ' . $workspace);
    }
    $report = json_decode(file_get_contents($workspace . '/report.json'), true, flags: JSON_THROW_ON_ERROR);
    $actual = [];
    foreach ($report['issues'] as $issue) {
        if (($issue['code'] ?? '') === 'ichinya/laramago/laramago-missing-required-file') {
            $actual[] = $issue['message'];
        }
    }
    $expected = $direct;
    if ($asserted) {
        $expected[] =
            'Required file "'
            . $workspace
            . DIRECTORY_SEPARATOR
            . 'helper-missing.php" is absent from the analyzed filesystem snapshot.';
    }
    foreach ([
        'negative-guard.php',
        'different-target.php',
        'changed-target.php',
        'else-target.php',
        'shadowed-target.php',
        'imported-shadow.php',
    ] as $missing) {
        $expected[] =
            'Required file "' . $workspace . '/' . $missing . '" is absent from the analyzed filesystem snapshot.';
    }
    if ($actual !== $expected) {
        throw new RuntimeException(
            'Expected ' . json_encode($expected) . ', got ' . json_encode($actual) . '; inspect ' . $workspace,
        );
    }
}
echo "PASS: required-file snapshot diagnostics, native existence guards and hostile boundaries\n";
