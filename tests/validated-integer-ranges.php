<?php

declare(strict_types=1);

$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago integer ranges '.bin2hex(random_bytes(8));
mkdir($workspace, recursive: true);
file_put_contents($workspace.'/bootstrap.php', '<?php throw new RuntimeException("Application bootstrap executed.");');
file_put_contents($workspace.'/composer.json', json_encode(['autoload' => ['files' => ['bootstrap.php']]], JSON_THROW_ON_ERROR));
file_put_contents($workspace.'/types.php', <<<'PHP'
<?php
namespace Fixtures { function sideEffect(): void {} }
namespace ShadowFilter { function filter_var(mixed $value, int $filter, array $options): int { return 0; } }
namespace ShadowGuard { function is_int(mixed $value): bool { return true; } }
namespace ShadowConstant { const FILTER_VALIDATE_INT = \FILTER_VALIDATE_INT; }
PHP);
$filter = 'filter_var($raw, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]])';
$negative = 'if (!is_int($id)) { throw new \\RuntimeException(); }';
$cases = [
    'positive minimum' => ['Fixtures', '/** @return positive-int */ function minimum(mixed $raw): int { $id = '.$filter.'; '.$negative.' return $id; }', true],
    'positive guard' => ['Fixtures', '/** @return positive-int */ function positive(mixed $raw): int { $id = '.$filter.'; if (is_int($id)) { return $id; } throw new \\RuntimeException(); }', true],
    'inclusive upper bound' => ['Fixtures', '/** @return int<min,10> */ function upper(mixed $raw): int { $id = filter_var($raw, FILTER_VALIDATE_INT, ["options" => ["max_range" => 10]]); '.$negative.' return $id; }', true],
    'signed interval' => ['Fixtures', '/** @return int<-10,10> */ function bounded(mixed $raw): int { $id = filter_var($raw, FILTER_VALIDATE_INT, ["options" => ["min_range" => -10, "max_range" => +10]]); '.$negative.' return $id; }', true],
    'zero bound' => ['Fixtures', '/** @return non-negative-int */ function zero(mixed $raw): int { $id = filter_var($raw, FILTER_VALIDATE_INT, ["flags" => 0, "options" => ["min_range" => 0]]); '.$negative.' return $id; }', true],
    'null failure flag' => ['Fixtures', '/** @return positive-int */ function nullable(mixed $raw): int { $id = filter_var($raw, FILTER_VALIDATE_INT, ["flags" => FILTER_NULL_ON_FAILURE, "options" => ["min_range" => 1]]); '.$negative.' return $id; }', true],
    'imported native aliases' => ['Fixtures', '/** @return positive-int */ function aliases(mixed $raw): int { $id = validate($raw, INTEGER_FILTER, ["options" => ["min_range" => 1]]); if (!check_int($id)) { throw new \\RuntimeException(); } return $id; }', true],
    'method body' => ['Fixtures', 'final class RangeReader { /** @return positive-int */ public function read(mixed $raw): int { $id = '.$filter.'; '.$negative.' return $id; } }', true],
    'closure body' => ['Fixtures', 'function closureReader(): \\Closure { return /** @return positive-int */ function(mixed $raw): int { $id = '.$filter.'; '.$negative.' return $id; }; }', true],
    'default outside range' => ['Fixtures', '/** @return positive-int */ function defaultValue(mixed $raw): int { $id = filter_var($raw, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1, "default" => 0]]); '.$negative.' return $id; }', false],
    'default inside range still deferred' => ['Fixtures', '/** @return positive-int */ function defaultInside(mixed $raw): int { $id = filter_var($raw, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1, "default" => 4]]); '.$negative.' return $id; }', false],
    'dynamic options' => ['Fixtures', '/** @return positive-int */ function options(mixed $raw, array $options): int { $id = filter_var($raw, FILTER_VALIDATE_INT, $options); '.$negative.' return $id; }', false],
    'dynamic bound' => ['Fixtures', '/** @return positive-int */ function bound(mixed $raw, int $minimum): int { $id = filter_var($raw, FILTER_VALIDATE_INT, ["options" => ["min_range" => $minimum]]); '.$negative.' return $id; }', false],
    'numeric string bound' => ['Fixtures', '/** @return positive-int */ function stringBound(mixed $raw): int { $id = filter_var($raw, FILTER_VALIDATE_INT, ["options" => ["min_range" => "1"]]); '.$negative.' return $id; }', false],
    'duplicate bound' => ['Fixtures', '/** @return positive-int */ function duplicate(mixed $raw): int { $id = filter_var($raw, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1, "min_range" => 0]]); '.$negative.' return $id; }', false],
    'spread options' => ['Fixtures', '/** @return positive-int */ function spread(mixed $raw): int { $id = filter_var($raw, FILTER_VALIDATE_INT, ["options" => [...["min_range" => 1]]]); '.$negative.' return $id; }', false],
    'unsupported scalar flags' => ['Fixtures', '/** @return positive-int */ function flags(mixed $raw): int { $id = filter_var($raw, FILTER_VALIDATE_INT, ["flags" => FILTER_FLAG_ALLOW_HEX, "options" => ["min_range" => 1]]); '.$negative.' return $id; }', false],
    'require array flag' => ['Fixtures', '/** @return positive-int */ function arrayFlag(mixed $raw): int { $id = filter_var($raw, FILTER_VALIDATE_INT, ["flags" => FILTER_REQUIRE_ARRAY, "options" => ["min_range" => 1]]); '.$negative.' return $id; }', false],
    'dynamic flags' => ['Fixtures', '/** @return positive-int */ function dynamicFlags(mixed $raw, int $flags): int { $id = filter_var($raw, FILTER_VALIDATE_INT, ["flags" => $flags, "options" => ["min_range" => 1]]); '.$negative.' return $id; }', false],
    'reversed range' => ['Fixtures', '/** @return positive-int */ function reversed(mixed $raw): int { $id = filter_var($raw, FILTER_VALIDATE_INT, ["options" => ["min_range" => 10, "max_range" => 1]]); '.$negative.' return $id; }', false],
    'prior variable' => ['Fixtures', '/** @return positive-int */ function prior(mixed $raw): int { $id = 0; $id = '.$filter.'; '.$negative.' return $id; }', false],
    'existing parameter' => ['Fixtures', '/** @return positive-int */ function parameter(mixed $raw, int $id): int { $id = '.$filter.'; '.$negative.' return $id; }', false],
    'right side read' => ['Fixtures', '/** @return positive-int */ function rightSide(mixed $raw): int { $id = filter_var($id ?? $raw, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]); '.$negative.' return $id; }', false],
    'intervening statement' => ['Fixtures', '/** @return positive-int */ function interrupted(mixed $raw): int { $id = '.$filter.'; sideEffect(); '.$negative.' return $id; }', false],
    'nested loop statements' => ['Fixtures', '/** @return positive-int */ function loop(mixed $raw): int { while (true) { $id = '.$filter.'; '.$negative.' return $id; } }', false],
    'unrelated reference escape' => ['Fixtures', '/** @return positive-int */ function reference(mixed $raw): int { $alias =& $raw; $id = '.$filter.'; '.$negative.' return $id; }', false],
    'by-reference parameter' => ['Fixtures', '/** @return positive-int */ function referenceParameter(mixed &$raw): int { $id = '.$filter.'; '.$negative.' return $id; }', false],
    'captured variable' => ['Fixtures', 'function captured(int $id): \\Closure { return /** @return positive-int */ function(mixed $raw) use ($id): int { $id = '.$filter.'; '.$negative.' return $id; }; }', false],
    'by-reference capture' => ['Fixtures', 'function capturedReference(mixed $raw): \\Closure { return /** @return positive-int */ function() use (&$raw): int { $id = '.$filter.'; '.$negative.' return $id; }; }', false],
    'extract alias' => ['Fixtures', '/** @return positive-int */ function extraction(mixed $raw, array $data): int { hydrate($data); $id = '.$filter.'; '.$negative.' return $id; }', false],
    'dynamic call scope' => ['Fixtures', '/** @return positive-int */ function dynamicCall(mixed $raw, \\Closure $callback): int { $callback(); $id = '.$filter.'; '.$negative.' return $id; }', false],
    'later mutation' => ['Fixtures', '/** @return positive-int */ function mutation(mixed $raw): int { $id = '.$filter.'; '.$negative.' $id = 0; return $id; }', false],
    'wrong downstream bound' => ['Fixtures', '/** @return int<2,max> */ function wrongBound(mixed $raw): int { $id = '.$filter.'; '.$negative.' return $id; }', false],
    'nonterminating failure branch' => ['Fixtures', '/** @return positive-int */ function failureBranch(mixed $raw): int { $id = '.$filter.'; if (!is_int($id)) { sideEffect(); } return $id; }', false],
    'false branch remains false' => ['Fixtures', '/** @return positive-int */ function falseBranch(mixed $raw): int { $id = '.$filter.'; if (is_int($id)) { throw new \\RuntimeException(); } return $id; }', false],
    'null branch remains null' => ['Fixtures', '/** @return positive-int */ function nullBranch(mixed $raw): int { $id = filter_var($raw, FILTER_VALIDATE_INT, ["flags" => FILTER_NULL_ON_FAILURE, "options" => ["min_range" => 1]]); if (is_int($id)) { throw new \\RuntimeException(); } return $id; }', false],
    'custom filter fallback' => ['ShadowFilter', '/** @return positive-int */ function customFilter(mixed $raw): int { $id = '.$filter.'; '.$negative.' return $id; }', false],
    'custom guard fallback' => ['ShadowGuard', '/** @return positive-int */ function customGuard(mixed $raw): int { $id = '.$filter.'; '.$negative.' return $id; }', false],
    'custom constant fallback' => ['ShadowConstant', '/** @return positive-int */ function customConstant(mixed $raw): int { $id = '.$filter.'; '.$negative.' return $id; }', false],
];
$source = "<?php\n";
$lines = [];
foreach ($cases as $name => [$namespace, $code, $positive]) {
    $source .= 'namespace '.$namespace." {\nuse function filter_var as validate; use function is_int as check_int; use function extract as hydrate; use const FILTER_VALIDATE_INT as INTEGER_FILTER;\n";
    $lines[substr_count($source, "\n")] = $name;
    $source .= $code."\n}\n";
}
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/worker.php', <<<'PHP'
<?php
require $argv[1];
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension('test/integer-ranges', 'Integer ranges', '1', analyzerPlugins: [new \Ichinya\Laramago\Analyzer\IntegerValidationPlugin])))->run();
PHP);
$modes = ['native', 'isolated'];
if (in_array('--integrated', $argv, true)) {
    $modes[] = 'integrated';
}
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$reports = [];
foreach ($modes as $mode) {
    file_put_contents($workspace.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml', 'php-version' => '8.2',
        'source' => ['paths' => ['cases.php'], 'includes' => ['types.php']],
        'extension-hosts' => $mode === 'native' ? new stdClass : ['test' => [
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
    $report = json_decode(file_get_contents($workspace.'/'.$mode.'.json'), true);
    if (! is_array($report) || ! isset($report['issues'])) {
        throw new RuntimeException("Mago $mode failed ($exit): ".file_get_contents($workspace.'/'.$mode.'.log'));
    }
    $reports[$mode] = [];
    foreach ($report['issues'] as $issue) {
        $span = $issue['annotations'][0]['span'] ?? [];
        if ($issue['level'] === 'Error' && ($span['file_id']['name'] ?? '') === 'cases.php') {
            $name = $lines[$span['start']['line'] ?? -1] ?? null;
            if ($name !== null) {
                $reports[$mode][$name][] = $issue['code'];
            }
        }
    }
    foreach ($cases as $name => [$namespace, $code, $positive]) {
        $errors = $reports[$mode][$name] ?? [];
        if (($positive && $mode !== 'native') ? $errors !== [] : $errors === []) {
            throw new RuntimeException("Unexpected $mode diagnostics for $name: ".json_encode($errors)."; workspace $workspace");
        }
    }
    echo "$mode: ".count($cases)." integer validation cases verified\n";
}

foreach ($cases as $name => [$namespace, $code, $positive]) {
    if ($positive) {
        continue;
    }
    foreach (array_slice($modes, 1) as $mode) {
        $remaining = $reports[$mode][$name];
        foreach ($reports['native'][$name] as $code) {
            $position = array_search($code, $remaining, true);
            if ($position === false) {
                throw new RuntimeException("Native $code disappeared from $mode negative case: $name");
            }
            unset($remaining[$position]);
        }
    }
}

require $package.'/vendor/autoload.php';
$cancel = new class implements \Mago\Sdk\CancellationTokenInterface {
    public bool $cancelled = false;
    public function isCancelled(): bool { return $this->cancelled; }
    public function throwIfCancelled(): void {
        if ($this->cancelled) {
            throw new RuntimeException('Scan cancelled.');
        }
    }
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
$index = new \Ichinya\Laramago\Analyzer\StaticAnalysis\ValidatedIntegerCalls;
$snapshot = '<?php function read(mixed $raw): int { $id = '.$filter.'; '.$negative.' return $id; }';
$start = strpos($snapshot, 'is_int(');
$span = new \Mago\Sdk\Span($start, $start + strlen('is_int($id)'));
$scan = static function (array $files, bool $first = true, bool $last = true) use ($index, $version, $cancel): void {
    $index->scan(new \Mago\Sdk\Analyzer\CodebaseScanContext($version, $cancel, $files, $first, $last));
};
$checks = 0;
$expect = static function (bool $present) use ($index, $span, &$checks): void {
    if (($index->call($span) !== null) !== $present) {
        throw new RuntimeException('Unexpected integer guard source inventory state.');
    }
    $checks++;
};
$scan([$file('/unsaved.php', $snapshot)]);
$expect(true);
$scan([$file('/unsaved.php', $snapshot)], last: false);
$expect(false);
$scan([], first: false);
$expect(true);
$scan([$file('/first.php', $snapshot), $file('/second.php', $snapshot)]);
$expect(false);
$scan([$file('/first.php', $snapshot)], last: false);
$scan([$file('/second.php', $snapshot)], first: false);
$expect(false);
// An unrelated call at the same offsets must poison the identity too.
$noncandidate = '<?php '.str_repeat(' ', $span->start - strlen('<?php ')).'random($id);';
$scan([$file('/first.php', $snapshot), $file('/unrelated.php', $noncandidate)]);
$expect(false);
$scan([$file('/first.php', $snapshot), $file('/broken.php', '<?php function broken( {')]);
$expect(false);
$scan([$file('/first.php', $snapshot), $file('/large.php', '<?php /*'.str_repeat('x', 2_000_000).'*/')]);
$expect(false);
// Seed the bounded inventory without building an unnecessarily large PHP AST.
$scan([], last: false);
$inventory = new ReflectionProperty($index, 'calls');
$inventory->setValue($index, array_fill_keys(array_map(static fn (int $offset): string => '-'.$offset.':0', range(1, 100_000)), null));
$scan([$file('/first.php', $snapshot)], first: false);
$expect(false);
$scan([$file('/unsaved.php', $snapshot)]);
$expect(true);
$scan([$file('/unsaved.php', str_replace('if (!is_int($id))', 'if (is_null($id))', $snapshot))]);
$expect(false);
$scan([$file('/unsaved.php', $snapshot)]);
$expect(true);
$cancel->cancelled = true;
try {
    $scan([$file('/unsaved.php', $snapshot)], first: false);
    throw new LogicException('Cancelled source scan continued.');
} catch (RuntimeException $exception) {
    if ($exception->getMessage() !== 'Scan cancelled.') {
        throw $exception;
    }
}
$expect(false);
$cancel->cancelled = false;
$scan([$file('/unsaved.php', $snapshot)]);
$expect(true);
echo "source inventory: $checks batching, collision, bounds, cancellation, and snapshot checks verified\n";
