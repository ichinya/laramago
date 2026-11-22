<?php

declare(strict_types=1);

// One real Mago run inside a fresh, resource-limited, network-free container.
$options = getopt('', ['payload:', 'workers:', 'deadline:', 'mode:']);
$payload = (int) ($options['payload'] ?? 0);
$workers = (int) ($options['workers'] ?? 0);
$deadline = (int) ($options['deadline'] ?? 0);
$mode = $options['mode'] ?? '';
if (! in_array($payload, [8, 16, 64], true) || ! in_array($workers, [1, 2], true)
    || ! in_array($mode, ['control', 'profile', 'reference'], true)
    || ($mode !== 'reference' && $deadline !== 30000) || $deadline < 30000 || $deadline > 600000) {
    throw new InvalidArgumentException('Invalid measurement settings.');
}
$read = static fn(string $path): string => trim((string) file_get_contents('/sys/fs/cgroup/'.$path));
$cpu = preg_split('/\s+/', $read('cpu.max'));
if ($cpu[0] === 'max' || (int) $cpu[0] !== 2 * (int) $cpu[1] || $read('memory.max') !== '6442450944'
    || PHP_VERSION !== '8.5.12' || ! PHP_ZTS || ini_get('opcache.enable_cli') !== '0') {
    throw new RuntimeException('Require pinned PHP 8.5.12 ZTS, CLI opcache off, 2 CPU / 6 GiB.');
}
require '/package/vendor/autoload.php';
foreach (['carthage-software/mago' => '1.59.0', 'nikic/php-parser' => '5.9.0'] as $name => $version) {
    if (ltrim(Composer\InstalledVersions::getPrettyVersion($name) ?? '', 'v') !== $version) {
        throw new RuntimeException('Dependency version mismatch: '.$name);
    }
}
$binary = '/mago';
if (hash_file('sha256', $binary) !== 'b839641903fe5160093386fbc7750e9fcbad8dd43100669abfb1462a378b8ec5') {
    throw new RuntimeException('Native Mago binary differs from the protocol.');
}
$config = ['version' => '1.59.0', 'php-version' => '8.5', 'threads' => 2,
    'source' => ['paths' => ['/corpus/app']],
    'extension-hosts' => ['laramago' => ['command' => [PHP_BINARY, '-d', 'memory_limit=512M', '-d', 'opcache.enable_cli=0',
        '-d', 'zend.enable_gc=1', '/package/bin/laramago-worker.php', '/package/vendor/autoload.php', '/corpus'],
        'workers' => $workers, 'request-timeout-ms' => $deadline, 'maximum-payload-size' => $payload * 1048576]]];
file_put_contents('/tmp/mago.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$usageBefore = getrusage(1);
$observerBefore = getrusage();
$cpuBefore = $read('cpu.stat');
$pressureBefore = ['cpu' => $read('cpu.pressure'), 'memory' => $read('memory.pressure'), 'io' => $read('io.pressure')];
if ((int) disk_total_space('/tmp') !== 536870912) { throw new RuntimeException('Temporary storage limit differs.'); }
$started = hrtime(true);
$process = proc_open([$binary, '--config', '/tmp/mago.json', 'analyze', '--reporting-format=json'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/output/stdout.json', 'w'], 2 => ['file', '/output/stderr.log', 'w']], $pipes, '/corpus');
if (! is_resource($process)) { throw new RuntimeException('Cannot start Mago.'); }
$scratchPeak = 0;
do {
    $status = proc_get_status($process);
    // statvfs is constant-cost. Walking every spill file here can itself consume
    // a CPU core and distort the deadline being measured.
    $scratch = (int) disk_total_space('/tmp') - (int) disk_free_space('/tmp');
    $scratchPeak = max($scratchPeak, $scratch);
    if (! $status['running']) { break; }
    if ((hrtime(true) - $started) > 900e9) { proc_terminate($process, 9); break; }
    usleep(100000);
} while (true);
$exit = $status['exitcode'];
proc_close($process);
$usage = getrusage(1);
$observer = getrusage();
$cpuSeconds = static fn(array $u): float => $u['ru_utime.tv_sec'] + $u['ru_utime.tv_usec'] / 1e6 + $u['ru_stime.tv_sec'] + $u['ru_stime.tv_usec'] / 1e6;
$report = ['mode' => $mode, 'payload_mib' => $payload, 'workers' => $workers, 'deadline_ms' => $deadline,
    'exit_code' => $exit, 'wall_seconds' => (hrtime(true) - $started) / 1e9,
    'cpu_seconds' => $cpuSeconds($usage) - $cpuSeconds($usageBefore),
    'observer_cpu_seconds' => $cpuSeconds($observer) - $cpuSeconds($observerBefore),
    'maximum_individual_child_rss_bytes' => $usage['ru_maxrss'] * 1024,
    'cgroup_memory_peak_bytes' => (int) $read('memory.peak'), 'cgroup_memory_events' => $read('memory.events'),
    'tmpfs_sampled_peak_allocated_bytes' => $scratchPeak, 'scratch_sampling_ms' => 100,
    'cgroup_cpu_stat_before' => $cpuBefore, 'cgroup_cpu_stat_after' => $read('cpu.stat'),
    'pressure_before' => $pressureBefore, 'pressure_after' => ['cpu' => $read('cpu.pressure'), 'memory' => $read('memory.pressure'), 'io' => $read('io.pressure')],
    'child_cpu_accounting_complete' => in_array($exit, [0, 1], true),
    'environment' => ['php' => PHP_VERSION, 'zts' => PHP_ZTS, 'extensions' => get_loaded_extensions(), 'gc' => gc_enabled(),
        'cpu_max' => $read('cpu.max'), 'memory_max' => $read('memory.max'), 'mago_sha256' => hash_file('sha256', $binary)],
    'effective_config' => $config];
file_put_contents('/output/run.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
echo json_encode(['exit_code' => $exit, 'seconds' => $report['wall_seconds']], JSON_THROW_ON_ERROR)."\n";
