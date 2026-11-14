<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\DefensiveBoundaryGuardProof;
use Ichinya\Laramago\Analyzer\StaticAnalysis\CodebaseScanSyntax;
use Ichinya\Laramago\Analyzer\StaticAnalysis\FactoryResultContract;
use Ichinya\Laramago\Analyzer\StaticAnalysis\RefreshedModelProperties;
use Mago\Sdk\Analyzer\CodebaseScanContext;
use Mago\Sdk\CancellationTokenInterface;
use Mago\Sdk\PHPVersion;
use Mago\Sdk\Syntax\SourceFile;

require getenv('LARAMAGO_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';

$checks = 0;
$expect = static function (mixed $actual, mixed $expected, string $label) use (&$checks): void {
    if ($actual !== $expected) { throw new RuntimeException($label); }
    $checks++;
};
$state = static fn (object $scanner, string $property): mixed => (new ReflectionProperty($scanner, $property))->getValue($scanner);
$version = PHPVersion::fromParts(8, 5);
$token = new class implements CancellationTokenInterface {
    public bool $cancelled = false;
    public function isCancelled(): bool { return $this->cancelled; }
    public function throwIfCancelled(): void { if ($this->cancelled) { throw new RuntimeException('Scan cancelled.'); } }
    public function subscribe(Closure $callback): int { return 0; }
    public function unsubscribe(int $subscription): void {}
};
$file = static fn (string $path, string $contents): SourceFile => new SourceFile($version, $path, $contents, [],
    (new ReflectionClass(\Mago\Sdk\Internal\Syntax\NodeStore::class))->newInstanceWithoutConstructor(),
    (new ReflectionClass(\Mago\Sdk\Internal\Syntax\ResolvedNameStore::class))->newInstanceWithoutConstructor(),
    (new ReflectionClass(\Mago\Sdk\Internal\Syntax\TriviaStore::class))->newInstanceWithoutConstructor(), null);
$scan = static function (object $scanner, array $files, bool $first = true, bool $last = true) use ($version, $token): void {
    $scanner->scan(new CodebaseScanContext($version, $token, $files, $first, $last));
};
$root = str_replace('\\', '/', dirname(__DIR__)).'/var/codebase-scan-consumers-'.bin2hex(random_bytes(8));
mkdir($root.'/a', recursive: true);
mkdir($root.'/b', recursive: true);

// Equal source bytes do not imply equal path-specific policy or scan state.
$mapping = '<?php class Mapping { function configure(): void { \Illuminate\Database\Eloquent\Factories\Factory::guessModelNamesUsing(static fn()=>\App\Models\Other::class); } }';
$macro = '<?php \Illuminate\Http\Request::macro("x", static fn()=>true);';
foreach ([false, true] as $reverse) {
    CodebaseScanSyntax::clear();
    $paths = [[$root.'/vendor/laravel/framework/src/Mapping.php', false], [$root.'/app/Mapping.php', true]];
    if ($reverse) { $paths = array_reverse($paths); }
    foreach ($paths as [$path, $configured]) {
        $factory = new FactoryResultContract($root);
        $scan($factory, [$file($path, $mapping)]);
        $expect($state($factory, 'mappingConfigured'), $configured, 'Factory path policy depends on this host, in both warm orders.');
    }
    $factory = new FactoryResultContract($root);
    $topLevel = $mapping.' \Illuminate\Database\Eloquent\Factories\Factory::flushState();';
    $scan($factory, [$file($root.'/vendor/laravel/framework/src/Mapping.php', $topLevel)]);
    $expect($state($factory, 'mappingConfigured'), true, 'Top-level native mapping changes remain hazards.');

    $paths = [[$root.'/vendor/laravel/framework/src/Illuminate/Foundation/Providers/FoundationServiceProvider.php', false], [$root.'/app/FoundationServiceProvider.php', true]];
    if ($reverse) { $paths = array_reverse($paths); }
    foreach ($paths as [$path, $hazard]) {
        $boundary = new DefensiveBoundaryGuardProof($root);
        $scan($boundary, [$file($path, $macro)]);
        $expect($state($boundary, 'requestRegistrationHazard'), $hazard, 'Foundation path exemption remains local, in both warm orders.');
    }
}

$source = <<<'PHP'
<?php
namespace Fixture;
use Testo\Assert;
final class RefreshProof {
    private function source(Row $value): Row { return $value; }
    public function check(Row $input): void {
        $row = $this->source($input);
        Assert::same($row->flag, true);
        $row->update(['flag' => '0']);
        $row->refresh();
        Assert::same($row->flag, false);
    }
}
PHP;
file_put_contents($root.'/a/proof.php', $source);
file_put_contents($root.'/b/other.php', $source);
foreach ([false, true] as $reverse) {
    CodebaseScanSyntax::clear();
    $hosts = [[$root.'/a', 'proof.php'], [$root.'/b', 'other.php']];
    if ($reverse) { $hosts = array_reverse($hosts); }
    $proofs = [];
    foreach ($hosts as [$directory, $path]) {
        $refresh = new RefreshedModelProperties($directory);
        $scan($refresh, [$file($path, $source)]);
        $selected = $refresh->proofs($path, $source);
        $expect(count($selected), 1, 'Source extraction retains the refresh candidate.');
        $proof = $selected[0];
        $expect([$proof['file'], $proof['diskFile'], $proof['hash']], [$path, $directory.'/'.$path, hash('sha256', $source)], 'Each consumer binds its own path, root and source hash.');
        $expect($proof['scope']->name->name, 'check', 'Warm extraction is isolated from prior consumer mutation.');
        $expect(RefreshedModelProperties::current($proof), true, 'Bound source is current on disk.');
        $expect($refresh->proofs('foreign.php', $source), [], 'Foreign file cannot select cached proof.');
        $expect($refresh->proofs($path, $source.' '), [], 'Changed in-memory bytes cannot select cached proof.');
        $proofs[$directory] = $proof;
        $proof['scope']->name->name = 'changed';
    }
    file_put_contents($root.'/a/proof.php', $source.' ');
    $expect(RefreshedModelProperties::current($proofs[$root.'/a']), false, 'Changed disk source invalidates only its bound proof.');
    $expect(RefreshedModelProperties::current($proofs[$root.'/b']), true, 'Another root retains its independent current source.');
    file_put_contents($root.'/a/proof.php', $source);
}

// Warm syntax/results cannot bypass incomplete, duplicate, cancelled or invalid input.
foreach ([new FactoryResultContract($root), new DefensiveBoundaryGuardProof($root)] as $scanner) {
    $complete = $scanner instanceof FactoryResultContract ? 'scanComplete' : 'complete';
    $failed = $scanner instanceof FactoryResultContract ? 'scanFailed' : 'failed';
    $host = $file($root.'/host.php', '<?php');
    $scan($scanner, [$host], last: false);
    $expect($state($scanner, $complete), false, 'Incomplete scan remains incomplete with warm source.');
    $scan($scanner, [], first: false);
    $expect($state($scanner, $complete), true, 'Final batch completes the current generation.');
    $scan($scanner, [$host, $file($root.'/broken.php', '<?php function broken( {')]);
    $expect($state($scanner, $failed), true, 'Malformed source still fails the generation.');
    $scan($scanner, [$file($root.'/large.php', str_repeat(' ', 2_000_001))]);
    $expect($state($scanner, $failed), true, 'Per-file source size bound is retained.');
    $scan($scanner, [$host]);
    $expect($state($scanner, $complete), true, 'Fresh generation recovers after invalid source.');
    $scan($scanner, [$host, $host]);
    if ($scanner instanceof FactoryResultContract) {
        $expect(array_values($state($scanner, 'analyzedSources')), [null], 'Duplicate factory source remains poisoned.');
    } else {
        $expect($state($scanner, $failed), true, 'Duplicate boundary host cannot certify a scan.');
    }
    $scan($scanner, [$host], last: false);
    $token->cancelled = true;
    try {
        $scan($scanner, [$host], first: false);
        if ($scanner instanceof FactoryResultContract) { throw new RuntimeException('Cancelled scan completed.'); }
        $expect($state($scanner, $failed), true, 'Boundary cancellation cannot certify a cached source.');
    } catch (RuntimeException $error) {
        $expect($error->getMessage(), 'Scan cancelled.', 'Factory cancellation propagates before query reuse.');
    } finally { $token->cancelled = false; }
    $expect($state($scanner, $complete), false, 'Cancelled cached source never completes the generation.');
    $scan($scanner, [$host]);
    $expect($state($scanner, $complete), true, 'Fresh generation recovers after cancelled cached source.');
}
$refresh = new RefreshedModelProperties($root.'/a');
$host = $file('proof.php', $source);
$scan($refresh, [$host], last: false);
$expect($refresh->proofs('proof.php', $source), [], 'Warm refresh extraction cannot finish an incomplete generation.');
$token->cancelled = true;
try {
    $scan($refresh, [$host], first: false);
    throw new RuntimeException('Cancelled scan completed.');
} catch (RuntimeException $error) {
    $expect($error->getMessage(), 'Scan cancelled.', 'Cancellation propagates before cached extraction.');
} finally { $token->cancelled = false; }
$expect($refresh->proofs('proof.php', $source), [], 'Cancelled generation exposes no refresh proof.');
$scan($refresh, [$host]);
$expect(count($refresh->proofs('proof.php', $source)), 1, 'New generation recovers after cancellation.');
$scan($refresh, [$host, $host]);
$expect($refresh->proofs('proof.php', $source), [], 'Duplicate refresh path cannot reuse cached proof.');
$ticked = str_replace('<?php', '<?php declare(ticks=1);', $source);
$scan($refresh, [$file('proof.php', $ticked)]);
$expect($refresh->proofs('proof.php', $ticked), [], 'Ticked source does not reuse an unticked extraction.');
CodebaseScanSyntax::clear();
echo 'Source query consumers passed: '.$checks." controls.\n";
