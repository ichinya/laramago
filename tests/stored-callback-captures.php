<?php

declare(strict_types=1);

// Native/source stored-reference regression harness. Fixture/application bodies are never loaded.
$arguments = array_slice($argv, 1);
if (count($arguments) > 1 || isset($arguments[0]) && !in_array($arguments[0], ['--fixtures-only', '--observe'], true)) {
    throw new RuntimeException('Supported modes are --fixtures-only, --observe, or the default bounded isolated gate.');
}
$mode = $arguments[0] ?? 'default';
$package = str_replace('\\','/',dirname(__DIR__));
$fixture=$package.'/tests/fixtures/stored-reference-possible-writes';
require $package.'/vendor/autoload.php';
$root = str_replace('\\','/',sys_get_temp_dir()).'/laramago stored reference possible writes '.bin2hex(random_bytes(8));
mkdir($root.'/packages', 0777, true);
mkdir($root.'/bootstrap', 0777, true);
mkdir($root.'/database/migrations', 0777, true);
file_put_contents($root.'/composer.json', json_encode(['name' => 'fixture/stored-captures', 'config' => ['vendor-dir' => 'packages']], JSON_THROW_ON_ERROR));
file_put_contents($root.'/bootstrap/app.php', '<?php file_put_contents(__DIR__."/../executed", "bootstrap"); throw new RuntimeException("Unexpected bootstrap.");');
file_put_contents($root.'/database/migrations/0001_trap.php', '<?php file_put_contents(__DIR__."/../../executed", "migration"); throw new RuntimeException("Unexpected migration.");');
$box = <<<'PHP'
class CallbackBox extends CallbackParent
{
    /** @var (Closure(string, Closure():mixed):mixed)|null */
    public ?Closure $reader = null;
    /** @var (Closure(string, mixed, int, Closure(mixed):bool):bool)|null */
    public ?Closure $writer = null;
    public function read(string $key): mixed
    {
        $reader = $this->reader;
        return $reader instanceof Closure ? $reader($key, static fn (): mixed => null) : null;
    }
    public function write(string $key, mixed $value, int $seconds): bool
    {
        $writer = $this->writer;
        return $writer instanceof Closure ? $writer($key, $value, $seconds, static fn (mixed $replacement): bool => true) : false;
    }
}
PHP;
$reader = <<<'PHP'
static function (string $key, Closure $next) use ($format, &$reads, &$cell) {
    if (++$reads < 3) { return $next(); }
    if (!is_int($cell)) { throw new \RuntimeException('Expected an integer write.'); }
    $adjusted = $cell + 1;
    return $format === 'number' ? $adjusted : (string) $adjusted;
}
PHP;
$writer = <<<'PHP'
static function (string $key, mixed $value, int $seconds, Closure $next) use (&$cell): bool {
    $cell = $value;
    return $next($value) === true;
}
PHP;
$cases = [
    'deferred shared reference cell' => ['candidate' => true],
    'unrelated argument error' => ['candidate' => true, 'prefix' => 'requireInteger("text");'],
    'integer-specific compatible writer' => ['candidate' => true, 'box' => 'IntegerBox', 'helper' => 'configureInteger', 'writer' => str_replace('mixed $value', 'int $value', $writer)],
    'aligned helper type and parameter separation' => ['candidate'=>true,'helper'=>'configureAligned'],
    'reader key has incompatible physical type' => ['candidate'=>false,'reader'=>str_replace('string $key','int $key',$reader)],
    'reader continuation has incompatible physical type' => ['candidate'=>false,'reader'=>str_replace('Closure $next','int $next',$reader)],
    'writer key has incompatible physical type' => ['candidate'=>false,'writer'=>str_replace('string $key','int $key',$writer)],
    'writer seconds has incompatible physical type' => ['candidate'=>false,'writer'=>str_replace('int $seconds','string $seconds',$writer)],
    'writer continuation has incompatible physical type' => ['candidate'=>false,'writer'=>str_replace('Closure $next','int $next',$writer)],
    'writer result has incompatible physical type' => ['candidate'=>false,'writer'=>str_replace('): bool {','): int {',$writer)],
    'reader captures by value' => ['candidate' => false, 'reader' => str_replace('$format, &$reads, &$cell', '$format, &$reads, $cell', $reader)],
    'writer captures by value' => ['candidate' => false, 'writer' => str_replace('use (&$cell)', 'use ($cell)', $writer)],
    'writer stores null' => ['candidate' => false, 'writer' => str_replace('$cell = $value;', '$cell = null;', $writer)],
    'writer stores text' => ['candidate' => false, 'writer' => str_replace('$cell = $value;', '$cell = "text";', $writer)],
    'reader resets cell' => ['candidate' => false, 'reader' => str_replace('if (!is_int($cell))', '$cell = null; if (!is_int($cell))', $reader)],
    'reference cell alias escapes' => ['candidate' => false, 'configurePrefix' => '$alias =& $cell; exportReference($alias);'],
    'genuine never-producing right side' => ['candidate' => false, 'reader' => str_replace('$cell + 1', 'neverValue()', $reader)],
    'different scalar guard' => ['candidate' => false, 'reader' => str_replace('is_int($cell)', 'is_string($cell)', $reader)],
    'dynamic predicate' => ['candidate' => false, 'reader' => str_replace('if (!is_int($cell))', '$predicate = "is_int"; if (!$predicate($cell))', $reader)],
    'caught failure' => ['candidate' => false, 'reader' => str_replace('if (!is_int($cell)) { throw new \RuntimeException(\'Expected an integer write.\'); }', 'try { if (!is_int($cell)) { throw new \RuntimeException("Expected an integer write."); } } catch (\RuntimeException) {}', $reader)],
    'mutation after integer guard' => ['candidate' => false, 'reader' => str_replace('$adjusted =', '$cell = null; $adjusted =', $reader)],
    'writer contract excludes integers' => ['candidate' => false, 'box' => 'TextBox', 'helper' => 'configureText', 'writer' => str_replace('mixed $value', 'string $value', $writer)],
    'incompatible writer callback contract' => ['candidate' => false, 'writer' => str_replace('mixed $value', 'string $value', $writer)],
    'helper does not invoke configuration' => ['candidate' => false, 'helper' => 'unconfigured'],
    'constructed store is rebound before configuration' => ['candidate'=>false,'helper'=>'rebound'],
    'constructed store escapes before configuration' => ['candidate'=>false,'helper'=>'escaped'],
    'constructed store field changes before configuration' => ['candidate'=>false,'helper'=>'fieldChanged'],
    'constructed store receives an alias before configuration' => ['candidate'=>false,'helper'=>'aliased'],
];
$header = <<<'PHP'
<?php
use Closure;
class CallbackParent { public function __construct() {} }
function requireInteger(int $value): void {}
function exportReference(mixed &$value): void {}
function passStore(CallbackBox $store): void {}
function neverValue(): never { throw new RuntimeException('No value.'); }
function incrementValue(int $value): int|float { return $value + 1; }
function overflowContractWitness(): void { requireInteger(incrementValue(PHP_INT_MAX)); }
PHP;
$methods = '';
$ranges = [];
foreach ($cases as $label => $case) {
    $name = 'captureCase'.count($ranges);
    $store = $case['box'] ?? 'CallbackBox';
    $helper = $case['helper'] ?? 'configure';
    $code = 'public function '.$name.'(string $format): '.$store." {\n".($case['prefix'] ?? '')."\n".'$reads = 0; $cell = null; return $this->'.$helper.'('
        .'function ('.$store.' $store) use ($format, &$reads, &$cell): void { '.($case['configurePrefix'] ?? '')."\n"
        .'$store->reader = '.($case['reader'] ?? $reader).";\n".'$store->writer = '.($case['writer'] ?? $writer).";\n}); }\n";
    $ranges[$name] = ['label' => $label, 'candidate' => $case['candidate'], 'body' => $code];
    $methods .= $code;
}
$helpers = <<<'PHP'
    /** @param callable(CallbackBox):void $configure */
    private function configure(callable $configure): CallbackBox
    {
        $store = new CallbackBox;
        $configure($store);
        $dependency=new \stdClass;
        $carrier=$dependency;
        return $store;
    }
    /** @param callable(IntegerBox):void $configure */
    private function configureInteger(callable $configure): IntegerBox
    {
        $store = new IntegerBox;
        $configure($store);
        $dependency=new \stdClass;
        $carrier=$dependency;
        return $store;
    }
    /** @param callable(TextBox):void $configure */
    private function configureText(callable $configure): TextBox
    {
        $store = new TextBox;
        $configure($store);
        $dependency=new \stdClass;
        $carrier=$dependency;
        return $store;
    }
    /** @param callable(CallbackBox):void $configure */
    private function rebound(callable $configure): CallbackBox
    {
        $store = new CallbackBox;
        $store = new stdClass;
        $configure($store);
        return new CallbackBox;
    }
    /** @param callable(CallbackBox):void $configure */
    private function escaped(callable $configure): CallbackBox
    {
        $store = new CallbackBox;
        passStore($store);
        $configure($store);
        return $store;
    }
    /** @param callable(CallbackBox):void $configure */
    private function fieldChanged(callable $configure): CallbackBox
    {
        $store = new CallbackBox;
        $store->reader = null;
        $configure($store);
        return $store;
    }
    /** @param callable(CallbackBox):void $configure */
    private function aliased(callable $configure): CallbackBox
    {
        $store = new CallbackBox;
        $alias = $store;
        $configure($store);
        return $store;
    }
    /**
     * @param  callable(CallbackBox): void  $configure
     */
    private function configureAligned(callable $configure): CallbackBox
    {
        $store = new CallbackBox;
        $configure($store);
        return $store;
    }
    /** @param callable(CallbackBox):void $configure */
    private function unconfigured(callable $configure): CallbackBox
    {
        return new CallbackBox;
    }
