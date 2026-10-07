<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

// Native excerpts: testo/assert 0.1.14, commit 5f0ae9b80057e9c81655f3355c6f4820b5a4d830.
// Copyright Aleksei Gagarin (roxblnfk), BSD-3-Clause.
// Redistribution and use in source and binary forms, with or without modification,
// are permitted provided that the following conditions are met:
// 1. Redistributions of source code must retain the above copyright notice,
//    this list of conditions and the following disclaimer.
// 2. Redistributions in binary form must reproduce the above copyright notice,
//    this list of conditions and the following disclaimer in the documentation
//    and/or other materials provided with the distribution.
// 3. Neither the name of the copyright holder nor the names of its contributors
//    may be used to endorse or promote products derived from this software without
//    specific prior written permission.
// THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
// ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
// WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.
// IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT,
// INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
// BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
// DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF
// LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE
// OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED
// OF THE POSSIBILITY OF SUCH DAMAGE.

// These declarations are analyzed; their assertion bodies are never executed.
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago asserted getter '.bin2hex(random_bytes(8));
mkdir($workspace, recursive: true);
file_put_contents($workspace.'/composer.json', '{"config":{"vendor-dir":"packages"},"autoload":{"files":["bootstrap.php"]}}');
file_put_contents($workspace.'/bootstrap.php', '<?php throw new RuntimeException("Application bootstrap executed.");');
file_put_contents($workspace.'/opaque.php', '<?php throw new RuntimeException("Opaque dependency body executed.");');
mkdir($workspace.'/database/migrations', recursive: true);
file_put_contents($workspace.'/database/migrations/001_trap.php', '<?php throw new RuntimeException("Migration body executed.");');

$native = [
    'Assert.php' => <<<'NATIVE_GETTER_0'
<?php

namespace Testo;

use Testo\Assert\Api\Builtin\ArrayType;
use Testo\Assert\Api\Builtin\FloatType;
use Testo\Assert\Api\Builtin\IntType;
use Testo\Assert\Api\Builtin\IterableType;
use Testo\Assert\Api\Builtin\NumericType;
use Testo\Assert\Api\Builtin\ObjectType;
use Testo\Assert\Api\Builtin\StringType;
use Testo\Assert\Api\Json\JsonAbstract;
use Testo\Assert\Internal\Assertion\AssertArray;
use Testo\Assert\Internal\Assertion\AssertFloat;
use Testo\Assert\Internal\Assertion\AssertInt;
use Testo\Assert\Internal\Assertion\AssertIterable;
use Testo\Assert\Internal\Assertion\AssertJson;
use Testo\Assert\Internal\Assertion\AssertNumeric;
use Testo\Assert\Internal\Assertion\AssertObject;
use Testo\Assert\Internal\Assertion\AssertString;
use Testo\Assert\Internal\StaticState;
use Testo\Assert\Internal\Support;
use Testo\Assert\State\Assertion\AssertionException;
use Testo\Assert\State\Assertion\ComparisonFailure;
use Testo\Assert\State\Test\Fail;
use Testo\Common\Attribute\AssertMethod;
/**
 * Assertion utilities.
 *
 * @api
 */
final class Assert
{
    /**
     * Asserts that the given value is not null.
     *
     * @param mixed $actual The actual value to check for non-null.
     * @param string $message Short description about what exactly is being asserted.
     * @throws AssertionException when the assertion fails.
     *
     * @psalm-assert !null $actual
     * @phpstan-assert !null $actual
     */
    #[\Testo\Common\Attribute\AssertMethod]
    public static function notNull(mixed $actual, string $message = ''): void
    {
        $actual !== null ? \Testo\Assert\Internal\StaticState::success($actual, 'is not `null`', $message) : \Testo\Assert\Internal\StaticState::fail(new \Testo\Assert\State\Assertion\ComparisonFailure(expected: 'non-null', actual: $actual, value: \Testo\Assert\Internal\Support::stringify($actual), assertion: 'is not `null`', context: $message, reason: 'expected a non-null value, got `null`'));
    }
}
NATIVE_GETTER_0,
    'src/Internal/StaticState.php' => <<<'NATIVE_GETTER_1'
<?php

namespace Testo\Assert\Internal;

use Testo\Assert\Exception\StateNotFound;
use Testo\Assert\Internal\Expectation\ExpectedFail;
use Testo\Assert\Internal\Expectation\ExpectExceptionHandler;
use Testo\Assert\Internal\Expectation\Leaks;
use Testo\Assert\Internal\Expectation\NotLeaks;
use Testo\Assert\State\Assertion\AssertionComposite;
use Testo\Assert\State\Assertion\AssertionException;
use Testo\Assert\State\Assertion\AssertionSuccess;
use Testo\Assert\State\Record;
use Testo\Assert\State\Test\Fail;
use Testo\Assert\TestState;
/**
 * Holds the current assertion collector.
 *
 * @internal
 * @psalm-internal Testo\Assert
 */
final class StaticState
{
    public static ?\Testo\Assert\TestState $state = null;
    /**
     * @param mixed $value The actual value that was asserted.
     * @param non-empty-string $assertion The assertion result (e.g., "is empty", "contains 'foo'").
     * @param string $context Optional user-provided context describing what is being asserted.
     */
    public static function success(mixed $value, string $assertion, string $context = ''): void
    {
        self::$state === null or self::$state->history[] = new \Testo\Assert\State\Assertion\AssertionSuccess(value: \Testo\Assert\Internal\Support::stringify($value), assertion: $assertion, context: $context);
    }
    /**
     * Log a failed assertion and throw the given exception.
     *
     * @template T
     * @param T $failure The exception to throw.
     * @throws T
     */
    public static function fail(\Testo\Assert\State\Record&\Throwable $failure): never
    {
        self::$state === null or self::$state->history[] = $failure;
        throw $failure;
    }
}
NATIVE_GETTER_1,
    'src/Internal/Support.php' => <<<'NATIVE_GETTER_2'
<?php

namespace Testo\Assert\Internal;

/**
 * @internal
 * @psalm-internal Testo\Assert
 */
final class Support
{
    /**
     * Convert a value to a string for error messages.
     *
     * Compact, single-line representation suitable for inline messages
     * like `Expected `array(3)`, got `array(5)``. Intentionally lossy:
     * arrays are summarised by count, strings >64 chars by length.
     *
     * @return non-empty-string
     */
    public static function stringify(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            $value === true => 'true',
            $value === false => 'false',
            \is_string($value) => \strlen($value) > 64 ? 'string(' . \strlen($value) . ')' : '"' . \str_replace('"', '\"', $value) . '"',
            \is_array($value) => 'array(' . \count($value) . ')',
            \is_resource($value) => 'resource',
            $value instanceof \UnitEnum => $value::class . '::' . $value->name,
            \is_object($value) => $value::class,
            default => (string) $value,
        };
    }
}
NATIVE_GETTER_2,
    'src/TestState.php' => <<<'NATIVE_GETTER_3'
