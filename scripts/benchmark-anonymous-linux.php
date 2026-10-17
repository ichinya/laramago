<?php

declare(strict_types=1);

// Aggregate-only Linux completion control. Use a disposable application copy.
$options = getopt('', ['project:', 'mago:', 'output:', 'profile:', 'request-timeout-ms::', 'timeout::', 'dependency-scope::', 'php-gc::', 'threads::', 'extension-workers::', 'phpstan-processes::', 'phpstan-level::', 'source-seal::', 'forbid-token:', 'worker:', 'diagnostic-seal:', 'create-diagnostic-seal']);
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
if (! in_array($profile, ['mago-laramago', 'mago-native', 'phpstan-level5-configured', 'phpstan-unfiltered'], true)) {
    throw new InvalidArgumentException('Unknown profile.');
}
$requestTimeout = (int) ($options['request-timeout-ms'] ?? 600000);
$timeout = (int) ($options['timeout'] ?? 1200);
$threads = (int) ($options['threads'] ?? 1);
$workers = (int) ($options['extension-workers'] ?? 1);
$phpstanProcesses = (int) ($options['phpstan-processes'] ?? 1);
$phpstanLevel = $profile === 'phpstan-level5-configured' ? 5 : (int) ($options['phpstan-level'] ?? 10);
if ($phpstanLevel < 0 || $phpstanLevel > 10) {
    throw new InvalidArgumentException('PHPStan level must be between 0 and 10.');
}
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
$collectInputHashes = static function () use ($root, $roots): array {
    // Rediscover paths as well as bytes: a newly generated input changes the workload.
    clearstatcache();
    $hashes = [];
    foreach ([...$roots, 'config', 'database/migrations'] as $relative) {
        $entries = is_dir($root.'/'.$relative)
            ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$relative, FilesystemIterator::SKIP_DOTS))
            : [new SplFileInfo($root.'/'.$relative)];
        foreach ($entries as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $hash = hash_file('sha256', $entry->getPathname());
                if ($hash === false) {
                    throw new RuntimeException('Cannot hash a workload input.');
                }
                $hashes[$entry->getPathname()] = $hash;
            }
        }
    }
    foreach (['composer.json', 'composer.lock', 'phpstan.neon', 'mago.toml', 'bootstrap/app.php', 'bootstrap/providers.php'] as $relative) {
        if (is_file($root.'/'.$relative)) {
            $hash = hash_file('sha256', $root.'/'.$relative);
            if ($hash === false) {
                throw new RuntimeException('Cannot hash a workload input.');
            }
            $hashes[$root.'/'.$relative] = $hash;
        }
    }
    ksort($hashes);
    return $hashes;
};
$inputHashes = $collectInputHashes();
if (isset($options['source-seal'])) {
    $fingerprint = hash('sha256', json_encode($inputHashes, JSON_THROW_ON_ERROR));
    $sealPath = $options['source-seal'];
    if (is_file($sealPath)) {
        if (trim(file_get_contents($sealPath)) !== $fingerprint) {
            throw new RuntimeException('Source differs from the sealed workload.');
        }
    } else {
        file_put_contents($sealPath, $fingerprint."\n", LOCK_EX);
    }
}
$baseConfigs = $dependencyScope === 'composer-autoload'
    ? ['vendor/ichinya/laramago/presets/laravel.toml']
    : ['mago.toml', 'vendor/ichinya/laramago/presets/laravel.toml'];
