<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago collection returns '.bin2hex(random_bytes(8));
$variant = $argv[1] ?? 'normal';
$framework = $workspace.'/vendor/laravel/framework/src/Illuminate/Collections';
if ($variant === 'custom-source') {
    $framework = $workspace.'/custom/Collections';
}
mkdir($framework, 0777, true);
$enumerable = <<<'PHP'
<?php
namespace Illuminate\Support;
/** @template TKey of array-key
 * @template-covariant TValue */
interface Enumerable {}
PHP;
$collection = <<<'PHP'
<?php
namespace Illuminate\Support;
/** @template TKey of array-key
 * @template-covariant TValue
 * @implements \Illuminate\Support\Enumerable<TKey, TValue>
 */
class Collection implements Enumerable {}
PHP;
if ($variant === 'changed-mapping') {
    $collection = str_replace('Enumerable<TKey, TValue>', 'Enumerable<string, TValue>', $collection);
}
if ($variant === 'changed-variance') {
    $enumerable = str_replace('@template TKey', '@template-contravariant TKey', $enumerable);
}
file_put_contents($framework.'/Enumerable.php', $enumerable);
file_put_contents($framework.'/Collection.php', $collection);
$worker = <<<'PHP'
<?php
require $argv[1];
$root = $argv[2];
$plugin = new class($root) implements \Mago\Sdk\Analyzer\Plugin {
    public function __construct(private string $root) {}
    public function getDefinition(): \Mago\Sdk\Analyzer\PluginDefinition {
        return new \Mago\Sdk\Analyzer\PluginDefinition('collection-return-fixture', 'Collection return fixture', 'Collection generic return compatibility');
    }
    public function register(\Mago\Sdk\Analyzer\PluginRegistry $registry): void {
        $filter = new \Ichinya\Laramago\Analyzer\CollectionReturnCompatibilityFilter($this->root);
        $registry->registerIssueFilterHook($filter);
        $registry->registerInitializationHook($filter);
    }
};
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension(
    identifier: 'collection-return-fixture', name: 'Collection return fixture', version: '1', analyzerPlugins: [$plugin],
)))->run();
PHP;
file_put_contents($workspace.'/worker.php', $worker);
$source = <<<'PHP'
<?php
declare(strict_types=1);
namespace Example;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
class Item {}
interface Marker {}
/** @template TKey of array-key
 * @template-covariant TValue
 * @extends Collection<TKey, TValue> */
class CustomCollection extends Collection {}
PHP;
$source .= "\n";
$cases = [
    'array-key and mixed target' => ['array-key,mixed', 'int,string', true],
    'array-key and concrete equal value' => ['array-key,int', 'int,int', true],
    'object item and mixed target' => ['array-key,mixed', 'int,Item', true],
    'nested array item and mixed target' => ['array-key,mixed', 'int,array<string,string|null>', true],
    'wrong concrete key' => ['string,mixed', 'int,string', false],
    'wrong concrete item' => ['array-key,int', 'int,string', false],
    'wrong key and item' => ['string,int', 'int,string', false],
    'custom collection mapping' => ['array-key,mixed', 'int,string', false, 'custom'],
    'native return mismatch' => ['array-key,mixed', 'int,string', false, 'native'],
    'explicit parent defaults' => ['array-key,mixed', 'int,string', true, 'default'],
    'parent template without a default' => ['array-key,mixed', 'int,string', false, 'no-default'],
    'explicit parent generic binding' => ['array-key,mixed', 'int,string', false, 'binding'],
    'unknown interface intersection' => ['array-key,mixed', 'int,string', false, 'intersection'],
];
$expected = [];
foreach (array_values($cases) as $index => [$target, $input, $accepted]) {
    $name = array_keys($cases)[$index];
    $kind = $cases[$name][3] ?? 'plain';
    $genericParent = in_array($kind, ['default', 'binding', 'no-default'], true);
    $parentDoc = $genericParent
        ? "/** @template TKey of array-key = array-key\n * @template TValue = mixed */\n" : '';
    if ($kind === 'no-default') {
        $parentDoc = str_replace([' = array-key', ' = mixed'], '', $parentDoc);
    }
    $parentTarget = $genericParent ? 'TKey,TValue' : $target;
    $parentNative = $kind === 'native' ? '\\DateTimeInterface' : 'Enumerable';
    $childType = $kind === 'custom' ? 'CustomCollection' : 'Collection';
    $childDoc = $kind === 'binding' ? "/** @implements Parent{$index}<string,int> */\n" : '';
    $intersection = $kind === 'intersection' ? '&Marker' : '';
    $source .= $parentDoc."interface Parent{$index} { /** @return Enumerable<{$parentTarget}> */ public function entries(): {$parentNative}; }\n";
    $source .= $childDoc."class Child{$index} implements Parent{$index} { /** @return {$childType}<{$input}>{$intersection} */ public function entries(): {$childType} { throw new \\LogicException; } }\n";
    $expected['Example\\Child'.$index] = [$name, $accepted];
}
file_put_contents($workspace.'/cases.php', $source);
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$nativeErrors = [];
foreach (['disabled', 'isolated', 'integrated'] as $mode) {
    if ($mode === 'integrated' && ! in_array('--integrated', $argv, true)) {
        continue;
    }
    file_put_contents($workspace.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml', 'php-version' => '8.2',
        'source' => ['paths' => ['cases.php'], 'includes' => [$framework]],
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
    $errors = [];
    foreach ($issues as $issue) {
        if ($issue['code'] === 'incompatible-return-type'
            && preg_match('/ of `([^`]+)::entries\(\)` is incompatible/', $issue['message'], $match)) {
            $errors[$match[1]] = true;
        }
    }
    foreach ($expected as $class => [$name, $accepted]) {
        if ($mode === 'disabled') {
            $nativeErrors[$class] = isset($errors[$class]);
        }
        $removed = $accepted && $variant === 'normal' && $mode !== 'disabled';
        $shouldRemain = $variant === 'normal' ? ! $removed : $nativeErrors[$class];
        if (isset($errors[$class]) !== $shouldRemain) {
            throw new RuntimeException($variant.' '.$mode.' '.$name.': unexpected override diagnostics; inspect '.$workspace);
        }
        echo 'PASS: '.$variant.' '.$mode.' '.$name."\n";
    }
}
