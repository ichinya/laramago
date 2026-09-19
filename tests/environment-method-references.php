<?php

declare(strict_types=1);

// Analyze generated source with the real Mago worker; do not execute application PHP.
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago env method '.bin2hex(random_bytes(8));
$mode = $argv[1] ?? 'native';
if (! in_array($mode, ['native', 'disabled', 'custom-env'], true)) {
    throw new RuntimeException('Unknown mode.');
}
mkdir($workspace);
$framework = $workspace.'/vendor/laravel/framework/src/Illuminate/Support';
mkdir($framework, 0777, true);
$envPath = $mode === 'custom-env' ? $workspace.'/custom/Env.php' : $framework.'/Env.php';
if ($mode === 'custom-env') {
    mkdir($workspace.'/custom');
}
// Preserve the native public static get($key, $default = null) declaration and
// argument positions. The hook does not inspect or infer the method's value.
file_put_contents($envPath, <<<'PHP'
    <?php
    namespace Illuminate\Support;
    class Env {
        public static function get($key, $default = null) { return $default; }
    }
    PHP);
file_put_contents($workspace.'/composer.json', json_encode([
    'extra' => [
        'laramago' => [
            'environment-names' => [
                'complete' => $mode !== 'disabled',
                'names' => ['APP_ENV'],
            ],
        ],
    ],
], JSON_THROW_ON_ERROR));

$missing = ['ichinya/laramago/laramago-uncataloged-environment-name'];
$cases = [
    'known' => ['Env::get("APP_ENV");', []],
    'uncataloged' => ['Env::get("TYPO");', $missing],
    'fallback still uncataloged' => ['Env::get("TYPO", "fallback");', $missing],
    'named reordered' => ['Env::get(default: 42, key: "TYPO");', $missing],
    'qualified' => ['\\Illuminate\\Support\\Env::get("TYPO");', $missing],
    'imported alias' => ['LaravelEnv::get("TYPO");', $missing],
    'case sensitive' => ['Env::get("app_env");', $missing],
    'dynamic key' => ['Env::get($key);', []],
    'concatenated key' => ['Env::get("T".$key);', []],
    'unpacked' => ['Env::get(...["TYPO"]);', []],
    'first-class callable' => ['$callable = Env::get(...);', []],
    'custom class' => ['\\App\\OtherEnv::get("TYPO");', []],
    'subclass' => ['\\App\\DerivedEnv::get("TYPO");', []],
];
$source = "<?php\nnamespace App {\nuse Illuminate\\Support\\Env;\nuse Illuminate\\Support\\Env as LaravelEnv;\n";
$source .= "class OtherEnv { public static function get(\$key, \$default = null) { return \$default; } }\n";
$source .= "class DerivedEnv extends Env {}\n";
$expected = [];
$expectedSpans = [];
foreach ($cases as $label => [$body, $codes]) {
    $source .= 'function case'.count($expected).'(string $key): void { '.$body." }\n";
    $line = substr_count($source, "\n");
    $expected[$line] = [$label, $mode === 'native' ? $codes : []];
    if ($codes !== []) {
        $expectedSpans[$line] = ['"'.($label === 'case sensitive' ? 'app_env' : 'TYPO').'"'];
    }
}
$source .= "}\n";
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => [
        'paths' => ['cases.php'],
        'includes' => [substr($envPath, strlen($workspace) + 1)],
    ],
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
            'workers' => 2,
        ],
    ],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$process = proc_open(
    [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
    [0 => ['pipe', 'r'], 1 => ['file', $workspace.'/report.json', 'w'], 2 => ['file', $workspace.'/stderr.log', 'w']],
    $pipes,
);
if (! is_resource($process)) {
    throw new RuntimeException('Cannot start Mago.');
}
fclose($pipes[0]);
$exit = proc_close($process);
$report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
$actual = [];
$spans = [];
foreach ($report['issues'] ?? [] as $issue) {
    $primary = array_values(array_filter(
        $issue['annotations'],
        static fn (array $a): bool => $a['kind'] === 'Primary',
    ))[0];
    $line = $primary['span']['start']['line'] + 1;
    $actual[$line][] = $issue['code'];
    if ($issue['code'] === 'ichinya/laramago/laramago-uncataloged-environment-name') {
        $spans[$line][] = substr(
            $source,
            $primary['span']['start']['offset'],
            $primary['span']['end']['offset'] - $primary['span']['start']['offset'],
        );
    }
}
foreach ($expected as $line => [$label, $codes]) {
    sort($codes);
    $actual[$line] ??= [];
    sort($actual[$line]);
    if ($actual[$line] !== $codes) {
        throw new RuntimeException(
            $mode
            .' '
            .$label
            .': expected '
            .json_encode($codes)
            .', got '
            .json_encode($actual[$line])
            .'; inspect '
            .$workspace,
        );
    }
    if ($codes !== [] && ($spans[$line] ?? []) !== $expectedSpans[$line]) {
        throw new RuntimeException($mode.' '.$label.': wrong literal span; inspect '.$workspace);
    }
    unset($actual[$line]);
    echo 'PASS: '.$mode.' '.$label."\n";
}
if (
    $actual !== []
    || preg_match(
        '/External analyzer provider failed|extension worker .*rejected request/i',
        file_get_contents($workspace.'/stderr.log'),
    )
) {
    throw new RuntimeException('Unexpected issues or worker failure; inspect '.$workspace);
}
if ($exit !== 0) {
    throw new RuntimeException('Unexpected exit '.$exit.'; inspect '.$workspace);
}
