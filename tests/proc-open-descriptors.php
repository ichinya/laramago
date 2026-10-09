<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago proc descriptors '.bin2hex(random_bytes(8));
mkdir($workspace);
file_put_contents($workspace.'/composer.json', '{}');
$cases = [
    'native redirect' => ['[0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["redirect", 1]]', []],
    'native ordinary descriptors' => ['[0 => ["pipe", "r"], 1 => ["pipe", "w"]]', []],
    'string redirect target rejected' => ['[2 => ["redirect", "1"]]', ['possibly-invalid-argument']],
    'missing redirect target rejected' => ['[2 => ["redirect"]]', ['possibly-invalid-argument']],
    'negative redirect target deferred' => ['[2 => ["redirect", -1]]', ['possibly-invalid-argument']],
    'unproven auxiliary target deferred' => ['[2 => ["redirect", 9]]', ['possibly-invalid-argument']],
    'unknown descriptor rejected' => ['[2 => ["mystery", 1]]', ['possibly-invalid-argument']],
    'invalid other slot retained' => ['[1 => ["pipe", "invalid"], 2 => ["redirect", 1]]', ['possibly-invalid-argument']],
];
if (in_array('--disabled', $argv, true)) { $cases['native redirect'][1] = ['possibly-invalid-argument']; }
$source = "<?php\n";
$lines = [];
foreach ($cases as $name => [$descriptor, $errors]) {
    $source .= 'function scenario'.count($lines).'(): void { $pipes = []; proc_open(["php"], '.$descriptor.', $pipes); }'."\n";
    $lines[substr_count($source, "\n")] = [$name, $errors];
}
$source .= 'function initializedPipes(): void { proc_open(["php"], [1 => ["pipe", "w"], 2 => ["redirect", 1]], $pipes); foreach ($pipes ?? [] as $pipe) { fclose($pipe); } }'."\n";
$lines[substr_count($source, "\n")] = ['native param-out pipe resources retained', in_array('--disabled', $argv, true) ? ['mixed-argument', 'possibly-invalid-argument'] : []];
$source .= 'function ordinaryPipes(): void { proc_open(["php"], [1 => ["pipe", "w"]], $pipes); foreach ($pipes ?? [] as $pipe) { fclose($pipe); } }'."\n";
$lines[substr_count($source, "\n")] = ['ordinary descriptor pipe output comparison', in_array('--disabled', $argv, true) ? ['mixed-argument'] : []];
$source .= 'function badCwd(): void { $pipes = []; proc_open(["php"], [2 => ["redirect", 1]], $pipes, []); }'."\n";
$lines[substr_count($source, "\n")] = ['other parameter mismatch retained', in_array('--disabled', $argv, true) ? ['invalid-argument', 'possibly-invalid-argument'] : ['invalid-argument']];
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml', 'php-version' => '8.2', 'source' => ['paths' => ['cases.php']],
    'analyzer' => ['disable-default-plugins' => in_array('--disabled', $argv, true)],
    'extension-hosts' => ['laramago' => ['command' => [PHP_BINARY, $package.'/bin/laramago-worker.php', $package.'/vendor/autoload.php', $workspace], 'workers' => 1]],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$process = proc_open([...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
    [0 => ['pipe', 'r'], 1 => ['file', $workspace.'/report.json', 'w'], 2 => ['file', $workspace.'/stderr.log', 'w']], $pipes);
if (! is_resource($process)) { throw new RuntimeException('Cannot start Mago.'); }
fclose($pipes[0]); $exit = proc_close($process);
$log = file_get_contents($workspace.'/stderr.log');
if ($exit !== 1 || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)) { throw new RuntimeException('Mago failed: '.$workspace.' '.$log); }
$actual = [];
foreach (json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR)['issues'] ?? [] as $issue) {
    if ($issue['level'] !== 'Error') { continue; }
    $primary = array_values(array_filter($issue['annotations'], static fn (array $a): bool => $a['kind'] === 'Primary'))[0];
    $actual[$primary['span']['start']['line'] + 1][] = $issue['code'];
}
foreach ($lines as $line => [$name, $expected]) {
    $codes = $actual[$line] ?? []; sort($codes); sort($expected);
    if ($codes !== $expected) { throw new RuntimeException($name.': expected '.json_encode($expected).', got '.json_encode($codes).'; inspect '.$workspace); }
    unset($actual[$line]); echo 'PASS: '.$name."\n";
}
if ($actual !== []) { throw new RuntimeException('Unexpected diagnostics: '.$workspace); }
// Exercise only this fixed local child program, never any analyzed command expression.
$process = proc_open([PHP_BINARY, '-r', 'fwrite(STDOUT, "stdout\n"); fwrite(STDERR, "stderr\n");'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
if (! is_resource($process)) { throw new RuntimeException('Native redirect failed.'); }
fclose($pipes[0]); $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
if (proc_close($process) !== 0 || $output !== "stdout\nstderr\n") { throw new RuntimeException('Native redirected drain contract failed.'); }
echo "PASS: native merged stdout/stderr drain\n";
