<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago weak strings '.bin2hex(random_bytes(8));
mkdir($workspace);

$weak = <<<'PHP'
<?php
namespace WeakCase;
class Value { /** @return string */ public function __toString() { return 'value'; } }
class Plain {}
class Target {
    public function take(string $value): void {}
    /** @param non-empty-string $value */ public function constrained(string $value): void {}
    /** @param string $value */ public function docOnly($value): void {}
}
function native(Value $value): bool { return str_contains($value, 'x'); }
function named(Value $value): bool { return str_contains(needle: 'x', haystack: $value); }
function instance(Target $target, Value $value): void { $target->take($value); }
function staticCall(Value $value): void { StaticTarget::take($value); }
class StaticTarget { public static function take(string $value): void {} }
function wrongOther(Value $value, Plain $plain): bool { return str_contains($value, $plain); }
function nonStringable(Plain $plain): bool { return str_contains($plain, 'x'); }
function constrained(Target $target, Value $value): void { $target->constrained($value); }
function docOnly(Target $target, Value $value): void { $target->docOnly($value); }
function nullable(?Value $value): bool { return str_contains($value, 'x'); }
function functionReference(): \Closure { return str_contains(...); }
function methodReference(Target $target): \Closure { return $target->take(...); }
function staticReference(): \Closure { return StaticTarget::take(...); }
PHP;
$strict = <<<'PHP'
<?php
declare(strict_types=1);
namespace StrictCase;
class Value { /** @return string */ public function __toString() { return 'value'; } }
class Target { public function take(string $value): void {} }
function native(Value $value): bool { return str_contains($value, 'x'); }
function method(Target $target, Value $value): void { $target->take($value); }
PHP;
file_put_contents($workspace.'/weak.php', $weak."\n");
file_put_contents($workspace.'/strict.php', $strict."\n");

$lineOf = static function (string $source, string $needle): int {
    foreach (explode("\n", $source) as $index => $line) {
        if (str_starts_with(trim($line), $needle)) {
            return $index;
        }
    }
    throw new RuntimeException('Missing fixture: '.$needle);
};

foreach (['disabled', 'enabled'] as $mode) {
    file_put_contents($workspace.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml',
        'php-version' => '8.2',
        'source' => ['paths' => ['weak.php', 'strict.php']],
        'extension-hosts' => $mode === 'disabled' ? new stdClass : [
            'laramago' => [
                'command' => [PHP_BINARY, $package.'/bin/laramago-worker.php', $package.'/vendor/autoload.php', $workspace],
                'workers' => 1,
            ],
        ],
    ], JSON_THROW_ON_ERROR));
    $binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
    $command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
    $process = proc_open([...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'], [
        0 => ['pipe', 'r'],
        1 => ['file', $workspace.'/'.$mode.'.json', 'w'],
        2 => ['file', $workspace.'/'.$mode.'.log', 'w'],
    ], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start Mago.');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $stderr = file_get_contents($workspace.'/'.$mode.'.log');
    if ($exit > 1 || preg_match('/provider failed|rejected request|hook .* failed|parse error/i', $stderr)) {
        throw new RuntimeException('Mago failed: '.$stderr.' '.$workspace);
    }
    $issues = json_decode(file_get_contents($workspace.'/'.$mode.'.json'), true, flags: JSON_THROW_ON_ERROR)['issues'];
    $codes = [];
    foreach ($issues as $issue) {
        foreach ($issue['annotations'] as $annotation) {
            if ($annotation['kind'] === 'Primary') {
                $name = $annotation['span']['file_id']['name'];
                if (in_array($name, ['weak.php', 'strict.php'], true)) {
                    $codes[$name][$annotation['span']['start']['line']][] = $issue['code'];
                    break;
                }
            }
        }
    }
    foreach (['native(', 'named(', 'instance(', 'staticCall('] as $case) {
        $line = $lineOf($weak, 'function '.$case);
        $has = in_array('invalid-argument', $codes['weak.php'][$line] ?? [], true);
        if ($has !== ($mode === 'disabled')) {
            throw new RuntimeException($mode.' wrong weak '.$case.': '.json_encode($codes).' '.$workspace);
        }
    }
    foreach (['nonStringable(', 'constrained(', 'docOnly('] as $case) {
        $line = $lineOf($weak, 'function '.$case);
        if (! in_array('invalid-argument', $codes['weak.php'][$line] ?? [], true)) {
            throw new RuntimeException($mode.' lost negative '.$case.': '.json_encode($codes).' '.$workspace);
        }
    }
    foreach (['functionReference(', 'methodReference(', 'staticReference('] as $case) {
        $line = $lineOf($weak, 'function '.$case);
        if (($codes['weak.php'][$line] ?? []) !== []) {
            throw new RuntimeException($mode.' unexpected callable reference diagnostics: '.json_encode($codes).' '.$workspace);
        }
    }
    $wrongOther = $lineOf($weak, 'function wrongOther(');
    $wrongCount = count(array_filter($codes['weak.php'][$wrongOther] ?? [], static fn (string $code): bool => $code === 'invalid-argument'));
    if ($wrongCount !== ($mode === 'disabled' ? 2 : 1)) {
        throw new RuntimeException($mode.' lost wrong second argument: '.json_encode($codes).' '.$workspace);
    }
    $nullable = $lineOf($weak, 'function nullable(');
    if (! in_array('invalid-argument', $codes['weak.php'][$nullable] ?? [], true)) {
        throw new RuntimeException($mode.' lost nullable source: '.json_encode($codes).' '.$workspace);
    }
    foreach (['native(', 'method('] as $case) {
        $line = $lineOf($strict, 'function '.$case);
        if (! in_array('invalid-argument', $codes['strict.php'][$line] ?? [], true)) {
            throw new RuntimeException($mode.' lost strict '.$case.': '.json_encode($codes).' '.$workspace);
        }
    }
    echo 'PASS: weak stringable arguments '.$mode."\n";
}