$worker = $options['worker'] ?? $root.'/vendor/ichinya/laramago/bin/laramago-worker.php';
if (isset($options['worker']) && ! is_file($worker)) {
    throw new RuntimeException('The experiment worker does not exist.');
}
$config = ['extends' => $baseConfigs,
    'version' => '1.48.1', 'php-version' => '8.5', 'threads' => $threads,
    'source' => ['paths' => $roots, 'includes' => $includes,
        'excludes' => ['vendor/laravel/framework/tests/Foundation/Fixtures/bad-syntax-strategy.php',
            'vendor/testo/bridge-symfony-console/resources/stubs/testo.php']],
    'extension-hosts' => ['laramago' => ['command' => [PHP_BINARY, '-d', 'opcache.enable_cli=0', '-d', 'memory_limit=2G',
        $worker, $root.'/vendor/autoload.php', $root],
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
if (str_starts_with($profile, 'phpstan-')) {
    $cache = sys_get_temp_dir().'/anonymous-phpstan-'.bin2hex(random_bytes(8));
    if (file_exists($cache)) {
        throw new RuntimeException('Full-run cache directory must not exist.');
    }
    $phpstanConfig = "includes:\n    - phpstan.neon\nparameters:\n    tmpDir: ".$cache."\n    parallel:\n        maximumNumberOfProcesses: ".$phpstanProcesses."\n";
    if ($profile === 'phpstan-unfiltered') {
        $phpstanConfig .= "    ignoreErrors!: []\n";
    }
    $probeConfig = str_replace($cache, $cache.'-configuration-probe', $phpstanConfig);
    file_put_contents($root.'/benchmark-linux-phpstan-probe.neon', $probeConfig);
    $probe = proc_open([PHP_BINARY, $root.'/vendor/bin/phpstan', 'dump-parameters',
        '-c', $root.'/benchmark-linux-phpstan-probe.neon', '--level='.$phpstanLevel, '--json'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $probePipes, $root);
    if (! is_resource($probe)) {
        throw new RuntimeException('Cannot verify PHPStan parameters.');
    }
    fclose($probePipes[0]);
    $parameters = json_decode(stream_get_contents($probePipes[1]), true, flags: JSON_THROW_ON_ERROR);
    stream_get_contents($probePipes[2]);
    fclose($probePipes[1]);
    fclose($probePipes[2]);
    if (proc_close($probe) !== 0 || ($profile === 'phpstan-unfiltered' && $parameters['ignoreErrors'] !== [])) {
        throw new RuntimeException('PHPStan ignore reset was not effective.');
    }
    if (file_exists($cache)) {
        throw new RuntimeException('Configuration probe polluted the measured cache.');
    }
    file_put_contents($root.'/benchmark-linux-phpstan.neon', $phpstanConfig);
    $command = [PHP_BINARY, '-d', 'opcache.enable_cli=0', '-d', 'memory_limit=2G',
        $root.'/vendor/bin/phpstan', 'analyse', '-c', $root.'/benchmark-linux-phpstan.neon',
        '--level='.$phpstanLevel, '--memory-limit=2G', '--error-format=json', '--no-progress', '--no-ansi', '-vv'];
} else {
    $command = [$options['mago'], '--config', $configPath, '--threads', (string) $threads, 'analyze', ...$roots, '--reporting-format=json'];
    if ($profile === 'mago-native') {
        $command[] = '--no-extensions';
    }
}
$usageBefore = getrusage(1);
$tmpDirAbsent = isset($cache) ? ! file_exists($cache) : null;
$startedAt = gmdate(DATE_ATOM);
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
$finished = hrtime(true);
$usage = getrusage(1);
$report = ['profile' => $profile, 'platform' => 'Linux', 'php' => PHP_VERSION, 'versions' => $versions, 'scope' => $scope,
    'started_at_utc' => $startedAt, 'finished_at_utc' => gmdate(DATE_ATOM),
    'laramago_commit' => '1a96f889fcd0d382079da11c500179bffc5d5ea2',
    'mago_binary_sha256' => hash_file('sha256', $options['mago']),
    'dependency_scope' => $dependencyScope, 'dependency_include_roots' => count($includes),
    'php_cycle_collector' => $phpGc,
    'effective_configuration_probe' => ['verified' => true, 'full_vendor_include' => $fullVendor, 'source_roots' => count($effective['paths'])],
    'extension_request_timeout_ms' => $requestTimeout, 'whole_command_timeout_seconds' => $timeout,
    'mago_threads' => $threads, 'extension_workers' => $workers, 'phpstan_maximum_processes' => $phpstanProcesses,
    'phpstan_level' => str_starts_with($profile, 'phpstan-') ? $phpstanLevel : null,
    'phpstan_config_ignore_count' => isset($parameters) ? count($parameters['ignoreErrors']) : null,
    'phpstan_probe_cache_separate' => isset($parameters) ? true : null,
    'phpstan_tmp_dir_absent_before_start' => $tmpDirAbsent,
    'configuration_probe_max_child_rss_mib' => round($usageBefore['ru_maxrss'] / 1024, 2),
    'vendor_read_only' => true,
    'sealed_workload' => isset($options['source-seal']),
    'wall_seconds' => round(($finished - $start) / 1e9, 4),
    'cpu_seconds' => round(($usage['ru_utime.tv_sec'] - $usageBefore['ru_utime.tv_sec'])
        + ($usage['ru_utime.tv_usec'] - $usageBefore['ru_utime.tv_usec']) / 1e6
        + ($usage['ru_stime.tv_sec'] - $usageBefore['ru_stime.tv_sec'])
        + ($usage['ru_stime.tv_usec'] - $usageBefore['ru_stime.tv_usec']) / 1e6, 4),
    'maximum_child_rss_mib' => round($usage['ru_maxrss'] / 1024, 2),
    'peak_container_memory_mib' => is_readable('/sys/fs/cgroup/memory.peak') ? round((float) file_get_contents('/sys/fs/cgroup/memory.peak') / 1048576, 2) : null,
    'memory_note' => 'RUSAGE_CHILDREN maxrss is the largest individual child RSS, not a process-tree sum. Cgroup memory.peak includes all container processes and charged file cache.',
    'exit_code' => $status['exitcode'], 'timed_out' => $timedOut,
    'cpu_accounting_complete' => ! $timedOut, 'source_unchanged' => false, 'valid' => false];
try {
    if ($collectInputHashes() !== $inputHashes) {
        throw new RuntimeException('Source changed during measurement.');
    }
    $report['source_unchanged'] = true;
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
    if (str_starts_with($profile, 'phpstan-')) {
        $report['fresh_result_cache_confirmed'] = str_contains($stderr, 'Result cache not used because the cache file does not exist.');
        if (! $report['fresh_result_cache_confirmed']) {
            throw new RuntimeException('Fresh result cache was not confirmed.');
        }
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
    if (isset($options['diagnostic-seal'])) {
        $report['diagnostic_multiset_verified'] = false;
        // Keep identities/messages and their fingerprints inside the private volume.
        $canonical = static function (mixed $value) use (&$canonical): mixed {
            if (! is_array($value)) { return $value; }
            if (! array_is_list($value)) { ksort($value); }
            return array_map($canonical, $value);
        };
        $issueHashes = array_map(static fn (array $issue): string => hash('sha256',
            json_encode($canonical($issue), JSON_THROW_ON_ERROR)), $issues);
        sort($issueHashes, SORT_STRING);
        $multiset = hash('sha256', implode("\n", $issueHashes));
        $seal = $options['diagnostic-seal'];
        if (isset($options['create-diagnostic-seal'])) {
            $handle = fopen($seal, 'x');
            if ($handle === false) { throw new RuntimeException('Diagnostic seal already exists.'); }
            $written = fwrite($handle, $multiset."\n");
            fclose($handle);
            if ($written !== strlen($multiset) + 1) { throw new RuntimeException('Cannot seal diagnostics.'); }
        } elseif (! is_file($seal) || trim(file_get_contents($seal)) !== $multiset) {
            throw new RuntimeException('Diagnostic multiset differs from the baseline.');
        }
        $report['diagnostic_multiset_verified'] = true;
    }
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
