<?php

declare(strict_types=1);

// Aggregate-only Linux completion control. Use a disposable application copy.
$options = getopt('', ['project:', 'mago:', 'output:', 'profile:', 'request-timeout-ms::', 'timeout::', 'dependency-scope::', 'php-gc::', 'threads::', 'extension-workers::', 'phpstan-processes::', 'forbid-token:']);
foreach (['project', 'mago', 'output', 'profile'] as $required) {
    if (! isset($options[$required]) || ! is_string($options[$required])) {
        throw new InvalidArgumentException('Required: --project --mago --output --profile');
    }
}
$root = realpath($options['project']);
if ($root === false || PHP_OS_FAMILY !== 'Linux') {
    throw new RuntimeException('A disposable Linux application directory is required.');
}
$profile = $options['profile'];
if (! in_array($profile, ['mago-laramago', 'mago-native', 'phpstan-level5-configured'], true)) {
    throw new InvalidArgumentException('Unknown profile.');
}
$requestTimeout = (int) ($options['request-timeout-ms'] ?? 600000);
$timeout = (int) ($options['timeout'] ?? 1200);
$threads = (int) ($options['threads'] ?? 1);
$workers = (int) ($options['extension-workers'] ?? 1);
$phpstanProcesses = (int) ($options['phpstan-processes'] ?? 1);
if ($requestTimeout <= 0 || $timeout <= 0 || $threads <= 0 || $workers <= 0 || $phpstanProcesses <= 0) {
    throw new InvalidArgumentException('Timeouts must be positive.');
}
chdir($root);
if (is_writable($root.'/vendor')) {
    throw new RuntimeException('Mount the disposable vendor directory read-only before benchmarking.');
}
foreach (['LARAMAGO_EVALUATE_RUNTIME', 'LARAMAGO_RUNTIME_METADATA', 'MAGO_THREADS'] as $key) {
    putenv($key);
}
foreach (['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_URL' => 'http://example.invalid',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array'] as $key => $value) {
    putenv($key.'='.$value);
}
$phpGc = $options['php-gc'] ?? 'on';
if (! in_array($phpGc, ['on', 'off'], true)) {
    throw new InvalidArgumentException('PHP GC must be on or off.');
}
$ini = $root.'/.benchmark-linux-ini-'.$phpGc;
if (! is_dir($ini)) {
    mkdir($ini);
}
file_put_contents($ini.'/99-benchmark.ini', "opcache.enable_cli=0\nopcache.jit=disable\nopcache.jit_buffer_size=0\nmemory_limit=2G\nzend.enable_gc=".($phpGc === 'on' ? '1' : '0')."\n");
putenv('PHP_INI_SCAN_DIR='.$ini);
$installed = json_decode(file_get_contents($root.'/vendor/composer/installed.json'), true, flags: JSON_THROW_ON_ERROR)['packages'];
$versions = [];
foreach ($installed as $package) {
    if (in_array($package['name'], ['phpstan/phpstan', 'larastan/larastan', 'laravel/framework', 'ichinya/laramago', 'carthage-software/mago'], true)) {
        $versions[$package['name']] = $package['version'];
    }
}
if (($versions['ichinya/laramago'] ?? null) !== '0.1.5') {
    throw new RuntimeException('Expected the pinned published Laramago release.');
}
$roots = ['app', 'tests', 'scripts/testing', 'testo.php'];
$dependencyScope = $options['dependency-scope'] ?? 'vendor';
$includes = ['vendor'];
if ($dependencyScope === 'composer-autoload') {
    $vendorRoot = realpath($root.'/vendor').'/';
    $candidates = [];
    foreach (['autoload_psr4.php', 'autoload_namespaces.php', 'autoload_classmap.php', 'autoload_files.php'] as $map) {
        $path = $root.'/vendor/composer/'.$map;
        if (! is_file($path)) {
            continue;
        }
        foreach (require $path as $locations) {
            foreach ((array) $locations as $location) {
                $resolved = realpath($location);
                if ($resolved !== false && str_starts_with($resolved, $vendorRoot)) {
                    $candidates[$resolved] = true;
                }
            }
        }
    }
    $paths = array_keys($candidates);
    usort($paths, static fn (string $left, string $right): int => strlen($left) <=> strlen($right));
    $includes = [];
    foreach ($paths as $path) {
        foreach ($includes as $parent) {
            if ($path === $parent || (is_dir($parent) && str_starts_with($path, $parent.'/'))) {
                continue 2;
            }
        }
        $includes[] = $path;
    }
    if ($includes === []) {
        throw new RuntimeException('Composer did not provide any installed dependency source.');
    }
} elseif ($dependencyScope !== 'vendor') {
    throw new InvalidArgumentException('Dependency scope must be vendor or composer-autoload.');
}
$scope = ['roots' => $roots, 'php_files' => 0, 'physical_lines' => 0, 'bytes' => 0];
foreach ($roots as $relative) {
    $entries = is_dir($root.'/'.$relative)
        ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$relative, FilesystemIterator::SKIP_DOTS))
        : [new SplFileInfo($root.'/'.$relative)];
    foreach ($entries as $entry) {
        if ($entry->isFile() && $entry->getExtension() === 'php') {
            ++$scope['php_files'];
            $bytes = file_get_contents($entry->getPathname());
            $scope['bytes'] += strlen($bytes);
            $lines = preg_split('/\r\n|\r|\n/', $bytes);
            if (end($lines) === '') {
                array_pop($lines);
            }
            $scope['physical_lines'] += count($lines);
        }
    }
}
$baseConfigs = $dependencyScope === 'composer-autoload'
    ? ['vendor/ichinya/laramago/presets/laravel.toml']
    : ['mago.toml', 'vendor/ichinya/laramago/presets/laravel.toml'];