<?php

namespace Testo\Assert;

use Testo\Assert\State\Record;
use Testo\Core\Context\TestResult;
/**
 * Collects assertions.
 *
 * The state is stored as an attribute in the {@see TestResult}, and can be accessed by Event Listeners
 * and Interceptors.
 *
 * ```
 *  $testState = $result->getAttribute(TestState::class);
 * ```
 *
 * @api
 */
final class TestState
{
    /**
     * @var list<Record> The history of assertions.
     */
    public array $history = [];
    /**
     * @note that the expectation list will be processed in LIFO order.
     *
     * @var list<callable(TestResult, TestState): TestResult> List of expectation handlers.
     */
    public array $expectations = [];
}
NATIVE_GETTER_3,
    'src/State/Assertion/AssertionSuccess.php' => <<<'NATIVE_GETTER_4'
<?php

namespace Testo\Assert\State\Assertion;

use Testo\Assert\State\Assertion;
/**
 * Successful assertion record.
 */
class AssertionSuccess
{
    /**
     * @param non-empty-string $value The actual value that was asserted.
     * @param non-empty-string $assertion The assertion result.
     * @param string $context Optional user-provided context describing what is being asserted.
     */
    public function __construct(protected readonly string $value, protected readonly string $assertion, protected readonly string $context)
    {
    }
}
NATIVE_GETTER_4,
    'src/State/Record.php' => <<<'NATIVE_GETTER_5'
<?php namespace Testo\Assert\State; interface Record {}
NATIVE_GETTER_5,
    'src/State/Assertion/ComparisonFailure.php' => <<<'NATIVE_GETTER_6'
<?php namespace Testo\Assert\State\Assertion; final class ComparisonFailure extends \RuntimeException implements \Testo\Assert\State\Record { public function __construct(mixed $expected, mixed $actual, string $value, string $assertion, string $context, string $reason, string $details = "") {} }
NATIVE_GETTER_6,
];
foreach ($native as $path => $contents) {
    $file = $workspace.'/packages/testo/assert/'.$path;
    if (! is_dir(dirname($file))) { mkdir(dirname($file), recursive: true); }
    file_put_contents($file, $contents);
}

