<?php

declare(strict_types=1);

// Analyze invented fixtures without executing declarations, bootstrap or consumer autoload.
require __DIR__.'/../vendor/autoload.php';

$package = str_replace('\\', '/', dirname(__DIR__));
$fixtures = $package.'/tests/fixtures/weak-numeric-floats';
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago weak numeric float '.bin2hex(random_bytes(8));
foreach (['cases', 'database/migrations', 'packages/composer'] as $directory) {
    if (! is_dir($workspace.'/'.$directory)) { mkdir($workspace.'/'.$directory, recursive: true); }
}
$trap = '<?php file_put_contents(__DIR__."/executed", "executed"); throw new RuntimeException("Analyzed body executed.");';
file_put_contents($workspace.'/database/migrations/001_trap.php', $trap);
file_put_contents($workspace.'/bootstrap.php', '<?php file_put_contents(__DIR__."/bootstrap-executed", "executed"); throw new RuntimeException("Bootstrap must not execute.");');
file_put_contents($workspace.'/composer.json', '{"config":{"vendor-dir":"packages"},"autoload":{"files":["bootstrap.php"]}}');
file_put_contents($workspace.'/packages/composer/autoload_files.php', '<?php file_put_contents(__DIR__."/composer-body-executed", "executed"); throw new RuntimeException("Consumer autoload files must not execute.");');
copy($fixtures.'/NativeFloatContracts.php', $workspace.'/contracts.php');
copy($fixtures.'/StrictFloatContracts.php', $workspace.'/strict-contracts.php');
file_put_contents($workspace.'/packages/composer/installed.json', '{"packages":[]}');
$cases = require $fixtures.'/cases.php';
$files = [];
foreach ($cases as $label => $case) {
    $file = 'cases/case'.count($files).'.php';
    $bytes = "<?php\n".($case['directive'] ?? '')."\nnamespace FloatCase".count($files).";\n".$case['source']."\n"
        .'file_put_contents(__DIR__."/case-body-executed", "Analyzed source body must never execute.");'."\n";
    file_put_contents($workspace.'/'.$file, $bytes);
    $files[$file] = $label;
}
file_put_contents($workspace.'/focus.php', <<<'PHP'
<?php
namespace FloatFocus;
/** @mixin \FloatFixtures\DecimalInput */
final class InvoiceTotals {
    private function accept(float $value): void {}
    public function run(\FloatFixtures\DecimalInput $input): void { $this->accept($input->amount); $input->missing(); }
    public function returnAmount(bool $select): float { return $select ? \FloatFixtures\numeric() : 1.5; }
}
file_put_contents(__DIR__.'/focus-body-executed', 'Analyzed source body must never execute.');
PHP);
$header = '<?php require '.var_export($package.'/vendor/autoload.php', true).';';
file_put_contents($workspace.'/worker.php', $header
    .' (new Mago\Sdk\Worker(new Mago\Sdk\Extension("fixture/weak-float", "Weak floats", "1", analyzerPlugins: [new Ichinya\Laramago\Analyzer\WeakNumericFloatCompatibilityPlugin('.var_export($workspace, true).')])))->run();');
file_put_contents($workspace.'/guard-worker.php', $header.' require '.var_export($fixtures.'/WeakNumericFloatControlObserver.php', true).';'
    .' (new Mago\Sdk\Worker(new Mago\Sdk\Extension("fixture/weak-float-controls", "Weak float controls", "1", analyzerPlugins: [new Ichinya\Laramago\Tests\Support\WeakNumericFloatControlObserver('.var_export($workspace, true).')])))->run();');
