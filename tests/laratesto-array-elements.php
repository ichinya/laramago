<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago array elements '.bin2hex(random_bytes(8));
mkdir($workspace.'/vendor/ichinya/laratesto/src/Testing', recursive: true);
mkdir($workspace.'/vendor/testo/assert/src/Internal/Assertion', recursive: true);
$check = file_get_contents(__DIR__.'/fixtures/analysis/laratesto-assertions.php.stub');
$assert = file_get_contents(__DIR__.'/fixtures/analysis/testo-assertions.php.stub');
$state = file_get_contents(__DIR__.'/fixtures/analysis/testo-static-state.php.stub');
if ($check === false || $assert === false || $state === false) {
    throw new RuntimeException('Cannot read assertion fixtures.');
}
$assert = str_replace('final class Assert'."\n    {", <<<'PHP'
final class Assert
    {
        /** @psalm-assert array $actual
         * @phpstan-assert array<mixed,mixed> $actual */
        public static function array(mixed $actual): \Testo\Assert\Api\Builtin\ArrayType {
            return \Testo\Assert\Internal\Assertion\AssertArray::validateAndCreate($actual);
        }
PHP, str_replace("\r\n", "\n", $assert));
$state = str_replace('final class StaticState'."\n    {", <<<'PHP'
final class StaticState
    {
        public static function typeSuccess(string $type, mixed $actual): object { return new \stdClass(); }
        public static function typeFail(string $type, mixed $actual, string $message = ''): never {
            throw new \RuntimeException('Assertion failed.');
        }
PHP, str_replace("\r\n", "\n", $state));
$delegate = <<<'PHP'
<?php
namespace Testo\Assert\Internal\Assertion;
use Testo\Assert\Internal\StaticState;
final readonly class AssertArray implements \Testo\Assert\Api\Builtin\ArrayType {
    public function __construct(private array $value, private object $parent) {}
    public static function validateAndCreate(mixed $value): self {
        \is_array($value) or StaticState::typeFail('array', $value);
        $parent = StaticState::typeSuccess('array', $value);
        return new self($value, $parent);
    }
    public function hasKeys(int|string ...$keys): static {
        foreach ($keys as $key) { if (!\array_key_exists($key, $this->value)) { throw new \RuntimeException('Missing key.'); } }
        return $this;
    }
}
PHP;
// The key-inspection implementation only calls native array functions and throws.
$delegate = str_replace("throw new \\RuntimeException('Missing key.');", "throw \$this->parent->fail('Missing key.');", $delegate);
file_put_contents($workspace.'/bootstrap.php', '<?php throw new RuntimeException("Application bootstrap executed.");');
file_put_contents($workspace.'/composer.json', json_encode(['autoload' => ['files' => ['bootstrap.php']]], JSON_THROW_ON_ERROR));
file_put_contents($workspace.'/types.php', <<<'PHP'
<?php
namespace Testo\Assert\Api\Builtin { interface ArrayType { public function hasKeys(int|string ...$keys): static; } }
namespace Fixtures {
    /** @implements \ArrayAccess<string,mixed> */
    final class MutableOffsets implements \ArrayAccess {
        public function offsetGet(mixed $offset): mixed { return random_int(0, 1) ? [] : null; }
        public function offsetSet(mixed $offset, mixed $value): void {}
        public function offsetExists(mixed $offset): bool { return true; }
        public function offsetUnset(mixed $offset): void {}
    }
    final class Holder { public mixed $value = null; }
    final class CustomCheck { public static function assertIsArray(mixed $actual): void {} }
    function consume(array $value): void {}
    function consumeInt(int $value): void {}
    function opaque(): void {}
}
PHP);
$cases = [
    'nested arrays' => ['function nested(mixed $data): void { Check::assertIsArray($data); Check::assertIsArray($data["nested"]); consume($data["nested"]); }', true],
    'deep arrays' => ['function deep(mixed $data): void { Check::assertIsArray($data); Check::assertIsArray($data["nested"]); Check::assertIsArray($data["nested"]["items"]); consume($data["nested"]["items"]); }', true],
    'integer keys' => ['function keys(mixed $data): void { Check::assertIsArray($data); Check::assertIsArray($data[1]); consume($data[1]); }', true],
    'named arguments' => ['function named(mixed $data): void { Check::assertIsArray($data); Check::assertIsArray(message: "values", actual: $data["nested"]); consume($data["nested"]); }', true],
    'fluent key check' => ['function fluent(mixed $data): void { Check::assertIsArray($data); Assert::array($data)->hasKeys("nested"); Check::assertIsArray($data["nested"]); consume($data["nested"]); }', true],
    'unknown prefix' => ['function unknown(array $data): void { Check::assertIsArray($data["nested"]); consume($data["nested"]); }', false],
    'missing middle' => ['function middle(mixed $data): void { Check::assertIsArray($data); Check::assertIsArray($data["nested"]["items"]); consume($data["nested"]["items"]); }', false],
    'dynamic key' => ['function dynamic(mixed $data, string $key): void { Check::assertIsArray($data); Check::assertIsArray($data[$key]); consume($data[$key]); }', false],
    'array access object' => ['function offset(MutableOffsets $data): void { Check::assertIsArray($data["nested"]); consume($data["nested"]); }', false],
    'property root' => ['function property(Holder $holder): void { Check::assertIsArray($holder->value); Check::assertIsArray($holder->value["nested"]); consume($holder->value["nested"]); }', false],
    'reassigned root' => ['function assigned(mixed $data): void { Check::assertIsArray($data); $data = new MutableOffsets(); Check::assertIsArray($data["nested"]); consume($data["nested"]); }', false],
    'intervening opaque call' => ['function interrupted(mixed $data): void { Check::assertIsArray($data); opaque(); Check::assertIsArray($data["nested"]); consume($data["nested"]); }', false],
    'unconditional proof only' => ['function condition(mixed $data, bool $flag): void { if ($flag) { Check::assertIsArray($data); } Check::assertIsArray($data["nested"]); consume($data["nested"]); }', false],
    'custom assertion' => ['function custom(mixed $data): void { CustomCheck::assertIsArray($data); Check::assertIsArray($data["nested"]); consume($data["nested"]); }', false],
    'assignment after assertion' => ['function changed(mixed $data): void { Check::assertIsArray($data); Check::assertIsArray($data["nested"]); $data["nested"] = null; consume($data["nested"]); }', false],
    'unrelated wrong type' => ['function wrong(mixed $data): void { Check::assertIsArray($data); Check::assertIsArray($data["nested"]); consumeInt($data["nested"]); }', false],
    'unasserted sibling' => ['function sibling(mixed $data): void { Check::assertIsArray($data); Check::assertIsArray($data["nested"]); consume($data["other"]); }', false],
    'unpacked assertion' => ['function unpacked(mixed $data): void { Check::assertIsArray($data); Check::assertIsArray(...[$data["nested"]]); consume($data["nested"]); }', false],
    'callable reference' => ['function callableCheck(mixed $data): void { Check::assertIsArray($data); $check = Check::assertIsArray(...); consume($data["nested"]); }', false],
    'message side effect' => ['function message(mixed $data): void { Check::assertIsArray($data); Check::assertIsArray($data["nested"], (string) random_int(0, 1)); consume($data["nested"]); }', false],
    'escaped root' => ['function escaped(mixed $data): void { $GLOBALS["escapedArray"] =& $data; Check::assertIsArray($data); Assert::array($data)->hasKeys("nested"); Check::assertIsArray($data["nested"]); consume($data["nested"]); }', false],
    'local reference alias' => ['function alias(mixed $data): void { $alias =& $data; Check::assertIsArray($data); Check::assertIsArray($data["nested"]); consume($data["nested"]); }', false],
    'reference parameter' => ['function reference(mixed &$data): void { Check::assertIsArray($data); Check::assertIsArray($data["nested"]); consume($data["nested"]); }', false],
];
$source = "<?php\nnamespace Fixtures;\nuse Laratesto\\Testing\\PhpUnitCompatibility as Check;\nuse Testo\\Assert;\n";
$lines = [];
foreach ($cases as $label => [$code, $positive]) {
    $lines[substr_count($source, "\n")] = $label;
    $source .= $code."\n";
}
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/worker.php', <<<'PHP'
<?php
require $argv[1];
$plugins = [new \Ichinya\Laramago\Analyzer\ArrayAssertionPlugin($argv[2])];
$plugins[] = new class($argv[2]) implements \Mago\Sdk\Analyzer\Plugin {
    public function __construct(private string $root) {}
    public function getDefinition(): \Mago\Sdk\Analyzer\PluginDefinition { return new \Mago\Sdk\Analyzer\PluginDefinition('test/base', 'Base assertions', 'Existing local assertion contracts.'); }
    public function register(\Mago\Sdk\Analyzer\PluginRegistry $registry): void {
        $provider = new \Ichinya\Laramago\Analyzer\LaratestoAssertionProvider($this->root);
        $registry->registerMethodAssertionProvider($provider);
        $registry->registerInitializationHook($provider);
    }
};
if (($argv[3] ?? '') === 'baseline') { array_shift($plugins); }
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension('test/array-elements', 'Array elements', '1', analyzerPlugins: $plugins)))->run();
PHP);
$modes = ['native', 'baseline', 'isolated', 'changed-assertion', 'nonreadonly-delegate', 'changed-constructor', 'added-destructor', 'changed-key-body', 'changed-type-success', 'changed-success', 'changed-parent-success', 'nonthrowing-failure', 'span-collision'];
if (in_array('--integrated', $argv, true)) {
    $modes[] = 'integrated';
}
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$reports = [];
foreach ($modes as $mode) {
    file_put_contents($workspace.'/vendor/ichinya/laratesto/src/Testing/PhpUnitCompatibility.php', $mode === 'changed-assertion'
        ? str_replace('Assert::true(\\is_array($actual), $message);', 'Assert::true(true, $message);', $check) : $check);
    file_put_contents($workspace.'/vendor/testo/assert/Assert.php', $assert);
    file_put_contents($workspace.'/vendor/testo/assert/src/Internal/StaticState.php', match ($mode) {
        'changed-type-success' => str_replace('return new \\stdClass();', '$GLOBALS["escapedArray"] = new \\Fixtures\\MutableOffsets(); return new \\stdClass();', $state),
        'changed-success' => str_replace('throw new \\RuntimeException(\'Assertion bodies must not execute during analysis.\');', '$GLOBALS["escapedArray"] = new \\Fixtures\\MutableOffsets(); return;', $state),
        default => $state,
    });
    file_put_contents($workspace.'/vendor/testo/assert/src/Internal/Assertion/AssertArray.php', match ($mode) {
        'nonreadonly-delegate' => str_replace('final readonly class', 'final class', $delegate),
        'changed-constructor' => str_replace('private object $parent) {}', 'private object $parent) { opaque(); }', $delegate),
        'added-destructor' => str_replace('public function hasKeys', 'public function __destruct() { $GLOBALS["escapedArray"] = new \\Fixtures\\MutableOffsets(); } public function hasKeys', $delegate),
        'changed-key-body' => str_replace('return $this;', 'opaque(); return $this;', $delegate),
        'changed-parent-success' => str_replace('return $this;', '$this->parent->success("keys"); return $this;', $delegate),
        'nonthrowing-failure' => str_replace('throw $this->parent->fail', '$this->parent->fail', $delegate),
        default => $delegate,
    });
    // A noncandidate method call at the same bytes in another host file poisons the index.
    $collision = str_replace('namespace Fixtures;', 'namespace Collided;', $source);
    $collision = str_replace('Check::assertIsArray', 'Dummy::irrelevantXXX', $collision);
    file_put_contents($workspace.'/collision.php', $mode === 'span-collision' ? $collision : '<?php');
    $current = [
        'extends' => $package.'/presets/laravel.toml', 'php-version' => '8.2',
        'source' => ['paths' => $mode === 'span-collision' ? ['cases.php', 'collision.php'] : ['cases.php'], 'includes' => ['types.php', 'vendor']],
        'extension-hosts' => $mode === 'native' ? new stdClass : ['test' => [
            'command' => [PHP_BINARY, '-d', 'opcache.enable_cli=0', $mode === 'integrated' ? $package.'/bin/laramago-worker.php' : $workspace.'/worker.php', $package.'/vendor/autoload.php', $workspace, $mode],
            'workers' => 2,
        ]],
    ];
    file_put_contents($workspace.'/mago.json', json_encode($current, JSON_THROW_ON_ERROR));
    $process = proc_open([...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'], [
        0 => ['pipe', 'r'], 1 => ['file', $workspace.'/'.$mode.'.json', 'w'], 2 => ['file', $workspace.'/'.$mode.'.log', 'w'],
    ], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start Mago.');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $report = json_decode(file_get_contents($workspace.'/'.$mode.'.json'), true);
    if (! is_array($report) || ! isset($report['issues'])) {
        throw new RuntimeException("Mago $mode failed ($exit): ".file_get_contents($workspace.'/'.$mode.'.log'));
    }
    $reports[$mode] = [];
    foreach ($report['issues'] as $issue) {
        $span = $issue['annotations'][0]['span'] ?? [];
        if (($span['file_id']['name'] ?? '') !== 'cases.php' || $issue['level'] !== 'Error') {
            continue;
        }
        $label = $lines[$span['start']['line'] ?? -1] ?? null;
        if ($label !== null) {
            $reports[$mode][$label][] = $issue['code'];
        }
    }
    foreach ($cases as $label => [$code, $positive]) {
        $errors = $reports[$mode][$label] ?? [];
        $fluentOnly = ['nonreadonly-delegate', 'changed-constructor', 'added-destructor', 'changed-key-body', 'changed-type-success', 'changed-parent-success', 'nonthrowing-failure'];
        $fixed = $positive && in_array($mode, ['isolated', 'integrated', ...$fluentOnly], true)
            && (! in_array($mode, $fluentOnly, true) || $label !== 'fluent key check');
        if ($fixed ? $errors !== [] : $errors === []) {
            throw new RuntimeException("Unexpected $mode errors for $label: ".json_encode($errors)."; workspace $workspace");
        }
    }
    echo "$mode: ".count($cases)." assertion cases verified\n";
}

// Compare every negative case against the existing provider, including multiplicity.
foreach ($cases as $label => [$code, $positive]) {
    if ($positive) {
        continue;
    }
    $expected = $reports['baseline'][$label] ?? [];
    if ($label === 'unrelated wrong type') {
        $expected = ['invalid-argument'];
    }
    sort($expected);
    foreach (array_intersect(['isolated', 'integrated'], $modes) as $mode) {
        $actual = $reports[$mode][$label] ?? [];
        sort($actual);
        if ($actual !== $expected) {
            throw new RuntimeException("Unexpected $mode negative diagnostic delta for $label");
        }
    }
}

require $package.'/vendor/autoload.php';
$cancel = new class implements \Mago\Sdk\CancellationTokenInterface {
    public function isCancelled(): bool { return false; }
    public function throwIfCancelled(): void {}
    public function subscribe(Closure $callback): int { return 0; }
    public function unsubscribe(int $subscription): void {}
};
$version = \Mago\Sdk\PHPVersion::fromParts(8, 2);
$file = static function (string $path, string $contents) use ($version): \Mago\Sdk\Syntax\SourceFile {
    return new \Mago\Sdk\Syntax\SourceFile($version, $path, $contents, [],
        (new ReflectionClass(\Mago\Sdk\Internal\Syntax\NodeStore::class))->newInstanceWithoutConstructor(),
        (new ReflectionClass(\Mago\Sdk\Internal\Syntax\ResolvedNameStore::class))->newInstanceWithoutConstructor(),
        (new ReflectionClass(\Mago\Sdk\Internal\Syntax\TriviaStore::class))->newInstanceWithoutConstructor(), null);
};
$index = new \Ichinya\Laramago\Analyzer\StaticAnalysis\ArrayAssertionCalls;
$snapshot = '<?php use Laratesto\\Testing\\PhpUnitCompatibility as Check; Check::assertIsArray($data); Check::assertIsArray($data["x"]);';
$span = new \Mago\Sdk\Span(strrpos($snapshot, 'Check::'), strlen($snapshot) - 1);
$scan = static function (array $files, bool $first = true, bool $last = true) use ($index, $version, $cancel): void {
    $index->scan(new \Mago\Sdk\Analyzer\CodebaseScanContext($version, $cancel, $files, $first, $last));
};
$expect = static function (?int $count) use ($index, $span): void {
    $run = $index->run($span);
    if (($run === null ? null : count($run)) !== $count) {
        throw new RuntimeException('Unexpected source inventory or span identity state.');
    }
};
$scan([$file('/unsaved.php', $snapshot)]);
$expect(2);
$scan([$file('/unsaved.php', $snapshot)], last: false);
$expect(null);
$scan([], first: false);
$expect(2);
$scan([$file('/first.php', $snapshot), $file('/second.php', $snapshot)]);
$expect(null);
$scan([$file('/first.php', $snapshot), $file('/second.php', str_replace('Check::assertIsArray', 'Dummy::irrelevantXXX', $snapshot))]);
$expect(null);
$scan([$file('/first.php', $snapshot), $file('/second.php', str_replace('Check::assertIsArray', str_repeat('f', strlen('Check::assertIsArray')), $snapshot))]);
$expect(null);
$scan([$file('/unsaved.php', $snapshot), $file('/broken.php', '<?php function broken( {')]);
$expect(null);
$scan([$file('/unsaved.php', $snapshot), $file('/large.php', '<?php /*'.str_repeat('x', 2_000_000).'*/')]);
$expect(null);
$scan([$file('/unsaved.php', $snapshot)]);
$expect(2);
$changed = str_replace('Check::assertIsArray($data);', str_repeat(' ', strlen('Check::assertIsArray($data)')).';', $snapshot);
$scan([$file('/unsaved.php', $changed)]);
$expect(1);
$scan([$file('/unsaved.php', $snapshot)]);
$expect(2);
echo "source inventory: batching, collisions, failed reads, and current snapshots verified\n";