$types = <<<'PHP'
#[\Attribute]
final class Marker {}
final class PathBox {
    private ?string $value = null;
    public function __construct(?string $value) { $this->value = $value; }
    public function path(): ?string { return $this->value; }
    public function other(): ?string { return null; }
    public function clear(): void { $this->value = null; }
}
final class PromotedBox {
    public function __construct(private readonly ?string $value) {}
    public function path(): ?string { return $this->value; }
}
class OpenBox {
    protected ?string $value = null;
    public function __construct(?string $value) { $this->value = $value; }
    public function path(): ?string { return $this->value; }
}
final class OverrideBox extends OpenBox { public function path(): ?string { return null; } }
final class StatefulBox {
    private ?string $value = null;
    public function path(): ?string { $before = $this->value; $this->value = null; return $before; }
}
final class DocumentedBox {
    private ?string $value = null;
    /** @return non-empty-string|null */
    public function path(): ?string { return $this->value; }
}
final class DocumentedFieldBox {
    /** @var non-empty-string|null */
    private ?string $value = null;
    public function path(): ?string { return $this->value; }
}
final class MagicBox {
    public function __get(string $name): ?string { return null; }
    public function path(): ?string { return $this->value; }
}
final class HookedBox {
    public ?string $value { get => null; }
    public function path(): ?string { return $this->value; }
}
final class NullBox {
    private null $value = null;
    public function path(): null { return $this->value; }
}
final class ReferenceBox {
    private ?string $value = null;
    public function &path(): ?string { return $this->value; }
}
final class OptionalBox {
    private ?string $value = null;
    public function path(?string $unused = null): ?string { return $this->value; }
}
final class StaticBox {
    private static ?string $value = null;
    public static function path(): ?string { return self::$value; }
}
final class HiddenBox {
    private ?string $value = null;
    private function path(): ?string { return $this->value; }
    public function __call(string $method, array $arguments): ?string { return null; }
}
final class ScalarAlternativesBox {
    private string|int|null $value = null;
    public function path(): string|int|null { return $this->value; }
}
function pair(string $first, string $second): int { return strlen($first.$second); }
function unknown(PathBox $box): string { $box->clear(); return ''; }
function retain(PathBox $box): void { static $saved; $saved = $box; }
function &byReference(PathBox &$box): PathBox { return $box; }
PHP;
$origin = '$box = new PathBox($input); ';
$assert = '\\Testo\\Assert::notNull($box->path()); ';
$read = 'return strlen($box->path());';
$cases = [
    'immediate strlen' => [$origin.$assert.$read, true],
    'immediate dirname assignment' => [$origin.$assert.'$directory = dirname($box->path()); return strlen($directory);', true],
    'direct return' => [$origin.$assert.'return $box->path();', true, 'string'],
    'native assertion import' => [$origin.'A::notNull($box->path()); '.$read, true],
    'named actual' => [$origin.'A::notNull(actual: $box->path()); '.$read, true],
    'literal message' => [$origin.'A::notNull($box->path(), "A scalar value is required."); '.$read, true],
    'named literal message' => [$origin.'A::notNull(message: "A scalar value is required.", actual: $box->path()); '.$read, true],
    'within one try body' => [$origin.'try { '.$assert.$read.' } catch (\\Throwable) { return 0; }', true],
    'within one true branch' => [$origin.'if ($input !== "skip") { '.$assert.$read.' } return 0;', true],
    'earlier receiver escape' => [$origin.'$alias = $box; retain($alias); '.$assert.$read, true],
    'readonly promoted field' => ['$box = new PromotedBox($input); '.$assert.$read, true],
    'decorated method caller' => [$origin.$assert.$read, true, 'int', true],
    'unguarded getter' => [$origin.$read, false],
    'branch without dominance' => [$origin.'if ($input !== "skip") { '.$assert.' } '.$read, false],
    'caught failing assertion' => [$origin.'try { '.$assert.' } catch (\\Throwable) {} '.$read, false],
    'intervening direct setter' => [$origin.$assert.'$box->clear(); '.$read, false],
    'intervening alias setter' => [$origin.'$alias = $box; '.$assert.'$alias->clear(); '.$read, false],
    'intervening captured receiver' => [$origin.'$callback = static fn (): string => unknown($box); '.$assert.'$callback(); '.$read, false],
    'intervening reference call' => [$origin.$assert.'byReference($box); '.$read, false],
    'intervening unknown function' => [$origin.$assert.'unknown($box); '.$read, false],
    'intervening receiver rebinding' => [$origin.$assert.'$box = new PathBox(null); '.$read, false],
    'intervening scalar assignment' => [$origin.$assert.'$length = 2; '.$read, false],
    'intervening unset' => [$origin.$assert.'unset($box); $box = new PathBox(null); '.$read, false],
    'intervening include' => [$origin.$assert.'include "opaque.php"; '.$read, false],
    'intervening extract' => [$origin.$assert.'extract(["box" => new PathBox(null)]); '.$read, false],
    'different repeated getter' => [$origin.$assert.'return strlen($box->other());', false],
    'unsafe earlier consumer argument' => [$origin.$assert.'return pair(unknown($box), $box->path());', false],
    'unsafe assertion message argument' => [$origin.'A::notNull($box->path(), unknown($box)); '.$read, false],
    'named consumer argument' => [$origin.$assert.'return strlen(string: $box->path());', false],
    'unknown consumer callee' => [$origin.$assert.'return (fn (): Closure => strlen(...))()($box->path());', false],
    'deferred closure use' => [$origin.$assert.'return (fn (): int => strlen($box->path()))();', false],
    'nonfinal dispatch' => ['$box = new OpenBox($input); '.$assert.$read, false],
    'stateful getter' => ['$box = new StatefulBox(); '.$assert.$read, false],
    'stronger getter PHPDoc' => ['$box = new DocumentedBox(); '.$assert.$read, false],
    'stronger field PHPDoc' => ['$box = new DocumentedFieldBox(); '.$assert.$read, false],
    'magic storage' => ['$box = new MagicBox(); '.$assert.$read, false],
    'hooked physical storage' => ['$box = new HookedBox(); '.$assert.$read, false],
    'known null-only storage' => ['$box = new NullBox(); '.$assert.$read, false],
    'reference-return getter' => ['$box = new ReferenceBox(); '.$assert.$read, false],
    'getter with optional parameter' => ['$box = new OptionalBox(); '.$assert.$read, false],
    'static getter' => ['$box = new StaticBox(); '.$assert.$read, false],
    'inaccessible getter with magic dispatch' => ['$box = new HiddenBox(); '.$assert.$read, false],
    'scalar alternatives' => ['$box = new ScalarAlternativesBox(); '.$assert.$read, false],
    'residual incompatible argument' => [$origin.$assert.'return abs($box->path());', 'residual'],
    'arrow callback carrier parameter' => [$origin.'$callback = static fn (PathBox $selected): string => unknown($selected); '.$assert.'$callback($box); '.$read, false],
    'closure callback carrier parameter' => [$origin.'$callback = static function (PathBox $selected): string { return unknown($selected); }; '.$assert.'$callback($box); '.$read, false],
    'arrow callback copied alias' => [$origin.'$callback = static fn (): string => unknown($box); $alias = $callback; '.$assert.'$alias(); '.$read, false],
    'closure callback copied alias' => [$origin.'$callback = static function () use ($box): string { return unknown($box); }; $alias = $callback; '.$assert.'$alias(); '.$read, false],
    'callback alias retains earlier callee' => [$origin.'$callback = static fn (): string => unknown($box); $alias = $callback; $callback = static fn (): string => "safe"; '.$assert.'$alias(); '.$read, false],
    'closure implicit method receiver' => ['$this->current = $input; $callback = function (): void { $this->clear(); }; A::notNull($this->path()); $callback(); return strlen($this->path());', false, 'int', 'implicit-this'],
];
$source = "<?php\ndeclare(strict_types=1);\nnamespace Fixtures;\nuse Testo\\Assert as A;\n".$types."\n";
$ranges = [];
foreach ($cases as $label => $case) {
    $start = strlen($source);
    $method = 'function case'.count($ranges).'(?string $input): '.($case[2] ?? 'int').' { '.$case[0].' }';
    $source .= (($case[3] ?? false)==='implicit-this'
        ? 'final class ImplicitCallbackBox { private ?string $current = null; public function path(): ?string { return $this->current; } public function clear(): void { $this->current = null; } #[Marker] public '.$method.' }'
        : (($case[3] ?? false) ? 'final class Probe'.count($ranges).' { #[Marker] public '.$method.' }' : $method))."\n";
    $ranges[$label] = [$start, strlen($source)];
}
file_put_contents($workspace.'/cases.php', $source);
file_put_contents($workspace.'/worker.php', <<<'PHP'
<?php
require $argv[1];
$plugin = new \Ichinya\Laramago\Analyzer\AssertedPureGetterPlugin($argv[2]);
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension('test/asserted-getter', 'Asserted getter', '1', analyzerPlugins: [$plugin])))->run();
PHP);
file_put_contents($workspace.'/existing-null-flow-worker.php', <<<'PHP'
<?php
require $argv[1];
$getter=new \Ichinya\Laramago\Analyzer\AssertedPureGetterPlugin($argv[2]);
$flow=new class($argv[2]) implements \Mago\Sdk\Analyzer\Plugin, \Mago\Sdk\Analyzer\InitializationHook, \Mago\Sdk\Analyzer\IssueFilterHook {
    private \Ichinya\Laramago\Analyzer\GuardedNullFlowIssueFilter $probe;
    public function __construct(private readonly string $root) { $this->probe=new \Ichinya\Laramago\Analyzer\GuardedNullFlowIssueFilter($root); }
    public function getDefinition(): \Mago\Sdk\Analyzer\PluginDefinition { return new \Mago\Sdk\Analyzer\PluginDefinition('test/getter-existing-null-flow','Existing null-flow policy','Independently evaluate the actual current policy.'); }
    public function register(\Mago\Sdk\Analyzer\PluginRegistry $registry):void { $registry->registerInitializationHook($this);$registry->registerIssueFilterHook($this); }
    public function initialize(\Mago\Sdk\Analyzer\InitializationContext $context):void { $this->probe->initialize($context); }
    public function getCodes():array { return $this->probe->getCodes(); }
    public function filterIssue(\Mago\Sdk\Analyzer\IssueFilterContext $context):\Mago\Sdk\Analyzer\IssueFilterDecision {
        $decision=$this->probe->filterIssue($context);$observation=$this->probe->observation();$primary=$context->issue->annotations[0]??null;
        if($context->issue->code==='possibly-null-argument'&&$primary!==null) {
            $native=$context->codebase->getFunction('strlen');$formal=$native?->parameters[0]??null;
            $row=['code'=>$context->issue->code,'level'=>$context->issue->level->name,'span'=>[$primary->span->start,$primary->span->end],
                'decision'=>$decision->name,'stage'=>$observation['stage']??null,'sourceProof'=>$observation['sourceProof']??null,
                'nativeReceivingName'=>$native?->identifier->name,'nativeReceivingClass'=>$native?->identifier->class,
                'nativeReceivingFormal'=>$formal?->name,'nativeReceivingType'=>$formal?->type===null?null:(string)$formal->type->type,
                'genuineContext'=>true,'constructedContext'=>false,'currentSourceSha256'=>hash('sha256',$context->contents)];
            file_put_contents($this->root.'/existing-null-flow-observations.jsonl',json_encode($row,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
        }
        return $decision;
    }
};
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension('test/getter-existing-null-flow','Getter and existing null-flow','1',analyzerPlugins:[$getter,$flow])))->run();
PHP);
file_put_contents($workspace.'/guard-worker.php', <<<'PHP'
<?php
require $argv[1];
$plugin = new class($argv[2]) implements \Mago\Sdk\Analyzer\Plugin {
    public function __construct(private readonly string $root) {}
    public function getDefinition(): \Mago\Sdk\Analyzer\PluginDefinition { return new \Mago\Sdk\Analyzer\PluginDefinition('test/asserted-getter-guards', 'Getter guard controls', 'Native metadata and source controls'); }
    public function register(\Mago\Sdk\Analyzer\PluginRegistry $registry): void {
        $provider = new \Ichinya\Laramago\Analyzer\AssertedPureGetterReturnProvider($this->root);
        $registry->registerInitializationHook($provider); $registry->registerCodebaseScanHook($provider->calls);
        $registry->registerMethodReturnTypeProvider(new class($provider, $this->root) implements \Mago\Sdk\Analyzer\MethodReturnTypeProvider {
            private bool $checked = false;
            public function __construct(private readonly \Ichinya\Laramago\Analyzer\AssertedPureGetterReturnProvider $provider, private readonly string $root) {}
            public function getTargets(): array { return $this->provider->getTargets(); }
            public function getReturnType(\Mago\Sdk\Analyzer\ReturnTypeProviderContext $context): ?\Mago\Sdk\Analyzer\Type {
                $result = $this->provider->getReturnType($context);
                if ($result === null || $this->checked) { return $result; } $this->checked = true;
                $probe = new \Ichinya\Laramago\Analyzer\AssertedPureGetterReturnProvider($this->root);
                $file = static function (string $path, string $contents) use ($context): \Mago\Sdk\Syntax\SourceFile {
                    return new \Mago\Sdk\Syntax\SourceFile($context->phpVersion, $path, $contents, [],
                        (new \ReflectionClass(\Mago\Sdk\Internal\Syntax\NodeStore::class))->newInstanceWithoutConstructor(),
                        (new \ReflectionClass(\Mago\Sdk\Internal\Syntax\ResolvedNameStore::class))->newInstanceWithoutConstructor(),
                        (new \ReflectionClass(\Mago\Sdk\Internal\Syntax\TriviaStore::class))->newInstanceWithoutConstructor(), null);
                };
                $scan = static fn (array $files, bool $first = true, bool $last = true) => $probe->calls->scan(new \Mago\Sdk\Analyzer\CodebaseScanContext($context->phpVersion, $context->cancellation, $files, $first, $last));
                $checks = [];
                $expect = static function (string $label, bool $known) use ($probe, $context, $result, &$checks): void {
                    $actual = $probe->getReturnType($context);
                    if (($actual === null ? null : (string) $actual) !== ($known ? (string) $result : null)) { throw new \RuntimeException('Unexpected getter proof state: '.$label); }
                    $checks[] = $label;
                };
                $contents = file_get_contents($this->root.'/cases.php'); $host = $file($this->root.'/cases.php', $contents);
                $expect('missing scan', false);
                $scan([$host], last: false); $expect('incomplete scan', false);
                $scan([], first: false); $expect('complete scan', true);
                $scan([$host, $host]); $expect('duplicate analyzed path', false);
                $scan([$file($this->root.'/cases.php', $contents."\n// Different analyzed caller")]); $expect('stale analyzed caller overlay', false);
                $prefix = '<?php function collide(): void { ';
                $start = $context->invocation->span->start; $end = $context->invocation->span->end;
                $collision = str_replace('->path()', '->noop()', substr($contents, $start, $end - $start));
                $scan([$host, $file($this->root.'/collision.php', $prefix.str_repeat(' ', $start - strlen($prefix)).$collision.'; }')]); $expect('unrelated invocation span collision', false);
                $nativePath = $context->codebase->getClassLike('Testo\\Assert')->location->file;
                $nativeDisk = $probe->calls->path($nativePath); $nativeContents = file_get_contents($nativeDisk);
                $scan([$host, $file($nativePath, $nativeContents."\n// Different analyzed native")]); $expect('stale analyzed native dependency', false);
                $scan([$host, $file($this->root.'/broken.php', '<?php function broken( {')]); $expect('failed parsing poisons scan', false);
                $scan([$host]); $expect('fresh first batch recovers', true);
                file_put_contents($nativeDisk, $nativeContents."\n// Changed native disk after cached proof");
                try { $expect('cached native source changed', false); } finally { file_put_contents($nativeDisk, $nativeContents); }
                $expect('restored native source', true);
                $getter = $context->codebase->getDeclaringMethod('Fixtures\\PathBox', 'path');
                $field = $context->codebase->getDeclaringProperty('Fixtures\\PathBox', '$value');
                $class = $context->codebase->getClassLike('Fixtures\\PathBox');
                $assert = $context->codebase->getDeclaringMethod('Testo\\Assert', 'notNull');
                $stringify = $context->codebase->getDeclaringMethod('Testo\\Assert\\Internal\\Support', 'stringify');
                $history = $context->codebase->getDeclaringProperty('Testo\\Assert\\TestState', '$history');
                $state = $context->codebase->getDeclaringProperty('Testo\\Assert\\Internal\\StaticState', '$state');
                $record = $context->codebase->getDeclaringMethod('Testo\\Assert\\State\\Assertion\\AssertionSuccess', '__construct');
                $caller = $context->codebase->getFunction($probe->calls->call($context->invocation->span)['name']);
                $cache = (new \ReflectionProperty($context->codebase, 'cache'))->getValue($context->codebase); $snapshot = $cache->values;
                $flags = static fn ($metadata, int $add = 0, int $remove = 0) => new \Mago\Sdk\Analyzer\Metadata\MetadataFlags(($metadata->flags->bits | $add) & ~$remove);
                $type = static fn ($metadata, \Mago\Sdk\Analyzer\Type $value, bool $documented = false) => new \Mago\Sdk\Analyzer\Metadata\TypeMetadata($metadata->location, $value, $documented, $metadata->inferred);
                $variants = [
                    'getter reference return' => [$getter, ['flags' => $flags($getter, \Mago\Sdk\Analyzer\Metadata\MetadataFlags::BY_REFERENCE)]],
                    'getter foreign owner' => [$getter, ['identifier' => new \Mago\Sdk\Analyzer\Type\FunctionLikeIdentifier($getter->identifier->kind, 'path', 'Fixtures\\OtherBox')]],
                    'getter wrong name span' => [$getter, ['nameLocation' => new \Mago\Sdk\SourceLocation($getter->nameLocation->file, new \Mago\Sdk\Span($getter->nameLocation->span->start + 1, $getter->nameLocation->span->end))]],
                    'getter wrong full span' => [$getter, ['location' => new \Mago\Sdk\SourceLocation($getter->location->file, new \Mago\Sdk\Span($getter->location->span->start, $getter->location->span->end - 1))]],
                    'getter stronger return' => [$getter, ['returnType' => $type($getter->returnType, \Mago\Sdk\Analyzer\Type::nonEmptyString(), true)]],
                    'getter missing declared type' => [$getter, ['declaredReturnType' => null]],
                    'getter global effects' => [$getter, ['globalsAccessed' => ['protectedState']]],
                    'class open dispatch' => [$class, ['flags' => $flags($class, remove: \Mago\Sdk\Analyzer\Metadata\MetadataFlags::FINAL)]],
                    'class incomplete hierarchy' => [$class, ['unresolvedHierarchyDependencies' => ['Fixtures\\UnknownParent']]],
                    'class wrong source' => [$class, ['location' => new \Mago\Sdk\SourceLocation('foreign.php', $class->location->span)]],
                    'field static storage' => [$field, ['flags' => $flags($field, \Mago\Sdk\Analyzer\Metadata\MetadataFlags::STATIC)]],
                    'field virtual storage' => [$field, ['flags' => $flags($field, \Mago\Sdk\Analyzer\Metadata\MetadataFlags::VIRTUAL_PROPERTY)]],
                    'field readonly discrepancy' => [$field, ['flags' => $flags($field, \Mago\Sdk\Analyzer\Metadata\MetadataFlags::READONLY)]],
                    'field wrong name' => [$field, ['name' => '$other']],
                    'field stronger PHPDoc' => [$field, ['type' => $type($field->type, \Mago\Sdk\Analyzer\Type::union(\Mago\Sdk\Analyzer\Type::nonEmptyString(), \Mago\Sdk\Analyzer\Type::null()), true)]],
                    'field default discrepancy' => [$field, ['defaultType' => $type($field->defaultType, \Mago\Sdk\Analyzer\Type::literalString('changed'))]],
                    'assertion missing native facts' => [$assert, ['assertions' => []]],
                    'assertion conditional extra facts' => [$assert, ['ifTrueAssertions' => $assert->assertions]],
                    'assertion nonvoid return' => [$assert, ['returnType' => $type($assert->returnType, \Mago\Sdk\Analyzer\Type::bool())]],
                    'stringifier broader return' => [$stringify, ['returnType' => $type($stringify->returnType, \Mago\Sdk\Analyzer\Type::mixed(), true)]],
                    'history readonly storage' => [$history, ['flags' => $flags($history, \Mago\Sdk\Analyzer\Metadata\MetadataFlags::READONLY)]],
                    'history wrong declared storage' => [$history, ['declaredType' => $type($history->declaredType, \Mago\Sdk\Analyzer\Type::namedObject('ArrayObject'))]],
                    'history wrong element PHPDoc' => [$history, ['type' => $type($history->type, \Mago\Sdk\Analyzer\Type::list(\Mago\Sdk\Analyzer\Type::mixed()), true)]],
                    'state wrong declared collector' => [$state, ['declaredType' => $type($state->declaredType, \Mago\Sdk\Analyzer\Type::object())]],
                    'state instance storage' => [$state, ['flags' => $flags($state, remove: \Mago\Sdk\Analyzer\Metadata\MetadataFlags::STATIC)]],
                    'caller reference scope' => [$caller, ['flags' => $flags($caller, \Mago\Sdk\Analyzer\Metadata\MetadataFlags::BY_REFERENCE)]],
                    'caller foreign source' => [$caller, ['location' => new \Mago\Sdk\SourceLocation('foreign.php', $caller->location->span)]],
                ];
                $parameters = $assert->parameters; $actual = get_object_vars($parameters[0]); $actual['flags'] = $flags($parameters[0], \Mago\Sdk\Analyzer\Metadata\MetadataFlags::BY_REFERENCE); $parameters[0] = new \Mago\Sdk\Analyzer\Metadata\ParameterMetadata(...$actual);
                $variants['assertion reference actual'] = [$assert, ['parameters' => $parameters]];
                $parameters = $assert->parameters; $message = get_object_vars($parameters[1]); $message['defaultType'] = $type($parameters[1]->defaultType, \Mago\Sdk\Analyzer\Type::literalString('changed')); $parameters[1] = new \Mago\Sdk\Analyzer\Metadata\ParameterMetadata(...$message);
                $variants['assertion changed message default'] = [$assert, ['parameters' => $parameters]];
                $parameters = $record->parameters; $promotion = get_object_vars($parameters[0]); $promotion['flags'] = $flags($parameters[0], remove: \Mago\Sdk\Analyzer\Metadata\MetadataFlags::PROMOTED_PROPERTY); $parameters[0] = new \Mago\Sdk\Analyzer\Metadata\ParameterMetadata(...$promotion);
                $variants['record promotion discrepancy'] = [$record, ['parameters' => $parameters]];
                foreach ($variants as $label => [$original, $changed]) {
                    $kind = $original::class; $replacement = new $kind(...array_replace(get_object_vars($original), $changed)); $replaced = 0;
                    foreach ($snapshot as $operation => $entries) { foreach ($entries as $key => $entry) { if ($entry === $original) { $cache->values[$operation][$key] = $replacement; $replaced++; } } }
                    try { if ($replaced === 0) { throw new \RuntimeException('Metadata control did not replace a native snapshot: '.$label); } $expect($label, false); }
                    finally { $cache->values = $snapshot; }
                }
                $expect('native metadata restored', true);
                $probe->initialize(new \Mago\Sdk\Analyzer\InitializationContext($context->phpVersion, $context->cancellation)); $expect('initialization invalidates scan', false);
                $scan([$host]); $expect('reinitialized complete scan', true);
                file_put_contents($this->root.'/guard-checks.json', json_encode($checks, JSON_THROW_ON_ERROR));
                return $result;
            }
        });
    }
};
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension('test/asserted-getter-guards', 'Getter guard controls', '1', analyzerPlugins: [$plugin])))->run();
PHP);
$binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
$command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
$primary = static function (array $issue): ?array {
    foreach ($issue['annotations'] ?? [] as $annotation) { if (($annotation['kind'] ?? '') === 'Primary') { return $annotation; } }
    return null;
};
$run = static function (string $mode, int $workers = 1, ?array $paths = null, ?array $includes = null) use ($workspace, $package, $native, $command): array {
    file_put_contents($workspace.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml', 'php-version' => '8.5',
        'source' => ['paths' => $paths ?? ['cases.php'], 'includes' => $includes ?? array_map(static fn (string $path): string => 'packages/testo/assert/'.$path, array_keys($native))],
        'extension-hosts' => str_starts_with($mode, 'native') ? new stdClass : ['test' => [
            'command' => [PHP_BINARY, '-d', 'opcache.enable_cli=0', str_starts_with($mode, 'integrated') ? $package.'/bin/laramago-worker.php' : $workspace.($mode === 'guards' ? '/guard-worker.php' : ($mode === 'existing-null-flow' ? '/existing-null-flow-worker.php' : '/worker.php')), $package.'/vendor/autoload.php', $workspace], 'workers' => $workers,
        ]],
    ], JSON_THROW_ON_ERROR));
    $process = proc_open([...$command, '--workspace', $workspace, 'analyze', '--reporting-format=json'], [
        0 => ['pipe', 'r'], 1 => ['file', $workspace.'/'.$mode.'.json', 'w'], 2 => ['file', $workspace.'/'.$mode.'.log', 'w'],
    ], $pipes);
    if (! is_resource($process)) { throw new RuntimeException('Cannot start Mago.'); }
    fclose($pipes[0]); $exit = proc_close($process);
    $log = file_get_contents($workspace.'/'.$mode.'.log');
    if (! in_array($exit, [0, 1], true) || preg_match('/failed|rejected request|parse error/i', $log)) { throw new RuntimeException('Mago '.$mode.' failed; inspect '.$workspace.'; '.$log); }
    return json_decode(file_get_contents($workspace.'/'.$mode.'.json'), true, flags: JSON_THROW_ON_ERROR)['issues'];
};
$normalize = static function (array $issues): array { $keys = array_map(static fn (array $issue): string => json_encode($issue, JSON_THROW_ON_ERROR), $issues); sort($keys); return $keys; };
$within = static fn (array $issues, array $range): array => array_values(array_filter($issues, static fn (array $issue): bool =>
    ($primary($issue)['span']['file_id']['name'] ?? '') === 'cases.php'
    && ($primary($issue)['span']['start']['offset'] ?? -1) >= $range[0] && ($primary($issue)['span']['start']['offset'] ?? -1) < $range[1]));
