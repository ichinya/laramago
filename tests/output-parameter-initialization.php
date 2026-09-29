<?php

declare(strict_types=1);

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago output parameters '.bin2hex(random_bytes(8));
mkdir($workspace);
file_put_contents($workspace.'/bootstrap.php', '<?php throw new RuntimeException("Never bootstrap test projects.");');
file_put_contents($workspace.'/composer.json', json_encode([
    'autoload' => ['files' => ['bootstrap.php']],
], JSON_THROW_ON_ERROR));
file_put_contents($workspace.'/worker.php', <<<'PHP'
<?php
declare(strict_types=1);
require $argv[1];
use Ichinya\Laramago\Analyzer\OutputParameterInitializationFilter;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;
final class OutputParameterPlugin implements Plugin {
    public function getDefinition(): PluginDefinition {
        return new PluginDefinition('output-parameter-test', 'Output parameter test', 'Test-only native output contract.');
    }
    public function register(PluginRegistry $registry): void {
        $filter = new OutputParameterInitializationFilter;
        $registry->registerIssueFilterHook($filter);
        $registry->registerInitializationHook($filter);
    }
}
(new Mago\Sdk\Worker(new Mago\Sdk\Extension(
    identifier: 'output-parameter-test',
    name: 'Output parameter test',
    version: '1',
    analyzerPlugins: [new OutputParameterPlugin],
)))->run();
PHP);
$cases = [
    'preg_match initializes matches' => ['preg_match("/a/", "a", $matches);', []],
    'preg_match_all initializes matches' => ['preg_match_all("/a/", "a", $matches);', []],
    'proc_open initializes pipes' => ['proc_open("php", [], $pipes);', []],
    'proc_open replaces mixed pipes' => ['proc_open("php", [], $input);', []],
    'named proc_open output' => ['proc_open(pipes: $input, command: "php", descriptor_spec: []);', []],
    'mixed command remains unsafe' => ['proc_open($input, [], $pipes);', ['mixed-argument']],
    'named matches initializes' => ['preg_match(pattern: "/a/", subject: "a", matches: $matches);', []],
    'other by reference retains warning' => ['customOutput($variable);', ['reference-to-undefined-variable']],
    'reference assignment retains warning' => ['$reference =& $variable;', ['reference-to-undefined-variable']],
    'undefined read before output retains error' => ['$read = $variable; preg_match("/a/", "a", $variable);', ['undefined-variable']],
    'invalid argument retains error' => ['preg_match([], "a", $matches);', ['invalid-argument']],
];
$source = <<<'PHP'
<?php
function customOutput(&$output): void {}
PHP;
$lines = [];
foreach ($cases as $name => [$body, $expected]) {
    $source .= 'function scenario'.count($lines).'(mixed $input): void { '.$body.' }'."\n";
    $lines[substr_count($source, "\n")] = [$name, $expected];
}
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/shadow.php', <<<'PHP'
<?php
namespace Shadow;
function preg_match($pattern, $subject, &$matches): bool { return false; }
function custom(): void {
    preg_match('/a/', 'a', $matches);
}
PHP);
file_put_contents($workspace.'/mago.json', json_encode([
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => ['paths' => ['cases.php', 'shadow.php']],
    'extension-hosts' => ['output-parameter-test' => [
        'command' => [PHP_BINARY, $workspace.'/worker.php', $package.'/vendor/autoload.php'],
        'workers' => 1,
    ]],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
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
$log = file_get_contents($workspace.'/stderr.log');
if ($exit !== 1 || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)) {
    throw new RuntimeException('Unexpected Mago result; inspect '.$workspace.' (exit '.$exit.'): '.$log);
}
$report = json_decode(file_get_contents($workspace.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
$actual = [];
foreach ($report['issues'] ?? [] as $issue) {
    if (! in_array($issue['code'], [
        'reference-to-undefined-variable', 'undefined-variable', 'invalid-argument', 'mixed-argument',
    ], true)) {
        continue;
    }
    $primary = array_values(array_filter($issue['annotations'], static fn (array $annotation): bool => $annotation['kind'] === 'Primary'))[0];
    $file = $primary['span']['file_id']['name'];
    $line = $primary['span']['start']['line'] + 1;
    $actual[$file][$line][] = $issue['code'];
}
foreach ($lines as $line => [$name, $expected]) {
    $codes = $actual['cases.php'][$line] ?? [];
    sort($codes);
    sort($expected);
    if ($codes !== $expected) {
        throw new RuntimeException($name.': expected '.json_encode($expected).', got '.json_encode($codes).'; inspect '.$workspace);
    }
    unset($actual['cases.php'][$line]);
    echo 'PASS: '.$name."\n";
}
$shadowCodes = $actual['shadow.php'][5] ?? [];
sort($shadowCodes);
if ($shadowCodes !== ['reference-to-undefined-variable']) {
    throw new RuntimeException('Namespaced shadow retains native warning: '.json_encode($shadowCodes).'; inspect '.$workspace);
}
unset($actual['shadow.php'][5]);
if (array_filter($actual)) {
    throw new RuntimeException('Unexpected diagnostics outside scenarios; inspect '.$workspace);
}
$nativeProcess = proc_open(
    [...$command, '--workspace', $workspace, 'analyze', '--no-extensions', '--reporting-format=json'],
    [0 => ['pipe', 'r'], 1 => ['file', $workspace.'/native-report.json', 'w'], 2 => ['file', $workspace.'/native-stderr.log', 'w']],
    $nativePipes,
);
if (! is_resource($nativeProcess)) {
    throw new RuntimeException('Cannot start native Mago control.');
}
fclose($nativePipes[0]);
if (proc_close($nativeProcess) !== 1) {
    throw new RuntimeException('Unexpected native Mago result; inspect '.$workspace);
}
$native = json_decode(file_get_contents($workspace.'/native-report.json'), true, flags: JSON_THROW_ON_ERROR);
$nativeWarnings = [];
foreach ($native['issues'] ?? [] as $issue) {
    if ($issue['code'] !== 'reference-to-undefined-variable') {
        continue;
    }
    $primary = array_values(array_filter($issue['annotations'], static fn (array $annotation): bool => $annotation['kind'] === 'Primary'))[0];
    $nativeWarnings[$primary['span']['file_id']['name']][$primary['span']['start']['line'] + 1] = true;
}
foreach (['preg_match initializes matches', 'preg_match_all initializes matches', 'proc_open initializes pipes', 'named matches initializes'] as $name) {
    $line = array_search($name, array_column($lines, 0), true);
    $line = array_keys($lines)[$line];
    if (! isset($nativeWarnings['cases.php'][$line])) {
        throw new RuntimeException('Native Mago did not emit control warning for '.$name.'; inspect '.$workspace);
    }
}
$shadowLine = 5;
if (! isset($nativeWarnings['shadow.php'][$shadowLine])) {
    throw new RuntimeException('Native Mago did not emit the shadow-function control warning; inspect '.$workspace);
}
$resolved = realpath($workspace);
$temporary = realpath(sys_get_temp_dir());
if ($resolved === false || $temporary === false || ! str_starts_with($resolved, $temporary.DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Refusing cleanup outside the temporary directory.');
}
$items = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
);
foreach ($items as $item) {
    $path = $item->getPathname();
    if (! str_starts_with($path, $resolved.DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Refusing cleanup outside the test workspace.');
    }
    $item->isDir() ? rmdir($path) : unlink($path);
}
rmdir($resolved);
