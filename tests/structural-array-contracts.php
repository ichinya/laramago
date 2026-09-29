<?php

declare(strict_types=1);

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago structural arrays '.bin2hex(random_bytes(8));
mkdir($workspace);
file_put_contents($workspace.'/worker.php', <<<'PHP'
<?php
declare(strict_types=1);
require $argv[1];
use Ichinya\Laramago\Analyzer\StructuralArrayContractFilter;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;
final class StructuralArrayPlugin implements Plugin {
    public function getDefinition(): PluginDefinition {
        return new PluginDefinition('structural-array-test', 'Structural array test', 'Test-only array contracts.');
    }
    public function register(PluginRegistry $registry): void {
        $filter = new StructuralArrayContractFilter;
        $registry->registerIssueFilterHook($filter);
        $registry->registerInitializationHook($filter);
    }
}
(new Mago\Sdk\Worker(new Mago\Sdk\Extension(
    identifier: 'structural-array-test', name: 'Structural array test', version: '1',
    analyzerPlugins: [new StructuralArrayPlugin],
)))->run();
PHP);
$source = <<<'PHP'
<?php
namespace Records;
/** @param array{label: string, child: array{id: int}} $record */
function consume(array $record): void {}
/** @param array<string, array{label: string, child: array{id: int}}> $records */
function consumeMap(array $records): void {}
/** @param array{mode: 'read'|'write', child: array{id: int}} $record */
function consumeMode(array $record): void {}
final class Receiver {
    /** @param array{label: string, child: array{id: int}} $record */
    public static function consume(array $record, int $other = 0): void {}
}
/**
 * @phpstan-type RequiredRow array{label: string, child: array{id: int}}
 * @phpstan-type RequiredMap array<string, RequiredRow>
 */