$before = $run('native');
$after = $run('isolated');
foreach ($cases as $label => $case) {
    $nativeIssues = $within($before, $ranges[$label]); $result = $within($after, $ranges[$label]);
    if ($case[1] === 'residual') {
        if ($nativeIssues === [] || $result === [] || count(array_filter($result, static fn (array $issue): bool => $issue['level'] === 'Error')) === 0) { throw new RuntimeException('Lost residual incompatible argument; inspect '.$workspace); }
    } elseif ($case[1] ? ($nativeIssues === [] || $result !== []) : ($nativeIssues === [] || $normalize($nativeIssues) !== $normalize($result))) {
        throw new RuntimeException('Unexpected asserted getter result for '.$label.'; inspect '.$workspace.'; '.json_encode([$nativeIssues, $result]));
    }
}
echo 'PASS: '.count($cases)." genuine native/isolated getter cases\n";
if ($normalize($run('guards')) !== $normalize($after)) { throw new RuntimeException('Getter source/metadata controls changed exact signatures; '.$workspace); }
$checks = json_decode(file_get_contents($workspace.'/guard-checks.json'), true, flags: JSON_THROW_ON_ERROR);
if (count($checks) < 40) { throw new RuntimeException('Missing getter provenance controls; '.$workspace); }
echo 'PASS: '.count($checks)." source, collision and native metadata controls\n";
if (in_array('--integrated', $argv, true)) {
    // The isolated pure-getter contract and every original negative remain independent evidence.
    $existing=$run('existing-null-flow');
    $plans=[
        ['label'=>'intervening scalar assignment','span'=>[6202,6214]],
        ['label'=>'named consumer argument','span'=>[7279,7291]],
        ['label'=>'nonfinal dispatch','span'=>[7732,7744]],
        ['label'=>'stronger getter PHPDoc','span'=>[8002,8014]],
        ['label'=>'stronger field PHPDoc','span'=>[8143,8155]],
        ['label'=>'magic storage','span'=>[8274,8286]],
        ['label'=>'hooked physical storage','span'=>[8406,8418]],
        ['label'=>'getter with optional parameter','span'=>[8805,8817]],
        ['label'=>'static getter','span'=>[8937,8949]],
    ];
    if(hash('sha256',$source)!=='21c1fdd9bb8dc676a71ea67c7debc73e619580b381a6c399a19663b5df119ec5') { throw new RuntimeException('Getter policy source plans are stale; '.$workspace); }
    $remove=[];
    foreach($plans as $plan) {
        $range=$ranges[$plan['label']]??null;$matches=[];
        if($range===null||$cases[$plan['label']][1]!==false||$plan['span'][0]<$range[0]||$plan['span'][1]>$range[1]
            ||substr($source,$plan['span'][0],$plan['span'][1]-$plan['span'][0])!=='$box->path()') { throw new RuntimeException('Getter policy owner plan changed; '.$workspace); }
        foreach($after as $index=>$issue) {
            $annotation=$primary($issue);$span=$annotation['span']??[];
            if(($span['file_id']['name']??null)==='cases.php'&&($span['start']['offset']??null)===$plan['span'][0]&&($span['end']['offset']??null)===$plan['span'][1]) { $matches[$index]=$issue; }
        }
        if(count($matches)!==1) { throw new RuntimeException('Getter policy plan is missing or ambiguous; '.$workspace); }
        $index=array_key_first($matches);$issue=$matches[$index];
        if(isset($remove[$index])||$issue['level']!=='Error'||$issue['code']!=='possibly-null-argument'
            ||$issue['message']!=='Argument #1 of function `strlen` is possibly `null`, but parameter type `string` does not accept it.') { throw new RuntimeException('Getter policy plan envelope changed; '.$workspace); }
        $remove[$index]=true;
    }
    $expected=array_values(array_filter($after,static fn(array $issue,int $index):bool=>!isset($remove[$index]),ARRAY_FILTER_USE_BOTH));
    if(count($remove)!==9||$normalize($existing)!==$normalize($expected)) { throw new RuntimeException('Independently measured getter/null-flow union differs; '.$workspace); }
    $observations=array_values(array_filter(explode("\n",trim(file_get_contents($workspace.'/existing-null-flow-observations.jsonl')))));
    $observations=array_map(static fn(string $row):array=>json_decode($row,true,flags:JSON_THROW_ON_ERROR),$observations);
    foreach([["intervening captured receiver",[5585,5597]],["unsafe assertion message argument",[7135,7147]],["arrow callback carrier parameter",[9572,9584]],["closure callback carrier parameter",[9813,9825]],["arrow callback copied alias",[10030,10042]],["closure callback copied alias",[10273,10285]],["callback alias retains earlier callee",[10534,10546]],["closure implicit method receiver",[10920,10933]]] as [$label,$span]) {
        $baseline=$within($after,$ranges[$label]);$current=$within($existing,$ranges[$label]);
        $entries=array_values(array_filter($observations,static fn(array $row):bool=>$row['span']===$span));
        $selectedErrors=array_values(array_filter($baseline,static fn(array $issue):bool=>$issue['level']==='Error'&&$issue['code']==='possibly-null-argument'
            &&[$primary($issue)['span']['start']['offset'],$primary($issue)['span']['end']['offset']]===$span));
        if(count($selectedErrors)!==1||$normalize($baseline)!==$normalize($current)||count($entries)!==1||$entries[0]['level']!=='Error'||$entries[0]['decision']!=='Keep'
            ||$entries[0]['genuineContext']!==true||$entries[0]['constructedContext']!==false||$entries[0]['currentSourceSha256']!==hash('sha256',$source)
            ||$entries[0]['nativeReceivingName']!=='strlen'||$entries[0]['nativeReceivingFormal']!=='$string'||$entries[0]['nativeReceivingType']!=='string') {
            throw new RuntimeException('Unsafe captured receiver or assertion message lost its genuine native Error; '.$workspace);
        }
    }
    foreach ([1, 3] as $workers) { if ($normalize($run('integrated'.$workers, $workers)) !== $normalize($existing)) { throw new RuntimeException('Integrated getter signatures differ from independent policy union: '.$workers.' workers; '.$workspace); } }
    echo "PASS: independent nine-record null-flow union; eight actual mutation-hazard owners retained; full one/three whole DTOs equal\n";
}
$mutations = [
    'assertion no-op body' => ['Assert.php', '$actual !== null', '$actual === null'],
    'assertion metadata source' => ['Assert.php', '@phpstan-assert !null $actual', '@phpstan-assert null $actual'],
    'assertion actual reference' => ['Assert.php', 'mixed $actual,', 'mixed &$actual,'],
    'success helper callback' => ['src/Internal/StaticState.php', 'Support::stringify($value)', 'opaque($value)'],
    'support string conversion callback' => ['src/Internal/Support.php', '\\str_replace', 'opaque'],
    'support stronger documented result' => ['src/Internal/Support.php', '@return non-empty-string', '@return never'],
    'native helper binding' => ['src/Internal/StaticState.php', '\\Testo\\Assert\\Internal\\Support::stringify', '\\Fixtures\\UnverifiedSupport::stringify'],
    'collector magic effect' => ['src/TestState.php', 'public array $expectations = [];', 'public array $expectations = []; public function __get(string $name): mixed { return opaque($name); }'],
    'collector different PHPDoc' => ['src/TestState.php', '@var list<Record>', '@var list<mixed>'],
    'collector storage changed' => ['src/TestState.php', 'public array $history = [];', 'public \\ArrayObject $history;'],
    'record constructor effect' => ['src/State/Assertion/AssertionSuccess.php', '    {'."\n".'    }', '    { opaque($context); }'],
];
// The constructor's fresh string-only storage is audited independently of method names.
foreach ($mutations as $label => [$fixture, $needle, $replacement]) {
    $file = $workspace.'/packages/testo/assert/'.$fixture; $original = file_get_contents($file);
    $changed = str_replace($needle, $replacement, $original);
    if ($changed === $original) { throw new RuntimeException('Native mutation did not change '.$label.'; '.$workspace); }
    file_put_contents($file, $changed);
    try {
        $nativeChanged = $run('native-mutated-'.str_replace(' ', '-', $label));
        $isolatedChanged = $run('isolated-mutated-'.str_replace(' ', '-', $label));
        if ($normalize($nativeChanged) !== $normalize($isolatedChanged)) { throw new RuntimeException('Changed native contract was admitted: '.$label.'; '.$workspace); }
        if ($within($nativeChanged, $ranges['immediate strlen']) === []) { throw new RuntimeException('Native mutation control was vacuous: '.$label.'; '.$workspace); }
    } finally { file_put_contents($file, $original); }
}
echo 'PASS: '.count($mutations)." changed native source and annotation contracts defer\n";
file_put_contents($workspace.'/single.php', '<?php declare(strict_types=1); namespace Fixtures; function single(?string $input): int { $box = new PathBox($input); \\Testo\\Assert::notNull($box->path()); return strlen($box->path()); }');
$includes = ['cases.php', ...array_map(static fn (string $path): string => 'packages/testo/assert/'.$path, array_keys($native))];
$singleBefore = $run('native-single', paths: ['single.php'], includes: $includes);
$singleAfter = $run('isolated-single', paths: ['single.php'], includes: $includes);
if ($singleBefore === [] || $normalize($singleBefore) !== $normalize($singleAfter)) { throw new RuntimeException('Missing scanned getter declaration did not defer for single-file analysis; '.$workspace); }
echo "PASS: single-file missing getter snapshot preserves native signatures\n";
echo 'Fixtures: '.$workspace."\n";
