<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago numeric arguments '.bin2hex(random_bytes(8));
mkdir($workspace);
$worker = <<<'PHP'
<?php
require $argv[1];
$plugin = new class implements \Mago\Sdk\Analyzer\Plugin {
    public function getDefinition(): \Mago\Sdk\Analyzer\PluginDefinition {
        return new \Mago\Sdk\Analyzer\PluginDefinition('numeric-fixture', 'Numeric fixture', 'Numeric subtype compatibility');
    }
    public function register(\Mago\Sdk\Analyzer\PluginRegistry $registry): void {
        $filter = new \Ichinya\Laramago\Analyzer\NumericArgumentCompatibilityFilter;
        $registry->registerIssueFilterHook($filter);
        $registry->registerInitializationHook($filter);
    }
};
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension(
    identifier: 'numeric-fixture', name: 'Numeric fixture', version: '1', analyzerPlugins: [$plugin],
)))->run();
PHP;
file_put_contents($workspace.'/worker.php', $worker);
$declarations = <<<'PHP'
<?php
declare(strict_types=1);
function acceptNumber(int|float|string $value): void {}
function acceptPair(int $id, int|float|string $value): void {}
/** @param int|float|numeric-string $value */ function acceptNumeric(int|float|string $value): void {}
function acceptArithmetic(int|float $value): void {}
function acceptString(string $value): void {}
/** @param positive-int|float|numeric-string $value */ function acceptPositive(int|float|string $value): void {}
class Receiver { public function accept(int|float|string $value): void {} }
class StaticReceiver { public static function accept(int|float|string $value): void {} }
PHP;
$cases = [
    'function numeric union' => ['function number(mixed $v): void { if (is_numeric($v)) { acceptNumber($v); } }', true],
    'numeric string union' => ['function numeric(mixed $v): void { if (is_numeric($v)) { acceptNumeric($v); } }', true],
    'method numeric union' => ['function method(Receiver $r, mixed $v): void { if (is_numeric($v)) { $r->accept($v); } }', true],
    'static numeric union' => ['function staticCall(mixed $v): void { if (is_numeric($v)) { StaticReceiver::accept($v); } }', true],
    'named reordered union' => ['function named(mixed $v): void { if (is_numeric($v)) { acceptPair(value: $v, id: 1); } }', true],
    'numeric strings excluded' => ['function arithmetic(mixed $v): void { if (is_numeric($v)) { acceptArithmetic($v); } }', false],
    'numeric scalar excluded' => ['function stringOnly(mixed $v): void { if (is_numeric($v)) { acceptString($v); } }', false],
    'positive range excluded' => ['function positive(mixed $v): void { if (is_numeric($v)) { acceptPositive($v); } }', false],
    'mixed argument unchanged' => ['function mixedValue(mixed $v): void { acceptNumber($v); }', false],
    'wrong other argument' => ['function wrongOther(mixed $v): void { if (is_numeric($v)) { acceptPair("bad", $v); } }', false],
    'array argument unchanged' => ['function arrayValue(): void { acceptNumber([]); }', false],
    'nullable numeric unchanged' => ['/** @param numeric|null $v */ function nullable(mixed $v): void { acceptNumber($v); }', false],
];
file_put_contents($workspace.'/declarations.php', $declarations);
file_put_contents($workspace.'/cases.php', "<?php\ndeclare(strict_types=1);\n".implode("\n", array_column($cases, 0))."\nfunction reference(): Closure { return acceptNumber(...); }\n");
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
foreach (['disabled', 'isolated', 'integrated'] as $mode) {
    if ($mode === 'integrated' && ! in_array('--integrated', $argv, true)) {
        continue;
    }
    file_put_contents($workspace.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml',
        'php-version' => '8.2',
        'source' => ['paths' => ['cases.php'], 'includes' => ['declarations.php']],
        'extension-hosts' => $mode === 'disabled' ? new stdClass : ['fixture' => [
            'command' => [PHP_BINARY, $mode === 'integrated' ? $package.'/bin/laramago-worker.php' : $workspace.'/worker.php', $package.'/vendor/autoload.php', $workspace],
            'workers' => 2,
        ]],
    ], JSON_THROW_ON_ERROR));
    $process = proc_open([...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'], [
        0 => ['pipe', 'r'], 1 => ['file', $workspace.'/'.$mode.'.json', 'w'], 2 => ['file', $workspace.'/'.$mode.'.log', 'w'],
    ], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start Mago.');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $stderr = file_get_contents($workspace.'/'.$mode.'.log');
    if ($exit !== 1 || preg_match('/failed|rejected request|parse error/i', $stderr)) {
        throw new RuntimeException('Mago failed: '.$stderr.' '.$workspace);
    }
    $issues = json_decode(file_get_contents($workspace.'/'.$mode.'.json'), true, flags: JSON_THROW_ON_ERROR)['issues'];
    $codes = [];
    foreach ($issues as $issue) {
        foreach ($issue['annotations'] as $annotation) {
            if ($annotation['kind'] === 'Primary' && $annotation['span']['file_id']['name'] === 'cases.php') {
                $codes[$annotation['span']['start']['line'] - 2][] = $issue['code'];
                break;
            }
        }
    }
    $index = 0;
    foreach ($cases as $name => [$source, $accepted]) {
        $actual = $codes[$index++] ?? [];
        $errors = array_filter($actual, static fn (string $code): bool => in_array($code, [
            'invalid-argument', 'possibly-invalid-argument', 'mixed-argument', 'possibly-null-argument',
        ], true));
        if (($errors === []) !== ($accepted && $mode !== 'disabled')) {
            throw new RuntimeException($mode.' '.$name.': unexpected '.json_encode($actual).'; inspect '.$workspace);
        }
        echo 'PASS: '.$mode.' '.$name."\n";
    }
}