$config = ['extends' => $baseConfigs,
    'version' => '1.48.1', 'php-version' => '8.5', 'threads' => $threads,
    'source' => ['paths' => $roots, 'includes' => $includes,
        'excludes' => ['vendor/laravel/framework/tests/Foundation/Fixtures/bad-syntax-strategy.php',
            'vendor/testo/bridge-symfony-console/resources/stubs/testo.php']],
    'extension-hosts' => ['laramago' => ['command' => [PHP_BINARY, '-d', 'opcache.enable_cli=0', '-d', 'memory_limit=2G',
        $root.'/vendor/ichinya/laramago/bin/laramago-worker.php', $root.'/vendor/autoload.php', $root],
        'workers' => $workers, 'request-timeout-ms' => $requestTimeout]]];
$configPath = $root.'/benchmark-linux-mago.json';
file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$check = proc_open([$options['mago'], '--config', $configPath, 'config', '--show', 'source', '--no-extensions'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $checkPipes, $root);
if (! is_resource($check)) {
    throw new RuntimeException('Cannot verify effective source configuration.');
}
fclose($checkPipes[0]);
$effective = json_decode(stream_get_contents($checkPipes[1]), true, flags: JSON_THROW_ON_ERROR);
stream_get_contents($checkPipes[2]);
fclose($checkPipes[1]);
fclose($checkPipes[2]);
if (proc_close($check) !== 0) {
    throw new RuntimeException('Effective source configuration probe failed.');
}
$fullVendor = in_array('vendor', $effective['includes'], true) || in_array($root.'/vendor', $effective['includes'], true);
if ($dependencyScope === 'composer-autoload' && ($fullVendor || $effective['paths'] !== $roots)) {
    throw new RuntimeException('Inherited source lists expanded the requested analysis scope.');
}
if ($profile === 'phpstan-level5-configured') {
    $cache = sys_get_temp_dir().'/anonymous-phpstan-'.bin2hex(random_bytes(8));
    file_put_contents($root.'/benchmark-linux-phpstan.neon', "includes:\n    - phpstan.neon\nparameters:\n    tmpDir: ".$cache."\n    parallel:\n        maximumNumberOfProcesses: ".$phpstanProcesses."\n");
    $command = [PHP_BINARY, '-d', 'opcache.enable_cli=0', '-d', 'memory_limit=2G',
        $root.'/vendor/bin/phpstan', 'analyse', '-c', $root.'/benchmark-linux-phpstan.neon',
        '--level=5', '--memory-limit=2G', '--error-format=json', '--no-progress', '--no-ansi'];
} else {
    $command = [$options['mago'], '--config', $configPath, '--threads', (string) $threads, 'analyze', ...$roots, '--reporting-format=json'];
    if ($profile === 'mago-native') {
        $command[] = '--no-extensions';
    }
}
$usageBefore = getrusage(1);
$start = hrtime(true);
$process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
if (! is_resource($process)) {
    throw new RuntimeException('Cannot start analyzer.');
}
fclose($pipes[0]);
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);
$stdout = $stderr = '';
$timedOut = false;
do {
    $stdout .= stream_get_contents($pipes[1]);
    $stderr .= stream_get_contents($pipes[2]);
    $status = proc_get_status($process);
    if ((hrtime(true) - $start) / 1e9 > $timeout && $status['running']) {
        $timedOut = true;
        proc_terminate($process, 9);
    }
    usleep(10000);
} while ($status['running']);
$stdout .= stream_get_contents($pipes[1]);
$stderr .= stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($process);
$usage = getrusage(1);
$report = ['profile' => $profile, 'platform' => 'Linux', 'php' => PHP_VERSION, 'versions' => $versions, 'scope' => $scope,
    'laramago_commit' => '1a96f889fcd0d382079da11c500179bffc5d5ea2',
    'mago_binary_sha256' => hash_file('sha256', $options['mago']),
    'dependency_scope' => $dependencyScope, 'dependency_include_roots' => count($includes),
    'php_cycle_collector' => $phpGc,
    'effective_configuration_probe' => ['verified' => true, 'full_vendor_include' => $fullVendor, 'source_roots' => count($effective['paths'])],
    'extension_request_timeout_ms' => $requestTimeout, 'whole_command_timeout_seconds' => $timeout,
    'mago_threads' => $threads, 'extension_workers' => $workers, 'phpstan_maximum_processes' => $phpstanProcesses,
    'vendor_read_only' => true,
    'wall_seconds' => round((hrtime(true) - $start) / 1e9, 4),
    'cpu_seconds' => round(($usage['ru_utime.tv_sec'] - $usageBefore['ru_utime.tv_sec'])
        + ($usage['ru_utime.tv_usec'] - $usageBefore['ru_utime.tv_usec']) / 1e6
        + ($usage['ru_stime.tv_sec'] - $usageBefore['ru_stime.tv_sec'])
        + ($usage['ru_stime.tv_usec'] - $usageBefore['ru_stime.tv_usec']) / 1e6, 4),
    'maximum_child_rss_mib' => round($usage['ru_maxrss'] / 1024, 2),
    'peak_container_memory_mib' => is_readable('/sys/fs/cgroup/memory.peak') ? round((float) file_get_contents('/sys/fs/cgroup/memory.peak') / 1048576, 2) : null,
    'memory_note' => 'RUSAGE_CHILDREN maxrss is the largest individual child RSS, not a process-tree sum. Cgroup memory.peak includes all container processes and charged file cache.',
    'exit_code' => $status['exitcode'], 'timed_out' => $timedOut,
    'cpu_accounting_complete' => ! $timedOut, 'valid' => false];
