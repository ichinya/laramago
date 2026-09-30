<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago weak stringable returns '.bin2hex(random_bytes(8));
mkdir($workspace);
$worker = <<<'PHP'
<?php
require $argv[1];
$plugin = new class implements \Mago\Sdk\Analyzer\Plugin {
    public function getDefinition(): \Mago\Sdk\Analyzer\PluginDefinition {
        return new \Mago\Sdk\Analyzer\PluginDefinition('stringable-return-fixture', 'Stringable return fixture', 'Weak Stringable return compatibility');
    }
    public function register(\Mago\Sdk\Analyzer\PluginRegistry $registry): void {
        $filter = new \Ichinya\Laramago\Analyzer\WeakStringableReturnFilter;
        $registry->registerIssueFilterHook($filter);
        $registry->registerInitializationHook($filter);
    }
};
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension(
    identifier: 'stringable-return-fixture', name: 'Stringable return fixture', version: '1', analyzerPlugins: [$plugin],
)))->run();
PHP;
file_put_contents($workspace.'/worker.php', $worker);
$cases = [
    'weak native string' => ['function nativeString(): string { return new Label; }', true],
    'weak documented string' => ['/** @return string */ function documented() { return new Label; }', true],
    'nested validation rules' => ['/** @return array<string,list<string>> */ function rules(): array { return ["items.*.id" => ["required", new Label]]; }', true],
    'stringable fluent builder' => ['/** @return array<string,list<string>> */ function builder(): array { return ["id" => [(new DocLabel)->fluent()]]; }', true],
    'namespaced method' => ['class Request { /** @return array<string,list<string>> */ public function rules(): array { return ["id" => [new DocLabel]]; } }', true],
    'inherited return contract' => ['class ChildRequest extends BaseRequest { public function rules(): array { return ["id" => [new Label]]; } }', true],
    'escaped literal rule' => ['/** @return array<string,list<string>> */ function escaped(): array { return ["id" => ["regex:/\\A[0-9]+\\z/", new Label]]; }', true],
    'fixed string record' => ['/** @return array{label: string} */ function record(): array { return ["label" => new Label]; }', true],
    'inherited string method' => ['/** @return list<string> */ function inherited(): array { return [new ChildLabel]; }', true],
    'wrong unrelated value' => ['/** @return array<string,list<string>> */ function wrongValue(): array { return ["id" => [new Label, 42]]; }', false],
    'non stringable object' => ['/** @return list<string> */ function wrongObject(): array { return [new Label, new \stdClass]; }', false],
    'nullable item' => ['/** @return list<string> */ function nullable(): array { return [new Label, null]; }', false],
    'mixed item' => ['/** @return list<string> */ function unknown(mixed $v): array { return [new Label, $v]; }', false],
    'wrong array key' => ['/** @return array<string,list<string>> */ function wrongKey(): array { return [[new Label]]; }', false],
    'missing record field' => ['/** @return array{label: string, id: int} */ function missing(): array { return ["label" => new Label]; }', false],
    'non empty string constraint' => ['/** @return list<non-empty-string> */ function nonEmpty(): array { return [new Label]; }', false],
    'literal string constraint' => ['/** @return list<"fixed"> */ function literal(): array { return [new Label]; }', false],
    'numeric string constraint' => ['/** @return list<numeric-string> */ function numeric(): array { return [new Label]; }', false],
    'incompatible native declaration' => ['/** @return string */ function nativeObject(): object { return new Label; }', false],
    'reference return' => ['/** @return string */ function &reference(Label $v) { return $v; }', false],
    'closure lexical boundary' => ['function closureOwner(): \Closure { return function (): string { return new Label; }; }', false],
];
$prefix = <<<'PHP'
class Label implements \Stringable { public function __toString(): string { return ""; } }
class ChildLabel extends Label {}
class DocLabel implements \Stringable {
    /** @return string */ public function __toString() { return "exists:items,id"; }
    /** @return $this */ public function fluent() { return $this; }
}
class BaseRequest { /** @return array<string,list<string>> */ public function rules(): array { return []; } }
PHP;
$files = [];
$lines = [];
foreach (['weak', 'strict'] as $typing) {
    $source = "<?php\n".($typing === 'strict' ? "declare(strict_types=1);\n" : '')."namespace Fixture\\".ucfirst($typing).";\n".$prefix."\n";
    foreach ($cases as $name => [$case, $accepted]) {
        $lines[$typing.'.php'][substr_count($source, "\n")] = [$typing.' '.$name, $accepted && $typing === 'weak'];
        $source .= $case."\n";
    }
    file_put_contents($workspace.'/'.$typing.'.php', $source);
    $files[] = $typing.'.php';
}
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$nativeReturns = [];
foreach (['disabled', 'isolated', 'integrated'] as $mode) {
    if ($mode === 'integrated' && ! in_array('--integrated', $argv, true)) {
        continue;
    }
    file_put_contents($workspace.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml', 'php-version' => '8.2',
        'source' => ['paths' => $files],
        'extension-hosts' => $mode === 'disabled' ? new stdClass : ['fixture' => [
            'command' => [PHP_BINARY, '-d', 'opcache.enable_cli=0', $mode === 'integrated' ? $package.'/bin/laramago-worker.php' : $workspace.'/worker.php', $package.'/vendor/autoload.php', $workspace],
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
    $returns = [];
    foreach ($issues as $issue) {
        foreach ($issue['annotations'] as $annotation) {
            if ($annotation['kind'] === 'Primary' && isset($lines[$annotation['span']['file_id']['name']])) {
                $codes[$annotation['span']['file_id']['name']][$annotation['span']['start']['line']][] = $issue['code'];
                if (str_contains($issue['code'], 'return-statement')) {
                    $returns[$annotation['span']['file_id']['name']][$annotation['span']['start']['line']][] = [$issue['code'], $issue['message']];
                }
                break;
            }
        }
    }
    foreach ($lines as $file => $fileLines) {
        foreach ($fileLines as $line => [$name, $accepted]) {
            $actual = $codes[$file][$line] ?? [];
            $errors = $returns[$file][$line] ?? [];
            sort($errors);
            if ($mode === 'disabled') {
                $nativeReturns[$file][$line] = $errors;
            }
            if (($errors === []) !== ($accepted && $mode !== 'disabled')
                || ! $accepted && $errors !== $nativeReturns[$file][$line]) {
                throw new RuntimeException($mode.' '.$name.': unexpected '.json_encode($actual).'; inspect '.$workspace);
            }
            echo 'PASS: '.$mode.' '.$name."\n";
        }
    }
}
