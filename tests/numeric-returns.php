<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago numeric returns '.bin2hex(random_bytes(8));
mkdir($workspace);
$worker = <<<'PHP'
<?php
require $argv[1];
$plugin = new class implements \Mago\Sdk\Analyzer\Plugin {
    public function getDefinition(): \Mago\Sdk\Analyzer\PluginDefinition {
        return new \Mago\Sdk\Analyzer\PluginDefinition('numeric-return-fixture', 'Numeric return fixture', 'Numeric subtype compatibility');
    }
    public function register(\Mago\Sdk\Analyzer\PluginRegistry $registry): void {
        $filter = new \Ichinya\Laramago\Analyzer\NumericReturnCompatibilityFilter;
        $registry->registerIssueFilterHook($filter);
        $registry->registerInitializationHook($filter);
    }
};
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension(
    identifier: 'numeric-return-fixture', name: 'Numeric return fixture', version: '1', analyzerPlugins: [$plugin],
)))->run();
PHP;
file_put_contents($workspace.'/worker.php', $worker);
$cases = [
    'numeric scalar return' => ['function scalar(mixed $v): int|float|string { if (!is_numeric($v)) { throw new LogicException; } return $v; }', true],
    'numeric record return' => ['/** @return array{amount: int|float|string} */ function record(mixed $v): array { if (!is_numeric($v)) { throw new LogicException; } return ["amount" => $v]; }', true],
    'numeric string record' => ['/** @return array{amount: int|float|numeric-string, details: mixed} */ function numeric(mixed $v, mixed $d): array { if (!is_numeric($v)) { throw new LogicException; } return ["amount" => $v, "details" => $d]; }', true],
    'nested numeric record' => ['/** @return array{data: array{amount: int|float|string}} */ function nested(mixed $v): array { if (!is_numeric($v)) { throw new LogicException; } return ["data" => ["amount" => $v]]; }', true],
    'numeric method return' => ['class Example { /** @return array{amount: int|float|string} */ public function value(mixed $v): array { if (!is_numeric($v)) { throw new LogicException; } return ["amount" => $v]; } }', true],
    'numeric strings excluded' => ['/** @return array{amount: int|float} */ function arithmetic(mixed $v): array { if (!is_numeric($v)) { throw new LogicException; } return ["amount" => $v]; }', false],
    'wrong unrelated field' => ['/** @return array{amount: int|float|string, label: string} */ function wrongField(mixed $v): array { if (!is_numeric($v)) { throw new LogicException; } return ["amount" => $v, "label" => 123]; }', false],
    'missing unrelated field' => ['/** @return array{amount: int|float|string, label: string} */ function missingField(mixed $v): array { if (!is_numeric($v)) { throw new LogicException; } return ["amount" => $v]; }', false],
    'mixed unrelated field' => ['/** @return array{amount: int|float|string, label: string} */ function mixedField(mixed $v, mixed $d): array { if (!is_numeric($v)) { throw new LogicException; } return ["amount" => $v, "label" => $d]; }', false],
    'positive integer constraint' => ['/** @return array{amount: positive-int|float|numeric-string} */ function positive(mixed $v): array { if (!is_numeric($v)) { throw new LogicException; } return ["amount" => $v]; }', false],
    'nullable numeric value' => ['/** @param numeric|null $v @return array{amount: int|float|string} */ function nullable(mixed $v): array { return ["amount" => $v]; }', false],
];
$source = "<?php\ndeclare(strict_types=1);\n";
$lines = [];
foreach ($cases as $name => [$case, $accepted]) {
    $case = str_replace(' @return ', "\n * @return ", $case);
    $lines[substr_count($source, "\n") + substr_count($case, "\n")] = [$name, $accepted];
    $source .= $case."\n";
}
file_put_contents($workspace.'/cases.php', $source);
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
foreach (['disabled', 'isolated', 'integrated'] as $mode) {
    if ($mode === 'integrated' && ! in_array('--integrated', $argv, true)) {
        continue;
    }
    file_put_contents($workspace.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml', 'php-version' => '8.2',
        'source' => ['paths' => ['cases.php']],
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
                $codes[$annotation['span']['start']['line']][] = $issue['code'];
                break;
            }
        }
    }
    foreach ($lines as $line => [$name, $accepted]) {
        $actual = $codes[$line] ?? [];
        $errors = array_filter($actual, static fn (string $code): bool => str_contains($code, 'return-statement'));
        if (($errors === []) !== ($accepted && $mode !== 'disabled')) {
            throw new RuntimeException($mode.' '.$name.': unexpected '.json_encode($actual).'; inspect '.$workspace);
        }
        echo 'PASS: '.$mode.' '.$name."\n";
    }
}
