<?php

declare(strict_types=1);

// Real native Codebase contexts; invented PHP bodies are parsed, never executed.
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago configuration writes '.bin2hex(random_bytes(8));
$sdk = getenv('MAGO_SDK_AUTOLOAD') ?: $package.'/vendor/autoload.php';
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
mkdir($workspace, 0777, true);
$cases = [
    'dynamic' => ['config($key);', true],
    'escaped' => ['$repository = config();', true],
    'resolved' => ['$repository = app("config");', true],
    'callable' => ['$write = config(...);', true],
    'spread' => ['config([...$values]);', true],
    'malformed' => ['if (', true],
    'literal' => ['config(["changed.child" => 42]);', false],
    'prefix' => ['config(["changed.".$suffix => 42]);', false],
    'facade' => ['\\Illuminate\\Support\\Facades\\Config::set("changed.child", 42);', false],
    'direct' => ['config()->set("changed.child", 42);', false],
    'empty' => ['', false],
    // Metadata survives removal of its source; it must still fail closed.
    'metadata-class' => ['', true],
    'metadata-function' => ['', true],
];
$includes = [];
foreach ($cases as $name => [$body]) {
    $caseRoot = str_starts_with($name, 'metadata-') ? $workspace.'-sources/'.$name : $workspace.'/'.$name;
    mkdir($caseRoot, 0777, true);
    file_put_contents($caseRoot.'/a.php', '<?php '.$body);
    file_put_contents(
        $caseRoot.'/z.php',
        '<?php throw new \\RuntimeException("Source executed"); config(["later.child" => 1]);',
    );
    if (str_starts_with($name, 'metadata-')) {
        mkdir($caseRoot.'/extra');
        $declaration = $name === 'metadata-class'
            ? 'class ConfigurationWriteMetadata { public function mutate(): void { config(["changed.child" => 1]); } }'
            : 'function configurationWriteMetadata(): void { config(["changed.child" => 1]); }';
        $file = $caseRoot.'/extra/metadata.php';
        file_put_contents($file, '<?php '.$declaration);
        $includes[] = $file;
    }
}
// Only the observer is an analyzed file. The other files are offline sources.
file_put_contents($workspace.'/anchor.php', '<?php function configurationWriteAnchor(): void {}');
file_put_contents($workspace.'/cases.json', json_encode($cases, JSON_THROW_ON_ERROR));
file_put_contents($workspace.'/mago.json', json_encode([
    'php-version' => '8.2',
    'threads' => 1,
    'source' => ['paths' => ['anchor.php'], 'includes' => $includes],
    'extension-hosts' => [
        'writes' => [
            'command' => [
                PHP_BINARY,
                $package.'/tests/fixtures/configuration-writes/worker.php',
                $sdk,
                $package,
                $workspace,
            ],
            'workers' => 1,
            'working-directory' => $workspace,
        ],
    ],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$process = proc_open(
    [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json', '--minimum-fail-level=help'],
    [
        0 => ['pipe', 'r'],
        1 => ['file', $workspace.'/native.json', 'w'],
        2 => ['file', $workspace.'/native.stderr.log', 'w'],
    ],
    $pipes,
);
if (! is_resource($process)) {
    throw new RuntimeException('Cannot start native configuration write test.');
}
fclose($pipes[0]);
$exit = proc_close($process);
if ($exit !== 0) {
    throw new RuntimeException('Native configuration write test failed: '.$workspace);
}
$report = json_decode(file_get_contents($workspace.'/native.json'), true, flags: JSON_THROW_ON_ERROR);
$checks = is_file($workspace.'/checks.json')
    ? json_decode(file_get_contents($workspace.'/checks.json'), true, flags: JSON_THROW_ON_ERROR)
    : [];
if (
    $exit !== 0
    || ($report['issues'] ?? null) !== []
    || count($checks) !== count($cases)
    || in_array(false, $checks, true)
) {
    throw new RuntimeException('Configuration write regression failed: '.$workspace);
}
echo 'PASS: '.count($checks).' native configuration write and early-stop controls; '.$workspace.PHP_EOL;
