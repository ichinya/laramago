<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago assertion keys '.bin2hex(random_bytes(8));
mkdir($workspace.'/vendor/testo/assert/src/Internal/Assertion', recursive: true);
// Original minimal fixtures model the verified public API and failure boundary.
$assert = <<<'PHP'
<?php
namespace Testo;
use Testo\Assert\Api\Builtin\ArrayType;
use Testo\Assert\Internal\Assertion\AssertArray;
final class Assert {
    /**
     * @psalm-assert array $actual
     * @phpstan-assert array<mixed, mixed> $actual
     */
    public static function array(mixed $actual): ArrayType {
        return AssertArray::validateAndCreate($actual);
    }
}
PHP;
$delegate = <<<'PHP'
<?php
namespace Testo\Assert\Internal\Assertion;
use Testo\Assert\Api\Builtin\ArrayType;
use Testo\Assert\Internal\StaticState;
final class AssertArray implements ArrayType {
    public function __construct(mixed $value, mixed $parent) {}
    public static function validateAndCreate(mixed $value): self {
        \is_array($value) or StaticState::typeFail('array', $value);
        $parent = StaticState::typeSuccess('array', $value);
        return new self($value, $parent);
    }
}
PHP;
$state = <<<'PHP'
<?php
namespace Testo\Assert\Internal;
final class StaticState {
    public static function typeSuccess(string $type, mixed $actual): object { return new \stdClass(); }
    public static function typeFail(string $type, mixed $actual, string $message = ''): never {
        throw new \RuntimeException('The assertion failed.');
    }
}
PHP;
file_put_contents($workspace.'/types.php', <<<'PHP'
<?php
namespace Testo\Assert\Api\Builtin { interface ArrayType {} }
namespace Fixtures {
    final class CustomAssert {
        /** @phpstan-assert array<mixed, mixed> $actual */
        public static function array(mixed $actual): void { if (!is_array($actual)) { throw new \RuntimeException(); } }
    }
    final class Holder { public mixed $payload = null; }
    /** @param iterable<array-key,mixed> $value */ function consume(iterable $value): void {}
}
PHP);
$cases = [
    'array return' => ['/** @return array<array-key,mixed> */ function arrayReturn(mixed $actual): array { Assert::array($actual); return $actual; }', 'key'],
    'native key function' => ['function keyFunction(mixed $actual): bool { Assert::array($actual); return array_key_exists("name", $actual); }', 'key'],
    'iterable argument' => ['function collectionInput(mixed $actual): void { Assert::array($actual); consume($actual); }', 'key'],
    'named argument' => ['/** @return array<array-key,mixed> */ function named(mixed $actual): array { Assert::array(actual: $actual); return $actual; }', 'key'],
    'qualified call' => ['/** @return array<array-key,mixed> */ function qualified(mixed $actual): array { \Testo\Assert::array($actual); return $actual; }', 'key'],
    'preserve string values' => ['/** @param array<int,string> $actual\n * @return array<int,string> */ function values(array $actual): array { Assert::array($actual); return $actual; }', 'valid'],
    'preserve known fields' => ['/** @param array{name:string} $actual\n * @return string */ function field(array $actual): string { Assert::array($actual); return $actual["name"]; }', 'valid'],
    'preserve nonempty' => ['/** @param non-empty-array<int,string> $actual\n * @return non-empty-array<int,string> */ function nonempty(array $actual): array { Assert::array($actual); return $actual; }', 'valid'],
    'unknown value return' => ['function item(mixed $actual): int { Assert::array($actual); return $actual["name"]; }', 'invalid'],
    'unknown input' => ['function unknown(mixed $actual): void { consume($actual); }', 'invalid'],
    'unasserted return' => ['/** @return array<array-key,mixed> */ function unasserted(mixed $actual): array { return $actual; }', 'invalid'],
    'custom assert class' => ['/** @return array<array-key,mixed> */ function custom(mixed $actual): array { CustomAssert::array($actual); return $actual; }', 'defer'],
    'property expression' => ['/** @return array<array-key,mixed> */ function property(Holder $holder): array { Assert::array($holder->payload); return $holder->payload; }', 'defer'],
    'unpacked call' => ['/** @return array<array-key,mixed> */ function unpacked(mixed $actual): array { Assert::array(...[$actual]); return $actual; }', 'invalid'],
    'first class callable' => ['/** @return array<array-key,mixed> */ function callableCheck(mixed $actual): array { $check = Assert::array(...); return $actual; }', 'invalid'],
    'wrong known value' => ['/** @param array<int,string> $actual\n * @return array<int,int> */ function wrongItems(array $actual): array { Assert::array($actual); return $actual; }', 'invalid'],
    'known nonarray' => ['function nonarray(): void { $actual = "wrong"; Assert::array($actual); }', 'contradiction'],
    'superglobal expression' => ['/** @return array<array-key,mixed> */ function superglobal(mixed $input): array { $_SERVER = $input; Assert::array($_SERVER); return $_SERVER; }', 'valid'],
];
$source = "<?php\nnamespace Fixtures;\nuse Testo\\Assert;\n";
$lines = [];
foreach ($cases as $label => [$code, $kind]) {
    $code = str_replace('\\n', "\n", $code);
    $line = substr_count($source, "\n") + substr_count($code, "\n");
    $lines[$line] = [$label, $kind];
    $source .= $code."\n";
}
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/bootstrap.php', '<?php throw new RuntimeException("Application bootstrap executed.");');
file_put_contents($workspace.'/composer.json', json_encode(['autoload' => ['files' => ['bootstrap.php']]], JSON_THROW_ON_ERROR));
file_put_contents($workspace.'/worker.php', <<<'PHP'
<?php
require $argv[1];
$plugin = new class($argv[2]) implements \Mago\Sdk\Analyzer\Plugin {
    public function __construct(private string $root) {}
    public function getDefinition(): \Mago\Sdk\Analyzer\PluginDefinition {
        return new \Mago\Sdk\Analyzer\PluginDefinition('test/array-keys', 'Array keys', 'Array assertion contracts');
    }
    public function register(\Mago\Sdk\Analyzer\PluginRegistry $registry): void {
        $provider = new \Ichinya\Laramago\Analyzer\TestoArrayAssertionProvider($this->root);
        $registry->registerMethodAssertionProvider(new class($provider) implements \Mago\Sdk\Analyzer\MethodAssertionProvider {
            public function __construct(private \Ichinya\Laramago\Analyzer\TestoArrayAssertionProvider $provider) {}
            public function getTargets(): array { return $this->provider->getTargets(); }
            public function getAssertions(\Mago\Sdk\Analyzer\AssertionProviderContext $context): ?\Mago\Sdk\Analyzer\InvocationAssertions {
                $result = $this->provider->getAssertions($context);
                file_put_contents(__DIR__.'/provider-calls.log', json_encode([
                    $context->invocation->getArgument(0, 'actual')?->expression, $result !== null,
                ], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
                return $result;
            }
        });
        $registry->registerInitializationHook($provider);
    }
};
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension('test/array-keys', 'Array keys', '1', analyzerPlugins: [$plugin])))->run();
PHP);
$modes = [
    'native' => [$assert, $delegate, $state],
    'isolated' => [$assert, $delegate, $state],
    'single-assertion' => [str_replace('     * @psalm-assert array $actual'."\n", '', $assert), $delegate, $state],
    'formatting' => [str_replace('return AssertArray', "// Forward only after argument validation.\n        return   AssertArray", $assert), $delegate, $state],
    'changed-forward' => [str_replace('validateAndCreate($actual)', 'validateAndCreate([])', $assert), $delegate, $state],
    'changed-input' => [str_replace('array(mixed $actual)', 'array(mixed &$actual)', $assert), $delegate, $state],
    'changed-return' => [str_replace('): ArrayType', '): object', $assert), $delegate, $state],
    'changed-contract' => [str_replace('array<mixed, mixed>', 'array<mixed, string>', $assert), $delegate, $state],
    'changed-truthy-values' => [str_replace('array<mixed, mixed>', 'array<mixed, non-empty-mixed>', $assert), $delegate, $state],
    'changed-truthy-keys' => [str_replace('array<mixed, mixed>', 'array<non-empty-mixed, mixed>', $assert), $delegate, $state],
    'changed-guard' => [$assert, str_replace('\\is_array($value)', '\\is_iterable($value)', $delegate), $state],
    'changed-delegate-input' => [$assert, str_replace('Create(mixed $value)', 'Create(mixed &$value)', $delegate), $state],
    'changed-failure-contract' => [$assert, $delegate, str_replace('): never', '): void', $state)],
    'changed-failure-body' => [$assert, $delegate, str_replace("throw new \\RuntimeException('The assertion failed.');", 'return;', $state)],
    'nonfinal-class' => [str_replace('final class Assert', 'class Assert', $assert), $delegate, $state],
    'custom-source-path' => [$assert, $delegate, $state],
];
if (in_array('--integrated', $argv, true)) {
    $modes['integrated'] = [$assert, $delegate, $state];
}
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
foreach ($modes as $mode => [$assertSource, $delegateSource, $stateSource]) {
    file_put_contents($workspace.'/provider-calls.log', '');
    file_put_contents($workspace.'/vendor/testo/assert/Assert.php', $mode === 'custom-source-path' ? '<?php' : $assertSource);
    file_put_contents($workspace.'/custom-assert.php', $mode === 'custom-source-path' ? $assertSource : '<?php');
    file_put_contents($workspace.'/vendor/testo/assert/src/Internal/Assertion/AssertArray.php', $delegateSource);
    file_put_contents($workspace.'/vendor/testo/assert/src/Internal/StaticState.php', $stateSource);
    file_put_contents($workspace.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml', 'php-version' => '8.2',
        'source' => ['paths' => ['cases.php'], 'includes' => ['types.php', 'custom-assert.php', 'vendor']],
        'extension-hosts' => $mode === 'native' ? new stdClass : ['test' => [
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
    if (! in_array($exit, [0, 1], true) || preg_match('/provider failed|rejected request|hook .* failed|parse error/i', $stderr)) {
        throw new RuntimeException('Mago failed: '.$stderr.'; inspect '.$workspace);
    }
    $issues = json_decode(file_get_contents($workspace.'/'.$mode.'.json'), true, flags: JSON_THROW_ON_ERROR)['issues'];
    $diagnostics = [];
    foreach ($issues as $issue) {
        foreach ($issue['annotations'] as $annotation) {
            if ($annotation['kind'] === 'Primary' && $annotation['span']['file_id']['name'] === 'cases.php') {
                $diagnostics[$annotation['span']['start']['line']][] = $issue['code'];
                break;
            }
        }
    }
    $enabled = in_array($mode, ['isolated', 'single-assertion', 'formatting', 'integrated'], true);
    foreach ($lines as $line => [$label, $kind]) {
        $codes = $diagnostics[$line] ?? [];
        $keyIssue = array_intersect(['less-specific-nested-return-statement', 'less-specific-nested-argument-type'], $codes) !== [];
        if (($kind === 'key' && $keyIssue === $enabled) || ($kind === 'defer' && ! $keyIssue)) {
            throw new RuntimeException($mode.' '.$label.': unexpected keys '.json_encode($codes).'; inspect '.$workspace);
        }
        if ($kind === 'valid' && ($enabled || $mode === 'native')
            && array_intersect(['invalid-return-statement', 'mixed-return-statement', 'less-specific-nested-return-statement'], $codes) !== []) {
            throw new RuntimeException($mode.' '.$label.': lost original types '.json_encode($codes).'; inspect '.$workspace);
        }
        $invalidCodes = ['mixed-argument', 'invalid-argument', 'invalid-return-statement', 'mixed-return-statement', 'less-specific-nested-return-statement'];
        if (! $enabled && $mode !== 'native') {
            array_push($invalidCodes, 'impossible-type-comparison', 'never-return');
        }
        if ($kind === 'invalid' && array_intersect($invalidCodes, $codes) === []) {
            throw new RuntimeException($mode.' '.$label.': lost invalid operation '.json_encode($codes).'; inspect '.$workspace);
        }
        if ($kind === 'contradiction' && ($enabled || $mode === 'native')
            && array_intersect(['impossible-condition', 'impossible-type-comparison', 'type-assertion-failure'], $codes) === []) {
            throw new RuntimeException($mode.' '.$label.': lost nonarray contradiction '.json_encode($codes).'; inspect '.$workspace);
        }
    }
    if (! in_array($mode, ['native', 'integrated'], true)) {
        $superglobalCalls = 0;
        foreach (file($workspace.'/provider-calls.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $entry) {
            [$expression, $provided] = json_decode($entry, true, flags: JSON_THROW_ON_ERROR);
            if (! $enabled && $provided) {
                throw new RuntimeException('Modified source or contracts must defer: '.$mode.' '.$workspace);
            }
            if ($expression === '$_SERVER') {
                ++$superglobalCalls;
                if ($provided) {
                    throw new RuntimeException('The superglobal argument must defer: '.$workspace);
                }
            }
        }
        if ($superglobalCalls === 0) {
            throw new RuntimeException('The superglobal provider control was not exercised: '.$workspace);
        }
    }
    echo 'PASS: Testo array keys '.$mode.' ('.count($cases).' cases)'.PHP_EOL;
}
