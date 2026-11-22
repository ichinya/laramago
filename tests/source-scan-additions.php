<?php

declare(strict_types=1);

use Ichinya\Laramago\Analyzer\StaticAnalysis\CodebaseScanSyntax as Syntax;
use Mago\Sdk\Analyzer\CodebaseScanContext;
use Mago\Sdk\CancellationTokenInterface;
use Mago\Sdk\PHPVersion;
use Mago\Sdk\Syntax\SourceFile;

require getenv('LARAMAGO_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
// Independent scan bodies from the pre-optimization commit in the fixture.
// Compare complete state, including aliases between proof nodes and their scopes.
$fixture = json_decode(file_get_contents(__DIR__.'/fixtures/source-scan-additions/oracles.json'), true, flags: JSON_THROW_ON_ERROR);
$version = PHPVersion::fromParts(8, 5);
$token = new class implements CancellationTokenInterface {
    public bool $cancelled = false;
    public function isCancelled(): bool { return $this->cancelled; }
    public function throwIfCancelled(): void { if ($this->cancelled) { throw new RuntimeException('Source cancellation'); } }
    public function subscribe(Closure $callback): int { return 0; }
    public function unsubscribe(int $subscription): void {}
};
$file = static fn(string $path, string $contents): SourceFile => new SourceFile($version, $path, $contents, [],
    (new ReflectionClass(\Mago\Sdk\Internal\Syntax\NodeStore::class))->newInstanceWithoutConstructor(),
    (new ReflectionClass(\Mago\Sdk\Internal\Syntax\ResolvedNameStore::class))->newInstanceWithoutConstructor(),
    (new ReflectionClass(\Mago\Sdk\Internal\Syntax\TriviaStore::class))->newInstanceWithoutConstructor(), null);
$samples = [
    '<?php function f(): void { $q=\App\User::query(); $q->chunk(10,function(\Illuminate\Database\Eloquent\Collection $rows) { foreach($rows as $row) { stringSink($row->name); } }); }',
    '<?php function f(\Closure $operation): mixed { return \Illuminate\Support\Facades\DB::transaction(function()use($operation){return $operation();}); }',
    '<?php function f(\Closure $operation): mixed { return \Illuminate\Support\Facades\DB::transaction(function()use(&$operation){return $operation();}); }',
    '<?php \Illuminate\Support\Facades\DB::swap($x); $x->macro("transaction",$y); \Illuminate\Database\Connection::extend("x",$y);',
    '<?php \Illuminate\Database\Eloquent\Factories\Factory::guessModelNamesUsing(static fn()=>\App\User::class);',
    '<?php class FactoryDefaults { function register() { \Illuminate\Database\Eloquent\Factories\Factory::guessModelNamesUsing(static fn()=>\App\User::class); } }',
    '<?php namespace First; class A { function f() { return $this->label; } }',
    '<?php function f() { $x?->name; $x->{$name}; A::$field; A::{$method}(); }',
    '<?php declare(ticks=1); function f() { return $x->name; }',
    '<?php malformed syntax',
    '<?php function f() { return \\Illuminate\\Support\\Facades\\DB::{"transaction"}(fn()=>1); }',
    '<?php function f() { $db->{"macro"}("transaction", fn()=>1); $db?->transaction(fn()=>1); }',
];
$checks = 0;
$check = static function(bool $ok, string $label) use (&$checks): void { if (! $ok) { throw new RuntimeException($label); } ++$checks; };
$observed = [];
foreach ($fixture['classes'] as $name => $data) {
    $class = 'Ichinya\\Laramago\\Analyzer\\StaticAnalysis\\'.$name;
    $oracle = eval('namespace Ichinya\\Laramago\\Analyzer\\StaticAnalysis; use PhpParser\\Node; use PhpParser\\NodeFinder; return function(\\Mago\\Sdk\\Analyzer\\CodebaseScanContext $context): void {'.$data['scan_body'].'};');
    foreach ($samples as $index => $source) {
        foreach (['app/input.php', 'vendor/laravel/framework/src/input.php'] as $path) {
            Syntax::clear(); $token->cancelled = false;
            $old = new $class('/invented/root'); $new = new $class('/invented/root');
            $legacy = Closure::bind($oracle, $old, $class);
            $invoke = static function(CodebaseScanContext $context) use ($legacy, $new): array {
                $errors = [];
                foreach ([$legacy, [$new, 'scan']] as $call) {
                    try { $call($context); $errors[] = null; }
                    catch (Throwable $error) { $errors[] = [$error::class, $error->getMessage()]; }
                }
                return $errors;
            };
            foreach ([[[$file($path, $source)], true, false, false], [[$file($path, $source)], false, true, false],
                [[$file('app/other.php', $source)], true, true, false],
                [[$file('app/cancelled.php', $source)], false, true, true],
                [[$file($path, $source."\n// New generation")], true, true, false],
                [[$file('app/too-large.php', '<?php //'.str_repeat('x', 2_000_001))], true, true, false],
                [[], false, true, true], [[], true, true, false]] as [$files, $first, $last, $cancelled]) {
                $token->cancelled = $cancelled;
                $errors = $invoke(new CodebaseScanContext($version, $token, $files, $first, $last));
                $check($errors[0] === $errors[1], 'Exception changed: '.$name.'/'.$index);
                $check(serialize($old) === serialize($new), 'Complete scanner state changed: '.$name.'/'.$index.'/'.$path);
                foreach (['members', 'fileMembers', 'calls', 'mappingConfigured'] as $property) {
                    if ((new ReflectionClass($new))->hasProperty($property)) {
                        $value = (new ReflectionProperty($new, $property))->getValue($new);
                        $observed[$name] = ($observed[$name] ?? 0) + (is_array($value) ? count(array_filter($value, static fn($v): bool => $v !== null)) : (int) $value);
                    }
                }
            }
        }
    }
    $check(($observed[$name] ?? 0) > 0, 'No positive witness: '.$name);
}
Syntax::clear();
echo 'PASS source scan additions: '.$checks.' complete-state and lifecycle controls '.json_encode($observed, JSON_THROW_ON_ERROR)."\n";