try {
    if ($timedOut || ! in_array($status['exitcode'], [0, 1], true)) {
        throw new RuntimeException('analyzer-infrastructure-failure');
    }
    if (preg_match('/did not answer request|external analyzer provider failed|extension worker failed|extension host failed|external plugin error|violated the protocol|protocol I\/O failed|rejected request|provider timed out|worker timed out/i', $stderr)) {
        throw new RuntimeException('extension-failure');
    }
    if (str_contains($stderr, 'parsing errors') && str_contains($stderr, 'incomplete')) {
        throw new RuntimeException('incomplete-dependency-parsing');
    }
    $body = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
    $issues = [];
    if ($profile === 'phpstan-level5-configured') {
        if (! isset($body['totals']) || $body['totals']['errors'] !== 0) {
            throw new RuntimeException('incomplete-phpstan-report');
        }
        foreach ($body['files'] as $entry) {
            array_push($issues, ...$entry['messages']);
        }
    } elseif (! isset($body['issues'])) {
        throw new RuntimeException('missing-issue-report');
    } else {
        $issues = $body['issues'];
    }
    $codes = $levels = [];
    foreach ($issues as $issue) {
        $code = $issue['code'] ?? $issue['identifier'] ?? 'uncoded';
        if ($code === 'configuration-source-changed') {
            throw new RuntimeException('configuration-source-changed');
        }
        $level = $issue['level'] ?? 'Error';
        $codes[$code] = ($codes[$code] ?? 0) + 1;
        $levels[$level] = ($levels[$level] ?? 0) + 1;
    }
    ksort($codes);
    ksort($levels);
    $report += ['diagnostics' => count($issues), 'codes' => (object) $codes, 'levels' => (object) $levels];
    $report['valid'] = true;
} catch (Throwable) {
    $report['failure'] = 'incomplete-analyzer-report';
    // Infrastructure-only probe; never export full diagnostic streams.
    $report['failure_features'] = ['request_timeout' => str_contains($stderr, 'did not answer request'),
        'stack_exhaustion' => (bool) preg_match('/stack.*(?:exhaust|size)|Maximum call stack/i', $stderr),
        'memory_exhaustion' => str_contains($stderr, 'Allowed memory size'),
        'transport_failure' => str_contains($stderr, 'protocol I/O failed')];
}
$json = json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
foreach ((array) ($options['forbid-token'] ?? []) as $token) {
    if (stripos($json, $token) !== false) {
        throw new RuntimeException('Privacy check rejected aggregate report.');
    }
}
file_put_contents($options['output'], $json);
echo $json;