final class AliasReceiver {
    /** @param array<string, RequiredRow> $records */
    public static function consume(array $records): void {}
    /** @param RequiredMap $records */
    public static function consumeAliasMap(array $records): void {}
}
PHP;
$cases = [
    'extra nested fields in argument' => ['array{label: string, child: array{id: int, extra: string}, unused: bool}', 'consume($input);', false],
    'extra nested fields in generic map' => ['array<string, array{label: string, child: array{id: int, extra: string}, unused: bool}>', 'consumeMap($input);', false],
    'named alias map argument' => ['array<string, array{label: string, child: array{id: int, extra: string}, unused: bool}>', 'AliasReceiver::consume($input);', false],
    'nested named alias map argument' => ['array<string, array{label: string, child: array{id: int, extra: string}, unused: bool}>', 'AliasReceiver::consumeAliasMap($input);', false],
    'wrong named alias field' => ['array<string, array{label: int, child: array{id: int, extra: string}, unused: bool}>', 'AliasReceiver::consume($input);', true],
    'method named argument' => ['array{label: string, child: array{id: int, extra: string}, unused: bool}', 'Receiver::consume(other: 1, record: $input);', false],
    'literal union field' => ["array{mode: 'read'|'write', child: array{id: int, extra: string}, unused: list<int>}", 'consumeMode($input);', false],
    'wrong literal union field' => ["array{mode: 'read'|'unknown', child: array{id: int, extra: string}, unused: list<int>}", 'consumeMode($input);', true],
    'wrong nested value' => ['array{label: string, child: array{id: string, extra: string}, unused: bool}', 'consume($input);', true],
    'missing nested field' => ['array{label: string, child: array{extra: string}, unused: bool}', 'consume($input);', true],
    'optional nested required field' => ['array{label: string, child: array{id?: int, extra: string}, unused: bool}', 'consume($input);', true],
    'mixed required field' => ['array{label: string, child: array{id: mixed, extra: string}, unused: bool}', 'consume($input);', true],
    'nullable input union' => ['array{label: string, child: array{id: int, extra: string}, unused: bool}|null', 'consume($input);', true],
    'wrong map keys' => ['array<int, array{label: string, child: array{id: int, extra: string}, unused: bool}>', 'consumeMap($input);', true],
    'wrong other method argument' => ['array{label: string, child: array{id: int, extra: string}, unused: bool}', 'Receiver::consume($input, "wrong");', true],
];
$lines = [];
foreach ($cases as $name => [$type, $body, $invalid]) {
    $source .= "\n/** @param ".$type.' $input */'."\n";
    $source .= 'function scenario'.count($lines).'('.(str_ends_with($type, '|null') ? 'array|null' : 'array').' $input): void { '.$body.' }'."\n";
    $lines[substr_count($source, "\n")] = [$name, $invalid];
}
$properties = [
    'nested property records with extra keys' => ['array{left: array{label: string, child: array{id: int, extra: string}, unused: bool}}', '$this->records = $input;', false],
    'open nested property record' => ['array<string, mixed>', '$input["label"] = "value"; $input["child"] = ["id" => 1]; $this->records = ["left" => $input];', false],
    'wrong nested property type' => ['array{left: array{label: string, child: array{id: string, extra: string}, unused: bool}}', '$this->records = $input;', true],
    'property required field absent' => ['array{left: array{label: string, child: array{extra: string}, unused: bool}}', '$this->records = $input;', true],
    'property union with invalid value' => ['array{left: array{label: string, child: array{id: int|string, extra: string}, unused: bool}}', '$this->records = $input;', true],
];
foreach ($properties as $name => [$type, $body, $invalid]) {
    $source .= "\nfinal class Property".count($lines)." {\n";
    $source .= '/** @var array<string, array{label: string, child: array{id: int}}> */ public array $records = [];'."\n";
    $source .= '/** @param '.$type.' $input */'."\n";
    $source .= 'public function assign(array $input): void { '.$body.' }'."\n";
    $lines[substr_count($source, "\n")] = [$name, $invalid];
    $source .= "}\n";
}
file_put_contents($workspace.'/cases.php', $source);
foreach (['enabled', 'native', 'integrated'] as $mode) {
    if ($mode === 'integrated' && ! in_array('--integrated', $argv, true)) {
        continue;
    }
    file_put_contents($workspace.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml',
        'php-version' => '8.2',
        'source' => ['paths' => ['cases.php']],
        'extension-hosts' => ['structural-array-test' => [
            'command' => [
                PHP_BINARY,
                $mode === 'integrated' ? $package.'/bin/laramago-worker.php' : $workspace.'/worker.php',
                $package.'/vendor/autoload.php',
                $workspace,
            ],
            'workers' => 2,
        ]],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    $process = proc_open(
        [...$command, '--workspace', $workspace, 'analyze', ...($mode === 'native' ? ['--no-extensions'] : []), '--reporting-format=json'],
        [0 => ['pipe', 'r'], 1 => ['file', $workspace.'/'.$mode.'.json', 'w'], 2 => ['file', $workspace.'/'.$mode.'.log', 'w']],
        $pipes,
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start Mago.');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $log = file_get_contents($workspace.'/'.$mode.'.log');
    if (! in_array($exit, [0, 1], true) || preg_match('/External analyzer provider failed|extension worker .*rejected request/i', $log)) {
        throw new RuntimeException('Unexpected Mago failure; inspect '.$workspace.': '.$log);
    }
    $report = json_decode(file_get_contents($workspace.'/'.$mode.'.json'), true, flags: JSON_THROW_ON_ERROR);
    $actual = [];
    foreach ($report['issues'] ?? [] as $issue) {
        if (! in_array($issue['code'], ['possibly-invalid-argument', 'invalid-argument', 'invalid-property-assignment-value', 'possibly-null-argument', 'mixed-argument', 'less-specific-argument'], true)) {
            continue;
        }
        $primary = array_values(array_filter($issue['annotations'], static fn (array $a): bool => $a['kind'] === 'Primary'))[0];
        $actual[$primary['span']['start']['line'] + 1][] = $issue['code'];
    }
    foreach ($lines as $line => [$name, $invalid]) {
        $hasIssue = ($actual[$line] ?? []) !== [];
        if ($mode !== 'native' && $hasIssue !== $invalid || $mode === 'native' && ! $hasIssue) {
            throw new RuntimeException($mode.' '.$name.': expected '.($invalid ? 'diagnostic' : 'compatible contract').', got '.json_encode($actual[$line] ?? []).'; inspect '.$workspace);
        }
        unset($actual[$line]);
        echo 'PASS: '.$mode.' '.$name.PHP_EOL;
    }
    if ($actual !== []) {
        throw new RuntimeException('Unexpected diagnostics outside scenarios; inspect '.$workspace);
    }
}
echo 'Structural array contracts: '.count($lines).' cases passed with native controls.'.PHP_EOL;
$resolved = realpath($workspace);
$temporary = realpath(sys_get_temp_dir());
if ($resolved === false || $temporary === false || ! str_starts_with($resolved, $temporary.DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Refusing cleanup outside the temporary directory.');
}
foreach (new DirectoryIterator($resolved) as $file) {
    if ($file->isFile()) {
        unlink($file->getPathname());
    }
}
rmdir($resolved);
