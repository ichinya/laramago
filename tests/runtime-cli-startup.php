<?php

declare(strict_types=1);

require_once __DIR__ . '/fixtures/runtime-schema/support.php';

$package = dirname(__DIR__);
$root = sys_get_temp_dir() . '/laramago startup ' . bin2hex(random_bytes(8));
mkdir($root);
$autoload = $root . '/autoload.php';
$entrypoint = $package . '/bin/laramago-runtime';
$arguments = [$entrypoint, '--project-root', $root, '--autoload', $autoload, '--model', 'SyntheticRuntime\\Record'];
$canary = "synthetic-startup\nquote\" apostrophe' slash\\ dollar\${SYNTHETIC_INI_VALUE};";
$iniCanary = '"' . str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $canary) . '"';
$source = <<<'PHP'
    <?php
    file_put_contents(__DIR__ . '/observed.json', json_encode([
        'display' => ini_get('display_errors'),
        'log' => ini_get('log_errors'),
        'memory' => ini_get('memory_limit'),
        'agent' => ini_get('user_agent'),
        'disabled' => !function_exists('phpinfo'),
        'scanned' => php_ini_scanned_files(),
    ], JSON_THROW_ON_ERROR));
    exit(7);
    PHP;
file_put_contents($autoload, $source);

foreach (['1', 'stderr'] as $display) {
    [$exit, $stdout, $stderr] = runtimeProcess(
        [
            PHP_BINARY,
            '-d',
            'opcache.enable_cli=0',
            '-d',
            'display_errors=' . $display,
            '-d',
            'log_errors=1',
            '-d',
            'memory_limit=96M',
            '-d',
            'user_agent=' . $iniCanary,
            '-d',
            'disable_functions=phpinfo',
            ...$arguments,
        ],
        $root,
        'startup-' . $display,
    );
    $observed = json_decode(file_get_contents($root . '/observed.json'), true, flags: JSON_THROW_ON_ERROR);
    runtimeAssert(
        $exit === 7 && $stdout === '' && $stderr === '',
        'Private launcher lost exit code or leaked diagnostics.',
    );
    runtimeAssert(
        !$observed['display'] && !$observed['log'],
        'Diagnostics were not private before application autoload.',
    );
    runtimeAssert(
        $observed['memory'] === '96M' && $observed['agent'] === $canary && $observed['disabled'],
        'Effective PHP options were lost.',
    );
    preg_match('~([^,\r\n]*laramago-runtime-ini-[^,\r\n]*runtime\.ini)~', $observed['scanned'], $match);
    runtimeAssert(
        count($match) === 2 && !file_exists(trim($match[1])) && !is_dir(dirname(trim($match[1]))),
        'Private configuration was not removed.',
    );
    echo 'PASS: private startup ' . $display . ', effective options, exit code and configuration cleanup' . PHP_EOL;
}

[$exit, $stdout, $stderr] = runtimeProcess(
    [PHP_BINARY, '-d', 'opcache.enable_cli=0', '-d', 'display_errors=0', '-d', 'log_errors=0', ...$arguments],
    $root,
    'already-private',
);
$observed = json_decode(file_get_contents($root . '/observed.json'), true, flags: JSON_THROW_ON_ERROR);
runtimeAssert(
    $exit === 7
    && $stdout === ''
    && $stderr === ''
    && !str_contains((string) $observed['scanned'], 'laramago-runtime-ini-'),
    'Already private collector unexpectedly relaunched PHP.',
);
echo 'PASS: already private startup avoids relaunch and temporary configuration' . PHP_EOL;

[$exit, $stdout, $stderr] = runtimeProcess(
    [
        PHP_BINARY,
        '-n',
        '-d',
        'display_errors=1',
        '-d',
        'log_errors=1',
        '-d',
        'memory_limit=96M',
        '-d',
        'user_agent=' . $iniCanary,
        '-d',
        'disable_functions=phpinfo',
        ...$arguments,
    ],
    $root,
    'without-ini',
);
$observed = json_decode(file_get_contents($root . '/observed.json'), true, flags: JSON_THROW_ON_ERROR);
runtimeAssert(
    $exit === 7
    && $stdout === ''
    && $stderr === ''
    && $observed['agent'] === $canary
    && $observed['memory'] === '96M'
    && $observed['disabled'],
    'Startup without a primary ini lost configuration.',
);
echo 'PASS: private startup without a primary php.ini' . PHP_EOL;

file_put_contents(
    $autoload,
    '<?php register_shutdown_function(static function (): void { throw new RuntimeException("synthetic-private-fatal-canary"); }); exit(7);',
);
[$exit, $stdout, $stderr] = runtimeProcess(
    [
        PHP_BINARY,
        '-d',
        'opcache.enable_cli=0',
        '-d',
        'display_errors=stderr',
        '-d',
        'log_errors=1',
        ...$arguments,
    ],
    $root,
    'fatal',
);
runtimeAssert(
    $exit === 255 && $stdout === '' && $stderr === '',
    'Fatal application diagnostics escaped the private process.',
);
echo 'PASS: fatal application shutdown diagnostics stay private' . PHP_EOL;

file_put_contents($autoload, '<?php file_put_contents(__DIR__."/unexpected-autoload", "yes");');
[$exit, $stdout, $stderr] = runtimeProcess(
    [
        PHP_BINARY,
        '-d',
        'display_errors=stderr',
        '-d',
        'log_errors=1',
        $entrypoint,
        '--help',
    ],
    $root,
    'help',
);
runtimeAssert(
    $exit === 0 && str_contains($stdout, 'Usage:') && $stderr === '' && !is_file($root . '/unexpected-autoload'),
    'CLI help loaded the application.',
);
echo 'PASS: CLI help stays dependency-free' . PHP_EOL;
