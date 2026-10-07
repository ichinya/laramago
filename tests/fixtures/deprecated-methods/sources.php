<?php
declare(strict_types=1);
// Invented consumer sources and bounded public library declarations; copied, never required.
return array (
  'bootstrap/app.php' => '<?php throw new RuntimeException(\'Application execution is forbidden.\');
',
  'cases.php' => '<?php
namespace DeprecationFixture;
class Consumer {
    public function request(\\Illuminate\\Http\\Request $request): void { $request->get(\'item\'); }
    public function reflection(\\ReflectionMethod $method): void { $method->setAccessible(true); }
    public function coverage(\\SebastianBergmann\\CodeCoverage\\Filter $filter): void { (new \\SebastianBergmann\\CodeCoverage\\Driver\\Selector)->forLineCoverage($filter); }
    public function requestBad(\\Illuminate\\Http\\Request $request): void { $request->get([]); }
    public function reflectionBad(\\ReflectionMethod $method): void { $method->setAccessible([]); }
    public function coverageBad(): void { (new \\SebastianBergmann\\CodeCoverage\\Driver\\Selector)->forLineCoverage(\'wrong\'); }
    public function freshReflection(): void { $method = new \\ReflectionMethod(Shape::class, \'action\'); $method->setAccessible(true); }
    public function unrelated(OtherService $service): void { $service->old(\'item\'); }
    public function inherited(ChildRequest $request): void { $request->get(\'item\'); }
    public function shadow(ShadowRequest $request): void { $request->get(\'item\'); }
    public function nullable(?\\Illuminate\\Http\\Request $request): void { $request->get(\'item\'); }
    public function dynamic(\\Illuminate\\Http\\Request $request, string $name): void { $request->$name(\'item\'); }
    public function rebound(\\Illuminate\\Http\\Request $request): void { $request = new \\Illuminate\\Http\\Request; $request->get(\'item\'); }
    public function alias(\\Illuminate\\Http\\Request $request): void { $other =& $request; $request->get(\'item\'); }
    public function captured(\\Illuminate\\Http\\Request $request): void { $callback = fn () => $request->get(\'item\'); }
    public function missing(\\Illuminate\\Http\\Request $request): void { $request->absent(\'item\'); }
    public function unknown(object $request): void { $request->get(\'item\'); }
    public function named(\\Illuminate\\Http\\Request $request): void { $request->get(key: \'item\'); }
    public function unpacked(\\Illuminate\\Http\\Request $request, array $values): void { $request->get(...$values); }
    public function unrelatedReflection(\\ReflectionProperty $property): void { $property->setAccessible(true); }
}',
  'database/migrations/trap.php' => '<?php throw new RuntimeException(\'Migration execution is forbidden.\');
',
  'declarations.php' => '<?php
namespace DeprecationFixture;
class OtherService { /** @deprecated Use current instead. */ public function old(string $value): string { return $value; } }
class ChildRequest extends \\Illuminate\\Http\\Request {}
class ShadowRequest extends \\Illuminate\\Http\\Request { public function get(string $key, mixed $default = null): mixed { return $default; } }
class Shape { public function action(): void {} }',
  'equal-a.php' => '<?php
namespace EqualFixture;
class CallerA { public function call(\\Illuminate\\Http\\Request $item): void { $item->get(\'x\'); } }
',
  'equal-b.php' => '<?php
namespace EqualFixture;
class CallerB { public function call(\\Illuminate\\Http\\Request $item): void { $item->get(\'x\'); } }
',
  'vendor/example/coverage.php' => '<?php
namespace SebastianBergmann\\CodeCoverage;
class Filter {}
namespace SebastianBergmann\\CodeCoverage\\Driver;
class Driver {}
class Selector {
    /** @deprecated Use the coverage factory instead. */
    public function forLineCoverage(\\SebastianBergmann\\CodeCoverage\\Filter $filter): Driver { return new Driver; }
}',
  'vendor/example/request.php' => '<?php
namespace Illuminate\\Http;
class Request {
    /** @deprecated Use input instead. */
    public function get(string $key, mixed $default = null): mixed { return $default; }
}',
);
