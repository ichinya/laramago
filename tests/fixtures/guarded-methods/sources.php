<?php
declare(strict_types=1);
// Invented consumer sources and bounded public library declarations; copied, never required.
return array (
  'bootstrap/app.php' => '<?php throw new RuntimeException(\'Application bootstrap must never execute.\');
',
  'closure-cases.php' => '<?php
namespace GuardedFixture;
class CapturedOwner {
    public function guarded(Lifecycle $app): void { if (method_exists($this, \'futureHook\')) { $app->booting(fn () => $this->futureHook($app)); } }
    public function guardedWithIsset(Lifecycle $app, array $state): void { if (isset($state[\'enabled\']) && method_exists($this, \'futureHook\')) { $app->booting(fn () => $this->futureHook($app)); } }
    public function wrongName(Lifecycle $app): void { if (method_exists($this, \'otherHook\')) { $app->booting(fn () => $this->futureHook($app)); } }
    public function wrongReceiver(Lifecycle $app, object $other): void { if (method_exists($other, \'futureHook\')) { $app->booting(fn () => $this->futureHook($app)); } }
    public function unguarded(Lifecycle $app): void { $app->booting(fn () => $this->futureHook($app)); }
    public function falseBranch(Lifecycle $app): void { if (! method_exists($this, \'futureHook\')) { $app->booting(fn () => $this->futureHook($app)); } }
    public function plainClosure(Lifecycle $app): void { if (method_exists($this, \'futureHook\')) { $app->booting(function () use ($app) { $this->futureHook($app); }); } }
    public function interveningStatement(Lifecycle $app): void { if (method_exists($this, \'futureHook\')) { consume([]); $app->booting(fn () => $this->futureHook($app)); } }
    public function wrongMethod(Lifecycle $app): void { if (method_exists($this, \'futureHook\')) { $app->booting(fn () => $this->otherHook($app)); } }
    public function staticArrow(Lifecycle $app): void { if (method_exists($this, \'futureHook\')) { $app->booting(static fn () => $this->futureHook($app)); } }
}
final class FinalOwner { public function guarded(Lifecycle $app): void { if (method_exists($this, \'futureHook\')) { $app->booting(fn () => $this->futureHook($app)); } } }',
  'contracts.php' => '<?php
namespace GuardedFixture;
interface Service { public function label(): string; }
class Specialized implements Service {
    public function label(): string { return \'item\'; }
    public function members(): array { return []; }
    public function needNumber(int $value): bool { return true; }
}
class Box {
    protected Service $service;
    public function read(): Service { return $this->service; }
    public function other(): Service { return $this->service; }
    public function mutate(): bool { return true; }
}
class ChangingBox extends Box {
    public function read(): Service { $this->mutate(); return $this->service; }
}
class Lifecycle {
    protected array $callbacks = [];
    public function booting($callback): void { $this->callbacks[] = $callback; }
}
function consume(array $items): bool { return true; }',
  'database/migrations/trap.php' => '<?php throw new RuntimeException(\'Migration execution is forbidden.\');
',
  'getter-cases.php' => '<?php
namespace GuardedFixture;
function sameGetterOr(Box $box): void { if (! $box->read() instanceof Specialized || ! consume($box->read()->members())) {} }
function sameGetterAnd(Box $box): void { if ($box->read() instanceof Specialized && consume($box->read()->members())) {} }
function wrongGetter(Box $box): void { if (! $box->read() instanceof Specialized || ! consume($box->other()->members())) {} }
function wrongReceiver(Box $box, Box $other): void { if (! $box->read() instanceof Specialized || ! consume($other->read()->members())) {} }
function interveningCall(Box $box): void { if (! $box->read() instanceof Specialized || $box->mutate() || ! consume($box->read()->members())) {} }
function unguarded(Box $box): void { consume($box->read()->members()); }
function changedImplementation(ChangingBox $box): void { if (! $box->read() instanceof Specialized || ! consume($box->read()->members())) {} }
function wrongMember(Box $box): void { if (! $box->read() instanceof Specialized || ! $box->read()->missing()) {} }
function declaredArgument(Box $box): void { if (! $box->read() instanceof Specialized || ! $box->read()->needNumber(\'bad\')) {} }
function negatedWrongBranch(Box $box): void { if (! $box->read() instanceof Specialized && consume($box->read()->members())) {} }',
);
