<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\InfiniteForReturnFilter;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\TypeComparator;
use Mago\Sdk\CancellationTokenInterface;
use Mago\Sdk\PHPVersion;
use Mago\Sdk\Reporting\Annotation;
use Mago\Sdk\Reporting\AnnotationKind;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Reporting\ReportedIssue;
use Mago\Sdk\Span;

require __DIR__.'/../vendor/autoload.php';

$binary = getenv('MAGO_BINARY') ?: __DIR__.'/../vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$package = str_replace('\\', '/', dirname(__DIR__));
$integrated = in_array('--integrated', $argv, true);
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago infinite for '.bin2hex(random_bytes(8));
mkdir($workspace);
file_put_contents($workspace.'/bootstrap.php', '<?php throw new RuntimeException("Never bootstrap test projects.");');
file_put_contents($workspace.'/composer.json', json_encode([
    'autoload' => ['files' => ['bootstrap.php']],
], JSON_THROW_ON_ERROR));
file_put_contents($workspace.'/worker.php', <<<'PHP'
<?php
declare(strict_types=1);
require $argv[1];
use Ichinya\Laramago\Analyzer\InfiniteForReturnFilter;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;
final class InfiniteForPlugin implements Plugin {
    public function getDefinition(): PluginDefinition {
        return new PluginDefinition('infinite-for-test', 'Infinite for test', 'Test-only non-fallthrough contract.');
    }
    public function register(PluginRegistry $registry): void {
        $filter = new InfiniteForReturnFilter;
        $registry->registerIssueFilterHook($filter);
        $registry->registerInitializationHook($filter);
    }
}
(new Mago\Sdk\Worker(new Mago\Sdk\Extension(
    identifier: 'infinite-for-test',
    name: 'Infinite for test',
    version: '1',
    analyzerPlugins: [new InfiniteForPlugin],
)))->run();
PHP);
$retry = 'try { return $operation(); } catch (\\RuntimeException $exception) { if ($attempt >= 3) { throw $exception; } }';
$retryBreak = 'try { return $operation(); } catch (\\RuntimeException $exception) { if ($attempt >= 3) { break; } }';
$cases = [
    'empty condition retry' => [
        'function emptyCondition(\\Closure $operation): int { for ($attempt = 1; ; $attempt++) { '.$retry.' } }',
        true,
        [],
    ],
    'true condition retry' => [
        'function trueCondition(\\Closure $operation): int { for ($attempt = 1; true; $attempt++) { '.$retry.' } }',
        true,
        [],
    ],
    'method retry' => [
        'class Retry { /** @param \\Closure(): int $operation */ public function attempt(\\Closure $operation): int { for ($attempt = 1; ; $attempt++) { '.$retry.' } } }',
        true,
        [],
    ],
    'retry with ordinary continue' => [
        'function explicitContinue(\\Closure $operation): int { for ($attempt = 1; ; $attempt++) { try { return $operation(); } catch (\\RuntimeException $exception) { if ($attempt >= 3) { throw $exception; } continue; } } }',
        true,
        [],
    ],
    'nested callable yield does not escape' => [
        'function nestedGenerator(\\Closure $operation): int { $generator = function (): \\Generator { yield 1; }; for ($attempt = 1; ; $attempt++) { '.$retry.' } }',
        true,
        [],
    ],
    'nested callable break does not escape' => [
        'function nestedBreak(\\Closure $operation): int { $callback = function (): void { while (true) { break; } }; for ($attempt = 1; ; $attempt++) { '.$retry.' } }',
        true,
        [],
    ],
    'real escaping break' => [
        'function escapingBreak(\\Closure $operation): int { for ($attempt = 1; ; $attempt++) { '.$retryBreak.' } }',
        false,
        ['missing-return-statement'],
    ],
    'finite loop' => [
        'function finiteLoop(\\Closure $operation): int { for ($attempt = 1; $attempt <= 3; $attempt++) { '.$retry.' } }',
        false,
        ['missing-return-statement'],
    ],
    'multiple conditions use final false expression' => [
        'function multipleConditions(\\Closure $operation): int { for ($attempt = 1; true, false; $attempt++) { '.$retry.' } }',
        false,
        ['missing-return-statement'],
    ],
    'conditional loop is not a final top level loop' => [
        'function conditionalLoop(\\Closure $operation, bool $enabled): int { if ($enabled) { for ($attempt = 1; ; $attempt++) { '.$retry.' } } }',
        false,
        ['missing-return-statement'],
    ],
    'nested infinite closure does not return from outer function' => [
        'function closureOnly(\\Closure $operation): int { $callback = function () use ($operation): int { for ($attempt = 1; ; $attempt++) { '.$retry.' } }; }',
        false,
        ['missing-return-statement'],
    ],
    'nested loop break level escapes retry' => [
        'function breakLevel(\\Closure $operation): int { for ($attempt = 1; ; $attempt++) { try { return $operation(); } catch (\\RuntimeException) { while (true) { break 2; } } } }',
        false,
        ['missing-return-statement'],
    ],
    'goto skips final loop' => [
        'function skipLoop(\\Closure $operation): int { goto finished; for ($attempt = 1; ; $attempt++) { '.$retry.' } finished: }',
        false,
        ['missing-return-statement'],
    ],
    'label and goto remain unsupported' => [
        'function backwardGoto(\\Closure $operation): int { retry: for ($attempt = 1; ; $attempt++) { try { return $operation(); } catch (\\RuntimeException) { goto retry; } } }',
        false,
        ['missing-return-statement'],
    ],
    'invalid return value is retained' => [
        'function wrongValue(\\Closure $operation): int { for ($attempt = 1; ; $attempt++) { try { $operation(); return "wrong"; } catch (\\RuntimeException $exception) { if ($attempt >= 3) { throw $exception; } } } }',
        true,
        ['invalid-return-statement'],
    ],
    'unrelated genuine missing method return' => [
        'class Missing { public function attempt(bool $enabled): int { if ($enabled) { return 1; } } }',
        false,
        ['missing-return-statement'],
    ],
];

