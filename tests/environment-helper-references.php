<?php

declare(strict_types=1);

// Analyze generated source with the real Mago worker. Never execute application PHP.
// Laravel excerpts: Copyright (c) Taylor Otwell. See fixtures/analysis/configuration-LICENSE.md.
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago env helper '.bin2hex(random_bytes(8));
$mode = $argv[1] ?? 'native';
if (! in_array($mode, ['native', 'disabled', 'custom-helper', 'custom-env', 'changed-forward'], true)) {
    throw new RuntimeException('Unknown mode.');
}
mkdir($workspace);
$framework = $workspace.'/vendor/laravel/framework/src/Illuminate/Support';
mkdir($framework, 0777, true);
$helperPath = $mode === 'custom-helper' ? $workspace.'/custom/helpers.php' : $framework.'/helpers.php';
if ($mode === 'custom-helper') {
    mkdir($workspace.'/custom');
}
$helper = <<<'PHP'
    <?php
    use Illuminate\Support\Env;
    if (! function_exists('env')) {
        function env($key, $default = null)
        {
            return Env::get($key, $default);
        }
    }
    PHP;
if ($mode === 'changed-forward') {
    $helper = str_replace('return Env::get($key, $default);', 'return $default;', $helper);
}
file_put_contents($helperPath, $helper);
$envPath = $mode === 'custom-env' ? $workspace.'/custom/Env.php' : $framework.'/Env.php';
if ($mode === 'custom-env') {
    mkdir($workspace.'/custom');
}
file_put_contents($envPath, <<<'PHP'
    <?php
    namespace Illuminate\Support;
    class Env {
        public static function get($key, $default = null) { return null; }
    }
    PHP);
$complete = $mode !== 'disabled';
file_put_contents($workspace.'/composer.json', json_encode([
    'extra' => ['laramago' => ['environment-names' => ['complete' => $complete, 'names' => ['APP_ENV']]]],
], JSON_THROW_ON_ERROR));

$cases = [
    'known name' => ['env("APP_ENV");', []],
    'uncataloged name' => ['env("TYPO");', ['ichinya/laramago/laramago-uncataloged-environment-name']],
    'fallback still uncataloged' => [
        'env("TYPO", "fallback");',
        ['ichinya/laramago/laramago-uncataloged-environment-name'],
    ],
    'named key' => ['env(key: "TYPO");', ['ichinya/laramago/laramago-uncataloged-environment-name']],
    'qualified helper' => ['\\env("TYPO");', ['ichinya/laramago/laramago-uncataloged-environment-name']],
    'imported helper' => ['native_env("TYPO");', ['ichinya/laramago/laramago-uncataloged-environment-name']],
    'case sensitive' => ['env("app_env");', ['ichinya/laramago/laramago-uncataloged-environment-name']],
    'dynamic name' => ['env($key);', []],
    'concatenated name' => ['env("T".$key);', []],
    'unpacked arguments' => ['env(...["TYPO"]);', []],
    'first-class callable' => ['$callable = env(...);', []],
];
$source = "<?php\nnamespace App {\nuse function env as native_env;\n";
$expected = [];
$expectedSpans = [];
foreach ($cases as $label => [$body, $codes]) {
    $source .= 'function case'.count($expected).'(string $key): void { '.$body." }\n";
    $line = substr_count($source, "\n");
    $expected[$line] = [$label, $complete && $mode === 'native' ? $codes : []];
    if ($codes !== []) {
        $expectedSpans[$line] = ['"'.($label === 'case sensitive' ? 'app_env' : 'TYPO').'"'];
    }
}
$source .= "}\nnamespace Custom {\nfunction env(string \$key): string { return \$key; }\n";
$source .= "function local(): void { env(\"TYPO\"); }\n}\n";
$localLine = substr_count($source, "\n") - 1;
$expected[$localLine] = ['namespaced custom helper', []];
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => [
        'paths' => ['cases.php'],
        'includes' => [
            substr($helperPath, strlen($workspace) + 1),
            substr($envPath, strlen($workspace) + 1),
        ],
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
