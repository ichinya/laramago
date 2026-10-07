<?php

declare(strict_types=1);

// Analyze invented source without loading its declarations, consumer bootstrap or consumer autoload.
require __DIR__.'/../vendor/autoload.php';

$package = str_replace('\\', '/', dirname(__DIR__));
$fixtures = $package.'/tests/fixtures/false-operands';
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago false operands '.bin2hex(random_bytes(8));
foreach (['packages/composer', 'bootstrap', 'database/migrations', 'workers', 'observations'] as $directory) { mkdir($workspace.'/'.$directory, recursive: true); }
$trap = '<?php file_put_contents(__DIR__."/executed", "executed"); throw new RuntimeException("Analyzed source body must never execute.");';
file_put_contents($workspace.'/bootstrap/app.php', $trap);
file_put_contents($workspace.'/database/migrations/001_trap.php', $trap);
file_put_contents($workspace.'/packages/composer/autoload_files.php', $trap);
file_put_contents($workspace.'/packages/composer/installed.json', '{"packages":[]}');
file_put_contents($workspace.'/composer.json', '{"config":{"vendor-dir":"packages"},"autoload":{"files":["bootstrap/app.php"]}}');
$focus = file_get_contents($fixtures.'/focus.php');
file_put_contents($workspace.'/focus.php', $focus);
$copy = str_replace(['namespace PrimitiveOperandFocus;', 'namespace ShadowedOperandFocus;'], ['namespace PrimitiveCopyFocus;', 'namespace ShadowedCopyFocus;'], $focus);
$copy = str_replace('declare(strict_types=1);', "declare(strict_types=1);\n// Independent UTF-8 prefix: \u{00E9}", $copy);
file_put_contents($workspace.'/copy.php', str_replace("\n", "\r\n", $copy));
file_put_contents($workspace.'/negative.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace FalseOperandNegatives;
function mutatedPeer(int|false $value, int $peer): bool { $peer = 'text'; return $value < $peer; }
function reusedPeer(int|false $value, int $peer): bool { affect($peer); return $value < $peer; }
function affect(mixed &$value): void {}
function nullablePeer(int|false $value, ?int $peer): bool { return $value < $peer; }
function objectPeer(int|false $value, object $peer): bool { return $value < $peer; }
function booleanObject(object $value): bool { return $value && true; }
function unknownConcatPeer(string|false $value, mixed $peer): string { return $peer.$value; }
function alwaysWrongReturn(int|false $value): array { return $value > 0; }
function unknownArithmetic(mixed $value): int { return $value + 1; }
file_put_contents(__DIR__.'/case-body-executed', 'Analyzed source body must never execute.');
PHP);
$observerPath = $fixtures.'/FalseOperandControlObserver.php';
$observerClass = '\\Ichinya\\Laramago\\Tests\\Support\\FalseOperandControlObserver';
$productionWorker = file_get_contents($package.'/bin/laramago-worker.php');
$productionReceipts = null;
if (in_array('--integrated', $argv, true)) {
    $registration = '        new FalseOperandCompatibilityPlugin,';
    if ($productionWorker === false || substr_count($productionWorker, $registration) !== 1) { throw new RuntimeException('Exactly one false operand production worker registration required.'); }
    $control = str_replace($registration, '', $productionWorker, $removedRegistrations);
    if ($removedRegistrations !== 1) { throw new RuntimeException('Cannot isolate false operand production registration.'); }
    $productionReceipts = ['workerSha256' => hash('sha256', $productionWorker), 'controlSha256' => hash('sha256', $control), 'removedRegistrations' => 1];
}
$traps = [$workspace.'/bootstrap/executed', $workspace.'/database/migrations/executed', $workspace.'/packages/composer/executed', $workspace.'/case-body-executed'];
$files = ['focus.php', 'copy.php', 'negative.php'];
$parser = (new \PhpParser\ParserFactory)->createForNewestSupportedVersion();
$receipts = [];
foreach ([...$files, 'bootstrap/app.php', 'database/migrations/001_trap.php', 'packages/composer/autoload_files.php'] as $file) {
    $bytes = file_get_contents($workspace.'/'.$file); $parser->parse($bytes);
    $receipts[$file] = ['sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes)];
}
// These checks inspect only source grammar and printed primitive domains; they never construct a native DTO positive.
require $fixtures.'/source-grammar.php';
file_put_contents($workspace.'/preparation.json', json_encode(['sourceOnly' => true, 'analyzerJobsStarted' => 0, 'files' => $receipts,
    'productionReceipts' => $productionReceipts], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
foreach ($traps as $trapPath) { if (file_exists($trapPath)) { throw new RuntimeException('Source body executed during preparation.'); } }
if (in_array('--prepare-only', $argv, true)) { echo 'Prepared self-contained false operand fixtures; no analyzer jobs. Evidence: '.$workspace.PHP_EOL; exit(0); }

$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$run = static function (string $label, string $mode, int $workers = 1, bool $single = false) use (
    $package, $fixtures, $workspace, $observerPath, $observerClass, $productionWorker, $command, $files,
): array {
    $output = $workspace.'/observations/'.$label; mkdir($output);
    $config = ['extends' => $package.'/presets/laravel.toml', 'php-version' => '8.5',
        'source' => ['paths' => $single ? ['focus.php'] : $files], 'extension-hosts' => new stdClass];
    if ($mode !== 'native') {
        $observer = 'new '.$observerClass.'('.var_export($output, true).', false, '.var_export($single ? ['focus.php'] : $files, true).')';
        if (str_starts_with($mode, 'full-')) {
            $worker = $mode === 'full-before' ? str_replace('        new FalseOperandCompatibilityPlugin,', '', $productionWorker) : $productionWorker;
            if (substr_count($worker, 'require $autoload;') !== 1 || substr_count($worker, '    analyzerPlugins: [') !== 1) { throw new RuntimeException('Expected unique production autoload and registry anchors.'); }
            $worker = str_replace('require $autoload;', 'require $autoload;' . "\nrequire ".var_export($observerPath, true).';', $worker);
            $worker = str_replace('    analyzerPlugins: [', '    analyzerPlugins: [' . "\n        ".$observer.',', $worker);
        } else {
            $plugins = $observer.($mode === 'remove' ? ', new \\Ichinya\\Laramago\\Analyzer\\FalseOperandCompatibilityPlugin' : '');
            if (in_array($mode, ['scalar', 'existing-policies'], true)) {
                $plugins .= ', new class implements \\Mago\\Sdk\\Analyzer\\Plugin {
                    public function getDefinition(): \\Mago\\Sdk\\Analyzer\\PluginDefinition {
                        return new \\Mago\\Sdk\\Analyzer\\PluginDefinition("fixture/scalar-operands", "Existing scalar policy", "Independently measure production policy overlap");
                    }
                    public function register(\\Mago\\Sdk\\Analyzer\\PluginRegistry $registry): void {
                        $filter = new \\Ichinya\\Laramago\\Analyzer\\ScalarOperandCompatibilityFilter;
                        $registry->registerInitializationHook($filter);
                        $registry->registerIssueFilterHook($filter);
                    }
                }';
                if ($mode === 'existing-policies') {
                    $plugins .= ', new \\Ichinya\\Laramago\\Analyzer\\ObjectBooleanCompatibilityPlugin';
                }
            }
            $worker = '<?php require $argv[1]; require '.var_export($observerPath, true).';'
                .' (new \\Mago\\Sdk\\Worker(new \\Mago\\Sdk\\Extension("fixture/false-operands", "False operands", "1", analyzerPlugins: ['.$plugins.'])))->run();';
        }
        $workerPath = $workspace.'/workers/'.$label.'.php'; file_put_contents($workerPath, $worker);
        (new \PhpParser\ParserFactory)->createForNewestSupportedVersion()->parse($worker);
        $config['extension-hosts'] = ['false-operands' => ['command' => [PHP_BINARY, '-d', 'opcache.enable_cli=0', $workerPath, $package.'/vendor/autoload.php', $workspace], 'workers' => $workers]];
    }
    file_put_contents($workspace.'/mago.json', json_encode($config, JSON_THROW_ON_ERROR));
    $process = proc_open([...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'],
        [0 => ['pipe', 'r'], 1 => ['file', $workspace.'/'.$label.'.json', 'w'], 2 => ['file', $workspace.'/'.$label.'.log', 'w']], $pipes);
    if (! is_resource($process)) { throw new RuntimeException('Cannot start static analyzer: '.$label); }
    fclose($pipes[0]); $exit = proc_close($process); $stderr = file_get_contents($workspace.'/'.$label.'.log');
    if (! in_array($exit, [0, 1], true) || preg_match('/provider failed|native analysis fallback|invalid extension frame|rejected request|hook .* failed|pars(?:e|ing)\s+errors?|incomplete codebase|timed out|did not answer|fatal error|orchestrator error|uncaught|PHP Warning/i', $stderr) === 1) { throw new RuntimeException('Invalid analyzer run '.$label.'; inspect '.$workspace); }
    $report = json_decode(file_get_contents($workspace.'/'.$label.'.json'), true, flags: JSON_THROW_ON_ERROR);
    if (! isset($report['issues']) || ! is_array($report['issues'])) { throw new RuntimeException('Missing native report: '.$label); }
    return $report['issues'];
};
$signature = static function (array $rows): array { $values = array_map(static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR), $rows); sort($values); return $values; };
$errors = static fn (array $rows): array => array_values(array_filter($rows, static fn (array $row): bool => $row['level'] === 'Error'));
$records = static function (string $label, string $kind) use ($workspace): array {
    $values = []; foreach (glob($workspace.'/observations/'.$label.'/'.$kind.'-*.jsonl') as $file) {
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) { $values[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR); }
    } return $values;
};
$preexistingRemovals = [];
$expected = static function (array $before, string $label) use ($records, &$preexistingRemovals, $workspace): array {
    $result = $before;
    foreach ($records($label, 'issues') as $record) {
        if ($record['proposal'] === null) { continue; }
        $native = $record['issue']; $annotation = $native['annotations'][0]; $matches = [];
        foreach ($result as $key => $issue) {
            if ($issue['level'] !== 'Warning' || $issue['code'] !== 'possibly-false-operand' || $issue['message'] !== $native['message']
                || $issue['notes'] !== $native['notes'] || ($issue['help'] ?? null) !== $native['help']) { continue; }
            foreach ($issue['annotations'] as $candidate) {
                if ($candidate['kind'] === 'Primary' && basename(str_replace('\\', '/', $candidate['span']['file_id']['name'])) === basename(str_replace('\\', '/', $record['file']))
                    && $candidate['span']['start']['offset'] === $annotation['span']['start'] && $candidate['span']['end']['offset'] === $annotation['span']['end']) { $matches[] = $key; }
            }
        }
        if ($matches === [] && $label === 'full-before') {
            $known = array_values(array_filter($preexistingRemovals, static function (array $issue) use ($native, $record, $annotation): bool {
                if ($issue['level'] !== 'Warning' || $issue['code'] !== $native['code'] || $issue['message'] !== $native['message']
                    || $issue['notes'] !== $native['notes'] || ($issue['help'] ?? null) !== $native['help']) { return false; }
                foreach ($issue['annotations'] as $candidate) {
                    if ($candidate['kind'] === 'Primary' && $candidate['span']['file_id']['name'] === $record['file']
                        && $candidate['span']['start']['offset'] === $annotation['span']['start'] && $candidate['span']['end']['offset'] === $annotation['span']['end']) { return true; }
                }
                return false;
            }));
            if (count($known) === 1) { continue; }
        }
        if (count($matches) !== 1) { throw new RuntimeException('Native proposal does not identify one complete report Warning: '.$label.'; inspect '.$workspace); }
        unset($result[$matches[0]]);
    }
    return array_values($result);
};
$check = static function (array $before, array $after, string $baselineLabel, string $afterLabel, int $positives) use ($signature, $errors, $records, $expected): array {
    if ($signature($after) !== $signature($expected($before, $baselineLabel))) { throw new RuntimeException('Complete report delta differs from genuine native proposals: '.$afterLabel); }
    if ($errors($before) === [] || $signature($errors($before)) !== $signature($errors($after))) { throw new RuntimeException('Complete native Errors were lost: '.$afterLabel); }
    $proposals = array_values(array_filter($records($baselineLabel, 'issues'), static fn (array $record): bool => $record['proposal'] !== null));
    $controls = $records($baselineLabel, 'controls'); $controlCount = 0;
    if (count($proposals) !== $positives || count($controls) !== $positives) { throw new RuntimeException('Missing exact genuine native positives/controls: '.$baselineLabel); }
    foreach ($controls as $record) { if (! $record['genuineNativePositive'] || count($record['checks']) !== 32) { throw new RuntimeException('Missing complete native negative controls.'); } $controlCount += count($record['checks']); }
    $beforeTypes = $records($baselineLabel, 'types'); $afterTypes = $records($afterLabel, 'types');
    if ($beforeTypes === [] || $signature($beforeTypes) !== $signature($afterTypes)) { throw new RuntimeException('Native complete operation/operand/peer Type DTO hashes changed: '.$afterLabel); }
    return ['positives' => count($proposals), 'removedWarnings' => count($before) - count($after), 'negativeControls' => $controlCount, 'completeErrors' => count($errors($after)), 'completeTypeRecords' => count($afterTypes)];
};
$native = $run('native', 'native'); $observe = $run('observe', 'observe');
if ($signature($native) !== $signature($observe)) { throw new RuntimeException('Always-Keep observer changed native complete report envelopes.'); }
$one = $run('one', 'remove'); $checks = ['one' => $check($observe, $one, 'observe', 'one', 21)];
$three = $run('three', 'remove', 3); $checks['three'] = $check($observe, $three, 'observe', 'three', 21);
$singleNative = $run('single-native', 'native', single: true); $singleObserve = $run('single-observe', 'observe', single: true);
if ($signature($singleNative) !== $signature($singleObserve)) { throw new RuntimeException('Single-file Always-Keep observer changed complete report envelopes.'); }
$single = $run('single', 'remove', single: true); $checks['single'] = $check($singleObserve, $single, 'single-observe', 'single', 10);
if (in_array('--integrated', $argv, true)) {
    $fullBefore = $run('full-before', 'full-before');
    $scalarOnly = $run('existing-scalar', 'scalar');
    $existingPolicies = $run('existing-policies', 'existing-policies');
    // Measure both existing policies independently; retain the exact production delta.
    $functions = (new \PhpParser\NodeFinder)->findInstanceOf($parser->parse(file_get_contents($workspace.'/negative.php')) ?? [], \PhpParser\Node\Stmt\Function_::class);
    $owners = array_values(array_filter($functions, static fn (\PhpParser\Node\Stmt\Function_ $node): bool => $node->name->name === 'booleanObject'));
    if (count($owners) !== 1) { throw new RuntimeException('One current booleanObject source owner is required.'); }
    $operations = (new \PhpParser\NodeFinder)->findInstanceOf($owners[0], \PhpParser\Node\Expr\BinaryOp\BooleanAnd::class);
    if (count($operations) !== 1 || ! $operations[0]->left instanceof \PhpParser\Node\Expr\Variable || $operations[0]->left->name !== 'value') {
        throw new RuntimeException('The current object boolean source control changed.');
    }
    $operand = $operations[0]->left;
    $objectWarnings = array_values(array_filter($scalarOnly, static function (array $issue) use ($operand): bool {
        if ($issue['level'] !== 'Warning' || $issue['code'] !== 'invalid-operand'
            || $issue['message'] !== 'Left operand in `&&` operation is an `object`.') { return false; }
        foreach ($issue['annotations'] as $annotation) {
            if ($annotation['kind'] === 'Primary' && $annotation['span']['file_id']['name'] === 'negative.php'
                && $annotation['span']['start']['offset'] === $operand->getStartFilePos()
                && $annotation['span']['end']['offset'] === $operand->getEndFilePos() + 1) { return true; }
        }
        return false;
    }));
    if (count($objectWarnings) !== 1) { throw new RuntimeException('One genuine scalar-baseline object boolean Warning is required.'); }
    $withoutObject = $scalarOnly;
    $index = array_search($objectWarnings[0], $withoutObject, true);
    if ($index === false) { throw new RuntimeException('The complete genuine object boolean envelope is missing.'); }
    unset($withoutObject[$index]);
    if ($signature($existingPolicies) !== $signature(array_values($withoutObject))
        || $signature($errors($scalarOnly)) !== $signature($errors($existingPolicies))
        || $signature($records('existing-scalar', 'types')) !== $signature($records('existing-policies', 'types'))) {
        throw new RuntimeException('The independently measured existing policy union changed more than its genuine object boolean Warning.');
    }
    if ($signature($fullBefore) !== $signature($existingPolicies)) { throw new RuntimeException('Production baseline differs from independently measured scalar and object boolean policies: '.$workspace); }
    file_put_contents($workspace.'/existing-policy-union.json', json_encode(['existingScalarMeasuredSeparately' => true,
        'objectBooleanGenuineWholeWarning' => $objectWarnings[0], 'extraRemovedWarnings' => 1,
        'completeErrorsAndNativeTypesPreserved' => true, 'productionBaselineMatchesIndependentUnion' => true], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    unset($functions, $owners, $operations, $operand);
    foreach ($observe as $issue) {
        if ($issue['level'] === 'Warning' && ! in_array(json_encode($issue, JSON_THROW_ON_ERROR), $signature($existingPolicies), true)) {
            $preexistingRemovals[] = $issue;
        }
    }
    foreach ([1, 3] as $workers) { $label = 'full'.$workers; $full = $run($label, 'full-after', $workers); $checks[$label] = $check($fullBefore, $full, 'full-before', $label, 21); }
}
foreach ($receipts as $file => $receipt) { if (hash_file('sha256', $workspace.'/'.$file) !== $receipt['sha256']) { throw new RuntimeException('Analyzed fixture source changed: '.$file); } }
foreach ($traps as $trapPath) { if (file_exists($trapPath)) { throw new RuntimeException('Analyzed source, autoload, bootstrap or migration body executed.'); } }
if (file_exists($workspace.'/.env')) { throw new RuntimeException('Unexpected fixture environment.'); }
file_put_contents($workspace.'/native-gate-result.json', json_encode(['completeReportEnvelopes' => true, 'completeErrorsPreserved' => true,
    'completeNativeTypeDtosPreserved' => true, 'genuineNativeControls' => $checks, 'sourceHashesUnchanged' => true, 'executionTrapsAbsent' => true], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo 'PASS: native and Always-Keep equality; isolated one/three/single and optional complete production registry controls; all Errors and native types preserved.'.PHP_EOL;
echo 'Evidence: '.$workspace.PHP_EOL;