$configuration = [
    'extends' => $package.'/presets/laravel.toml',
    'php-version' => '8.2',
    'source' => ['paths' => ['cases']],
    'extension-hosts' => ['infinite-for-test' => [
        'command' => $integrated
            ? [PHP_BINARY, $package.'/bin/laramago-worker.php', $package.'/vendor/autoload.php', $workspace]
            : [PHP_BINARY, $workspace.'/worker.php', $package.'/vendor/autoload.php'],
        'workers' => 2,
    ]],
];
mkdir($workspace.'/cases');
$files = [];
foreach ($cases as $name => [$code, $remove, $retained]) {
    $file = 'cases/scenario'.count($files).'.php';
    $files[$file] = [$name, $remove, $retained];
    $namespace = 'Scenario'.(count($files) - 1);
    file_put_contents($workspace.'/'.$file, "<?php\nnamespace ".$namespace.";\n/** @param \\Closure(): int \$operation */\n".$code."\n");
}
file_put_contents($workspace.'/mago.json', json_encode($configuration, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$reports = [];
foreach (['native', 'enabled'] as $mode) {
    $arguments = [...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'];
    if ($mode === 'native') {
        $arguments[] = '--no-extensions';
    }
    $process = proc_open($arguments, [
        0 => ['pipe', 'r'],
        1 => ['file', $workspace.'/'.$mode.'.json', 'w'],
        2 => ['file', $workspace.'/'.$mode.'.log', 'w'],
    ], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start Mago.');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $log = file_get_contents($workspace.'/'.$mode.'.log');
    if ($exit !== 1 || preg_match('/External analyzer provider failed|extension worker .*rejected request|No files found/i', $log)) {
        throw new RuntimeException('Unexpected '.$mode.' Mago result; inspect '.$workspace.' (exit '.$exit.'): '.$log);
    }
    $report = json_decode(file_get_contents($workspace.'/'.$mode.'.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach ($report['issues'] ?? [] as $issue) {
        $primary = array_values(array_filter($issue['annotations'], static fn (array $annotation): bool => $annotation['kind'] === 'Primary'))[0];
        $file = str_replace('\\', '/', $primary['span']['file_id']['name']);
        $reports[$mode][$file][] = $issue;
    }
}
foreach ($files as $file => [$name, $remove, $retained]) {
    $native = $reports['native'][$file] ?? [];
    $enabled = $reports['enabled'][$file] ?? [];
    $nativeCodes = array_column($native, 'code');
    $enabledCodes = array_column($enabled, 'code');
    if (! in_array('missing-return-statement', $nativeCodes, true)) {
        throw new RuntimeException('Native Mago did not emit the missing-return control for '.$name.'; inspect '.$workspace);
    }
    $expected = $native;
    if ($remove) {
        $expected = array_values(array_filter($expected, static fn (array $issue): bool => $issue['code'] !== 'missing-return-statement'));
    }
    $normalize = static function (array $issues): array {
        $serialized = array_map(static fn (array $issue): string => json_encode($issue, JSON_THROW_ON_ERROR), $issues);
        sort($serialized);

        return $serialized;
    };
    if ($normalize($expected) !== $normalize($enabled)) {
        throw new RuntimeException($name.': unexpected diagnostic delta; native '.json_encode($nativeCodes).', enabled '.json_encode($enabledCodes).'; inspect '.$workspace);
    }
    foreach ($retained as $code) {
        if (! in_array($code, $enabledCodes, true)) {
            throw new RuntimeException($name.': missing negative control '.$code.'; inspect '.$workspace);
        }
    }
    echo 'PASS: '.$name.PHP_EOL;
}

// Exercise exact in-memory source identity and conservative controls independently of Mago's flow decisions.
$cancel = new class implements CancellationTokenInterface {
    public function isCancelled(): bool { return false; }
    public function throwIfCancelled(): void {}
    public function subscribe(Closure $callback): int { return 0; }
    public function unsubscribe(int $subscription): void {}
};
$codebase = (new ReflectionClass(Codebase::class))->newInstanceWithoutConstructor();
$types = (new ReflectionClass(TypeComparator::class))->newInstanceWithoutConstructor();
$filter = new InfiniteForReturnFilter;
$check = static function (
    string $contents,
    IssueFilterDecision $expected,
    string $code = 'missing-return-statement',
    string $target = 'probe',
    int $shift = 0,
    ?string $file = null,
    bool $duplicate = false,
) use ($workspace, $filter, $codebase, $types, $cancel): void {
    $start = strpos($contents, 'probe');
    if ($start === false) {
        throw new RuntimeException('Direct fixture must contain a probe identifier.');
    }
    $annotation = new Annotation(AnnotationKind::Primary, new Span($start + $shift, $start + $shift + 5), null, $file);
    $issue = new ReportedIssue(
        Level::Error,
        $code,
        'Missing return statement in function `'.$target.'`',
        [],
        null,
        null,
        $duplicate ? [$annotation, $annotation] : [$annotation],
        [],
    );
    $context = new IssueFilterContext(
        PHPVersion::fromParts(8, 2), $codebase, $types, $cancel, $workspace.'/unsaved.php', $contents, $issue,
    );
    if ($filter->filterIssue($context) !== $expected) {
        throw new RuntimeException('Direct issue boundary failed: '.$contents);
    }
};
$infinite = '<?php function probe(): int { for (;;) {} }';
$check($infinite, IssueFilterDecision::Remove);
$check('<?php function probe(): int { for (;;) { break; } }', IssueFilterDecision::Keep);
$check($infinite, IssueFilterDecision::Remove);
$check($infinite, IssueFilterDecision::Keep, shift: 1);
$check($infinite, IssueFilterDecision::Keep, target: 'different');
$check($infinite, IssueFilterDecision::Keep, code: 'invalid-return-statement');
$check($infinite, IssueFilterDecision::Keep, file: 'other.php');
$check($infinite, IssueFilterDecision::Keep, duplicate: true);
foreach ([
    'if (random_int(0, 1)) { return; } for (;;) {}',
    'for (;;) { yield 1; }',
    'for (;;) { yield from []; }',
    'for (;;) { while (true) { continue 2; } }',
    'for (;;) { continue $level; }',
    'for (;;) { eval("return;"); }',
    'for (; 1;) {}',
    'for (; true, true;) {}',
    'for (; false;) {}',
    'for (;;) {} return 1;',
] as $body) {
    $check('<?php function probe(): int { '.$body.' }', IssueFilterDecision::Keep);
}
$check('<?php function probe( {', IssueFilterDecision::Keep);
echo 'PASS: in-memory source, exact issue spans, and unsupported control flow'.PHP_EOL;

$resolved = realpath($workspace);
$temporary = realpath(sys_get_temp_dir());
if ($resolved === false || $temporary === false || ! str_starts_with($resolved, $temporary.DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Refusing cleanup outside the temporary directory.');
}
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
);
foreach ($iterator as $entry) {
    $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
}
rmdir($workspace);
