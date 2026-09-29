<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago parameter docs '.bin2hex(random_bytes(8));
mkdir($workspace);
file_put_contents($workspace.'/worker.php', <<<'PHP'
<?php
require $argv[1];
$plugin = new class implements \Mago\Sdk\Analyzer\Plugin {
    public function getDefinition(): \Mago\Sdk\Analyzer\PluginDefinition {
        return new \Mago\Sdk\Analyzer\PluginDefinition('parameter-doc-test', 'Parameter docs', 'PHPDoc parameter policy');
    }
    public function register(\Mago\Sdk\Analyzer\PluginRegistry $registry): void {
        $filter = new \Ichinya\Laramago\Analyzer\PhpDocParameterCompatibilityFilter;
        $registry->registerIssueFilterHook($filter);
        $registry->registerInitializationHook($filter);
    }
};
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension(
    identifier: 'parameter-doc-test', name: 'Parameter docs', version: '1', analyzerPlugins: [$plugin],
)))->run();
PHP);
$source = <<<'PHP'
<?php
declare(strict_types=1);
namespace ParameterDocs;
interface MixedParent { public function map(mixed $value): void; }
interface UnionParent { /** @param int|string $value */ public function map(mixed $value): void; }
interface ArrayParent { /** @param array<string, mixed> $value */ public function map(array $value): void; }
interface StringParent { /** @param string $value */ public function map(mixed $value): void; }
interface ReferenceParent { public function map(mixed &$value): void; }
interface ShapeParent { /** @param array{a: mixed} $value */ public function map(array $value): void; }
interface ListParent { /** @param non-empty-list<int> $value */ public function map(array $value): void; }
/** @template-contravariant Row = mixed */
interface GenericParent { /** @param Row $value */ public function map(mixed $value): void; }
/** @template-contravariant Row */
interface NoDefaultParent { /** @param Row $value */ public function map(mixed $value): void; }
PHP;
$cases = [
    'MixedChild' => ['class MixedChild implements MixedParent { /** @param array<string, string|null> $value */ public function map($value): void {} }', true],
    'UnionChild' => ['class UnionChild implements UnionParent { /** @param int $value */ public function map(mixed $value): void {} }', true],
    'ArrayChild' => ['class ArrayChild implements ArrayParent { /** @param array<string, int> $value */ public function map(array $value): void {} }', true],
    'DefaultGenericChild' => ['class DefaultGenericChild implements GenericParent { /** @param array<string, string|null> $value */ public function map($value): void {} }', true],
    'NativeMismatch' => ['class NativeMismatch implements MixedParent { /** @param array<string, int> $value */ public function map(array $value): void {} }', false],
    'DisjointDocs' => ['class DisjointDocs implements StringParent { /** @param int $value */ public function map(mixed $value): void {} }', false],
    'WrongArrayValue' => ['class WrongArrayValue implements ArrayParent { /** @param array<int, string> $value */ public function map(array $value): void {} }', false],
    'ReferenceChild' => ['class ReferenceChild implements ReferenceParent { /** @param array<string, string> $value */ public function map(&$value): void {} }', false],
    'ExplicitGenericChild' => ['/** @implements GenericParent<string> */ class ExplicitGenericChild implements GenericParent { /** @param array<string, int> $value */ public function map($value): void {} }', false],
    'NoDefaultChild' => ['class NoDefaultChild implements NoDefaultParent { /** @param array<string, int> $value */ public function map($value): void {} }', false],
    'NeverMixedChild' => ['class NeverMixedChild implements MixedParent { /** @param never $value */ public function map(mixed $value): void {} }', false],
    'NeverStringChild' => ['class NeverStringChild implements StringParent { /** @param never $value */ public function map(mixed $value): void {} }', false],
    'NeverShapeChild' => ['class NeverShapeChild implements ShapeParent { /** @param array{a: never} $value */ public function map(array $value): void {} }', false],
    'NeverListChild' => ['class NeverListChild implements ListParent { /** @param list<never> $value */ public function map(array $value): void {} }', false],
];
$lines = [];
foreach ($cases as $name => [$case, $accepted]) {
    $source .= "\n".$case;
    $lines[substr_count($source, "\n")] = [$name, $accepted];
}
$source .= "\nfunction invalidCall(MixedChild \$child): void { \$child->map(123); }\n";
$callLine = substr_count($source, "\n") - 1;
file_put_contents($workspace.'/cases.php', $source);
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
foreach (['native', 'isolated', 'integrated'] as $mode) {
    if ($mode === 'integrated' && ! in_array('--integrated', $argv, true)) {
        continue;
    }
    file_put_contents($workspace.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml', 'php-version' => '8.2',
        'source' => ['paths' => ['cases.php']],
        'extension-hosts' => $mode === 'native' ? new stdClass : ['fixture' => [
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
        $error = in_array('incompatible-parameter-type', $actual, true);
        if ($error !== (! $accepted || $mode === 'native')) {
            throw new RuntimeException($mode.' '.$name.': unexpected '.json_encode($actual).'; inspect '.$workspace);
        }
        echo 'PASS: '.$mode.' '.$name."\n";
    }
    if (! in_array('invalid-argument', $codes[$callLine] ?? [], true)) {
        throw new RuntimeException('Invalid child call must remain checked: '.$workspace);
    }
}