$parser = (new \PhpParser\ParserFactory)->createForNewestSupportedVersion();
$receipts = [];
foreach ([...array_keys($files), 'focus.php', 'contracts.php', 'strict-contracts.php', 'worker.php', 'guard-worker.php',
    'bootstrap.php', 'database/migrations/001_trap.php', 'packages/composer/autoload_files.php'] as $file) {
    $bytes = file_get_contents($workspace.'/'.$file); $parser->parse($bytes);
    $receipts[$file] = ['bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
}
$modeChecks = [
    'absent weak declaration' => ['<?php function test():void{}', true],
    'literal zero weak declaration' => ['<?php declare(strict_types=0); function test():void{}', true],
    'strict declaration' => ['<?php declare(strict_types=1); function test():void{}', false],
    'unknown ticks declaration' => ['<?php declare(ticks=1); function test():void{}', false],
    'unknown encoding declaration' => ['<?php declare(encoding="UTF-8"); function test():void{}', false],
    'invalid strict integer declaration' => ['<?php declare(strict_types=2); function test():void{}', false],
    'unknown strict constant declaration' => ['<?php declare(strict_types=UNKNOWN); function test():void{}', false],
    'block zero declaration' => ['<?php declare(strict_types=0) { function test():void{} }', false],
    'late zero declaration' => ['<?php function test():void{} declare(strict_types=0);', false],
    'duplicate declaration' => ['<?php declare(strict_types=0); declare(strict_types=0);', false],
    'combined declaration' => ['<?php declare(strict_types=0,ticks=1);', false],
];
foreach ($modeChecks as $label => [$bytes, $expected]) {
    if (\Ichinya\Laramago\Analyzer\WeakNumericFloatCalls::weak($parser->parse($bytes) ?? []) !== $expected) { throw new RuntimeException('Source mode control failed: '.$label); }
}
$domainChecks = ['numeric-string' => true, 'numeric-string|float' => true, 'float(1.5)|numeric-string' => true,
    'float(-1.0E+3)|numeric-string' => true, 'float' => false, 'string' => false, 'numeric' => false, 'mixed' => false,
    'numeric-string|null' => false, 'numeric-string|int' => false, 'numeric-string|array' => false,
    'numeric-string|float(NAN)' => false, 'numeric-string|float(INF)' => false, 'numeric-string|numeric-string' => false];
foreach ($domainChecks as $domain => $expected) {
    if (\Ichinya\Laramago\Analyzer\WeakNumericFloatCompatibilityFilter::domain($domain) !== $expected) { throw new RuntimeException('Printed native domain control failed: '.$domain); }
}
$traps = [$workspace.'/bootstrap-executed', $workspace.'/database/migrations/executed', $workspace.'/packages/composer/composer-body-executed',
    $workspace.'/declaration-body-executed', $workspace.'/strict-declaration-body-executed', $workspace.'/cases/case-body-executed', $workspace.'/focus-body-executed'];
$mixinSourceChecks = [
    'no source tag' => ['', []],
    'unrelated description' => ['/** Ordinary mixin description. */', []],
    'plain source name' => ['/** @mixin Contract */', ['Contract']],
    'fully qualified source name' => ['/** @mixin \\Fixture\\Contract */', ['\\Fixture\\Contract']],
    'two separate source tags' => ["/**\n * @mixin First\n * @mixin Second\n */", ['First', 'Second']],
    'generic source tag' => ['/** @mixin Contract<int> */', null],
    'union source tag' => ['/** @mixin Contract|Other */', null],
    'nullable source tag' => ['/** @mixin ?Contract */', null],
    'dialect source tag' => ['/** @phpstan-mixin Contract */', null],
    'pseudo self' => ['/** @mixin self */', null],
    'pseudo static' => ['/** @mixin static */', null],
    'pseudo parent' => ['/** @mixin parent */', null],
    'relative namespace tag' => ['/** @mixin namespace\\Contract */', null],
    'two tags on one line' => ['/** @mixin First @mixin Second */', null],
    'trailing type-like text' => ['/** @mixin Contract &Other */', null],
];
foreach ($mixinSourceChecks as $label => [$doc, $expected]) {
    if (\Ichinya\Laramago\Analyzer\WeakNumericFloatCalls::mixinNames($doc) !== $expected) { throw new RuntimeException('Mixin source grammar control failed: '.$label); }
}
foreach ($traps as $marker) { if (file_exists($marker)) { throw new RuntimeException('Analyzed body executed during preparation.'); } }
if (file_exists($workspace.'/.env')) { throw new RuntimeException('Unexpected environment.'); }
file_put_contents($workspace.'/source-parser-receipts.json', json_encode(['sourceOnly' => true, 'analyzerJobsStarted' => 0, 'cases' => count($cases),
    'candidateCases' => count(array_filter($cases, static fn ($case): bool => $case['remove'] > 0)), 'sourceModeChecks' => count($modeChecks),
    'domainChecks' => count($domainChecks), 'files' => $receipts], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo 'Prepared '.count($cases).' invented cases, '.count($receipts).' parsed fixture files, '.count($modeChecks).' mode and '.count($domainChecks)." domain controls; zero analyzer jobs.\n";
echo 'PASS: '.count($mixinSourceChecks)." plain named-mixin source grammar controls.\n";
if (in_array('--prepare-only', $argv, true)) { echo 'Evidence: '.$workspace."\n"; exit(0); }

$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$run = static function (string $label, ?string $worker = null, int $workers = 1, bool $single = false) use ($package, $workspace, $command): array {
    $config = ['extends' => $package.'/presets/laravel.toml', 'php-version' => '8.5',
        'source' => ['paths' => $single ? ['focus.php'] : ['cases', 'focus.php'], 'includes' => ['contracts.php', 'strict-contracts.php']]];
    if ($worker !== null) { $config['extension-hosts'] = ['weak-float' => ['command' => [PHP_BINARY, $worker, $package.'/vendor/autoload.php', $workspace], 'workers' => $workers]]; }
    file_put_contents($workspace.'/mago.json', json_encode($config, JSON_THROW_ON_ERROR));
    $process = proc_open([...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [0 => ['pipe', 'r'], 1 => ['file', $workspace.'/'.$label.'.json', 'w'], 2 => ['file', $workspace.'/'.$label.'.log', 'w']], $pipes);
    if (! is_resource($process)) { throw new RuntimeException('Cannot start Mago: '.$label); }
    fclose($pipes[0]); $exit = proc_close($process); $stderr = file_get_contents($workspace.'/'.$label.'.log');
    if (! in_array($exit, [0, 1], true) || preg_match('/provider failed|rejected request|protocol error|panicked/i', $stderr) === 1) { throw new RuntimeException('Mago failed '.$label.'; inspect '.$workspace); }
    $report = json_decode(file_get_contents($workspace.'/'.$label.'.json'), true, flags: JSON_THROW_ON_ERROR);
    if (! isset($report['issues']) || ! is_array($report['issues'])) { throw new RuntimeException('Invalid Mago report '.$label); }
    return $report['issues'];
};
$signature = static function (array $issues): array { $rows = array_map(static fn ($issue): string => json_encode($issue, JSON_THROW_ON_ERROR), $issues); sort($rows); return $rows; };
$fileOf = static function (array $issue): string { foreach ($issue['annotations'] as $annotation) { if ($annotation['kind'] === 'Primary') { return str_replace('\\', '/', $annotation['span']['file_id']['name']); } } throw new RuntimeException('Missing native Primary.'); };
$native = $run('native'); $isolated = $run('isolated', $workspace.'/worker.php'); $expected = $native; $removed = 0;
foreach ($files as $file => $label) {
    $case = $cases[$label];
    $before = array_values(array_filter($native, static fn ($issue): bool => $fileOf($issue) === $file));
    $after = array_values(array_filter($isolated, static fn ($issue): bool => $fileOf($issue) === $file));
    if (! in_array('Error', array_column($before, 'level'), true)) { throw new RuntimeException('Vacuous native negative/positive baseline: '.$label); }
    $matches = array_keys(array_filter($expected, static fn ($issue): bool => $fileOf($issue) === $file && $issue['level'] === 'Error' && $issue['code'] === ($case['code'] ?? 'invalid-argument')));
    if ($case['remove'] > 0 && count($matches) !== $case['remove']) { throw new RuntimeException('Missing or ambiguous native candidate: '.$label); }
    foreach (array_slice($matches, 0, $case['remove']) as $index) { unset($expected[$index]); $removed++; }
    $perCase = array_values(array_filter($expected, static fn ($issue): bool => $fileOf($issue) === $file));
    if ($signature($after) !== $signature($perCase)) { throw new RuntimeException('Complete issue signature mismatch: '.$label); }
    if (isset($case['required']) && ! in_array($case['required'], array_column($after, 'code'), true)) { throw new RuntimeException('Missing retained property Error: '.$label); }
    if (isset($case['residual']) && ! in_array('Error', array_column($after, 'level'), true)) { throw new RuntimeException('Missing independent residual Error: '.$label); }
    echo 'PASS: '.$label."\n";
}
$focus = array_keys(array_filter($expected, static fn ($issue): bool => $fileOf($issue) === 'focus.php' && $issue['level'] === 'Error'
    && in_array($issue['code'], ['invalid-argument', 'invalid-return-statement'], true)));
if (count($focus) !== 2) { throw new RuntimeException('Expected two nonvacuous native focus candidates.'); }
foreach ($focus as $index) { unset($expected[$index]); $removed++; }
if ($signature($isolated) !== $signature(array_values($expected))) { throw new RuntimeException('Whole suite native/isolated signatures disagree.'); }
$guard = $run('guarded', $workspace.'/guard-worker.php', single: true);
$focusIsolated = array_values(array_filter($isolated, static fn ($issue): bool => $fileOf($issue) === 'focus.php'));
if ($signature($guard) !== $signature($focusIsolated)) { throw new RuntimeException('Genuine SDK controls changed complete focus signatures.'); }
$controls = [];
foreach (['invalid-argument', 'invalid-return-statement'] as $mode) {
    $values = json_decode(file_get_contents($workspace.'/native-'.$mode.'-controls.json'), true, flags: JSON_THROW_ON_ERROR);
    $expectedControls = $mode === 'invalid-argument' ? 84 : 67;
    if (count($values) !== $expectedControls || in_array(false, $values, true) || ! isset($values['genuine native positive before mutations'])) { throw new RuntimeException('Missing native positive/control evidence: '.$mode); }
    $controls[$mode] = count($values);
}
$singleNative = $run('single-native', single: true); $single = $run('single-isolated', $workspace.'/worker.php', single: true);
$singleExpected = array_values(array_filter($singleNative, static fn ($issue): bool => ! ($issue['level'] === 'Error' && in_array($issue['code'], ['invalid-argument', 'invalid-return-statement'], true))));
if ($signature($single) !== $signature($singleExpected) || ! in_array('Error', array_column($singleExpected, 'level'), true)) { throw new RuntimeException('Single-file residual Error or complete signatures differ.'); }
if (in_array('--integrated', $argv, true)) {
    $fullWorker = $package.'/bin/laramago-worker.php';
    foreach ([1, 3] as $workers) { $full = $run('integrated'.$workers, $fullWorker, $workers); if ($signature($full) !== $signature($isolated)) { throw new RuntimeException('Full integrated signatures differ: '.$workers); } }
}
foreach ($traps as $marker) { if (file_exists($marker)) { throw new RuntimeException('Analyzed source/autoload/migration body executed.'); } }
file_put_contents($workspace.'/native-gate-result.json', json_encode(['cases' => count($cases), 'removed' => $removed, 'genuineNativeControls' => $controls,
    'completeSignatures' => true, 'singleFile' => true, 'rootSpaces' => true, 'customVendorDir' => true, 'executionTrapsAbsent' => true], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo 'PASS: '.count($cases).' native cases, '.$removed." exact native float boundary corrections; every other issue preserved; source bodies never execute.\n";

echo 'Evidence: '.$workspace."\n";
