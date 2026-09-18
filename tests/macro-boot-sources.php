<?php

declare(strict_types=1);

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago macro boot '.bin2hex(random_bytes(8));
mkdir($workspace);
mkdir($workspace.'/app');
copy(__DIR__.'/fixtures/analysis/macro-callables.php.stub', $workspace.'/framework.php');
$provider = <<<'PHP'
    <?php
    namespace App;
    use Illuminate\Support\ServiceProvider;
    use CallableMacroBox as Box;
    final class ActiveMacros extends ServiceProvider
    {
        public function boot(): void
        {
            Box::macro('bootlabel', fn (int $number): string => throw new \RuntimeException('Application executed'));
            Box::macro('mixedCase', fn (int $number): string => 'label');
            Box::macro('native', fn (): int => 1);
            \DocumentedCallableBox::macro('documented', fn (int $value): int => $value);
        }
    }
    PHP;
file_put_contents($workspace.'/app/ActiveMacros.php', $provider);
$manifest = ['extra' => ['laramago' => ['macro-service-providers' => ['App\\ActiveMacros' => 'app/ActiveMacros.php']]]];
file_put_contents($workspace.'/composer.json', json_encode($manifest));
$deferred = ['mixed-return-statement', 'non-documented-method'];
$cases = [
    'active boot closure return' => ['return CallableMacroBox::bootlabel(1);', 'string', []],
    'active boot instance dispatch' => ['return (new CallableMacroBox)->bootlabel(1);', 'string', []],
    'active boot invalid argument' => ["return CallableMacroBox::bootlabel('bad');", 'string', ['invalid-argument']],
    'mixed-case static call preserves literal name' => ['return CallableMacroBox::mixedCase(1);', 'string', []],
    'mixed-case instance SDK lookup remains deferred' => [
        'return (new CallableMacroBox)->mixedCase(1);',
        'string',
        $deferred,
    ],
    'native priority' => ['return CallableMacroBox::native();', 'string', []],
    'PHPDoc priority' => ['return DocumentedCallableBox::documented(1);', 'string', []],
    'unregistered macro' => ['return CallableMacroBox::missing();', 'string', $deferred],
];
$source = "<?php\n";
$lines = [];
foreach ($cases as $name => [$body, $return, $codes]) {
    $source .= "/**\n * @return ".$return."\n */\n";
    $source .= 'function scenario'.count($lines).'() { '.$body.' }'."\n";
    $lines[substr_count($source, "\n")] = [$name, $codes];
}
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => ['paths' => ['cases.php'], 'includes' => ['framework.php']],
    'extension-hosts' => [
        'laramago' => [
            'command' => [
                PHP_BINARY,
                $package.'/bin/laramago-worker.php',
                $package.'/vendor/autoload.php',
                $workspace,
            ],
            'workers' => 1,
        ],
    ],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

$analyze = static function (string $report, string $log) use ($command, $workspace): int {
    $process = proc_open(
        [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $workspace.'/'.$report, 'w'],
            2 => ['file', $workspace.'/'.$log, 'w'],
        ],
        $pipes,
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start Mago.');
    }
    fclose($pipes[0]);

    return proc_close($process);
};

$exit = $analyze('report.json', 'stderr.log');
$log = file_get_contents($workspace.'/stderr.log');
if ($exit !== 1 || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)) {
    throw new RuntimeException('Expected native diagnostics without extension fallback; inspect '.$workspace);
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
            $name.': expected '.json_encode($expected).', got '.json_encode($codes).'; see '.$workspace,
        );
    }
    unset($actual[$line]);
    echo 'PASS: '.$name."\n";
}
if ($actual !== []) {
    throw new RuntimeException('Unexpected diagnostics; inspect '.$workspace);
}

file_put_contents(
    $workspace.'/cases.php',
    '<?php function checkBoot(): string { return CallableMacroBox::bootlabel(1); }',
);
$variants = [
    'conditional boot defers' => str_replace("Box::macro('bootlabel'", "if (true) Box::macro('bootlabel'", $provider),
    'custom register defers' => str_replace(
        'public function boot()',
        'public function register(): void {} public function boot()',
        $provider,
    ),
    'inherited boot defers' => str_replace('extends ServiceProvider', 'extends CustomProvider', $provider),
    'boot arguments defer' => str_replace('boot()', 'boot(int $value)', $provider),
    'static boot defers' => str_replace('public function boot()', 'public static function boot()', $provider),
    'early return defers' => str_replace("Box::macro('bootlabel'", "return; Box::macro('bootlabel'", $provider),
    'nested helper call defers' => str_replace("Box::macro('bootlabel'", "helper(); Box::macro('bootlabel'", $provider),
    'contextual receiver defers' => str_replace('Box::macro', 'self::macro', $provider),
    'executed top level defers' => $provider."\nthrow new \\RuntimeException('Executed');",
];
foreach ($variants as $name => $variant) {
    file_put_contents($workspace.'/app/ActiveMacros.php', $variant);
    $exit = $analyze('negative.json', 'negative.log');
    $report = json_decode(file_get_contents($workspace.'/negative.json'), true, flags: JSON_THROW_ON_ERROR);
    $codes = array_column($report['issues'] ?? [], 'code');
    sort($codes);
    if ($exit !== 1 || $codes !== $deferred) {
        throw new RuntimeException($name.': '.json_encode($codes).'; inspect '.$workspace);
    }
    echo 'PASS: '.$name."\n";
}
file_put_contents($workspace.'/app/ActiveMacros.php', $provider);
file_put_contents($workspace.'/composer.json', '{}');
$exit = $analyze('absent.json', 'absent.log');
$report = json_decode(file_get_contents($workspace.'/absent.json'), true, flags: JSON_THROW_ON_ERROR);
$codes = array_column($report['issues'] ?? [], 'code');
sort($codes);
if ($exit !== 1 || $codes !== $deferred) {
    throw new RuntimeException('Unlisted provider must remain unknown; inspect '.$workspace);
}
echo "PASS: unlisted existing provider remains unknown\n";
$config = json_decode(file_get_contents($workspace.'/mago.json'), true, flags: JSON_THROW_ON_ERROR);
unset($config['extension-hosts']);
file_put_contents($workspace.'/mago.json', json_encode($config));
file_put_contents($workspace.'/composer.json', json_encode($manifest));
$exit = $analyze('disabled.json', 'disabled.log');
$report = json_decode(file_get_contents($workspace.'/disabled.json'), true, flags: JSON_THROW_ON_ERROR);
$codes = array_column($report['issues'] ?? [], 'code');
sort($codes);
if ($exit !== 1 || $codes !== $deferred) {
    throw new RuntimeException('Disabled extension must retain diagnostics; inspect '.$workspace);
}
echo "PASS: provider-disabled comparison retains native diagnostics\n";