PHP;
$integerBox = str_replace(['CallbackBox', 'Closure(string, mixed, int, Closure(mixed):bool)', 'mixed $value', 'fn (mixed $replacement)'], ['IntegerBox', 'Closure(string, int, int, Closure(int):bool)', 'int $value', 'fn (int $replacement)'], $box);
$textBox = str_replace(['CallbackBox', 'Closure(string, mixed, int, Closure(mixed):bool)', 'mixed $value', 'fn (mixed $replacement)'], ['TextBox', 'Closure(string, string, int, Closure(string):bool)', 'string $value', 'fn (string $replacement)'], $box);
$source = $header."\n".$box."\n".$integerBox."\n".$textBox."\nfinal class CaptureCases {\n".$methods.$helpers."\n}\n";
file_put_contents($root.'/cases.php', $source);
$shadow = '<?php namespace ShadowPredicates; use Closure; function is_int(mixed $value): bool { return false; } class CallbackParent { public function __construct() {} }'
    ."\n".$box."\n".$integerBox."\n".$textBox."\nfinal class ShadowCases {\n".str_replace('captureCase0', 'shadowCase', $ranges['captureCase0']['body']).$helpers."\n}\n";
file_put_contents($root.'/shadow.php', $shadow);
$witness = <<<'PHP'
<?php
// Independent invented witnesses; not executed by this observation harness.
require __DIR__.'/cases.php';
$fixture = new CaptureCases;
$ordinary = $fixture->captureCase0('number');
if (!$ordinary->write('entry', 41, 90)) { throw new RuntimeException('The integer write failed.'); }
$ordinary->read('entry');
$ordinary->read('entry');
if ($ordinary->read('entry') !== 42) { throw new RuntimeException('Reference capture did not complete.'); }
$overflow = $fixture->captureCase0('number');
if (!$overflow->write('entry', PHP_INT_MAX, 90)) { throw new RuntimeException('The overflow write failed.'); }
$overflow->read('entry');
$overflow->read('entry');
if (!is_float($overflow->read('entry'))) { throw new RuntimeException('Integer overflow was not a float.'); }
PHP;
file_put_contents($root.'/witness.php', $witness);
file_put_contents($root.'/case-matrix.json', json_encode($ranges, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
$context = $header."\n".$box."\n".$integerBox."\n".$textBox."\nfinal class CaptureCases {\n".$ranges['captureCase0']['body'].$helpers."\n}\n";
file_put_contents($root.'/context.php', $context);
copy($package.'/src/Analyzer/StaticAnalysis/StoredCallbackSourceDeclarations.php', $root.'/sources-draft.php');
copy($package.'/src/Analyzer/StaticAnalysis/StoredReferencePossibleWrites.php', $root.'/proofs-draft.php');
copy($package.'/src/Analyzer/StoredReferencePossibleWriteFilter.php', $root.'/filter-draft.php');
copy($fixture.'/worker.php', $root.'/worker.php');
$registry=file_get_contents($package.'/bin/laramago-worker.php');
$registry=preg_replace('/^\s*new (?:\\\\?Ichinya\\\\Laramago\\\\Analyzer\\\\)?StoredReferencePossibleWritePlugin\([^\r\n]*\),\s*$/m','',$registry,1,$removed);
if($removed!==1){throw new RuntimeException('The production stored-reference registration must be unique.');}
$registry=str_replace('require $autoload;','require $autoload; require $argv[2].\'/worker.php\';',$registry,$requires);
$registry=str_replace('    analyzerPlugins: [','    analyzerPlugins: ['."\n        \$plugin,",$registry,$registrations);
if([$requires,$registrations]!==[1,1]){throw new RuntimeException('Unknown current production worker anchors.');}
file_put_contents($root.'/full-worker.php',$registry);
file_put_contents($root.'/unrelated-docs.php', '<?php /** A lone namespace separator \\ in plain prose. */ final class UnrelatedStoredDocumentation {}');
$preflight = [];
foreach (['cases.php', 'shadow.php', 'context.php', 'witness.php', 'sources-draft.php', 'proofs-draft.php', 'filter-draft.php', 'worker.php', 'bootstrap/app.php', 'database/migrations/0001_trap.php'] as $file) {
    $path = $root.'/'.$file; $contents = file_get_contents($path);
    (new PhpParser\ParserFactory)->createForNewestSupportedVersion()->parse($contents);
    $log = $root.'/lint-'.str_replace('/', '-', $file);
    $child = proc_open([PHP_BINARY, '-l', $path], [0 => ['pipe', 'r'], 1 => ['file', $log.'.stdout', 'w'], 2 => ['file', $log.'.stderr', 'w']], $pipes);
    if (!is_resource($child)) { throw new RuntimeException('Cannot start source syntax preflight.'); }
    fclose($pipes[0]); $exit = proc_close($child);
    $preflight[$file] = ['hash' => hash('sha256', $contents), 'parser' => true, 'lintExit' => $exit, 'childClosed' => true];
    if ($exit !== 0) { file_put_contents($root.'/preflight.json', json_encode($preflight, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)); throw new RuntimeException('PHP syntax failed: '.$file.' '.$root); }
}
file_put_contents($root.'/preflight.json', json_encode($preflight, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
$proofs = new class($root) {};
require_once $root.'/sources-draft.php';
require_once $root.'/proofs-draft.php';
$parserProof = new ReflectionMethod(Ichinya\Laramago\Analyzer\StaticAnalysis\StoredReferencePossibleWrites::class, 'candidates');
$parserNodes = (new PhpParser\NodeTraverser(new PhpParser\NodeVisitor\NameResolver))->traverse((new PhpParser\ParserFactory)->createForNewestSupportedVersion()->parse($source));
$syntax = [];
foreach ((new PhpParser\NodeFinder)->findInstanceOf($parserNodes, PhpParser\Node\Stmt\ClassMethod::class) as $method) {
    if (!isset($ranges[$method->name->name])) { continue; }
    $range = $ranges[$method->name->name]; $proofRows = $parserProof->invoke(null, $method);
    $syntax[$method->name->name] = ['candidate' => $range['candidate'], 'sourceCandidates' => count($proofRows), 'start' => $method->getStartFilePos(), 'end' => $method->getEndFilePos() + 1];
}
file_put_contents($root.'/syntax-candidates.json', json_encode($syntax, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
if (($syntax['captureCase0']['sourceCandidates'] ?? 0) !== 1 || ($syntax['captureCase1']['sourceCandidates'] ?? 0) !== 1 || ($syntax['captureCase2']['sourceCandidates'] ?? 0) !== 1) {
    throw new RuntimeException('A faithful positive was absent from source-only candidate preparation. '.$root);
}
if (file_exists($root.'/executed') || file_exists($root.'/.env')) { throw new RuntimeException('Unexpected fixture/environment execution.'); }
if ($mode === '--fixtures-only') { echo 'Stored-reference syntax/candidate preparation passed: '.count($syntax).' groups; no analyzer or bodies; workspace='.$root."\n"; exit(0); }
$run = static function (string $kind, array $paths, int $workers = 1) use ($root, $package): array {
    $scope = in_array('context.php', $paths, true) ? 'context' : 'cases'; $name = $kind.'-'.$workers.'-'.$scope;
    $settings = ['source' => ['paths' => $paths, 'excludes' => ['packages/', 'bootstrap/', 'database/', 'witness.php']], 'analyzer' => ['ignore' => []]];
    if(str_starts_with($kind,'full-')) { $settings['extends']=$package.'/presets/laravel.toml'; }
    if ($kind !== 'native') { $settings['extension-hosts'] = ['stored' => ['command' => [PHP_BINARY, '-d', 'opcache.enable_cli=0', str_starts_with($kind,'full-')?$root.'/full-worker.php':$root.'/worker.php', $package.'/vendor/autoload.php', $root, $kind], 'workers' => $workers, 'request-timeout-ms' => 120000]]; }
    file_put_contents($root.'/'.$name.'-config.json', json_encode($settings, JSON_THROW_ON_ERROR));
    $binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago'; $command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
    $child = proc_open([...$command, '--workspace', $root, '--config', $root.'/'.$name.'-config.json', 'analyze', '--reporting-format=json'],
        [0 => ['pipe', 'r'], 1 => ['file', $root.'/'.$name.'.json', 'w'], 2 => ['file', $root.'/'.$name.'.stderr', 'w']], $pipes);
    if (!is_resource($child)) { throw new RuntimeException('Cannot start genuine stored-reference native gate.'); }
    fclose($pipes[0]); $exit = proc_close($child); $stderr = file_get_contents($root.'/'.$name.'.stderr');
    file_put_contents($root.'/'.$name.'-result.json', json_encode(['exit' => $exit, 'childClosed' => true, 'paths' => $paths, 'workers' => $workers], JSON_THROW_ON_ERROR));
    if (!in_array($exit, [0,1], true) || preg_match('/provider failed|native analysis fallback|rejected request|fatal error|pars(?:e|ing) errors?|hook .* failed|orchestrator error|did not answer|worker .* exited/i', $stderr)) { throw new RuntimeException('Unusable native gate: '.$name.' '.$stderr.' '.$root); }
    return json_decode(file_get_contents($root.'/'.$name.'.json'), true, flags: JSON_THROW_ON_ERROR)['issues'];
};
$signature = static function (array $issues): array { $rows = array_map(static fn (array $issue): string => json_encode($issue, JSON_THROW_ON_ERROR), $issues); sort($rows, SORT_STRING); return $rows; };
$native = $run('native', ['cases.php','shadow.php','unrelated-docs.php']); $observe = $run('observe', ['cases.php','shadow.php','unrelated-docs.php']);
if ($signature($native) !== $signature($observe)) { throw new RuntimeException('Always-Keep stored-reference observation changed the whole native report. '.$root); }
if ($mode === '--observe') { echo 'Stored-reference observation closed with '.count($native).' unchanged native reports; workspace='.$root."\n"; exit(0); }
$isolated = $run('isolated', ['cases.php','shadow.php','unrelated-docs.php']); $expected = $native; $removed = []; $matrix = [];
foreach ($syntax as $name => $range) {
    $group = static function (array $issues) use ($range): array { return array_filter($issues, static function (array $issue) use ($range): bool {
        $primary = array_values(array_filter($issue['annotations'], static fn (array $annotation): bool => $annotation['kind'] === 'Primary'));
        return count($primary) === 1 && $primary[0]['span']['file_id']['name'] === 'cases.php' && $primary[0]['span']['start']['offset'] >= $range['start'] && $primary[0]['span']['end']['offset'] <= $range['end'];
    }); };
    $before = $group($native); $after = $group($isolated);
    $targets = array_filter($before, static fn (array $issue): bool => $issue['level'] === 'Error' && in_array($issue['code'], ['impossible-assignment','impossible-type-comparison'], true) || $issue['level'] === 'Warning' && $issue['code']==='mixed-assignment');
    $errors = array_filter($before, static fn (array $issue): bool => $issue['level'] === 'Error');
    if ($errors === []) { throw new RuntimeException('Vacuous native negative/positive group: '.$name.' '.$root); }
    if ($range['candidate']) {
        if (count(array_filter($targets,static fn(array $issue):bool=>$issue['level']==='Error'))!==2) { throw new RuntimeException('A positive did not reproduce both exact native Errors: '.$name.' '.$root); }
        foreach ($targets as $index => $issue) { unset($expected[$index]); $removed[] = $issue; }
    } elseif ($signature(array_values($before)) !== $signature(array_values($after))) { throw new RuntimeException('A near-miss lost a complete native diagnostic: '.$name.' '.$root); }
    $matrix[$name] = ['positive' => $range['candidate'], 'native' => array_values($before), 'isolated' => array_values($after), 'exactTargets' => array_values($targets)];
}
file_put_contents($root.'/target-signatures.json', json_encode($matrix, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
if ($signature(array_values($expected)) !== $signature($isolated)) { throw new RuntimeException('Stored-reference delta changed an unrelated complete issue. '.$root); }
if ($signature($isolated) !== $signature($run('isolated', ['cases.php','shadow.php','unrelated-docs.php'], 3))) { throw new RuntimeException('Stored-reference one/three native multisets differ. '.$root); }
$fullBefore=$run('full-before',['cases.php','shadow.php','unrelated-docs.php']);
$fullAfter=$run('full-after',['cases.php','shadow.php','unrelated-docs.php']);
$fullThree=$run('full-after',['cases.php','shadow.php','unrelated-docs.php'],3);
$remaining=array_count_values($signature($removed));$expectedFull=[];
foreach($fullBefore as $issue){$row=json_encode($issue,JSON_THROW_ON_ERROR);if(($remaining[$row]??0)>0){$remaining[$row]--;}else{$expectedFull[]=$issue;}}
if(array_sum($remaining)!==0 || $signature($expectedFull)!==$signature($fullAfter) || $signature($fullAfter)!==$signature($fullThree)){throw new RuntimeException('Intact production registry removed unrelated records or changed worker results. '.$root);}
$contextNative = $run('native', ['context.php']); $controlled = $run('contexts', ['context.php']);
if ($signature($contextNative) !== $signature($controlled)) { throw new RuntimeException('Always-Keep genuine controls changed the native context. '.$root); }
$receipt = json_decode(file_get_contents($root.'/context-controls.json'), true, flags: JSON_THROW_ON_ERROR);
if (!$receipt['positive'] || count($receipt['nativeControls']) !== 26 || count($receipt['envelopeControls']) !== 6 || count($receipt['sourceLifecycleControls']) !== 4 || !$receipt['restoredPositive']) { throw new RuntimeException('Missing nonempty genuine controls. '.$root); }
if (file_exists($root.'/executed') || file_exists($root.'/.env')) { throw new RuntimeException('Unexpected source/fixture/environment execution.'); }
file_put_contents($root.'/accepted-result.json', json_encode(['cases' => count($matrix), 'exactErrorsRemoved' => count(array_filter($removed,static fn(array $issue):bool=>$issue['level']==='Error')), 'exactWarningsRemoved' => count(array_filter($removed,static fn(array $issue):bool=>$issue['level']==='Warning')), 'allUnrelatedNativeFieldsPreserved' => true,
    'nativeOverflowErrorPreserved' => true, 'oneThreeEqual' => true, 'controls' => $receipt, 'allRunChildrenClosed' => true], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
echo 'Stored-reference isolated gate passed '.count($matrix).' groups/'.count($removed).' exact diagnostics; workspace='.$root."\n";
