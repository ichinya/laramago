<?php

declare(strict_types=1);

// Source-only invented declarations. Application bootstrap and database access are traps.
$package = str_replace('\\', '/', dirname(__DIR__));
$workspace = str_replace('\\', '/', sys_get_temp_dir()).'/laramago direct references '.bin2hex(random_bytes(8));
mkdir($workspace);
$vendor = 'packages';
file_put_contents($workspace.'/composer.json', json_encode([
    'config' => ['vendor-dir' => $vendor],
    'autoload' => ['files' => ['bootstrap.php']],
    'extra' => ['laramago' => new stdClass],
], JSON_THROW_ON_ERROR));
file_put_contents($workspace.'/bootstrap.php', '<?php file_put_contents(__DIR__."/executed", "bootstrap"); throw new RuntimeException("Application bootstrap executed.");');
$nativePath = $vendor.'/testo/assert/Assert.php';
mkdir(dirname($workspace.'/'.$nativePath), recursive: true);
// Native Testo assertion excerpts; preserve header bindings and assertion metadata.
file_put_contents($workspace.'/'.$nativePath, <<<'PHP'
<?php
declare(strict_types=1);
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
final class Assert {
    /**
     * Asserts that the value is true.
     *
     * @param mixed $actual The actual value to check.
     * @param string $message Short description about what exactly is being asserted.
     * @throws AssertionException when the assertion fails.
     *
     * @psalm-assert true $actual
     * @phpstan-assert true $actual
     */
    #[AssertMethod]
    public static function true(mixed $actual, string $message = ''): void
    {
        $actual === true
            ? StaticState::success($actual, 'is exactly `true`', $message)
            : StaticState::fail(new ComparisonFailure(
                expected: true,
                actual: $actual,
                value: Support::stringify($actual),
                assertion: 'is exactly `true`',
                context: $message,
                reason: 'expected `true`, got `' . Support::stringify($actual) . '`',
            ));
    }
    /**
     * Asserts that the value is false.
     *
     * @param mixed $actual The actual value to check.
     * @param string $message Short description about what exactly is being asserted.
     * @throws AssertionException when the assertion fails.
     *
     * @psalm-assert false $actual
     * @phpstan-assert false $actual
     */
    #[AssertMethod]
    public static function false(mixed $actual, string $message = ''): void
    {
        $actual === false
            ? StaticState::success($actual, 'is exactly `false`', $message)
            : StaticState::fail(new ComparisonFailure(
                expected: false,
                actual: $actual,
                value: Support::stringify($actual),
                assertion: 'is exactly `false`',
                context: $message,
                reason: 'expected `false`, got `' . Support::stringify($actual) . '`',
            ));
    }
}
PHP);
file_put_contents($workspace.'/support.php', <<<'PHP'
<?php
namespace Testo\Assert\Internal {
    final class StaticState {
        public static function success(mixed $value, string $assertion, string $message): void {}
        public static function fail(\Throwable $failure): never { throw $failure; }
    }
    final class Support { public static function stringify(mixed $value): string { return ''; } }
}
namespace Testo\Common\Attribute { #[\Attribute] final class AssertMethod {} }
namespace Testo\Assert\State\Assertion {
    class AssertionException extends \RuntimeException {}
    final class ComparisonFailure extends \RuntimeException {
        public function __construct(mixed $expected, mixed $actual, string $value, string $assertion, string $context, string $reason) {}
    }
}
namespace Fixture {
    function unavailableForwarder(callable $callback): void {}
    function stopNever(): never { exit(1); }
    final class FakeClosure {
        public static function fromCallable(callable $callback): \Closure { return static function (string $stage): void {}; }
    }
    final class WrongAssert { public static function true(mixed $actual): void {} }
}
namespace Fixture\LocalOps { function strlen(string $value): never { exit(1); } }
PHP);

$runners = [
    'DirectRunner' => 'public function run(callable $before): void { $before("checkpoint"); }',
    'NativeRunner' => 'public function run(?callable $before = null): void { $hook = \Closure::fromCallable($before ?? static fn (string $stage): null => null); $hook("checkpoint"); }',
    'CaughtRunner' => 'public function run(?callable $before = null): void { $hook = \Closure::fromCallable($before ?? static fn (string $stage): null => null); try { $hook("checkpoint"); } catch (\Throwable) {} }',
    'ForwardRunner' => 'public function run(?callable $before = null): void { $hook = \Closure::fromCallable($before ?? static fn (string $stage): null => null); $notes = []; $this->attempt($hook, "checkpoint", $notes); }
        /** @param list<string> $notes */ private function attempt(\Closure $hook, string $stage, array &$notes): void { try { $hook($stage); } catch (\Throwable) { $notes[] = $stage; } try { $hook($stage); } catch (\Throwable) { $notes[] = $stage; } }',
    'StagesRunner' => 'public function run(?callable $before = null): void { $hook = \Closure::fromCallable($before ?? static fn (string $stage): null => null); try { $hook("checkpoint"); } catch (\Throwable) {} try { $hook("fallback"); } catch (\Throwable) {} }',
    'AliasRunner' => 'public function run(callable $before): void { $hook = $before; $hook("checkpoint"); }',
    'UninvokedRunner' => 'public function run(callable $before): void {}',
    'ReplacedRunner' => 'public function run(callable $before): void { $hook = static function (string $stage): void {}; $hook("checkpoint"); }',
    'ReturnRunner' => 'public function run(callable $before): void { return; $before("checkpoint"); }',
    'ResetRunner' => 'public function run(callable $before): void { try { $before("checkpoint"); } catch (\Throwable) {} try { $before("rollback"); } catch (\Throwable) {} }',
    'FinallyRunner' => 'public function run(callable $before): void { try { try { $before("checkpoint"); } catch (\Throwable) {} } finally { $before("rollback"); } }',
    'WrongCatchRunner' => 'public function run(callable $before): void { try { $before("checkpoint"); } catch (\LogicException) {} }',
    'UnknownRunner' => 'public function run(callable $before): void { unavailableForwarder($before); }',
    'FakeNativeRunner' => 'public function run(callable $before): void { $hook = FakeClosure::fromCallable($before); $hook("checkpoint"); }',
    'StoredRunner' => 'private mixed $callback; public function run(callable $before): void { $this->callback = $before; }',
    'EscapedRunner' => 'private mixed $callback; public function run(callable $before): void { $this->callback = $before; $before("checkpoint"); }',
    'ReturnedRunner' => 'public function run(callable $before): callable { return $before; }',
    'RecursiveRunner' => 'public function run(callable $before): void { $this->run($before); }',
    'UnboundedRunner' => 'public function run(callable $before): void { while (true) { $before("checkpoint"); } }',
    'NeverRunner' => 'public function run(callable $before): void { $this->stop(); $before("checkpoint"); } private function stop(): never { throw new \RuntimeException("No normal return."); }',
    'FalseBranchRunner' => 'public function run(callable $before): void { if (false) { $before("checkpoint"); } }',
    'KnownStageRunner' => 'public function run(callable $before): void { $before("unrelated"); }',
    'TypedStageRunner' => 'public function run(callable $before): void { $before(17); }',
    'NullStageRunner' => 'public function run(callable $before): void { try { $before(null); } catch (\Throwable) {} }',
    'GlobalRunner' => 'public function run(callable $before): void { $GLOBALS["callback"] = $before; $before("checkpoint"); }',
    'NamedForwardRunner' => 'public function run(callable $before): void { $this->invoke(stage: "checkpoint", hook: $before); } private function invoke(\Closure $hook, string $stage): void { $hook($stage); }',
    'ContradictoryGateRunner' => 'public function run(callable $before, bool $gate): void { if ($gate) { if (! $gate) { $before("checkpoint"); } } }',
    'InvocationBudgetRunner' => 'public function run(callable $before): void { '.str_repeat('try { $before("checkpoint"); } catch (\Throwable) {} ', 33).' }',
    'ThrowingRunner' => 'public function __construct() { throw new \RuntimeException("Construction stops."); } public function run(callable $before): void { try { $before("checkpoint"); } catch (\Throwable) {} }',
    'TokenRunner' => 'public function run(callable $before): void { $token = new ThrowingToken; try { $before("checkpoint"); } catch (\Throwable) {} }',
    'RequiredRunner' => 'public function __construct(int $count) {} public function run(callable $before): void { try { $before("checkpoint"); } catch (\Throwable) {} }',
    'ObjectIdentityRunner' => 'public function run(callable $before): void { if ((new PlainToken) === (new PlainToken)) { try { $before("checkpoint"); } catch (\Throwable) {} } }',
    'UntypedNeverRunner' => 'public function run(callable $before): void { $this->invoke($before); } /** @return never */ private function invoke(callable $before) { try { $before("checkpoint"); } catch (\Throwable) {} }',
    'UntypedLiteralRunner' => 'public function run(callable $before): void { $this->invoke($before, "checkpoint"); } /** @param "different" $stage */ private function invoke(callable $before, $stage): void { try { $before($stage); } catch (\Throwable) {} }',
    'CopiedGateRunner' => 'public function run(callable $before, bool $gate): void { $copy = $gate; if ($gate && ! $copy) { try { $before("checkpoint"); } catch (\Throwable) {} } }',
    'NeverActionRunner' => 'public function __construct(private readonly \Closure $action) {} public function run(callable $before): void { ($this->action)(); try { $before("checkpoint"); } catch (\Throwable) {} }',
    'WrongReturnRunner' => 'public function run(callable $before): int { try { $before("checkpoint"); } catch (\Throwable) {} return "invalid"; }',
    'InvalidOrdinaryRunner' => 'public function run(callable $before): void { strlen([]); try { $before("checkpoint"); } catch (\Throwable) {} }',
    'PrivateRunner' => 'private function run(callable $before): void { try { $before("checkpoint"); } catch (\Throwable) {} }',
    'PrivateActionRunner' => 'public function run(callable $before): void { (new PrivateActionOwner)->act(); try { $before("checkpoint"); } catch (\Throwable) {} }',
    'GlobalBuiltinRunner' => 'public function run(callable $before): void { \strlen("plain"); try { $before("checkpoint"); } catch (\Throwable) {} }',
    'NonStringableRunner' => 'public function run(callable $before): void { $value = "value: ".(new PlainToken); try { $before("checkpoint"); } catch (\Throwable) {} }',
    'InterpolatedObjectRunner' => 'public function run(callable $before): void { $token = new PlainToken; $value = "value: $token"; try { $before("checkpoint"); } catch (\Throwable) {} }',
    'PromotedStateRunner' => 'public function __construct(public readonly string $label, private readonly bool $enabled = true) {} public function run(callable $before): void { if ($this->enabled) { try { $before("checkpoint"); } catch (\Throwable) {} } }',
    'PhysicalActionRunner' => 'private \Closure $action; public function __construct(\Closure $action) { $this->action = $action; } public function run(callable $before): void { ($this->action)(); try { $before("checkpoint"); } catch (\Throwable) {} }',
    'ReadonlyActionRunner' => 'private readonly \Closure $action; public function __construct(\Closure $action) { $this->action = $action; } public function run(callable $before): void { ($this->action)(); try { $before("checkpoint"); } catch (\Throwable) {} }',
    'NormalizedActionRunner' => 'private \Closure $action; public function __construct(callable $action) { $this->action = \Closure::fromCallable($action); } public function run(callable $before): void { ($this->action)(); try { $before("checkpoint"); } catch (\Throwable) {} }',
    'TraceConstructorRunner' => 'public function __construct(array &$trace, private \Closure $action) {} public function run(callable $before): void { ($this->action)(); try { $before("checkpoint"); } catch (\Throwable) {} }',
    'PacketRunner' => '/** @param list<PacketFault> $failures */ public function __construct(public readonly array $failures, public readonly string $label, public readonly int $attempts = 2) {} public function run(callable $before): void { $failures = $this->failures; try { $before("checkpoint"); } catch (\Throwable) {} }',
    'DefaultStateRunner' => 'private bool $enabled = true; public function run(callable $before): void { if ($this->enabled) { try { $before("checkpoint"); } catch (\Throwable) {} } }',
    'NamedNormalizationRunner' => 'public function run(callable $before): void { $hook = \Closure::fromCallable(callback: $before); try { $hook("checkpoint"); } catch (\Throwable) {} }',
    'WrongNormalizationRunner' => 'public function run(callable $before): void { $hook = \Closure::fromCallable(other: $before); try { $hook("checkpoint"); } catch (\Throwable) {} }',
    'UninitializedActionRunner' => 'private \Closure $action; public function run(callable $before): void { ($this->action)(); try { $before("checkpoint"); } catch (\Throwable) {} }',
    'WrongFieldRunner' => 'private \Closure $action; public function __construct(int $value) { $this->action = $value; } public function run(callable $before): void { try { $before("checkpoint"); } catch (\Throwable) {} }',
    'RepeatedReadonlyRunner' => 'private readonly \Closure $action; public function __construct(\Closure $action) { $this->action = $action; $this->action = $action; } public function run(callable $before): void { try { $before("checkpoint"); } catch (\Throwable) {} }',
    'StoredSelectedRunner' => 'public function __construct(public \Closure $action) {} public function run(): void { try { ($this->action)("checkpoint"); } catch (\Throwable) {} }',
    'NestedSelectedRunner' => 'public function __construct(public array $actions) {} public function run(callable $before): void { try { $before("checkpoint"); } catch (\Throwable) {} }',
    'UnsupportedConstructorRunner' => 'public function __construct(string $label) { $copy = $label; } public function run(callable $before): void { try { $before("checkpoint"); } catch (\Throwable) {} }',
    'StaticFieldRunner' => 'public static bool $ready = false; public function run(callable $before): void { try { $before("checkpoint"); } catch (\Throwable) {} }',
    'HookFieldRunner' => 'public bool $ready { get => false; } public function run(callable $before): void { try { $before("checkpoint"); } catch (\Throwable) {} }',
    'PhysicalSelectedRunner' => 'public \Closure $action; public function __construct(\Closure $action) { $this->action = $action; } public function run(callable $before): void { try { $before("checkpoint"); } catch (\Throwable) {} }',
];
$runnerSource = "<?php\nnamespace Fixture;\nuse Closure;\n";
foreach ($runners as $class => $body) { $runnerSource .= 'final class '.$class.' { '.$body." }\n"; }
$runnerSource .= 'class OpenRunner { public function run(callable $before): void { $before("checkpoint"); } }'."\n";
$runnerSource .= 'final class QuietDescendant extends OpenRunner { public function run(callable $before): void {} }'."\n";
$runnerSource .= 'trait NoopHook { public function run(callable $before): void {} } final class TraitRunner { use NoopHook; }'."\n";
$runnerSource .= 'final class PlainToken {} final class ThrowingToken { public function __construct() { throw new \RuntimeException("Token construction stops."); } }'."\n";
$runnerSource .= 'final class PrivateActionOwner { private function act(): void {} }'."\n";
$runnerSource .= <<<'PHP'
final class PacketFault extends \RuntimeException {
    public function __construct(public readonly string $stage) { parent::__construct(message: "Synthetic ".$stage); }
}
final class WrongParentFault extends \RuntimeException {
    public function __construct(public readonly string $stage) { parent::__construct(other: $stage); }
}
final class EntryWorker {
    public function idle(): void {}
    public function nothing(): null { return null; }
    public function entries(): array { return []; }
    public function text(): string { return "plain"; }
    public function conditionalText(): string { return \strtoupper("plain"); }
    public function stop(): never { throw new \RuntimeException("Worker has no continuation."); }
}
final class MutableWorker {
    public bool $ready = false;
    public function process(): array { $this->ready = true; return []; }
    public function reset(): null { $this->ready = false; return null; }
}
final class PrivateStateWorker {
    private bool $ready = false;
    public function process(): null { $this->ready = true; return null; }
}
final class ReadonlyStateWorker {
    public function __construct(public readonly bool $ready = false) {}
    public function process(): null { $this->ready = true; return null; }
}
final class ReadonlyFlags { public function __construct(public readonly bool $ready) {} }
final class StorageWorker {
    protected array $settings = [];
    public function check(): null { if ($this->settings["ready"] === true) { return null; } throw new \RuntimeException("Worker is not ready."); }
}
final class DocumentedStorageWorker {
    /** @var array<string, mixed> */ protected array $settings = [];
    public function check(): null { return null; }
}
final class UntypedStorageWorker {
    /** @var array<string, mixed> */ protected $settings = [];
}
final class ReadonlyStorageWorker { public function __construct(public readonly array $settings = []) {} }
final class StaticStorageWorker { public static array $settings = []; }
final class HookStorageWorker { public array $settings { get => []; } }
class StorageBase { protected array $settings = []; }
final class InheritedStorageWorker extends StorageBase {}
class ActionBase { public function __construct(public \Closure $action) {} }
final class InheritedActionRunner extends ActionBase {
    public function run(callable $before): void { try { $before("checkpoint"); } catch (\Throwable) {} }
}
function resetAllocated(MutableWorker $worker): void { $worker->ready = false; }
/** @param list<MutableWorker> $workers */
function resetAllocatedArray(array $workers): void { $workers[0]->ready = false; }
function invokeUntracked(\Closure $action): void { $action(); }
/** @param list<MutableWorker> $workers */
function resetAllocatedReference(array &$workers): void { $workers[0]->ready = false; }
PHP;
$runnerSource .= "\n";
$runnerSource .= 'namespace Fixture\\LocalOps; final class ShadowFunctionRunner { public function run(callable $before): void { strlen("plain"); try { $before("checkpoint"); } catch (\\Throwable) {} } }'."\n";
$runnerSource .= 'use function Fixture\\LocalOps\\strlen as measured; final class ImportedFunctionRunner { public function run(callable $before): void { measured("plain"); try { $before("checkpoint"); } catch (\\Throwable) {} } }'."\n";
file_put_contents($workspace.'/runners.php', $runnerSource);
$caughtWrite = 'static function (string $stage) use (&$changed): void { if (! $changed && $stage === "checkpoint") { $changed = true; throw new \RuntimeException("Synthetic callback failure."); } }';
$write = $caughtWrite;
$stageWrite = 'static function (string $stage) use (&$changed): void { if ($stage === "checkpoint") { $changed = true; throw new \RuntimeException("First stage."); } elseif ($stage === "rollback") { $changed = false; } }';
$cases = [
    'direct named receiver' => ['$changed = false; $runner = new DirectRunner; try { $runner->run('.$write.'); } catch (\Throwable) {} Assert::true($changed);', true],
    'direct inline receiver' => ['$changed = false; try { (new DirectRunner)->run('.$write.'); } catch (\Throwable) {} Assert::true($changed);', true],
    'native normalization' => ['$changed = false; try { (new NativeRunner)->run('.$write.'); } catch (\Throwable) {} Assert::true($changed);', true],
    'caught write before throw' => ['$changed = false; (new CaughtRunner)->run('.$caughtWrite.'); Assert::true($changed);', true],
    'private forwarding and caught retry' => ['$changed = false; (new ForwardRunner)->run('.$caughtWrite.'); Assert::true($changed);', true],
    'stable callback local alias' => ['$changed = false; try { (new AliasRunner)->run('.$write.'); } catch (\Throwable) {} Assert::true($changed);', true],
    'named callable argument' => ['$changed = false; try { (new NativeRunner)->run(before: '.$write.'); } catch (\Throwable) {} Assert::true($changed);', true],
    'named private helper arguments' => ['$changed = false; try { (new NamedForwardRunner)->run('.$write.'); } catch (\Throwable) {} Assert::true($changed);', true],
    'false expected after true initial value' => ['$changed = true; (new CaughtRunner)->run(static function (string $stage) use (&$changed): void { if ($changed && $stage === "checkpoint") { $changed = false; throw new \RuntimeException("False write."); } }); Assert::false($changed);', true],
    'unknown ordinary condition admits write' => ['$changed = false; (new CaughtRunner)->run(static function (string $stage) use (&$changed, $condition): void { if ($condition && ! $changed && $stage === "checkpoint") { $changed = true; throw new \RuntimeException("Conditional write."); } }); Assert::true($changed);', true],
    'finite captured stages and separate trace' => ['foreach (["checkpoint", "fallback"] as $selected) { $changed = false; $trace = []; (new StagesRunner)->run(static function (string $stage) use (&$changed, &$trace, $selected): void { $trace[] = $stage; if (! $changed && $stage === $selected) { $changed = true; throw new \RuntimeException("Synthetic stage failure."); } }); Assert::true($changed); }', true],
    'caller try and later finally' => ['$changed = false; $trace = []; try { (new CaughtRunner)->run('.$caughtWrite.'); Assert::true($changed); } finally { $trace[] = "complete"; }', true],
    'qualified compatible native action' => ['$changed = false; (new GlobalBuiltinRunner)->run('.$write.'); Assert::true($changed);', true],
    'uninvoked supplied closure' => ['$changed = false; (new UninvokedRunner)->run('.$write.'); Assert::true($changed);', false],
    'closure only constructed' => ['$changed = false; $callback = '.$write.'; Assert::true($changed);', false],
    'capture is by value' => ['$changed = false; (new NativeRunner)->run(static function (string $stage) use ($changed): void { $changed = true; }); Assert::true($changed);', false],
    'callback writes only false' => ['$changed = false; (new NativeRunner)->run(static function (string $stage) use (&$changed): void { $changed = false; }); Assert::true($changed);', false],
    'callback true then false' => ['$changed = false; (new NativeRunner)->run(static function (string $stage) use (&$changed): void { $changed = true; $changed = false; }); Assert::true($changed);', false],
    'runner last invocation resets false' => ['$changed = false; (new ResetRunner)->run('.$stageWrite.'); Assert::true($changed);', false],
    'runner finally resets false' => ['$changed = false; (new FinallyRunner)->run('.$stageWrite.'); Assert::true($changed);', false],
    'caller reset before assertion' => ['$changed = false; (new NativeRunner)->run('.$write.'); $changed = false; Assert::true($changed);', false],
    'caller finally resets before assertion' => ['$changed = false; try { (new NativeRunner)->run('.$write.'); } finally { $changed = false; } Assert::true($changed);', false],
    'write occurs after thrown exit' => ['$changed = false; (new CaughtRunner)->run(static function (string $stage) use (&$changed): void { throw new \RuntimeException("Before write."); $changed = true; }); Assert::true($changed);', false],
    'uncaught callback exception' => ['$changed = false; (new NativeRunner)->run('.$caughtWrite.'); Assert::true($changed);', false],
    'incompatible catch leaves no return' => ['$changed = false; (new WrongCatchRunner)->run('.$caughtWrite.'); Assert::true($changed);', false],
    'callee returns before callback' => ['$changed = false; (new ReturnRunner)->run('.$write.'); Assert::true($changed);', false],
    'known unequal stage predicate' => ['$changed = false; (new KnownStageRunner)->run('.$caughtWrite.'); Assert::true($changed);', false],
    'known false branch in callback' => ['$changed = false; (new CaughtRunner)->run(static function (string $stage) use (&$changed): void { if (false) { $changed = true; throw new \RuntimeException("Unreachable."); } }); Assert::true($changed);', false],
    'known false captured condition' => ['$condition = false; $changed = false; (new CaughtRunner)->run(static function (string $stage) use (&$changed, $condition): void { if ($condition) { $changed = true; throw new \RuntimeException("Unreachable."); } }); Assert::true($changed);', false],
    'known false callee branch' => ['$changed = false; (new FalseBranchRunner)->run('.$write.'); Assert::true($changed);', false],
    'supplied callback replaced' => ['$changed = false; (new ReplacedRunner)->run('.$write.'); Assert::true($changed);', false],
    'callback stored without invocation' => ['$changed = false; (new StoredRunner)->run('.$write.'); Assert::true($changed);', false],
    'callback escapes before invocation' => ['$changed = false; (new EscapedRunner)->run('.$write.'); Assert::true($changed);', false],
    'callback is returned' => ['$changed = false; (new ReturnedRunner)->run('.$write.'); Assert::true($changed);', false],
    'unknown forwarding body' => ['$changed = false; (new UnknownRunner)->run('.$write.'); Assert::true($changed);', false],
    'global callback escape' => ['$changed = false; (new GlobalRunner)->run('.$write.'); Assert::true($changed);', false],
    'user class impersonates native Closure' => ['$changed = false; (new FakeNativeRunner)->run('.$write.'); Assert::true($changed);', false],
    'recursive callee has no finite proof' => ['$changed = false; (new RecursiveRunner)->run('.$write.'); Assert::true($changed);', false],
    'unbounded callee loop' => ['$changed = false; (new UnboundedRunner)->run('.$write.'); Assert::true($changed);', false],
    'known never callee' => ['$changed = false; (new NeverRunner)->run('.$write.'); Assert::true($changed);', false],
    'callback writes unrelated storage' => ['$changed = false; $other = false; (new NativeRunner)->run(static function (string $stage) use (&$other): void { $other = true; }); Assert::true($changed);', false],
    'prior local reference alias' => ['$changed = false; $alias =& $changed; (new NativeRunner)->run('.$write.'); Assert::true($changed);', false],
    'global captured storage' => ['global $changed; $changed = false; (new NativeRunner)->run('.$write.'); Assert::true($changed);', false],
    'static captured storage' => ['static $changed = false; (new NativeRunner)->run('.$write.'); Assert::true($changed);', false],
    'strong authoritative false local' => ['/** @var false $changed */ $changed = false; (new NativeRunner)->run('.$write.'); Assert::true($changed);', false],
    'nameless authoritative false local' => ['/** @var false */ $changed = false; (new CaughtRunner)->run('.$write.'); Assert::true($changed);', false],
    'escaped callback array' => ['$changed = false; $callback = '.$write.'; $callbacks = [$callback]; (new NativeRunner)->run($callback); Assert::true($changed);', false],
    'by reference foreach storage' => ['$flags = [false]; foreach ($flags as &$changed) { (new NativeRunner)->run('.$write.'); Assert::true($changed); }', false],
    'known descendant drops callback' => ['$changed = false; (new OpenRunner)->run('.$write.'); Assert::true($changed);', false],
    'trait supplies no-op callback' => ['$changed = false; (new TraitRunner)->run('.$write.'); Assert::true($changed);', false],
    'wrong callback input type' => ['$changed = false; (new TypedStageRunner)->run('.$write.'); Assert::true($changed);', false],
    'null callback input throws before body' => ['$changed = false; (new NullStageRunner)->run('.$write.'); Assert::true($changed);', false],
    'unpacked callback argument' => ['$changed = false; (new NativeRunner)->run(...['.$write.']); Assert::true($changed);', false],
    'wrong named callback argument' => ['$changed = false; (new NativeRunner)->run(unknown: '.$write.'); Assert::true($changed);', false],
    'dynamic method name' => ['$changed = false; $method = "run"; (new NativeRunner)->$method('.$write.'); Assert::true($changed);', false],
    'unrelated assertion method' => ['$changed = false; (new NativeRunner)->run('.$write.'); WrongAssert::true($changed); $other = false; Assert::true($other);', false],
    'property assertion is independent' => ['$changed = false; $box = new \stdClass; $box->flag = false; (new NativeRunner)->run('.$write.'); Assert::true($box->flag);', false],
    'callback nested frame spelling is separate' => ['$changed = false; (new NativeRunner)->run(static function (string $stage): void { $changed = true; }); Assert::true($changed);', false],
    'contradictory correlated gate' => ['$changed = false; (new ContradictoryGateRunner)->run('.$write.', $condition); Assert::true($changed);', false],
    'contradictory correlated stage' => ['$changed = false; (new CaughtRunner)->run(static function (string $stage) use (&$changed): void { if ($stage === "checkpoint" && $stage !== "checkpoint") { $changed = true; throw new \RuntimeException("Contradiction."); } }); Assert::true($changed);', false],
    'known false enclosing caller guard' => ['$changed = false; if (false) { (new NativeRunner)->run('.$write.'); } Assert::true($changed);', false],
    'independent impossible assertion before origin' => ['Assert::true(false); $changed = false; (new NativeRunner)->run('.$write.'); Assert::true($changed);', false],
    'iteration capture escapes' => ['$callbacks = []; foreach (["checkpoint", "fallback"] as $selected) { $changed = false; $callback = static function (string $stage) use (&$changed, $selected): void { if ($stage === $selected) { $changed = true; throw new \RuntimeException("Escaped stage."); } }; $callbacks[] = $callback; (new CaughtRunner)->run($callback); Assert::true($changed); }', false],
    'distinct trace aliases tracked storage' => ['$changed = false; $trace =& $changed; (new CaughtRunner)->run(static function (string $stage) use (&$changed, &$trace): void { $trace = false; if (! $changed && $stage === "checkpoint") { $changed = true; throw new \RuntimeException("Aliased storage."); } }); Assert::true($changed);', false],
    'dynamic local storage access' => ['$changed = false; $name = "changed"; $$name = false; (new NativeRunner)->run('.$write.'); Assert::true($changed);', false],
    'invocation event budget exceeded' => ['$changed = false; (new InvocationBudgetRunner)->run('.$write.'); Assert::true($changed);', false],
    'receiver constructor stops before callback' => ['$changed = false; try { (new ThrowingRunner)->run('.$write.'); } catch (\Throwable) {} Assert::true($changed);', false],
    'ordinary token constructor stops before callback' => ['$changed = false; try { (new TokenRunner)->run('.$write.'); } catch (\Throwable) {} Assert::true($changed);', false],
    'constructor argument violates declared scalar' => ['$changed = false; try { (new RequiredRunner("invalid"))->run('.$write.'); } catch (\Throwable) {} Assert::true($changed);', false],
    'distinct fresh objects cannot be identical' => ['$changed = false; (new ObjectIdentityRunner)->run('.$write.'); Assert::true($changed);', false],
    'stronger closure literal parameter' => ['$changed = false; (new CaughtRunner)->run(/** @param "different" $stage */ '.$write.'); Assert::true($changed);', false],
    'stronger closure never return' => ['$changed = false; (new CaughtRunner)->run(/** @return never */ '.$write.'); Assert::true($changed);', false],
    'assigned wrapper carries closure literal parameter' => ['$changed = false; /** @param "different" $stage */ $callback = '.$write.'; (new CaughtRunner)->run($callback); Assert::true($changed);', false],
    'assigned wrapper carries closure never return' => ['$changed = false; /** @return never */ $callback = '.$write.'; (new CaughtRunner)->run($callback); Assert::true($changed);', false],
    'assigned expression carries closure literal parameter' => ['$changed = false; $callback = /** @param "different" $stage */ '.$write.'; (new CaughtRunner)->run($callback); Assert::true($changed);', false],
    'assigned expression carries closure never return' => ['$changed = false; $callback = /** @return never */ '.$write.'; (new CaughtRunner)->run($callback); Assert::true($changed);', false],
    'untyped helper never return' => ['$changed = false; (new UntypedNeverRunner)->run('.$write.'); Assert::true($changed);', false],
    'untyped helper literal argument' => ['$changed = false; (new UntypedLiteralRunner)->run('.$write.'); Assert::true($changed);', false],
    'known never exception operand' => ['$changed = false; (new CaughtRunner)->run(static function (string $stage) use (&$changed): void { if (! $changed && $stage === "checkpoint") { $changed = true; throw new \RuntimeException(stopNever()); } }); Assert::true($changed);', false],
    'copied gate cannot contradict its origin' => ['$changed = false; (new CopiedGateRunner)->run('.$write.', $condition); Assert::true($changed);', false],
    'unrelated action is known never' => ['$changed = false; try { (new NeverActionRunner(static function (): never { throw new \RuntimeException("Action stops first."); }))->run('.$write.'); } catch (\Throwable) {} Assert::true($changed);', false],
    'runner normal return violates scalar declaration' => ['$changed = false; (new WrongReturnRunner)->run('.$write.'); Assert::true($changed);', false],
    'callback normal return violates scalar declaration' => ['$changed = false; (new NativeRunner)->run(static function (string $stage) use (&$changed): int { if (! $changed && $stage === "checkpoint") { $changed = true; return "invalid"; } return 0; }); Assert::true($changed);', false],
    'callback scalar return falls through' => ['$changed = false; (new NativeRunner)->run(static function (string $stage) use (&$changed): int { if (! $changed && $stage === "checkpoint") { $changed = true; } }); Assert::true($changed);', false],
    'ordinary primitive call cannot accept array' => ['$changed = false; (new InvalidOrdinaryRunner)->run('.$write.'); Assert::true($changed);', false],
    'external private method is inaccessible' => ['$changed = false; (new PrivateRunner)->run('.$write.'); Assert::true($changed);', false],
    'foreign private action is inaccessible' => ['$changed = false; (new PrivateActionRunner)->run('.$write.'); Assert::true($changed);', false],
    'strict true prefix excludes integer' => ['Assert::true(1); $changed = false; (new CaughtRunner)->run('.$write.'); Assert::true($changed);', false],
    'strict false prefix excludes null' => ['Assert::false(null); $changed = false; (new CaughtRunner)->run('.$write.'); Assert::true($changed);', false],
    'successful prefix refines captured condition' => ['Assert::true($condition); $changed = false; (new CaughtRunner)->run(static function (string $stage) use (&$changed, $condition): void { if (! $condition) { $changed = true; throw new \RuntimeException("Excluded condition."); } }); Assert::true($changed);', false],
    'sparse literal append keeps native key' => ['$changed = false; foreach ([2 => "outside", "checkpoint"] as $key => $selected) { (new CaughtRunner)->run(static function (string $stage) use (&$changed, $key): void { if ($key === 1 && ! $changed && $stage === "checkpoint") { $changed = true; throw new \RuntimeException("Incorrect key."); } }); } Assert::true($changed);', false],
    'namespace shadows ordinary builtin' => ['$changed = false; (new \Fixture\LocalOps\ShadowFunctionRunner)->run('.$write.'); Assert::true($changed);', false],
    'imported ordinary alias remains never' => ['$changed = false; (new \Fixture\LocalOps\ImportedFunctionRunner)->run('.$write.'); Assert::true($changed);', false],
    'object concatenation cannot complete' => ['$changed = false; (new NonStringableRunner)->run('.$write.'); Assert::true($changed);', false],
    'object interpolation cannot complete' => ['$changed = false; (new InterpolatedObjectRunner)->run('.$write.'); Assert::true($changed);', false],
    'promoted readonly primitive defaults' => ['$changed = false; (new PromotedStateRunner("plain"))->run('.$write.'); Assert::true($changed);', true],
    'physical stored void closure' => ['$changed = false; (new PhysicalActionRunner(static function (): void {}))->run('.$write.'); Assert::true($changed);', true],
    'physical stored null closure' => ['$changed = false; (new PhysicalActionRunner(static fn (): null => null))->run('.$write.'); Assert::true($changed);', true],
    'physical stored array closure' => ['$changed = false; (new PhysicalActionRunner(static fn (): array => []))->run('.$write.'); Assert::true($changed);', true],
    'readonly stored untracked closure' => ['$changed = false; (new ReadonlyActionRunner(static fn (): null => null))->run('.$write.'); Assert::true($changed);', true],
    'normalized stored untracked closure' => ['$changed = false; (new NormalizedActionRunner(static fn (): null => null))->run('.$write.'); Assert::true($changed);', true],
    'distinct constructor array reference' => ['$trace = []; $changed = false; (new TraceConstructorRunner($trace, static fn (): null => null))->run(static function (string $stage) use (&$changed, &$trace): void { $trace[] = $stage; if (! $changed && $stage === "checkpoint") { $changed = true; throw new \RuntimeException("Trace write."); } }); Assert::true($changed);', true],
    'readonly documented list promotion' => ['$changed = false; (new PacketRunner([], "plain"))->run('.$write.'); Assert::true($changed);', true],
    'native exception parent constructor' => ['$changed = false; (new CaughtRunner)->run(static function (string $stage) use (&$changed): void { if (! $changed && $stage === "checkpoint") { $changed = true; throw new PacketFault($stage); } }); Assert::true($changed);', true],
    'physical primitive default state' => ['$changed = false; (new DefaultStateRunner)->run('.$write.'); Assert::true($changed);', true],
    'first class void action storage' => ['$worker = new EntryWorker; $changed = false; (new PhysicalActionRunner($worker->idle(...)))->run('.$write.'); Assert::true($changed);', true],
    'first class null action storage' => ['$worker = new EntryWorker; $changed = false; (new PhysicalActionRunner($worker->nothing(...)))->run('.$write.'); Assert::true($changed);', true],
    'first class array action storage' => ['$worker = new EntryWorker; $changed = false; (new PhysicalActionRunner($worker->entries(...)))->run('.$write.'); Assert::true($changed);', true],
    'source string action result contract' => ['$worker = new EntryWorker; $changed = false; (new NormalizedActionRunner($worker->text(...)))->run('.$write.'); Assert::true($changed);', true],
    'unsupported native conditional action result' => ['$worker = new EntryWorker; $changed = false; (new NormalizedActionRunner($worker->conditionalText(...)))->run('.$write.'); Assert::true($changed);', false],
    'shared allocated field state' => ['$worker = new MutableWorker; $changed = false; (new PhysicalActionRunner($worker->process(...)))->run(static function (string $stage) use (&$changed, $worker): void { if ($worker->ready && ! $changed && $stage === "checkpoint") { $changed = true; throw new \RuntimeException("Shared allocation write."); } }); Assert::true($changed);', true],
    'native normalization named callback' => ['$changed = false; (new NamedNormalizationRunner)->run('.$write.'); Assert::true($changed);', true],
    'native physical reflection before origin' => ['$worker = new StorageWorker; $selector = new \ReflectionProperty(StorageWorker::class, "settings"); $selector->setValue($worker, ["ready" => true]); $changed = false; (new PhysicalActionRunner($worker->check(...)))->run('.$write.'); Assert::true($changed);', true],
    'constructor false field prevents invocation' => ['$changed = false; (new PromotedStateRunner("plain", false))->run('.$write.'); Assert::true($changed);', false],
    'physical uninitialized closure has no return' => ['$changed = false; (new UninitializedActionRunner)->run('.$write.'); Assert::true($changed);', false],
    'stored lexical never action' => ['$changed = false; (new PhysicalActionRunner(static function (): never { throw new \RuntimeException("Never action."); }))->run('.$write.'); Assert::true($changed);', false],
    'stored first class never action' => ['$worker = new EntryWorker; $changed = false; (new PhysicalActionRunner($worker->stop(...)))->run('.$write.'); Assert::true($changed);', false],
    'constructor argument violates primitive type' => ['$changed = false; (new PromotedStateRunner([]))->run('.$write.'); Assert::true($changed);', false],
    'constructor assigned field violates type' => ['$changed = false; (new WrongFieldRunner(17))->run('.$write.'); Assert::true($changed);', false],
    'constructor initializes readonly field twice' => ['$changed = false; (new RepeatedReadonlyRunner(static fn (): null => null))->run('.$write.'); Assert::true($changed);', false],
    'selected constructor capability storage' => ['$changed = false; (new StoredSelectedRunner('.$write.'))->run(); Assert::true($changed);', false],
    'selected nested constructor storage' => ['$changed = false; $hook = '.$write.'; (new NestedSelectedRunner([$hook]))->run($hook); Assert::true($changed);', false],
    'unsupported constructor local assignment' => ['$changed = false; (new UnsupportedConstructorRunner("plain"))->run('.$write.'); Assert::true($changed);', false],
    'source constructor inheritance deferred' => ['$changed = false; (new InheritedActionRunner(static fn (): null => null))->run('.$write.'); Assert::true($changed);', false],
    'static physical field contract deferred' => ['$changed = false; (new StaticFieldRunner)->run('.$write.'); Assert::true($changed);', false],
    'physical read hook contract deferred' => ['$changed = false; (new HookFieldRunner)->run('.$write.'); Assert::true($changed);', false],
    'wrong native normalization name' => ['$changed = false; (new WrongNormalizationRunner)->run('.$write.'); Assert::true($changed);', false],
    'wrong native parent constructor name' => ['$changed = false; (new CaughtRunner)->run(static function (string $stage) use (&$changed): void { throw new WrongParentFault($stage); $changed = true; }); Assert::true($changed);', false],
    'physical field write violates type' => ['$worker = new MutableWorker; $changed = false; $worker->ready = []; (new CaughtRunner)->run('.$write.'); Assert::true($changed);', false],
    'external private physical field write' => ['$worker = new PrivateStateWorker; $changed = false; $worker->ready = true; (new CaughtRunner)->run('.$write.'); Assert::true($changed);', false],
    'readonly physical field rewrite' => ['$worker = new ReadonlyStateWorker; $changed = false; $worker->ready = true; (new CaughtRunner)->run('.$write.'); Assert::true($changed);', false],
    'unknown physical receiver contract' => ['$worker = new \stdClass; $changed = false; $worker->ready = true; (new CaughtRunner)->run('.$write.'); Assert::true($changed);', false],
    'dynamic physical field write deferred' => ['$worker = new MutableWorker; $field = "ready"; $changed = false; $worker->$field = true; (new CaughtRunner)->run('.$write.'); Assert::true($changed);', false],
    'selected capability post constructor storage' => ['$runner = new PhysicalSelectedRunner(static fn (): null => null); $changed = false; $hook = '.$write.'; $runner->action = $hook; $runner->run($hook); Assert::true($changed);', false],
    'allocated field final reset prevents write' => ['$worker = new MutableWorker; $worker->process(); $changed = false; (new PhysicalActionRunner($worker->reset(...)))->run(static function (string $stage) use (&$changed, $worker): void { if ($worker->ready) { $changed = true; throw new \RuntimeException("Unreachable reset write."); } }); Assert::true($changed);', false],
    'reflection readonly field deferred' => ['$worker = new ReadonlyStorageWorker; $selector = new \ReflectionProperty(ReadonlyStorageWorker::class, "settings"); $selector->setValue($worker, ["ready" => true]); $changed = false; (new CaughtRunner)->run('.$write.'); Assert::true($changed);', false],
    'reflection static field deferred' => ['$worker = new StaticStorageWorker; $selector = new \ReflectionProperty(StaticStorageWorker::class, "settings"); $selector->setValue($worker, ["ready" => true]); $changed = false; (new CaughtRunner)->run('.$write.'); Assert::true($changed);', false],
    'reflection hooked field deferred' => ['$worker = new HookStorageWorker; $selector = new \ReflectionProperty(HookStorageWorker::class, "settings"); $selector->setValue($worker, ["ready" => true]); $changed = false; (new CaughtRunner)->run('.$write.'); Assert::true($changed);', false],
    'reflection inherited field deferred' => ['$worker = new InheritedStorageWorker; $selector = new \ReflectionProperty(InheritedStorageWorker::class, "settings"); $selector->setValue($worker, ["ready" => true]); $changed = false; (new CaughtRunner)->run('.$write.'); Assert::true($changed);', false],
    'reflection unknown selector deferred' => ['$worker = new StorageWorker; $field = "settings"; $selector = new \ReflectionProperty(StorageWorker::class, $field); $selector->setValue($worker, ["ready" => true]); $changed = false; (new CaughtRunner)->run('.$write.'); Assert::true($changed);', false],
    'reflection wrong value type deferred' => ['$worker = new StorageWorker; $selector = new \ReflectionProperty(StorageWorker::class, "settings"); $selector->setValue($worker, false); $changed = false; (new CaughtRunner)->run('.$write.'); Assert::true($changed);', false],
    'reflection wrong receiver deferred' => ['$worker = new MutableWorker; $selector = new \ReflectionProperty(StorageWorker::class, "settings"); $selector->setValue($worker, ["ready" => true]); $changed = false; (new CaughtRunner)->run('.$write.'); Assert::true($changed);', false],
    'reflection after selected origin deferred' => ['$worker = new StorageWorker; $changed = false; $selector = new \ReflectionProperty(StorageWorker::class, "settings"); $selector->setValue($worker, ["ready" => true]); (new CaughtRunner)->run('.$write.'); Assert::true($changed);', false],
    'reflection selected reference storage deferred' => ['$worker = new StorageWorker; $selector = new \ReflectionProperty(StorageWorker::class, "settings"); $changed = false; $selector->setValue($worker, ['.$write.']); (new CaughtRunner)->run('.$write.'); Assert::true($changed);', false],
    'array reference maximum key append cannot return' => ['$trace = [9223372036854775807 => "occupied"]; $changed = false; (new CaughtRunner)->run(static function (string $stage) use (&$changed, &$trace): void { $trace[] = $stage; $changed = true; throw new \RuntimeException("After invalid append."); }); Assert::true($changed);', false],
    'non throwable token cannot establish caught return' => ['$changed = false; try { (new DirectRunner)->run(static function (string $stage) use (&$changed): void { $changed = true; throw new PlainToken; }); } catch (PlainToken) {} Assert::true($changed);', false],
    'parenthesized physical closure invocation' => ['$box = new PhysicalSelectedRunner(static fn (): null => null); $changed = false; ($box->action)(); (new CaughtRunner)->run('.$write.'); Assert::true($changed);', true],
    'same named method is not a closure field call' => ['$box = new PhysicalSelectedRunner(static fn (): null => null); $changed = false; $box->action(); (new CaughtRunner)->run('.$write.'); Assert::true($changed);', false],
    'allocated reflected false field remains false' => ['$worker = new MutableWorker; $selector = new \ReflectionProperty(MutableWorker::class, "ready"); $selector->setValue($worker, false); $changed = false; (new PromotedStateRunner("plain", $worker->ready))->run('.$write.'); Assert::true($changed);', false],
    'opaque reflected false field cannot be forgotten' => ['$selector = new \ReflectionProperty(MutableWorker::class, "ready"); $selector->setValue($input, false); $changed = false; (new PromotedStateRunner("plain", $input->ready))->run('.$write.'); Assert::true($changed);', false],
    'readonly opaque field assertion stays correlated' => ['Assert::true($flags->ready); $changed = false; (new CaughtRunner)->run(static function (string $stage) use (&$changed, $flags): void { if (! $flags->ready) { $changed = true; throw new \RuntimeException("Contradicts readonly prefix."); } }); Assert::true($changed);', false],
    'ordinary allocated reset method invalidates state' => ['$worker = new MutableWorker; $worker->ready = true; $changed = false; $worker->reset(); (new CaughtRunner)->run(static function (string $stage) use (&$changed, $worker): void { if ($worker->ready) { $changed = true; throw new \RuntimeException("After ordinary method reset."); } }); Assert::true($changed);', false],
    'ordinary allocated reset function invalidates state' => ['$worker = new MutableWorker; $worker->ready = true; $changed = false; resetAllocated($worker); (new CaughtRunner)->run(static function (string $stage) use (&$changed, $worker): void { if ($worker->ready) { $changed = true; throw new \RuntimeException("After ordinary function reset."); } }); Assert::true($changed);', false],
    'ordinary nested allocated array invalidates state' => ['$worker = new MutableWorker; $worker->ready = true; $workers = [$worker]; $changed = false; resetAllocatedArray($workers); (new CaughtRunner)->run(static function (string $stage) use (&$changed, $worker): void { if ($worker->ready) { $changed = true; throw new \RuntimeException("After nested reset."); } }); Assert::true($changed);', false],
    'ordinary lexical allocated capture invalidates state' => ['$worker = new MutableWorker; $worker->ready = true; $changed = false; invokeUntracked(static function () use ($worker): void { $worker->ready = false; }); (new CaughtRunner)->run(static function (string $stage) use (&$changed, $worker): void { if ($worker->ready) { $changed = true; throw new \RuntimeException("After captured reset."); } }); Assert::true($changed);', false],
    'ordinary method callback receiver invalidates state' => ['$worker = new MutableWorker; $worker->ready = true; $changed = false; invokeUntracked($worker->reset(...)); (new CaughtRunner)->run(static function (string $stage) use (&$changed, $worker): void { if ($worker->ready) { $changed = true; throw new \RuntimeException("After method capability reset."); } }); Assert::true($changed);', false],
    'ordinary captured heap array invalidates state' => ['$worker = new MutableWorker; $worker->ready = true; $workers = [$worker]; $changed = false; invokeUntracked(static function () use ($workers): void { $workers[0]->ready = false; }); (new CaughtRunner)->run(static function (string $stage) use (&$changed, $worker): void { if ($worker->ready) { $changed = true; throw new \RuntimeException("After captured array reset."); } }); Assert::true($changed);', false],
    'ordinary captured heap reference invalidates state' => ['$worker = new MutableWorker; $worker->ready = true; $workers = [$worker]; $changed = false; invokeUntracked(static function () use (&$workers): void { $workers[0]->ready = false; }); (new CaughtRunner)->run(static function (string $stage) use (&$changed, $worker): void { if ($worker->ready) { $changed = true; throw new \RuntimeException("After captured reference reset."); } }); Assert::true($changed);', false],
    'ordinary by reference heap array invalidates state' => ['$worker = new MutableWorker; $worker->ready = true; $workers = [$worker]; $changed = false; resetAllocatedReference($workers); (new CaughtRunner)->run(static function (string $stage) use (&$changed, $worker): void { if ($worker->ready) { $changed = true; throw new \RuntimeException("After by-reference reset."); } }); Assert::true($changed);', false],
];
$callerSource = "<?php\nnamespace Fixture;\nuse Testo\\Assert;\nfinal class ReferenceScenarios {\n";
$ranges = [];
foreach ($cases as $label => [$body]) {
    $start = strlen($callerSource);
    $callerSource .= 'public function scenario'.count($ranges).'(ReadonlyFlags $flags, MutableWorker $input, bool $condition = true): void { '.$body." }\n";
    $ranges[$label] = [$start, strlen($callerSource)];
}
$callerSource .= "}\n";
file_put_contents($workspace.'/cases.php', $callerSource);
file_put_contents($workspace.'/expected-domains.json', json_encode(array_map(
    static fn (array $range, string $label): array => [$label, $range, $cases[$label][1]],
    array_values($ranges), array_keys($ranges),
), JSON_THROW_ON_ERROR));
if (in_array('--object-state-probe', $argv, true)) {
    $stateSource = "<?php\nnamespace Fixture;\nuse Testo\\Assert;\nfinal class ReferenceStateProbe {\n";
    $stateRanges = [];
    foreach ($cases as $label => [$body]) {
        if (! str_starts_with($label, 'ordinary ') || ! str_ends_with($label, 'invalidates state')) { continue; }
        $start = strlen($stateSource);
        $stateSource .= 'public function scenario'.count($stateRanges).'(): void { '.$body." }\n";
        $stateRanges[$label] = [$start, strlen($stateSource)];
    }
    $stateSource .= "}\n";
    file_put_contents($workspace.'/state-cases.php', $stateSource);
    file_put_contents($workspace.'/state-ranges.json', json_encode($stateRanges, JSON_THROW_ON_ERROR));
}
file_put_contents($workspace.'/proof.php', <<<'PHP'
<?php
namespace Fixture;
use Testo\Assert;
final class ReferenceProof {
    public function check(): void {
        $changed = false;
        (new ForwardRunner)->run(static function (string $stage) use (&$changed): void {
            if (! $changed && $stage === 'checkpoint') {
                $changed = true;
                throw new \RuntimeException('Synthetic proof failure.');
            }
        });
        Assert::true($changed);
    }
    public function normalReturnAlreadyHandled(): void {
        $changed = false;
        (new NativeRunner)->run(static function (string $stage) use (&$changed): void { $changed = true; });
        Assert::true($changed);
    }
    public function finallyResetAlreadyHandled(): void {
        $changed = false;
        (new CaughtRunner)->run(static function (string $stage) use (&$changed): void {
            try { $changed = true; throw new \RuntimeException('Before finally.'); }
            finally { $changed = false; }
        });
        Assert::true($changed);
    }
}
PHP);
$validateSyntax = static function (array $paths) use ($workspace): void {
    foreach ($paths as $sourcePath) {
        $syntaxProcess = proc_open([PHP_BINARY, '-d', 'opcache.enable_cli=0', '-l', $workspace.'/'.$sourcePath], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $syntaxPipes, $workspace);
        if (! is_resource($syntaxProcess)) { throw new RuntimeException('Cannot validate invented fixture syntax: '.$sourcePath); }
        fclose($syntaxPipes[0]);
        $syntaxOutput = stream_get_contents($syntaxPipes[1]); fclose($syntaxPipes[1]);
        $syntaxError = stream_get_contents($syntaxPipes[2]); fclose($syntaxPipes[2]);
        if (proc_close($syntaxProcess) !== 0 || $syntaxError !== '') { throw new RuntimeException('Invalid invented fixture source: '.$sourcePath.' '.$syntaxOutput.$syntaxError); }
    }
};
$validateSyntax(['cases.php', 'runners.php', 'proof.php', 'support.php', $nativePath]);
if (file_exists($workspace.'/state-cases.php')) { $validateSyntax(['state-cases.php']); }
file_put_contents($workspace.'/worker.php', <<<'PHP'
<?php
require $argv[1];
$plugin = new class($argv[2], $argv[3]) implements \Mago\Sdk\Analyzer\Plugin {
    public function __construct(private string $root, private string $mode) {}
    public function getDefinition(): \Mago\Sdk\Analyzer\PluginDefinition {
        return new \Mago\Sdk\Analyzer\PluginDefinition('fixture/direct-references', 'Direct references', 'Source-proven captured reference effects');
    }
    public function register(\Mago\Sdk\Analyzer\PluginRegistry $registry): void {
        if ($this->mode === 'control') { return; }
        $index = new \Ichinya\Laramago\Analyzer\StaticAnalysis\DirectCallbackReferenceEffects($this->root);
        $registry->registerInitializationHook($index);
        $registry->registerCodebaseScanHook($index);
        $filter = new \Ichinya\Laramago\Analyzer\DirectCallbackReferenceIssueFilter($index);
        if ($this->mode === 'object-state-probe') {
            $registry->registerAfterFileAnalysisHook(new class($index, $this->root) implements \Mago\Sdk\Analyzer\AfterFileAnalysisHook {
                public function __construct(private $index, private string $root) {}
                public function getRequirements(): array { return []; }
                public function afterFileAnalysis(\Mago\Sdk\Analyzer\AfterFileAnalysisContext $context): void {
                    if ($context->analysis->file !== 'state-cases.php') { return; }
                    $observations = [];
                    foreach ($this->index->proofs('state-cases.php', $context->analysis->getSourceFile()->contents) as $proof) {
                        $domain = $this->index->domain($context->codebase, $context->types, $proof);
                        $observations[] = ['method' => $proof['scope']->name->name, 'assertionStart' => $proof['assertion']->getStartFilePos(),
                            'domain' => $domain === null ? null : (string) $domain,
                            'admitsExpected' => $domain !== null && $context->types->isContainedBy($proof['expected'] ? \Mago\Sdk\Analyzer\Type::true() : \Mago\Sdk\Analyzer\Type::false(), $domain)];
                    }
                    file_put_contents($this->root.'/object-state-domains.json', json_encode($observations, JSON_THROW_ON_ERROR));
                }
            });
        }
        if ($this->mode === 'standalone') {
            $registry->registerAfterFileAnalysisHook(new class($index, $this->root) implements \Mago\Sdk\Analyzer\AfterFileAnalysisHook {
                public function __construct(private $index, private string $root) {}
                public function getRequirements(): array { return []; }
                public function afterFileAnalysis(\Mago\Sdk\Analyzer\AfterFileAnalysisContext $context): void {
                    if ($context->analysis->file !== 'cases.php') { return; }
                    $contents = $context->analysis->getSourceFile()->contents;
                    $ranges = json_decode(file_get_contents($this->root.'/expected-domains.json'), true, flags: JSON_THROW_ON_ERROR);
                    $checks = 0;
                    foreach ($this->index->proofs('cases.php', $contents) as $proof) {
                        $offset = $proof['assertion']->getStartFilePos();
                        foreach ($ranges as [$label, [$start, $end], $positive]) {
                            if ($positive || $offset < $start || $offset >= $end) { continue; }
                            $domain = $this->index->domain($context->codebase, $context->types, $proof);
                            $expected = $proof['expected'] ? \Mago\Sdk\Analyzer\Type::true() : \Mago\Sdk\Analyzer\Type::false();
                            if ($domain !== null && $context->types->isContainedBy($expected, $domain)) { throw new \RuntimeException('Unsafe negative source-domain witness: '.$label); }
                            $checks++;
                            break;
                        }
                    }
                    file_put_contents($this->root.'/negative-domain-checks.log', $checks."\n", FILE_APPEND);
                }
            });
        }
        if ($this->mode !== 'probe') { $registry->registerIssueFilterHook($filter); return; }
        $registry->registerIssueFilterHook(new class($index, $filter, $this->root) implements \Mago\Sdk\Analyzer\IssueFilterHook {
            private bool $observed = false;
            public function __construct(private $index, private $filter, private string $root) {}
            public function getCodes(): array { return $this->filter->getCodes(); }
            public function filterIssue(\Mago\Sdk\Analyzer\IssueFilterContext $context): \Mago\Sdk\Analyzer\IssueFilterDecision {
                if (!$this->observed) {
                    $this->observed = true;
                    $class = $context->codebase->getClassLike('Closure');
                    $method = $context->codebase->getMethod('Closure', 'fromCallable');
                    $candidates = $this->index->proofs($context->file, $context->contents);
                    $matching = [];
                    foreach ($candidates as $proof) {
                        $assertion = $proof['assertion'];
                        if ($assertion->getStartFilePos() !== $context->issue->annotations[0]->span->start) { continue; }
                        $domain = $this->index->domain($context->codebase, $context->types, $proof);
                        $matching[] = [$proof['local'], $proof['initial'], array_map(get_debug_type(...), $domain?->atomicTypes ?? [])];
                    }
                    file_put_contents($this->root.'/probe-metadata.log', var_export([
                        'class' => $class, 'method' => $method, 'file' => $context->file,
                        'candidates' => count($candidates), 'matching' => $matching, 'issue' => $context->issue,
                    ], true));
                }
                return $this->filter->filterIssue($context);
            }
        });
    }
};
$plugins = [$plugin];
if ($argv[3] === 'existing-policies') { $plugins[] = new \Ichinya\Laramago\Analyzer\ArgumentClosurePossibleWritePlugin($argv[2]); }
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension(identifier: 'fixture/direct-references', name: 'Direct references', version: '1', analyzerPlugins: $plugins)))->run();
PHP);
file_put_contents($workspace.'/proof-worker.php', <<<'PHP'
<?php
require $argv[1];
$plugin = new class($argv[2]) implements \Mago\Sdk\Analyzer\Plugin {
    public function __construct(private string $root) {}
    public function getDefinition(): \Mago\Sdk\Analyzer\PluginDefinition {
        return new \Mago\Sdk\Analyzer\PluginDefinition('fixture/reference-contexts', 'Reference contexts', 'Exact native diagnostic and captured storage controls');
    }
    public function register(\Mago\Sdk\Analyzer\PluginRegistry $registry): void {
        $index = new \Ichinya\Laramago\Analyzer\StaticAnalysis\DirectCallbackReferenceEffects($this->root);
        $registry->registerInitializationHook($index);
        $registry->registerCodebaseScanHook($index);
        $filter = new \Ichinya\Laramago\Analyzer\DirectCallbackReferenceIssueFilter($index);
        $registry->registerAfterFileAnalysisHook(new class($index, $this->root) implements \Mago\Sdk\Analyzer\AfterFileAnalysisHook {
            public function __construct(private $index, private string $root) {}
            public function getRequirements(): array { return []; }
            public function afterFileAnalysis(\Mago\Sdk\Analyzer\AfterFileAnalysisContext $context): void {
                if ($context->analysis->file !== 'proof.php') { return; }
                $contents = $context->analysis->getSourceFile()->contents;
                $checks = 0;
                foreach ($this->index->proofs('proof.php', $contents) as $proof) {
                    $method = $proof['scope']->name->name;
                    if (!in_array($method, ['normalReturnAlreadyHandled', 'finallyResetAlreadyHandled'], true)) { continue; }
                    $domain = $this->index->domain($context->codebase, $context->types, $proof);
                    $expected = $method === 'normalReturnAlreadyHandled' ? \Mago\Sdk\Analyzer\Type::true() : \Mago\Sdk\Analyzer\Type::false();
                    if ($domain === null || !$context->types->equals($domain, $expected)) { throw new \RuntimeException('Incorrect native-covered final domain: '.$method); }
                    $checks++;
                }
                if ($checks !== 2) { throw new \RuntimeException('Missing native-covered source-domain controls.'); }
                file_put_contents($this->root.'/native-covered-checks.log', $checks."\n", FILE_APPEND);
                $planChecks = 0;
                $cache = (new \ReflectionProperty($context->codebase, 'cache'))->getValue($context->codebase);
                $replace = static function ($original, $changed) use ($cache): int {
                    $count = 0;
                    foreach ($cache->values as $operation => $entries) {
                        foreach ($entries as $key => $entry) {
                            if ($entry === $original || $original instanceof \Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata
                                && $entry instanceof \Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata && strcasecmp($entry->name, $original->name) === 0) {
                                $cache->values[$operation][$key] = $changed; $count++;
                            }
                        }
                    }
                    return $count;
                };
                $construct = fn (string $name) => \Ichinya\Laramago\Analyzer\StaticAnalysis\CallbackConstructorPlan::plan(
                    $context->codebase, $context->types, clone $this->index, 'Fixture\\'.$name);
                $reflect = fn (string $name) => \Ichinya\Laramago\Analyzer\StaticAnalysis\NativeCallbackReflection::verify(
                    $context->codebase, $context->types, $this->root, 'Fixture\\'.$name, 'settings');
                foreach ([
                    ['PromotedStateRunner', '$enabled', $construct],
                    ['PhysicalActionRunner', '$action', $construct],
                    ['PacketRunner', '$failures', $construct],
                    ['PacketRunner', '$attempts', $construct],
                    ['MutableWorker', '$ready', $construct],
                    ['StorageWorker', '$settings', $reflect],
                    ['DocumentedStorageWorker', '$settings', $reflect],
                    ['UntypedStorageWorker', '$settings', $reflect],
                ] as [$name, $fieldName, $prove]) {
                    if ($prove($name) === null) { throw new \RuntimeException('Missing genuine constructor or reflection declaration: '.$name.' '.$fieldName); }
                    $planChecks++;
                    $field = $context->codebase->getDeclaringProperty('Fixture\\'.$name, $fieldName);
                    if ($field === null || $field->type === null) { throw new \RuntimeException('Missing genuine physical field metadata.'); }
                    $variants = ['name', 'name-file', 'name-span', 'body-file', 'effective-type', 'effective-file', 'effective-span', 'effective-doc', 'write-type', 'static', 'virtual', 'readonly', 'asymmetric', 'hooks', 'visibility', 'write-visibility', 'default-flag'];
                    if ($field->declaredType !== null) { $variants = [...$variants, 'declared-type', 'declared-file', 'declared-span', 'declared-doc']; }
                    if ($field->defaultType !== null) { $variants = [...$variants, 'default-type', 'default-file', 'default-span', 'default-doc', 'missing-default']; }
                    else { $variants[] = 'invented-default'; }
                    foreach ($variants as $variant) {
                        $snapshot = $cache->values;
                        $values = get_object_vars($field);
                        if ($variant === 'name') { $values['name'] = '$different'; }
                        if ($variant === 'name-file') { $values['nameLocation'] = new \Mago\Sdk\SourceLocation('foreign.php', $field->nameLocation->span); }
                        if ($variant === 'name-span') { $values['nameLocation'] = new \Mago\Sdk\SourceLocation($field->nameLocation->file, new \Mago\Sdk\Span($field->nameLocation->span->start + 1, $field->nameLocation->span->end)); }
                        if ($variant === 'body-file') { $values['location'] = new \Mago\Sdk\SourceLocation('foreign.php', $field->location?->span ?? $field->nameLocation->span); }
                        foreach (['effective' => 'type', 'declared' => 'declaredType', 'default' => 'defaultType'] as $prefix => $key) {
                            $type = $field->$key;
                            if ($type === null || ! str_starts_with($variant, $prefix.'-')) { continue; }
                            $typeValues = get_object_vars($type);
                            if ($variant === $prefix.'-type') { $typeValues['type'] = \Mago\Sdk\Analyzer\Type::never(); }
                            if ($variant === $prefix.'-file') { $typeValues['location'] = new \Mago\Sdk\SourceLocation('foreign.php', $type->location->span); }
                            if ($variant === $prefix.'-span') { $typeValues['location'] = new \Mago\Sdk\SourceLocation($type->location->file, new \Mago\Sdk\Span($type->location->span->start + 1, $type->location->span->end)); }
                            if ($variant === $prefix.'-doc') { $typeValues['fromDocblock'] = ! $type->fromDocblock; }
                            $values[$key] = new \Mago\Sdk\Analyzer\Metadata\TypeMetadata(...$typeValues);
                        }
                        if ($variant === 'write-type') { $values['writeType'] = new \Mago\Sdk\Analyzer\Metadata\TypeMetadata($field->type->location, \Mago\Sdk\Analyzer\Type::never(), false, false); }
                        if ($variant === 'static') { $values['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($field->flags->bits | \Mago\Sdk\Analyzer\Metadata\MetadataFlags::STATIC); }
                        if ($variant === 'virtual') { $values['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($field->flags->bits | \Mago\Sdk\Analyzer\Metadata\MetadataFlags::VIRTUAL_PROPERTY); }
                        if ($variant === 'readonly') { $values['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($field->flags->bits ^ \Mago\Sdk\Analyzer\Metadata\MetadataFlags::READONLY); }
                        if ($variant === 'asymmetric') { $values['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($field->flags->bits ^ \Mago\Sdk\Analyzer\Metadata\MetadataFlags::ASYMMETRIC_PROPERTY); }
                        if ($variant === 'hooks') { $values['hooks'] = ['get' => new \Mago\Sdk\Analyzer\Metadata\PropertyHookMetadata('get', $field->nameLocation, new \Mago\Sdk\Analyzer\Metadata\MetadataFlags(0), null, false, false, [], $field->type, false)]; }
                        if ($variant === 'visibility') { $values['readVisibility'] = $field->readVisibility === \Mago\Sdk\Analyzer\Type\Visibility::Public ? \Mago\Sdk\Analyzer\Type\Visibility::Private : \Mago\Sdk\Analyzer\Type\Visibility::Public; }
                        if ($variant === 'write-visibility') { $values['writeVisibility'] = $field->writeVisibility === \Mago\Sdk\Analyzer\Type\Visibility::Public ? \Mago\Sdk\Analyzer\Type\Visibility::Private : \Mago\Sdk\Analyzer\Type\Visibility::Public; }
                        if ($variant === 'default-flag') { $values['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($field->flags->bits ^ \Mago\Sdk\Analyzer\Metadata\MetadataFlags::HAS_DEFAULT); }
                        if ($variant === 'missing-default') { $values['defaultType'] = null; }
                        if ($variant === 'invented-default') { $values['defaultType'] = new \Mago\Sdk\Analyzer\Metadata\TypeMetadata($field->type->location, \Mago\Sdk\Analyzer\Type::null(), false, true); }
                        $changed = new \Mago\Sdk\Analyzer\Metadata\PropertyMetadata(...$values);
                        try {
                            if ($replace($field, $changed) === 0) { throw new \RuntimeException('Physical metadata absent from genuine Codebase cache.'); }
                            if ($prove($name) !== null) { throw new \RuntimeException('Physical declaration accepted stale '.$name.' '.$fieldName.' '.$variant); }
                            $planChecks++;
                        } finally { $cache->values = $snapshot; }
                    }
                    if ($prove($name) === null) { throw new \RuntimeException('Physical declaration controls failed to restore the native proof.'); }
                }
                foreach (['ReadonlyStorageWorker', 'StaticStorageWorker', 'HookStorageWorker', 'InheritedStorageWorker'] as $name) {
                    if ($reflect($name) !== null) { throw new \RuntimeException('Unsupported physical reflection field accepted: '.$name); }
                    $planChecks++;
                }
                if (\Ichinya\Laramago\Analyzer\StaticAnalysis\NativeCallbackReflection::verify($context->codebase, $context->types, $this->root, 'Fixture\\StorageWorker', 'absent') !== null) { throw new \RuntimeException('Missing reflection field accepted.'); }
                $planChecks++;
                foreach (['namespace', 'field-name', 'field-syntax', 'documented-domain', 'default'] as $variant) {
                    $file = $this->root.'/runners.php';
                    $original = file_get_contents($file);
                    [$from, $to] = match ($variant) {
                        'namespace' => ['namespace Fixture;', 'namespace Fiction;'],
                        'field-name' => ['protected array $settings', 'protected array $settting'],
                        'field-syntax' => ['protected array $settings', 'protected mixed $settings'],
                        'documented-domain' => ['array<string, mixed>', 'array<string, never>'],
                        'default' => ['protected array $settings = [];', 'protected array $settings = 17;'],
                    };
                    $changed = str_replace($from, $to, $original, $count);
                    if ($count === 0 || strlen($changed) !== strlen($original)) { throw new \RuntimeException('Invalid physical source snapshot control.'); }
                    file_put_contents($file, $changed);
                    try {
                        $name = $variant === 'documented-domain' ? 'DocumentedStorageWorker' : 'StorageWorker';
                        if ($reflect($name) !== null) { throw new \RuntimeException('Reflection consumed stale source: '.$variant); }
                        $planChecks++;
                    } finally { file_put_contents($file, $original); }
                }
                foreach ([['__construct', 'constructor'], ['setValue', 'setter']] as [$method, $role]) {
                    $metadata = $context->codebase->getMethod('ReflectionProperty', $method);
                    if ($metadata === null) { throw new \RuntimeException('Missing genuine native ReflectionProperty signature.'); }
                    foreach (['owner', 'kind', 'visibility', 'static', 'user-defined', 'not-builtin', 'return', 'parameter-name', 'parameter-type', 'parameter-reference', 'parameter-variadic', 'parameter-default'] as $variant) {
                        $snapshot = $cache->values;
                        $values = get_object_vars($metadata);
                        if ($variant === 'owner') { $values['identifier'] = new \Mago\Sdk\Analyzer\Type\FunctionLikeIdentifier($metadata->identifier->kind, $metadata->identifier->name, 'OtherReflection'); }
                        if ($variant === 'kind') { $values['kind'] = \Mago\Sdk\Analyzer\Metadata\FunctionLikeKind::Closure; }
                        if ($variant === 'visibility') { $values['visibility'] = \Mago\Sdk\Analyzer\Type\Visibility::Private; }
                        if ($variant === 'static') { $values['static'] = true; }
                        if ($variant === 'user-defined') { $values['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($metadata->flags->bits | \Mago\Sdk\Analyzer\Metadata\MetadataFlags::USER_DEFINED); }
                        if ($variant === 'not-builtin') { $values['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($metadata->flags->bits & ~\Mago\Sdk\Analyzer\Metadata\MetadataFlags::BUILTIN); }
                        if ($variant === 'return') { $values['returnType'] = new \Mago\Sdk\Analyzer\Metadata\TypeMetadata($metadata->location, \Mago\Sdk\Analyzer\Type::never(), false, false); }
                        if (str_starts_with($variant, 'parameter-')) {
                            $parameter = $metadata->parameters[0]; $parameterValues = get_object_vars($parameter);
                            if ($variant === 'parameter-name') { $parameterValues['name'] = '$other'; }
                            if ($variant === 'parameter-type') { $parameterValues['type'] = new \Mago\Sdk\Analyzer\Metadata\TypeMetadata($parameter->type->location, \Mago\Sdk\Analyzer\Type::never(), false, false); }
                            foreach (['reference' => \Mago\Sdk\Analyzer\Metadata\MetadataFlags::BY_REFERENCE, 'variadic' => \Mago\Sdk\Analyzer\Metadata\MetadataFlags::VARIADIC, 'default' => \Mago\Sdk\Analyzer\Metadata\MetadataFlags::HAS_DEFAULT] as $suffix => $bit) {
                                if ($variant === 'parameter-'.$suffix) { $parameterValues['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($parameter->flags->bits ^ $bit); }
                            }
                            $values['parameters'][0] = new \Mago\Sdk\Analyzer\Metadata\ParameterMetadata(...$parameterValues);
                        }
                        $changed = new \Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata(...$values);
                        try {
                            if ($replace($metadata, $changed) === 0) { throw new \RuntimeException('Native reflection signature absent from cache.'); }
                            if ($reflect('StorageWorker') !== null) { throw new \RuntimeException('Reflection accepted altered '.$role.' '.$variant); }
                            $planChecks++;
                        } finally { $cache->values = $snapshot; }
                    }
                }
                $nativeClass = $context->codebase->getClassLike('ReflectionProperty');
                if ($nativeClass === null) { throw new \RuntimeException('Missing native ReflectionProperty class metadata.'); }
                foreach (['kind', 'name', 'original-name', 'file', 'user-defined', 'not-builtin', 'final', 'readonly', 'parent', 'interfaces', 'templates'] as $variant) {
                    $snapshot = $cache->values; $values = get_object_vars($nativeClass);
                    if ($variant === 'kind') { $values['kind'] = \Mago\Sdk\Analyzer\Metadata\ClassLikeKind::Trait; }
                    if ($variant === 'name') { $values['name'] = 'OtherReflection'; }
                    if ($variant === 'original-name') { $values['originalName'] = 'OtherReflection'; }
                    if ($variant === 'file') { $values['location'] = new \Mago\Sdk\SourceLocation('foreign.php', $nativeClass->location->span); }
                    if ($variant === 'user-defined') { $values['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($nativeClass->flags->bits | \Mago\Sdk\Analyzer\Metadata\MetadataFlags::USER_DEFINED); }
                    if ($variant === 'not-builtin') { $values['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($nativeClass->flags->bits & ~\Mago\Sdk\Analyzer\Metadata\MetadataFlags::BUILTIN); }
                    if ($variant === 'final') { $values['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($nativeClass->flags->bits | \Mago\Sdk\Analyzer\Metadata\MetadataFlags::FINAL); }
                    if ($variant === 'readonly') { $values['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($nativeClass->flags->bits | \Mago\Sdk\Analyzer\Metadata\MetadataFlags::READONLY); }
                    if ($variant === 'parent') { $values['directParentClass'] = 'OtherReflection'; }
                    if ($variant === 'interfaces') { $values['directParentInterfaces'] = []; }
                    if ($variant === 'templates') { $values['templates'] = []; }
                    $changed = new \Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata(...$values);
                    try {
                        if ($replace($nativeClass, $changed) < 2) { throw new \RuntimeException('Native reflection class cache aliases were not both replaced.'); }
                        if ($reflect('StorageWorker') !== null) { throw new \RuntimeException('Reflection accepted altered native class '.$variant); }
                        $planChecks++;
                    } finally { $cache->values = $snapshot; }
                }
                if ($reflect('StorageWorker') === null) { throw new \RuntimeException('Native reflection class controls failed to restore cached aliases.'); }
                file_put_contents($this->root.'/construction-reflection-checks.log', $planChecks."\n", FILE_APPEND);
            }
        });
        $registry->registerIssueFilterHook(new class($filter, $index, $this->root) implements \Mago\Sdk\Analyzer\IssueFilterHook {
            private bool $checked = false;
            public function __construct(private $filter, private $index, private string $root) {}
            public function getCodes(): array { return $this->filter->getCodes(); }
            public function filterIssue(\Mago\Sdk\Analyzer\IssueFilterContext $context): \Mago\Sdk\Analyzer\IssueFilterDecision {
                if ($this->checked || $context->file !== 'proof.php') { return $this->filter->filterIssue($context); }
                $checks = 0;
                $keep = static function ($filter, $probe, string $label) use (&$checks): void {
                    if ($filter->filterIssue($probe) !== \Mago\Sdk\Analyzer\IssueFilterDecision::Keep) { throw new \RuntimeException('Reference context accepted '.$label); }
                    $checks++;
                };
                $sourceChanges = [
                    'caller capture identity' => ['proof.php', 'use (&$changed)', 'use ( $changed)'],
                    'caller assigned literal' => ['proof.php', '$changed = true;', '$changed = null;'],
                    'callee literal stage' => ['runners.php', '"checkpoint"', '"unrelated_"'],
                    'callee namespace' => ['runners.php', 'namespace Fixture;', 'namespace Fiction;'],
                    'callee import' => ['runners.php', 'use Closure;', 'use Invalid;'],
                    'callee parameter default' => ['runners.php', '$before = null', '$before = true'],
                    'callee final dispatch' => ['runners.php', 'final class ForwardRunner', '      class ForwardRunner'],
                    'private helper identity' => ['runners.php', 'private function attempt', 'public  function attempt'],
                    'native assertion binding' => ['packages/testo/assert/Assert.php', 'use Testo\\Assert\\Internal\\StaticState;', 'use Testo\\Assert\\External\\StaticState;'],
                    'native assertion namespace' => ['packages/testo/assert/Assert.php', 'namespace Testo;', 'namespace Other;'],
                    'native assertion body' => ['packages/testo/assert/Assert.php', '$actual === true', '$actual !== true'],
                ];
                foreach ($sourceChanges as $label => [$path, $from, $to]) {
                    $file = $this->root.'/'.$path;
                    $bytes = file_get_contents($file);
                    $changed = str_replace($from, $to, $bytes, $count);
                    if ($count === 0 || strlen($changed) !== strlen($bytes)) { throw new \RuntimeException('Invalid equal-length source control: '.$label); }
                    $probe = new \Ichinya\Laramago\Analyzer\DirectCallbackReferenceIssueFilter(clone $this->index);
                    file_put_contents($file, $changed);
                    try { $keep($probe, $context, $label); }
                    finally { file_put_contents($file, $bytes); }
                }
                $result = $this->filter->filterIssue($context);
                if ($result !== \Mago\Sdk\Analyzer\IssueFilterDecision::Remove) { throw new \RuntimeException('Missing source-proven context control. '.$this->root); }
                $this->checked = true;
                foreach (['span-start', 'span-end', 'foreign-annotation', 'duplicate-annotation', 'secondary', 'annotation-message', 'code', 'level', 'message', 'actual-type', 'expected-type', 'notes', 'help', 'link', 'edits', 'context-file', 'context-source'] as $variant) {
                    $annotations = $context->issue->annotations;
                    $annotation = $annotations[0];
                    $annotations[0] = new \Mago\Sdk\Reporting\Annotation(
                        $variant === 'secondary' ? \Mago\Sdk\Reporting\AnnotationKind::Secondary : $annotation->kind,
                        new \Mago\Sdk\Span($annotation->span->start + ($variant === 'span-start' ? 1 : 0), $annotation->span->end + ($variant === 'span-end' ? 1 : 0)),
                        $variant === 'annotation-message' ? 'Unrelated captured storage.' : $annotation->message,
                        $variant === 'foreign-annotation' ? 'other.php' : $annotation->file);
                    if ($variant === 'duplicate-annotation') { $annotations[] = $annotation; }
                    $message = match ($variant) {
                        'message' => 'Unrelated boolean assertion.',
                        'actual-type' => str_replace('of type `false`', 'of type `bool`', $context->issue->message),
                        'expected-type' => str_replace('`true|true`', '`false|false`', $context->issue->message),
                        default => $context->issue->message,
                    };
                    if (in_array($variant, ['actual-type', 'expected-type'], true) && $message === $context->issue->message) { throw new \RuntimeException('Missing exact native boolean diagnostic grammar.'); }
                    $issue = new \Mago\Sdk\Reporting\ReportedIssue(
                        $variant === 'level' ? \Mago\Sdk\Reporting\Level::Warning : $context->issue->level,
                        $variant === 'code' ? 'no-value' : $context->issue->code,
                        $message,
                        $variant === 'notes' ? ['Unrelated control flow.'] : $context->issue->notes,
                        $variant === 'help' ? 'Unrelated read contract.' : $context->issue->help,
                        $variant === 'link' ? 'https://example.invalid/reference' : $context->issue->link,
                        $annotations, $variant === 'edits' ? [\Mago\Sdk\Reporting\TextEdit::insert(0, ' ')] : $context->issue->edits);
                    $changed = new \Mago\Sdk\Analyzer\IssueFilterContext($context->phpVersion, $context->codebase, $context->types, $context->cancellation,
                        $variant === 'context-file' ? 'other.php' : $context->file,
                        $variant === 'context-source' ? $context->contents.' ' : $context->contents, $issue);
                    $keep($this->filter, $changed, $variant);
                }
                foreach (['proof.php', 'runners.php', 'packages/testo/assert/Assert.php'] as $path) {
                    $file = $this->root.'/'.$path;
                    $bytes = file_get_contents($file);
                    file_put_contents($file, '<?php /* different current source */');
                    try { $keep($this->filter, $context, 'changed disk '.$path); }
                    finally { file_put_contents($file, $bytes); }
                }
                $targets = [
                    'caller' => $context->codebase->getMethod('Fixture\\ReferenceProof', 'check'),
                    'callee' => $context->codebase->getMethod('Fixture\\ForwardRunner', 'run'),
                    'helper' => $context->codebase->getMethod('Fixture\\ForwardRunner', 'attempt'),
                    'assertion' => $context->codebase->getMethod('Testo\\Assert', 'true'),
                    'native normalization' => $context->codebase->getMethod('Closure', 'fromCallable'),
                    'native construction' => $context->codebase->getDeclaringMethod('RuntimeException', '__construct'),
                ];
                $cache = (new \ReflectionProperty($context->codebase, 'cache'))->getValue($context->codebase);
                $replace = static function ($original, $changed) use ($cache): int {
                    $count = 0;
                    foreach ($cache->values as $operation => $entries) {
                        foreach ($entries as $key => $entry) {
                            if ($entry === $original || $original instanceof \Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata
                                && $entry instanceof \Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata && strcasecmp($entry->name, $original->name) === 0) {
                                $cache->values[$operation][$key] = $changed; $count++;
                            }
                        }
                    }
                    return $count;
                };
                foreach ($targets as $role => $metadata) {
                    if ($metadata === null) { throw new \RuntimeException('Missing effective reference metadata: '.$role); }
                    $variants = in_array($role, ['native normalization', 'native construction'], true)
                        ? ['identifier-class', 'original-name', 'kind', 'static', 'reference', 'return-type']
                        : ['file', 'name-file', 'name-span', 'body-span', 'identifier-class', 'original-name', 'kind', 'static', 'reference', 'return-type'];
                    if (in_array($role, ['native normalization', 'native construction'], true)) { $variants[] = 'user-defined'; $variants[] = 'not-builtin'; }
                    if ($role === 'native construction') { $variants[] = 'visibility'; $variants[] = 'abstract'; $variants[] = 'declared-return-type'; }
                    if ($role === 'helper') { $variants[] = 'visibility'; }
                    if ($role === 'assertion') { $variants[] = 'assertions'; }
                    foreach ($variants as $variant) {
                        $snapshot = $cache->values;
                        $values = get_object_vars($metadata);
                        if ($variant === 'file') { $values['location'] = new \Mago\Sdk\SourceLocation('other.php', $metadata->location->span); }
                        if ($variant === 'name-file') { $values['nameLocation'] = new \Mago\Sdk\SourceLocation('other.php', $metadata->nameLocation?->span ?? $metadata->location->span); }
                        if ($variant === 'name-span') { $values['nameLocation'] = new \Mago\Sdk\SourceLocation($metadata->nameLocation?->file ?? $metadata->location->file, new \Mago\Sdk\Span(($metadata->nameLocation?->span->start ?? 0) + 1, ($metadata->nameLocation?->span->end ?? 1) + 1)); }
                        if ($variant === 'body-span') { $values['location'] = new \Mago\Sdk\SourceLocation($metadata->location->file, new \Mago\Sdk\Span($metadata->location->span->start + 1, $metadata->location->span->end + 1)); }
                        if ($variant === 'identifier-class') { $values['identifier'] = new \Mago\Sdk\Analyzer\Type\FunctionLikeIdentifier($metadata->identifier->kind, $metadata->identifier->name, 'OtherOwner'); }
                        if ($variant === 'original-name') { $values['originalName'] = 'other'; }
                        if ($variant === 'kind') { $values['kind'] = \Mago\Sdk\Analyzer\Metadata\FunctionLikeKind::Closure; }
                        if ($variant === 'static') { $values['static'] = !$metadata->static; }
                        if ($variant === 'visibility') { $values['visibility'] = $role === 'native construction' ? \Mago\Sdk\Analyzer\Type\Visibility::Private : \Mago\Sdk\Analyzer\Type\Visibility::Public; }
                        if ($variant === 'abstract') { $values['abstract'] = true; }
                        if ($variant === 'assertions') { $values['assertions'] = []; }
                        if ($variant === 'return-type') { $values['returnType'] = new \Mago\Sdk\Analyzer\Metadata\TypeMetadata($metadata->returnType?->location ?? $metadata->location, \Mago\Sdk\Analyzer\Type::bool(), false, false); }
                        if ($variant === 'declared-return-type') { $values['declaredReturnType'] = new \Mago\Sdk\Analyzer\Metadata\TypeMetadata($metadata->location, \Mago\Sdk\Analyzer\Type::never(), false, false); }
                        if ($variant === 'reference') { $values['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($metadata->flags->bits | \Mago\Sdk\Analyzer\Metadata\MetadataFlags::BY_REFERENCE); }
                        if ($variant === 'user-defined') { $values['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($metadata->flags->bits | \Mago\Sdk\Analyzer\Metadata\MetadataFlags::USER_DEFINED); }
                        if ($variant === 'not-builtin') { $values['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($metadata->flags->bits & ~\Mago\Sdk\Analyzer\Metadata\MetadataFlags::BUILTIN); }
                        $changed = new \Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata(...$values);
                        try {
                            if ($replace($metadata, $changed) === 0) { throw new \RuntimeException('Metadata not present in SDK cache: '.$role); }
                            $keep(new \Ichinya\Laramago\Analyzer\DirectCallbackReferenceIssueFilter(clone $this->index), $context, $role.' '.$variant);
                        } finally { $cache->values = $snapshot; }
                    }
                    if ($metadata->parameters !== [] && $role !== 'native construction') {
                        foreach ($role === 'native normalization' ? ['name', 'reference', 'type', 'variadic', 'default'] : ['name', 'location', 'reference', 'type'] as $variant) {
                            $snapshot = $cache->values;
                            $values = get_object_vars($metadata);
                            $parameter = $metadata->parameters[0];
                            $parameterValues = get_object_vars($parameter);
                            if ($variant === 'name') { $parameterValues['name'] = '$other'; }
                            if ($variant === 'location') { $parameterValues['location'] = new \Mago\Sdk\SourceLocation('other.php', $parameter->location->span); }
                            if ($variant === 'reference') { $parameterValues['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($parameter->flags->bits ^ \Mago\Sdk\Analyzer\Metadata\MetadataFlags::BY_REFERENCE); }
                            if ($variant === 'variadic') { $parameterValues['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($parameter->flags->bits ^ \Mago\Sdk\Analyzer\Metadata\MetadataFlags::VARIADIC); }
                            if ($variant === 'default') { $parameterValues['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($parameter->flags->bits ^ \Mago\Sdk\Analyzer\Metadata\MetadataFlags::HAS_DEFAULT); }
                            if ($variant === 'type') { $parameterValues['type'] = new \Mago\Sdk\Analyzer\Metadata\TypeMetadata($parameter->type?->location ?? $parameter->location, \Mago\Sdk\Analyzer\Type::never(), false, false); }
                            $values['parameters'][0] = new \Mago\Sdk\Analyzer\Metadata\ParameterMetadata(...$parameterValues);
                            $changed = new \Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata(...$values);
                            try {
                                if ($replace($metadata, $changed) === 0) { throw new \RuntimeException('Missing parameter metadata control.'); }
                                $keep(new \Ichinya\Laramago\Analyzer\DirectCallbackReferenceIssueFilter(clone $this->index), $context, $role.' parameter '.$variant);
                            } finally { $cache->values = $snapshot; }
                        }
                    }
                }
                $constructor = $targets['native construction'];
                if (count($constructor->parameters) !== 3 || $constructor->identifier->class !== 'Exception'
                    || $constructor->declaredReturnType !== null || $constructor->returnType !== null) { throw new \RuntimeException('Missing exact inherited native constructor signature.'); }
                foreach ($constructor->parameters as $position => $parameter) {
                    foreach (['name', 'reference', 'variadic', 'default', 'type', 'declared-type', 'default-type', 'missing-default-type'] as $variant) {
                        $snapshot = $cache->values;
                        $values = get_object_vars($constructor);
                        $parameterValues = get_object_vars($parameter);
                        if ($variant === 'name') { $parameterValues['name'] = '$other'; }
                        if ($variant === 'reference') { $parameterValues['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($parameter->flags->bits | \Mago\Sdk\Analyzer\Metadata\MetadataFlags::BY_REFERENCE); }
                        if ($variant === 'variadic') { $parameterValues['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($parameter->flags->bits | \Mago\Sdk\Analyzer\Metadata\MetadataFlags::VARIADIC); }
                        if ($variant === 'default') { $parameterValues['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($parameter->flags->bits & ~\Mago\Sdk\Analyzer\Metadata\MetadataFlags::HAS_DEFAULT); }
                        if ($variant === 'type') { $parameterValues['type'] = new \Mago\Sdk\Analyzer\Metadata\TypeMetadata($parameter->type?->location ?? $parameter->location, \Mago\Sdk\Analyzer\Type::never(), false, false); }
                        if ($variant === 'declared-type') { $parameterValues['declaredType'] = new \Mago\Sdk\Analyzer\Metadata\TypeMetadata($parameter->declaredType?->location ?? $parameter->location, \Mago\Sdk\Analyzer\Type::never(), false, false); }
                        if ($variant === 'default-type') { $parameterValues['defaultType'] = new \Mago\Sdk\Analyzer\Metadata\TypeMetadata($parameter->defaultType?->location ?? $parameter->location, \Mago\Sdk\Analyzer\Type::true(), false, true); }
                        if ($variant === 'missing-default-type') { $parameterValues['defaultType'] = null; }
                        $values['parameters'][$position] = new \Mago\Sdk\Analyzer\Metadata\ParameterMetadata(...$parameterValues);
                        $changed = new \Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata(...$values);
                        try {
                            if ($replace($constructor, $changed) === 0) { throw new \RuntimeException('Missing inherited constructor metadata control.'); }
                            $keep(new \Ichinya\Laramago\Analyzer\DirectCallbackReferenceIssueFilter(clone $this->index), $context, 'native constructor parameter '.$position.' '.$variant);
                        } finally { $cache->values = $snapshot; }
                    }
                }
                $callee = $targets['callee'];
                $parameter = $callee->parameters[0];
                if ($parameter->defaultType === null) { throw new \RuntimeException('Missing authoritative optional callback default.'); }
                foreach (['literal', 'missing', 'foreign-location', 'expression-only-location', 'missing-equals-location', 'wrong-end', 'name-overlap', 'missing-default-flag'] as $variant) {
                    $snapshot = $cache->values;
                    $values = get_object_vars($callee);
                    $parameterValues = get_object_vars($parameter);
                    $parameterValues['defaultType'] = match ($variant) {
                        'literal' => new \Mago\Sdk\Analyzer\Metadata\TypeMetadata($parameter->defaultType->location, \Mago\Sdk\Analyzer\Type::true(), false, true),
                        'missing' => null,
                        'foreign-location' => new \Mago\Sdk\Analyzer\Metadata\TypeMetadata(new \Mago\Sdk\SourceLocation('other.php', $parameter->defaultType->location->span), $parameter->defaultType->type, false, true),
                        'expression-only-location' => new \Mago\Sdk\Analyzer\Metadata\TypeMetadata(new \Mago\Sdk\SourceLocation($parameter->defaultType->location->file, new \Mago\Sdk\Span($parameter->defaultType->location->span->start + 2, $parameter->defaultType->location->span->end)), $parameter->defaultType->type, false, true),
                        'missing-equals-location' => new \Mago\Sdk\Analyzer\Metadata\TypeMetadata(new \Mago\Sdk\SourceLocation($parameter->defaultType->location->file, new \Mago\Sdk\Span($parameter->defaultType->location->span->start + 1, $parameter->defaultType->location->span->end)), $parameter->defaultType->type, false, true),
                        'wrong-end' => new \Mago\Sdk\Analyzer\Metadata\TypeMetadata(new \Mago\Sdk\SourceLocation($parameter->defaultType->location->file, new \Mago\Sdk\Span($parameter->defaultType->location->span->start, $parameter->defaultType->location->span->end + 1)), $parameter->defaultType->type, false, true),
                        'name-overlap' => new \Mago\Sdk\Analyzer\Metadata\TypeMetadata(new \Mago\Sdk\SourceLocation($parameter->defaultType->location->file, new \Mago\Sdk\Span($parameter->nameLocation?->span->start ?? $parameter->location->span->start, $parameter->defaultType->location->span->end)), $parameter->defaultType->type, false, true),
                        default => $parameter->defaultType,
                    };
                    if ($variant === 'missing-default-flag') { $parameterValues['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($parameter->flags->bits & ~\Mago\Sdk\Analyzer\Metadata\MetadataFlags::HAS_DEFAULT); }
                    $values['parameters'][0] = new \Mago\Sdk\Analyzer\Metadata\ParameterMetadata(...$parameterValues);
                    $changed = new \Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata(...$values);
                    try {
                        if ($replace($callee, $changed) === 0) { throw new \RuntimeException('Missing optional default control.'); }
                        $probe = new \Ichinya\Laramago\Analyzer\DirectCallbackReferenceIssueFilter(clone $this->index);
                        if ($variant === 'expression-only-location') {
                            if ($probe->filterIssue($context) !== \Mago\Sdk\Analyzer\IssueFilterDecision::Remove) { throw new \RuntimeException('Equivalent exact default-expression anchor was rejected.'); }
                            $checks++;
                        } else { $keep($probe, $context, 'callback default '.$variant); }
                    } finally { $cache->values = $snapshot; }
                }
                $native = $targets['native normalization'];
                $atom = $native->returnType?->type->atomicTypes[0] ?? null;
                if (!$atom instanceof \Mago\Sdk\Analyzer\Type\CallableType || $atom->signature === null || !$atom->signature->closure) { throw new \RuntimeException('Missing native closure CallableType representation.'); }
                $shapes = [];
                foreach (['closure', 'pure', 'source', 'no-return', 'no-parameters'] as $variant) {
                    $values = get_object_vars($atom->signature);
                    if ($variant === 'closure') { $values['closure'] = false; }
                    if ($variant === 'pure') { $values['pure'] = true; }
                    if ($variant === 'source') { $values['source'] = $native->identifier; }
                    if ($variant === 'no-return') { $values['returnType'] = null; }
                    if ($variant === 'no-parameters') { $values['parameters'] = []; }
                    $shapes[$variant] = \Mago\Sdk\Analyzer\Type::fromAtomic(new \Mago\Sdk\Analyzer\Type\CallableType(new \Mago\Sdk\Analyzer\Type\CallableSignature(...$values), null));
                }
                foreach (['reference', 'variadic', 'default', 'type'] as $variant) {
                    $values = get_object_vars($atom->signature);
                    $parameter = get_object_vars($values['parameters'][0]);
                    if ($variant === 'reference') { $parameter['byReference'] = true; }
                    if ($variant === 'variadic') { $parameter['variadic'] = false; }
                    if ($variant === 'default') { $parameter['hasDefault'] = false; }
                    if ($variant === 'type') { $parameter['type'] = \Mago\Sdk\Analyzer\Type::int(); }
                    $values['parameters'][0] = new \Mago\Sdk\Analyzer\Type\CallableParameter(...$parameter);
                    $shapes['callable parameter '.$variant] = \Mago\Sdk\Analyzer\Type::fromAtomic(new \Mago\Sdk\Analyzer\Type\CallableType(new \Mago\Sdk\Analyzer\Type\CallableSignature(...$values), null));
                }
                $shapes['alias-only callable'] = \Mago\Sdk\Analyzer\Type::fromAtomic(new \Mago\Sdk\Analyzer\Type\CallableType(null, $native->identifier));
                $shapes['duplicate callable atoms'] = \Mago\Sdk\Analyzer\Type::fromAtomics($atom, $atom);
                $shapes['nullable callable union'] = \Mago\Sdk\Analyzer\Type::fromAtomics($atom, \Mago\Sdk\Analyzer\Type::null()->atomicTypes[0]);
                $shapes['unverified named Closure'] = \Mago\Sdk\Analyzer\Type::namedObject('Closure');
                foreach ($shapes as $variant => $type) {
                    $snapshot = $cache->values;
                    $values = get_object_vars($native);
                    $values['returnType'] = new \Mago\Sdk\Analyzer\Metadata\TypeMetadata($native->returnType->location, $type, false, false);
                    $changed = new \Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata(...$values);
                    try {
                        if ($replace($native, $changed) === 0) { throw new \RuntimeException('Missing structural closure control.'); }
                        $keep(new \Ichinya\Laramago\Analyzer\DirectCallbackReferenceIssueFilter(clone $this->index), $context, 'native closure '.$variant);
                    } finally { $cache->values = $snapshot; }
                }
                foreach (['Fixture\\ForwardRunner', 'Closure', 'RuntimeException', 'Exception'] as $name) {
                    $metadata = $context->codebase->getClassLike($name);
                    if ($metadata === null) { throw new \RuntimeException('Missing complete reference class metadata.'); }
                    $variants = match ($name) {
                        'Closure' => ['kind', 'user-defined', 'not-builtin'],
                        'RuntimeException', 'Exception' => ['kind', 'user-defined', 'not-builtin', 'incomplete', 'parent-class'],
                        default => ['kind', 'not-final', 'incomplete', 'file'],
                    };
                    foreach ($variants as $variant) {
                        $snapshot = $cache->values;
                        $values = get_object_vars($metadata);
                        if ($variant === 'kind') { $values['kind'] = \Mago\Sdk\Analyzer\Metadata\ClassLikeKind::Trait; }
                        if ($variant === 'not-final') { $values['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($metadata->flags->bits & ~\Mago\Sdk\Analyzer\Metadata\MetadataFlags::FINAL); }
                        if ($variant === 'incomplete') { $values['unresolvedHierarchyDependencies'] = ['Fixture\\UnresolvedOwner']; }
                        if ($variant === 'file') { $values['location'] = new \Mago\Sdk\SourceLocation('other.php', $metadata->location->span); }
                        if ($variant === 'user-defined') { $values['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($metadata->flags->bits | \Mago\Sdk\Analyzer\Metadata\MetadataFlags::USER_DEFINED); }
                        if ($variant === 'not-builtin') { $values['flags'] = new \Mago\Sdk\Analyzer\Metadata\MetadataFlags($metadata->flags->bits & ~\Mago\Sdk\Analyzer\Metadata\MetadataFlags::BUILTIN); }
                        if ($variant === 'parent-class') { $values['directParentClass'] = 'Fixture\\UnverifiedParent'; }
                        $changed = new \Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata(...$values);
                        try {
                            if ($replace($metadata, $changed) === 0) { throw new \RuntimeException('Missing class metadata control.'); }
                            $keep(new \Ichinya\Laramago\Analyzer\DirectCallbackReferenceIssueFilter(clone $this->index), $context, $name.' '.$variant);
                        } finally { $cache->values = $snapshot; }
                    }
                }
                if ($this->filter->filterIssue($context) !== $result) { throw new \RuntimeException('Reference controls mutated a valid effect proof.'); }
                file_put_contents($this->root.'/context-checks.log', $checks."\n", FILE_APPEND);
                return $result;
            }
        });
    }
};
(new \Mago\Sdk\Worker(new \Mago\Sdk\Extension(identifier: 'fixture/reference-contexts', name: 'Reference contexts', version: '1', analyzerPlugins: [$plugin])))->run();
PHP);
$validateSyntax(['worker.php', 'proof-worker.php']);
if (in_array('--syntax-only', $argv, true)) {
    echo 'Direct reference fixture syntax passed: '.count($cases).' source cases; workspace='.$workspace.".\n";
    exit(0);
}

$analyze = static function (string $mode, int $workers = 1, bool $external = false, array $paths = ['cases.php', 'runners.php']) use ($workspace, $package, $nativePath): array {
    $configuration = $external ? $workspace.' external configuration' : $workspace;
    if (!is_dir($configuration)) { mkdir($configuration); }
    $hosts = $mode === 'native' ? new stdClass : ['fixture' => [
        'command' => $mode === 'integrated'
            ? [PHP_BINARY, '-d', 'opcache.enable_cli=0', $package.'/bin/laramago-worker.php', $package.'/vendor/autoload.php', $workspace]
            : [PHP_BINARY, '-d', 'opcache.enable_cli=0', $workspace.'/'.($mode === 'contexts' ? 'proof-worker.php' : 'worker.php'), $package.'/vendor/autoload.php', $workspace, $mode],
        'workers' => $workers, 'request-timeout-ms' => 120000,
    ]];
    file_put_contents($configuration.'/mago.json', json_encode([
        'extends' => $package.'/presets/laravel.toml', 'php-version' => '8.5',
        'source' => ['paths' => $paths, 'includes' => [$nativePath, 'support.php', ...(!in_array('runners.php', $paths, true) ? ['runners.php'] : [])]],
        'extension-hosts' => $hosts,
    ], JSON_THROW_ON_ERROR));
    $binary = getenv('MAGO_BINARY') ?: $package.'/vendor/bin/mago';
    $command = str_ends_with($binary, '.exe') ? [$binary] : [PHP_BINARY, $binary];
    $name = $mode.'-'.$workers.($external ? '-external' : '').'-'.hash('xxh64', implode('|', $paths));
    $process = proc_open([...$command, '--workspace', $workspace, '--config', $configuration.'/mago.json', 'analyze', '--reporting-format=json'], [
        0 => ['pipe', 'r'], 1 => ['file', $workspace.'/'.$name.'.json', 'w'], 2 => ['file', $workspace.'/'.$name.'.log', 'w'],
    ], $pipes, $configuration);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start Mago.'); }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $stderr = file_get_contents($workspace.'/'.$name.'.log');
    if ($exit > 1 || preg_match('/provider failed|rejected request|hook .* failed|parse error|worker .* exited|did not answer/i', $stderr)) {
        throw new RuntimeException('Mago failed: '.$stderr.' '.$workspace);
    }
    return json_decode(file_get_contents($workspace.'/'.$name.'.json'), true, flags: JSON_THROW_ON_ERROR)['issues'];
};
$signature = static function (array $issues): array {
    $result = [];
    foreach ($issues as $issue) {
        foreach ($issue['annotations'] as $annotation) {
            if ($annotation['kind'] !== 'Primary') { continue; }
            $result[] = [$issue['code'], $issue['message'], $annotation['span']['file_id']['name'], $annotation['span']['start']['offset'], $annotation['span']['end']['offset']];
            break;
        }
    }
    sort($result);
    return $result;
};
$group = static function (array $issues) use ($ranges, $signature): array {
    $grouped = [];
    foreach ($signature($issues) as $issue) {
        if ($issue[2] !== 'cases.php') { continue; }
        foreach ($ranges as $label => [$start, $end]) {
            if ($issue[3] >= $start && $issue[3] < $end) { $grouped[$label][] = $issue; break; }
        }
    }
    return $grouped;
};
if (in_array('--object-state-probe', $argv, true)) {
    $stateNative = $signature($analyze('native', paths: ['state-cases.php', 'runners.php']));
    $stateExtended = $signature($analyze('object-state-probe', paths: ['state-cases.php', 'runners.php']));
    $stateRanges = json_decode(file_get_contents($workspace.'/state-ranges.json'), true, flags: JSON_THROW_ON_ERROR);
    $stateDomains = json_decode(file_get_contents($workspace.'/object-state-domains.json'), true, flags: JSON_THROW_ON_ERROR);
    $observations = []; $witnesses = 0;
    foreach ($stateRanges as $label => [$start, $end]) {
        $belongs = static fn (array $issue): bool => $issue[2] === 'state-cases.php' && $issue[3] >= $start && $issue[3] < $end;
        $before = array_values(array_filter($stateNative, $belongs));
        $after = array_values(array_filter($stateExtended, $belongs));
        if (! in_array('impossible-type-comparison', array_column($before, 0), true)) { throw new RuntimeException('Vacuous object-state counterexample baseline: '.$label.' '.$workspace); }
        $domains = array_values(array_filter($stateDomains, static fn (array $proof): bool => $proof['assertionStart'] >= $start && $proof['assertionStart'] < $end));
        $unsafe = array_filter($domains, static fn (array $proof): bool => $proof['admitsExpected']) !== []
            && count(array_filter($before, static fn (array $issue): bool => $issue[0] === 'impossible-type-comparison'))
                > count(array_filter($after, static fn (array $issue): bool => $issue[0] === 'impossible-type-comparison'));
        $observations[$label] = ['native' => $before, 'extended' => $after, 'proofs' => $domains, 'unsafeRemovedComparison' => $unsafe];
        if ($unsafe) { $witnesses++; }
    }
    file_put_contents($workspace.'/object-state-counterexamples.json', json_encode($observations, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    if ($witnesses !== 0) { throw new RuntimeException('Unsafe ordinary object-state comparisons removed: '.$witnesses.' '.$workspace); }
    if (file_exists($workspace.'/executed')) { throw new RuntimeException('Object-state observation executed application bootstrap.'); }
    echo 'Object-state regression probe completed: '.count($observations).' nonempty native comparison groups, '.$witnesses.' unsafe removed comparisons; workspace='.$workspace.".\n";
    exit(0);
}
$native = $analyze('native');
$control = $analyze('control');
if (in_array('--baseline', $argv, true)) {
    foreach (['native' => $native, 'control' => $control] as $mode => $issues) {
        $groups = $group($issues);
        foreach ($cases as $label => [, $positive]) { echo $mode.' '.$label.': '.implode(',', array_column($groups[$label] ?? [], 0))."\n"; }
    }
    echo 'Direct reference baseline: workspace='.$workspace.".\n";
    exit(0);
}
$standalone = $analyze(in_array('--probe', $argv, true) ? 'probe' : 'standalone');
if (in_array('--probe', $argv, true)) {
    $before = $group($native);
    $after = $group($standalone);
    foreach ($cases as $label => [, $positive]) {
        echo ($positive ? 'positive' : 'negative').' '.$label.': '.implode(',', array_column($before[$label] ?? [], 0)).' -> '.implode(',', array_column($after[$label] ?? [], 0))."\n";
    }
    echo 'Direct reference probe: workspace='.$workspace.".\n";
    exit(0);
}
$corrected = 0;
$retained = 0;
foreach ([$native, $control] as $originalReports) {
    $before = $group($originalReports);
    $after = $group($standalone);
    foreach ($cases as $label => [, $positive]) {
        $original = $before[$label] ?? [];
        $actual = $after[$label] ?? [];
        if ($original === []) { throw new RuntimeException('Missing genuine direct-reference baseline: '.$label.' '.$workspace); }
        if (!$positive) {
            if ($actual !== $original) { throw new RuntimeException('Unsafe direct-reference correction: '.$label.' '.json_encode([$original, $actual]).' '.$workspace); }
            $retained++;
            continue;
        }
        $removed = array_values(array_filter($original, static fn (array $issue): bool => $issue[0] === 'impossible-type-comparison'));
        $expected = array_values(array_filter($original, static fn (array $issue): bool => $issue[0] !== 'impossible-type-comparison'));
        if (count($removed) !== 1 || $actual !== $expected) { throw new RuntimeException('Missing exact direct-reference correction: '.$label.' '.json_encode([$original, $actual]).' '.$workspace); }
        $corrected += count($removed);
    }
}
if ($signature($analyze('standalone', external: true)) !== $signature($standalone)) { throw new RuntimeException('External configuration lost explicit project root. '.$workspace); }
if ($signature($analyze('standalone', paths: ['cases.php'])) !== $signature($analyze('native', paths: ['cases.php']))) {
    throw new RuntimeException('Single-file analysis accepted an absent callee snapshot. '.$workspace);
}
$contexts = $analyze('contexts', paths: ['proof.php', 'runners.php']);
$contextChecks = file_exists($workspace.'/context-checks.log') ? array_sum(array_map('intval', file($workspace.'/context-checks.log', FILE_IGNORE_NEW_LINES))) : 0;
if ($contextChecks !== 171 || in_array('impossible-type-comparison', array_column($contexts, 'code'), true)) {
    throw new RuntimeException('Missing exact reference issue and metadata controls: '.$contextChecks.' '.$workspace);
}
$nativeCoveredChecks = file_exists($workspace.'/native-covered-checks.log') ? array_sum(array_map('intval', file($workspace.'/native-covered-checks.log', FILE_IGNORE_NEW_LINES))) : 0;
if ($nativeCoveredChecks !== 2) { throw new RuntimeException('Missing real-Codebase native-covered domain controls. '.$workspace); }
$constructionChecks = file_exists($workspace.'/construction-reflection-checks.log') ? array_sum(array_map('intval', file($workspace.'/construction-reflection-checks.log', FILE_IGNORE_NEW_LINES))) : 0;
if ($constructionChecks !== 249) { throw new RuntimeException('Missing genuine constructor and physical reflection metadata controls: '.$constructionChecks.' '.$workspace); }
if (in_array('--integrated', $argv, true)) {
    // Keep the standalone guaranteed-dispatch negatives; measure the independent possible-write scope policy.
    $policyPlans = json_decode(<<<'JSON'
[
    {"label":"uninvoked supplied closure","ownerSpan":[5056,5414],"ownerSha256":"b275e8aa46c6b6c84504cb75a9dc6cce219fc2ce29d97688f7c5eac1acf505c9","span":[5388,5410],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"uncaught callback exception","ownerSpan":[8336,8691],"ownerSha256":"fbf6277d3af7c3afa7795f111c99041c7a8a3d688d96b44d51919b2eed0f2021","span":[8665,8687],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"incompatible catch leaves no return","ownerSpan":[8691,9050],"ownerSha256":"3fae8d6fef049c7e501748a837edf1c291808a7d8a41fc160785c530d544c328","span":[9024,9046],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"callee returns before callback","ownerSpan":[9050,9405],"ownerSha256":"5483257136990b22d6267269ca5a8e3d6eec276d2af04bbcd9ad0c8e6c1df33c","span":[9379,9401],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"known unequal stage predicate","ownerSpan":[9405,9764],"ownerSha256":"2e38e5314b24c56d6e265d9ff9863710541086d80a345a6fbeb0bdbaa04f6834","span":[9738,9760],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"known false callee branch","ownerSpan":[10417,10777],"ownerSha256":"1f7762fa65dcdd3ea58a96577b1ef6de36505265b905f29da1e23d9dbbddbbc9","span":[10751,10773],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"supplied callback replaced","ownerSpan":[10777,11134],"ownerSha256":"20f7548217fbdb7e76e5b193a0ed91be6da8919ecd13787b5ab00cad20f5ab1e","span":[11108,11130],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"callback stored without invocation","ownerSpan":[11134,11489],"ownerSha256":"63e2e8890e6adb351a257a74796c56f2e837ad9958901cc47ece623658cd0775","span":[11463,11485],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"callback escapes before invocation","ownerSpan":[11489,11845],"ownerSha256":"816fcd1927d90489b7220a93e7dc61b348a35eb633984614f5edc37af4c42859","span":[11819,11841],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"callback is returned","ownerSpan":[11845,12202],"ownerSha256":"351b5e0165b90b0765d41345a9cb568bc3a99a095abd2e1e2e7110562a02f315","span":[12176,12198],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"unknown forwarding body","ownerSpan":[12202,12558],"ownerSha256":"43793f624a488f88b4723215054b5e3bfca3179f007471f77e145a700988bc7a","span":[12532,12554],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"global callback escape","ownerSpan":[12558,12913],"ownerSha256":"1d088db684e69bbca3a9c261503bc559c6c3dee9003eb6d9aef0f9a539b8213d","span":[12887,12909],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"user class impersonates native Closure","ownerSpan":[12913,13272],"ownerSha256":"e05c9f3282c1a8c32c5da1b76a88a6d1646a84541ab3c5c4fb284a57ac36bd49","span":[13246,13268],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"recursive callee has no finite proof","ownerSpan":[13272,13630],"ownerSha256":"ff3e7285992f039c073c7f1fc97c9ef0e5ec19853aee29c1fa9adbdfe9c21e27","span":[13604,13626],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"unbounded callee loop","ownerSpan":[13630,13988],"ownerSha256":"c4637596616f196faf1f2e60bdb935006ba4d80c3dce082729e493814a850db6","span":[13962,13984],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"known never callee","ownerSpan":[13988,14342],"ownerSha256":"fda3ee9f6f992ea97c93e9c2a64984e0b6868074780a5b1b71c20f1790588a3c","span":[14316,14338],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"strong authoritative false local","ownerSpan":[15711,16093],"ownerSha256":"f5c41e298bf50d3d9c65423979958b058f818d186a41b9ffdbf1b0e2a6f6a4f2","span":[16067,16089],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"nameless authoritative false local","ownerSpan":[16093,16466],"ownerSha256":"5acbb31ec4ea850a3de53f922b4a239f93185167a6acfa08a8334dc0033bbb17","span":[16440,16462],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"known descendant drops callback","ownerSpan":[17259,17612],"ownerSha256":"a40c57465c79d0eda23d102d53ed77b60deb98b72e5eb9fc3b899a613ad7e39f","span":[17586,17608],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"trait supplies no-op callback","ownerSpan":[17612,17966],"ownerSha256":"5a98792f0d44585f19199472b9baf30bc54ce8ae7ae3b4166557bdf9cf5bb165","span":[17940,17962],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"wrong callback input type","ownerSpan":[17966,18325],"ownerSha256":"fe56413cc63d7336a89fd4ea21478a13d9766d8dc91c37153a6bd547d98acbc9","span":[18299,18321],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"null callback input throws before body","ownerSpan":[18325,18683],"ownerSha256":"2c778c29ea241bba96b2f0a7894112baf3935122893b86b0ead0d4749ee236b1","span":[18657,18679],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"dynamic method name","ownerSpan":[19407,19783],"ownerSha256":"99e0bcb7f85bb266bc64600ea3390f3d386a7497d87d4a8e61da22b8f27c2239","span":[19757,19779],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"contradictory correlated gate","ownerSpan":[20812,21190],"ownerSha256":"2bc594df46dce2acb246bf00d641d6b886c76a20d04092d34d06d105e236d583","span":[21164,21186],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"known false enclosing caller guard","ownerSpan":[21545,21915],"ownerSha256":"3d7b24e02938c8f8900fa24c312e45b05be29c5711227254a6f71626cd629f96","span":[21889,21911],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"independent impossible assertion before origin","ownerSpan":[21915,22291],"ownerSha256":"b1be6fc211fc04571b698571f9d0e2a1d1e68ca94b5bb299528f3cfdb399d6ef","span":[22265,22287],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"invocation event budget exceeded","ownerSpan":[23526,23891],"ownerSha256":"120ba7ad1a2c61eac570dbbc3667d6ba5995de08e43de512b1d2541c3a1d03a5","span":[23865,23887],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"receiver constructor stops before callback","ownerSpan":[23891,24278],"ownerSha256":"a7ddd5fc1440bf3fc9e963220b21acf11391239b562fc13d05b3f2ab94073f1c","span":[24252,24274],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"ordinary token constructor stops before callback","ownerSpan":[24278,24662],"ownerSha256":"08586ebcf8d990629d2b04e080e868417585aebae726730ee186f1d3c59aa92d","span":[24636,24658],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"constructor argument violates declared scalar","ownerSpan":[24662,25060],"ownerSha256":"e66428c6b5cd278e3858222849b4f010b2d80f7a3fa747f291c416cbb2288a8a","span":[25034,25056],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"distinct fresh objects cannot be identical","ownerSpan":[25060,25423],"ownerSha256":"dc915aa61a8d2d53faf5e52727b5896c2fc76aaeb68ace246ddfe1b3a19f4669","span":[25397,25419],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"stronger closure literal parameter","ownerSpan":[25423,25811],"ownerSha256":"225a3ed6af0d685e6dc97eb6773bb481681868d0e142dda93735c24c653069a0","span":[25785,25807],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"stronger closure never return","ownerSpan":[25811,26187],"ownerSha256":"0419080c12925c603765806322f8eda0a542cde2a12e7455b2ea72e72b879e99","span":[26161,26183],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"untyped helper never return","ownerSpan":[27807,28168],"ownerSha256":"2e19f3bc7d87f0f01d0c067cae83db3f4acf939ba5c4a4bc998d99ccc73c4c0f","span":[28142,28164],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"untyped helper literal argument","ownerSpan":[28168,28531],"ownerSha256":"8f1a6c9dbbc5143f2d34cf31a7fd3921ab3b4743c31644a9b1fe4f22322e815a","span":[28505,28527],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"known never exception operand","ownerSpan":[28531,28868],"ownerSha256":"35ecab35f4555dd9397d1de04fd66aceadb8a6688729bf37840fc22b78c0ccfa","span":[28842,28864],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"copied gate cannot contradict its origin","ownerSpan":[28868,29239],"ownerSha256":"7113b04cc77c8b10c57ccf8de79a71c0c2675da329dc10d3a5103a8226657867","span":[29213,29235],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"unrelated action is known never","ownerSpan":[29239,29712],"ownerSha256":"d9b72c4d52bdb4e432201e46be9ab72d183a0b38d4b2f6d56130754a385040ad","span":[29686,29708],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"runner normal return violates scalar declaration","ownerSpan":[29712,30072],"ownerSha256":"d1321f8b6695ea4b63bc6b0d7f369245d91bfbf6de4cef2e8349c5980ed063f3","span":[30046,30068],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"ordinary primitive call cannot accept array","ownerSpan":[30688,31052],"ownerSha256":"4f0e039afcc639915758a42f5c1a82ccb64e5bfbe14c1f0fcbe765f32cc6a921","span":[31026,31048],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"external private method is inaccessible","ownerSpan":[31052,31408],"ownerSha256":"f5f864f493cef2e8d4cce462f4725d40f8f2024614d370761e9b99144be2573d","span":[31382,31404],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"foreign private action is inaccessible","ownerSpan":[31408,31770],"ownerSha256":"6c60672720ad813666b590e8dab12c8118a4d7ce02f4141bbc67655193d1ae12","span":[31744,31766],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"strict true prefix excludes integer","ownerSpan":[31770,32142],"ownerSha256":"d6791e7f7df574e27592f5e873ab22dd330cde9a47af39f0d94c52e828d8b2d1","span":[32116,32138],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"strict false prefix excludes null","ownerSpan":[32142,32518],"ownerSha256":"c5093781f77b601d4d78eda2e68f42ecdb746211c87467efa4737355f37d8d3a","span":[32492,32514],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"namespace shadows ordinary builtin","ownerSpan":[33306,33687],"ownerSha256":"b320ff478a2e315135f028dd663462320ec232502a83158255eb9849a021f7f9","span":[33661,33683],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"imported ordinary alias remains never","ownerSpan":[33687,34070],"ownerSha256":"7a3859306b2e6ef948962d99ba7216a692ab4071817fb2c4f8ad02b0edcadb67","span":[34044,34066],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"object concatenation cannot complete","ownerSpan":[34070,34432],"ownerSha256":"5b8082a5462cecb7250c6617d74f5282f0094a5b7668fe01746be9f83fcf758d","span":[34406,34428],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"object interpolation cannot complete","ownerSpan":[34432,34799],"ownerSha256":"9e79cb8a1cdb983ac16dc6a560ec8cb8e1be0fdf71242ab51f4e297e296fc06c","span":[34773,34795],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"unsupported native conditional action result","ownerSpan":[40266,40690],"ownerSha256":"e9f1d24e7ef50189cb9e25a365f1493fbb34f63891e0b1ebac47bd17947230b7","span":[40664,40686],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"constructor false field prevents invocation","ownerSpan":[42032,42411],"ownerSha256":"81440658463c938649999416078886dd8f4ae029cdde29da7834030abeb5dfa8","span":[42385,42407],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"physical uninitialized closure has no return","ownerSpan":[42411,42780],"ownerSha256":"0ebf88bd086133ddf4c1abcabe4e55e28cf867298d10fe18a09770474a431c9a","span":[42754,42776],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"stored lexical never action","ownerSpan":[42780,43221],"ownerSha256":"d84fc746d1130a78bbe90edfff04cdf93d8f925dcd4273b26b70614170611361","span":[43195,43217],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"stored first class never action","ownerSpan":[43221,43632],"ownerSha256":"04a58443f64ab48fad423271284130c0f6e939814253f00df66993f08c145eef","span":[43606,43628],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"constructor argument violates primitive type","ownerSpan":[43632,43999],"ownerSha256":"486f585a5d5d5911ab01a740509d07558da31105bd88af1413002eaab601b931","span":[43973,43995],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"constructor assigned field violates type","ownerSpan":[43999,44363],"ownerSha256":"669b674ecbe1759a22f15e3054173d3e29af4d826f26102cd5df576655836330","span":[44337,44359],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"constructor initializes readonly field twice","ownerSpan":[44363,44757],"ownerSha256":"a5c28547e559eb6a3c3f388985b45cbbf13af4780e12855aaa8b1dc6749d5c5f","span":[44731,44753],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"selected constructor capability storage","ownerSpan":[44757,45123],"ownerSha256":"9c1585371c66e19d38bc0b353f339d73ec5eea7e75d41ac57d7c759da507099e","span":[45097,45119],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"unsupported constructor local assignment","ownerSpan":[45511,45892],"ownerSha256":"ad68209d5cd7519e7449e430e871ebe84c74363418c4b96eaf4f4f556d8a3e42","span":[45866,45888],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"source constructor inheritance deferred","ownerSpan":[45892,46285],"ownerSha256":"f64f73be5bac2ea4ed3901d6fd30631da2a0618bc854eacfb4de1fdfb1c5f06f","span":[46259,46281],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"static physical field contract deferred","ownerSpan":[46285,46646],"ownerSha256":"8bbf82ce56e7f81f7aaac9ccb129d1157a610c87b477f09767d7c3f6af1d4b9e","span":[46620,46642],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"physical read hook contract deferred","ownerSpan":[46646,47005],"ownerSha256":"41bd2ef08443287dcfb3c1664bf6fcfb72b98d7b457a967b6bc9720e73893dd7","span":[46979,47001],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"wrong native normalization name","ownerSpan":[47005,47373],"ownerSha256":"38a66a782a45b87511795dcb1ae5800b8472770b39c0d02d0cae1d995bcee654","span":[47347,47369],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"physical field write violates type","ownerSpan":[47658,48064],"ownerSha256":"0dc23967dbb63c53ff1d8f951345d0e6a56a23391ad5936f4cf616effe7ce566","span":[48038,48060],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"external private physical field write","ownerSpan":[48064,48477],"ownerSha256":"dafeb8a1bb242d3705c6283dc7a32169847e712ad1f3bbac458e7f2c0435c746","span":[48451,48473],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"readonly physical field rewrite","ownerSpan":[48477,48891],"ownerSha256":"dae3c134e2e6bc2e296aa3441e5b14bce0cf05a4842ea1589b897b945aa966b9","span":[48865,48887],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"unknown physical receiver contract","ownerSpan":[48891,49295],"ownerSha256":"fa22c6cdea7a9d8ebf107f77740b0676ebe28be1d0e4085fe7438a323ff240f3","span":[49269,49291],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"dynamic physical field write deferred","ownerSpan":[49295,49722],"ownerSha256":"d61ad239ece0aaa404150d8c65736c67e292f7a8887b85c56de6fd91cb9c9faa","span":[49696,49718],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"reflection readonly field deferred","ownerSpan":[50590,51111],"ownerSha256":"70897777189056b568cbf879c290611fab1c2b7cad5512bb14615256d12fec6e","span":[51085,51107],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"reflection static field deferred","ownerSpan":[51111,51628],"ownerSha256":"a7af4cfbde106d10bc9856c1543aeb09db56287cb4b20fc39d7d5ae972592742","span":[51602,51624],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"reflection hooked field deferred","ownerSpan":[51628,52141],"ownerSha256":"f12bfa75dbceef0386dd587bf36a4c13c15ba24570880ef24eab67ed2f672d5a","span":[52115,52137],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"reflection inherited field deferred","ownerSpan":[52141,52664],"ownerSha256":"07675ef4b6c1ebd73970d41e5d6c535b4f9b1d5a69f18c84bb11dfcaa2da1f67","span":[52638,52660],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"reflection unknown selector deferred","ownerSpan":[52664,53186],"ownerSha256":"35bd4daf22c2e61d680911994e9f8f1e4f70e1940141ee177df9c979597d7640","span":[53160,53182],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"reflection wrong value type deferred","ownerSpan":[53186,53679],"ownerSha256":"959c5315d405fa834f2be6d7a2c555e054c186e123e33d074fcdc32e9f402095","span":[53653,53675],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"reflection wrong receiver deferred","ownerSpan":[53679,54184],"ownerSha256":"bafa60bcb544e5af2dc26b3a246b1c1dddaccacfca1fd6d07f2660281caae2b3","span":[54158,54180],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"reflection after selected origin deferred","ownerSpan":[54184,54689],"ownerSha256":"4b67cc55e600cba884335d9319313b741da7897fcc72697f60393b5ade666616","span":[54663,54685],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"same named method is not a closure field call","ownerSpan":[56475,56910],"ownerSha256":"20b3c8257eaf93eea6fcedf447d2ca5ccad0e9ed616c6ad1c2212b869a423bcc","span":[56884,56906],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"allocated reflected false field remains false","ownerSpan":[56910,57432],"ownerSha256":"f3492311d596cdff6757d5342d52e424e53c0921b523b88b51e0699bfa3097f2","span":[57406,57428],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."},
    {"label":"opaque reflected false field cannot be forgotten","ownerSpan":[57432,57923],"ownerSha256":"55324c97a6fd54f2d20df8496e27a63e3c63987e03c9681a1609554cd8a6b0f5","span":[57897,57919],"assertionSource":"Assert::true($changed)","code":"impossible-type-comparison","message":"Impossible type assertion: `$changed` of type `false` can never be `true|true`."}
]
JSON, true, flags: JSON_THROW_ON_ERROR);
    $policySourceSha256 = 'deddb3c47fb333e320e69de71bb8131b67a49222652c488b569694b73501185f';
    $policySource = file_get_contents($workspace.'/cases.php');
    if (hash('sha256', $policySource) !== $policySourceSha256) { throw new RuntimeException('The current possible-write fixture source changed.'); }
    $wholeSignature = static function (array $issues): array {
        $records = array_map(static fn (array $issue): string => json_encode($issue, JSON_THROW_ON_ERROR), $issues);
        sort($records);
        return $records;
    };
    $existingPolicies = $analyze('existing-policies');
    $expected = $standalone; $genuineErrors = [];
    foreach ($policyPlans as $plan) {
        $owner = $ranges[$plan['label']] ?? null;
        if ($owner === null || ($cases[$plan['label']][1] ?? null) !== false || $owner !== $plan['ownerSpan']
            || hash('sha256', substr($policySource, $owner[0], $owner[1] - $owner[0])) !== $plan['ownerSha256']
            || substr($policySource, $plan['span'][0], $plan['span'][1] - $plan['span'][0]) !== $plan['assertionSource']) {
            throw new RuntimeException('A current guaranteed-dispatch negative owner changed: '.$plan['label']);
        }
        $matches = array_filter($standalone, static function (array $issue) use ($plan): bool {
            if ($issue['level'] !== 'Error' || $issue['code'] !== $plan['code'] || $issue['message'] !== $plan['message']) { return false; }
            $primary = array_values(array_filter($issue['annotations'], static fn (array $annotation): bool => $annotation['kind'] === 'Primary'));
            return count($primary) === 1 && $primary[0]['span']['file_id']['name'] === 'cases.php'
                && $primary[0]['span']['start']['offset'] === $plan['span'][0] && $primary[0]['span']['end']['offset'] === $plan['span'][1];
        });
        if (count($matches) !== 1) { throw new RuntimeException('One genuine possible-write policy Error required: '.$plan['label']); }
        $issue = array_values($matches)[0]; $index = array_search($issue, $expected, true);
        if ($index === false) { throw new RuntimeException('Missing or duplicate complete possible-write Error.'); }
        unset($expected[$index]); $genuineErrors[] = $issue;
    }
    if (count($policyPlans) !== 78 || $wholeSignature($existingPolicies) !== $wholeSignature(array_values($expected))) {
        throw new RuntimeException('The independently measured possible-write policy changed more than its78 exact source-bound Errors. '.$workspace);
    }
    $residualErrors = array_values(array_filter($expected, static fn (array $issue): bool => $issue['level'] === 'Error'));
    $actualErrors = array_values(array_filter($existingPolicies, static fn (array $issue): bool => $issue['level'] === 'Error'));
    if ($residualErrors === [] || $wholeSignature($residualErrors) !== $wholeSignature($actualErrors)) {
        throw new RuntimeException('Independent Error records changed outside the exact possible-write policy. '.$workspace);
    }
    foreach ([1, 3] as $workers) {
        if ($wholeSignature($analyze('integrated', $workers)) !== $wholeSignature($existingPolicies)) {
            throw new RuntimeException('Integrated direct-reference diagnostics differ from independently measured possible-write policy: '.$workers.' workers. '.$workspace);
        }
    }
    file_put_contents($workspace.'/existing-policy-union.json', json_encode([
        'status' => 'PASS', 'standaloneGuaranteedDispatchNegativeGroupsUnchanged' => true,
        'existingPolicy' => 'ArgumentClosurePossibleWritePlugin', 'genuineWholePolicyErrorsRemoved' => $genuineErrors,
        'exactAdditionalErrorsRemoved' => 78, 'remainingErrorCount' => count($residualErrors),
        'allOtherCompleteIssuesPreserved' => true, 'productionOneAndThreeWorkerParity' => true, 'nativeTypesChanged' => false,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
}
if (file_exists($workspace.'/executed')) { throw new RuntimeException('Analyzed application bootstrap executed.'); }

file_put_contents($workspace.'/runtime-controls.php', <<<'PHP'
<?php
declare(strict_types=1);
final class InventedReferenceRunner {
    public function run(callable $callback, bool $catch = false): void {
        $hook = \Closure::fromCallable($callback);
        if ($catch) { try { $hook('checkpoint'); } catch (\RuntimeException) {} }
        else { $hook('checkpoint'); }
    }
    public function retry(callable $callback): void {
        $hook = \Closure::fromCallable($callback);
        for ($attempt = 0; $attempt < 2; $attempt++) { try { $hook('checkpoint'); } catch (\RuntimeException) {} }
    }
}
$runner = new InventedReferenceRunner;
$direct = false;
$runner->run(static function (string $stage) use (&$direct): void { $direct = true; });
$value = false;
$runner->run(static function (string $stage) use ($value): void { $value = true; });
$uninvoked = false;
$unused = static function () use (&$uninvoked): void { $uninvoked = true; };
$caught = false;
$runner->run(static function (string $stage) use (&$caught): void { $caught = true; throw new \RuntimeException('Caught.'); }, true);
$afterThrow = false;
$runner->run(static function (string $stage) use (&$afterThrow): void { throw new \RuntimeException('Before write.'); $afterThrow = true; }, true);
$reset = false;
$runner->run(static function (string $stage) use (&$reset): void { $reset = true; $reset = false; });
$finallyReset = false;
$runner->run(static function (string $stage) use (&$finallyReset): void { try { $finallyReset = true; } finally { $finallyReset = false; } });
$normal = false;
$uncaughtWrite = false;
try { $runner->run(static function (string $stage) use (&$uncaughtWrite): void { $uncaughtWrite = true; throw new \RuntimeException('Uncaught in runner.'); }); $normal = true; }
catch (\RuntimeException) {}
$retried = false;
$writes = 0;
$runner->retry(static function (string $stage) use (&$retried, &$writes): void {
    if (! $retried && $stage === 'checkpoint') { $retried = true; $writes++; throw new \RuntimeException('Retry.'); }
});
$finite = [];
foreach (['checkpoint', 'fallback'] as $selected) {
    $changed = false;
    $trace = [];
    $callback = static function (string $stage) use (&$changed, &$trace, $selected): void {
        $trace[] = $stage;
        if (! $changed && $stage === $selected) { $changed = true; throw new \RuntimeException('Stage.'); }
    };
    foreach (['checkpoint', 'fallback'] as $stage) { try { $callback($stage); } catch (\RuntimeException) {} }
    $finite[] = [$changed, $trace];
}
$laterFinally = false;
$atAssertion = false;
try { $runner->run(static function (string $stage) use (&$laterFinally): void { $laterFinally = true; }); $atAssertion = $laterFinally; }
finally { $laterFinally = false; }
echo json_encode([$direct, $value, $uninvoked, $caught, $afterThrow, $reset, $finallyReset, $normal, $uncaughtWrite, $retried, $writes, $finite, $atAssertion, $laterFinally], JSON_THROW_ON_ERROR);
PHP);
$process = proc_open([PHP_BINARY, '-d', 'opcache.enable_cli=0', $workspace.'/runtime-controls.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $workspace);
if (!is_resource($process)) { throw new RuntimeException('Cannot start invented reference runtime controls.'); }
fclose($pipes[0]);
$runtime = stream_get_contents($pipes[1]); fclose($pipes[1]);
$stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
$exit = proc_close($process);
$expectedRuntime = [true, false, false, true, false, false, false, false, true, true, 1, [[true, ['checkpoint', 'fallback']], [true, ['checkpoint', 'fallback']]], true, false];
if ($exit !== 0 || $stderr !== '' || json_decode($runtime, true, flags: JSON_THROW_ON_ERROR) !== $expectedRuntime) {
    throw new RuntimeException('Incorrect native PHP reference, catch, final-state or finite-stage boundary. '.$workspace);
}
file_put_contents($workspace.'/object-state-runtime.php', <<<'PHP'
<?php
declare(strict_types=1);
final class InventedStateWorker {
    public bool $ready = true;
    public function reset(): null { $this->ready = false; return null; }
}
function inventedReset(InventedStateWorker $worker): void { $worker->ready = false; }
function inventedResetArray(array $workers): void { $workers[0]->ready = false; }
function inventedInvoke(Closure $action): void { $action(); }
function inventedResetReference(array &$workers): void { $workers[0]->ready = false; }
$outcomes = [];
foreach (range(0, 7) as $variant) {
    $worker = new InventedStateWorker;
    $workers = [$worker];
    $changed = false;
    switch ($variant) {
        case 0: $worker->reset(); break;
        case 1: inventedReset($worker); break;
        case 2: inventedResetArray($workers); break;
        case 3: inventedInvoke(static function () use ($worker): void { $worker->ready = false; }); break;
        case 4: inventedInvoke($worker->reset(...)); break;
        case 5: inventedInvoke(static function () use ($workers): void { $workers[0]->ready = false; }); break;
        case 6: inventedInvoke(static function () use (&$workers): void { $workers[0]->ready = false; }); break;
        case 7: inventedResetReference($workers); break;
    }
    $hook = static function (string $stage) use (&$changed, $worker): void {
        if ($worker->ready) { $changed = true; throw new RuntimeException('Unreachable after reset.'); }
    };
    try { $hook('checkpoint'); } catch (RuntimeException) {}
    $outcomes[] = $changed;
}
echo json_encode($outcomes, JSON_THROW_ON_ERROR);
PHP);
$validateSyntax(['object-state-runtime.php']);
$process = proc_open([PHP_BINARY, '-d', 'opcache.enable_cli=0', $workspace.'/object-state-runtime.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $workspace);
if (! is_resource($process)) { throw new RuntimeException('Cannot start invented object-state runtime boundaries.'); }
fclose($pipes[0]); $objectRuntime = stream_get_contents($pipes[1]); fclose($pipes[1]);
$objectStderr = stream_get_contents($pipes[2]); fclose($pipes[2]); $objectExit = proc_close($process);
if ($objectExit !== 0 || $objectStderr !== '' || json_decode($objectRuntime, true, flags: JSON_THROW_ON_ERROR) !== array_fill(0, 8, false)) {
    throw new RuntimeException('Incorrect ordinary method/function, nested object or captured reference native boundary. '.$workspace);
}
require_once $package.'/vendor/autoload.php';
$scanChecks = (static function (string $root): int {
    $index = new \Ichinya\Laramago\Analyzer\StaticAnalysis\DirectCallbackReferenceEffects($root);
    $contents = file_get_contents($root.'/proof.php');
    $runners = file_get_contents($root.'/runners.php');
    $version = \Mago\Sdk\PHPVersion::fromParts(8, 5);
    $cancel = new class implements \Mago\Sdk\CancellationTokenInterface {
        public bool $cancelled = false;
        public function isCancelled(): bool { return $this->cancelled; }
        public function throwIfCancelled(): void { if ($this->cancelled) { throw new RuntimeException('Scan cancelled.'); } }
        public function subscribe(Closure $callback): int { return 0; }
        public function unsubscribe(int $subscription): void {}
    };
    $file = static function (string $path, string $bytes) use ($version): \Mago\Sdk\Syntax\SourceFile {
        return new \Mago\Sdk\Syntax\SourceFile($version, $path, $bytes, [],
            (new ReflectionClass(\Mago\Sdk\Internal\Syntax\NodeStore::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(\Mago\Sdk\Internal\Syntax\ResolvedNameStore::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(\Mago\Sdk\Internal\Syntax\TriviaStore::class))->newInstanceWithoutConstructor(), null);
    };
    $host = $file('proof.php', $contents);
    $callee = $file('runners.php', $runners);
    $scan = static function (array $files, bool $first = true, bool $last = true) use ($index, $version, $cancel): void {
        $index->scan(new \Mago\Sdk\Analyzer\CodebaseScanContext($version, $cancel, $files, $first, $last));
    };
    $checks = 0;
    $expect = static function (string $label, bool $known) use ($index, $contents, &$checks): void {
        if (($index->proofs('proof.php', $contents) !== []) !== $known) { throw new RuntimeException('Wrong reference scan state: '.$label); }
        $checks++;
    };
    $expect('unscanned', false);
    $scan([$host, $callee]); $expect('complete source', true);
    if ($index->proofs('other.php', $contents) !== [] || $index->proofs('proof.php', $contents.' ') !== []) { throw new RuntimeException('Foreign reference source snapshot selected.'); }
    $checks += 2;
    $proof = $index->proofs('proof.php', $contents)[0];
    if ($proof['hash'] !== hash('sha256', $contents) || $proof['local'] !== 'changed' || $proof['initial'] !== false) { throw new RuntimeException('Reference certificate lost fresh storage identity.'); }
    $checks++;
    $scan([$host], last: false); $expect('incomplete batch', false);
    $scan([$callee], first: false); $expect('completed second batch', true);
    $scan([]); $expect('new empty generation', false);
    $scan([$callee, $host]); $expect('complete reverse order', true);
    $scan([$host, $host, $callee]); $expect('duplicate caller path', false);
    $scan([$host, $callee]); $scan([$callee], first: false); $expect('duplicate across batches', false);
    $ticks = $file('runners.php', str_replace('<?php', '<?php declare(ticks=1);', $runners));
    $scan([$host, $ticks, $callee]); $expect('unsupported duplicate first', false);
    $scan([$host, $callee, $ticks]); $expect('unsupported duplicate last', false);
    $scan([$ticks, $host], last: false); $scan([$callee], first: false); $expect('unsupported duplicate across batches', false);
    $scan([$host, $callee, $file('duplicate.php', $runners)]); $expect('duplicate source class', false);
    $scan([$host, $callee, $file('broken.php', '<?php function broken( {')]); $expect('parse failure', false);
    $scan([$file('oversized.php', str_repeat(' ', 2_000_001)), $host, $callee]); $expect('oversized first file', false);
    $scan([$host, $callee, $file('oversized.php', str_repeat(' ', 2_000_001))]); $expect('oversized last file', false);
    $padding = '<?php '.str_repeat(' ', 1_999_980);
    $many = [$host, $callee];
    for ($number = 0; $number < 34; $number++) { $many[] = $file('aggregate'.$number.'.php', $padding); }
    $scan($many); $expect('aggregate byte budget exceeded', false);
    unset($many, $padding);
    $scan([$host, $callee]); $expect('recovered after failure', true);
    $cancel->cancelled = true;
    try { $scan([$host], last: false); throw new RuntimeException('Cancelled reference scan completed.'); }
    catch (RuntimeException $error) { if ($error->getMessage() !== 'Scan cancelled.') { throw $error; } }
    $cancel->cancelled = false;
    $expect('cancelled generation is unavailable', false);
    $scan([$host, $callee]); $expect('recovered after cancellation', true);
    $scan([$host, $callee], last: false); $expect('pending complete snapshots', false);
    $cancel->cancelled = true;
    try { $scan([$file('middle.php', '<?php')], first: false, last: false); throw new RuntimeException('Cancelled middle batch completed.'); }
    catch (RuntimeException $error) { if ($error->getMessage() !== 'Scan cancelled.') { throw $error; } }
    $cancel->cancelled = false;
    $expect('cancelled accumulated snapshots are unavailable', false);
    $scan([], first: false); $expect('cancelled generation cannot be republished', false);
    $scan([$host, $callee]); $expect('new generation recovers cancelled snapshots', true);
    if (PHP_OS_FAMILY === 'Windows') { $scan([$host, $file('PROOF.PHP', $contents), $callee]); $expect('case-folded duplicate', false); }
    $index->initialize(new \Mago\Sdk\Analyzer\InitializationContext($version, $cancel));
    $expect('initialization clears source generation', false);
    return $checks;
})($workspace);
echo 'Direct callback reference checks passed: '.count($cases).' source cases, '.$corrected.' exact native/control corrections, '.$retained.' retained negative groups, '.$contextChecks.' issue and metadata controls, '.$constructionChecks.' constructor and reflection controls, '.$nativeCoveredChecks.' native-covered domain controls, '.$scanChecks.' scan controls, 20 invented runtime boundaries, explicit root with spaces, nonstandard vendor directory and safe single-file deferral'.(in_array('--integrated', $argv, true) ? ', one and three integrated workers' : '').".\n";
