<?php

declare(strict_types=1);
$package = str_replace('\\', '/', dirname(__DIR__));
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago never returns '.bin2hex(random_bytes(8));
mkdir($workspace);
file_put_contents($workspace.'/composer.json', '{}');
file_put_contents($workspace.'/library.php', <<<'PHP'
    <?php
    function terminate(): never { throw new LogicException('Expected failure'); }
    /** @return never */
    function documented() { throw new LogicException('Expected failure'); }
    PHP);
$source = <<<'PHP'
    <?php
    function direct(): int { return terminate(); }
    function docOnly(): int { return documented(); }
    function outerNever(): never { return terminate(); }
    function outerVoid(): void { return terminate(); }
    final class Owner {
        public function handle(): int { return $this->stop(); }
        private function stop(): never { throw new LogicException('Expected failure'); }
    }
    function realReturn(): int { return 'wrong'; }
    function badArguments(): int { return terminate('extra'); }
    PHP;
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml', 'php-version' => '8.2', 'source' => ['paths' => ['cases.php'], 'includes' => ['library.php']],
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
$expected = [2 => [], 3 => ['never-return'], 4 => ['never-return', 'semantics'], 5 => ['never-return', 'semantics', 'invalid-return-statement'], 7 => [], 10 => ['invalid-return-statement'], 11 => ['too-many-arguments']];
if (in_array('--disabled', $argv, true)) {
    $expected[2] = $expected[7] = ['never-return'];
    $expected[11][] = 'never-return';
}
foreach ($expected as $line => $codes) {
    $got = $actual[$line] ?? []; sort($got); sort($codes);
    if ($got !== $codes) { throw new RuntimeException('line '.$line.': expected '.json_encode($codes).', got '.json_encode($got).'; inspect '.$workspace); }
    unset($actual[$line]); echo 'PASS: never-return line '.$line."\n";
}
if ($actual !== []) { throw new RuntimeException('Unexpected diagnostics: '.$workspace); }
