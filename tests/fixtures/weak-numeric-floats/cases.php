<?php

declare(strict_types=1);

// Invented source only. No generated scenario/class/getter body is included or executed.
$numeric = '\FloatFixtures\numeric()';
$argument = 'function scenario(): void { \FloatFixtures\consume('.$numeric.'); }';
$return = 'function scenario(): float { return '.$numeric.'; }';
$private = 'class InvoiceTotals { private function accept(float $value): void {} public function scenario(\FloatFixtures\DecimalInput $input): void { $this->accept($input->amount); } }';

$base = [
    'weak external function argument' => ['source' => $argument, 'remove' => 1, 'code' => 'invalid-argument'],
    'weak caller strict declared function argument' => ['source' => 'function scenario(): void { \StrictFloatFixtures\consume('.$numeric.'); }', 'remove' => 1, 'code' => 'invalid-argument'],
    'weak imported function target' => ['source' => 'use function FloatFixtures\consume as takeFloat; function scenario(): void { takeFloat('.$numeric.'); }', 'remove' => 1, 'code' => 'invalid-argument'],
    'weak direct source function' => ['source' => 'function acceptLocal(float $value): void {} function scenario(): void { acceptLocal('.$numeric.'); }', 'remove' => 1, 'code' => 'invalid-argument'],
    'weak final static function target' => ['source' => 'function scenario(): void { \FloatFixtures\FloatSink::take('.$numeric.'); }', 'remove' => 1, 'code' => 'invalid-argument'],
    'weak private lexical method' => ['source' => $private, 'remove' => 1, 'code' => 'invalid-argument'],
    'weak private lexical child shadow' => ['source' => $private.' final class ChildTotals extends InvoiceTotals { public function accept(string $value): void {} }', 'remove' => 1, 'code' => 'invalid-argument'],
    'weak reordered named function argument' => ['source' => 'function scenario(): void { \FloatFixtures\pair(value: '.$numeric.', label: "label"); }', 'remove' => 1, 'code' => 'invalid-argument'],
    'weak reordered named private method' => ['source' => 'class InvoiceTotals { private function accept(string $label, float $value): void {} public function scenario(): void { $this->accept(value: '.$numeric.', label: "label"); } }', 'remove' => 1, 'code' => 'invalid-argument'],
    'weak named argument with omitted literal default' => ['source' => 'function acceptLocal(float $value, string $label = "default"): void {} function scenario(): void { acceptLocal(value: '.$numeric.'); }', 'remove' => 1, 'code' => 'invalid-argument'],
    'explicit weak zero argument file' => ['directive' => 'declare(strict_types=0);', 'source' => $argument, 'remove' => 1, 'code' => 'invalid-argument'],
    'weak native function return' => ['source' => $return, 'remove' => 1, 'code' => 'invalid-return-statement'],
    'weak native method return' => ['source' => 'class ExchangeValue { public function scenario(): float { return '.$numeric.'; } }', 'remove' => 1, 'code' => 'invalid-return-statement'],
    'weak float branch return' => ['source' => 'function scenario(bool $select): float { return $select ? '.$numeric.' : 1.5; }', 'remove' => 1, 'code' => 'invalid-return-statement'],
    'explicit weak zero return file' => ['directive' => 'declare(strict_types=0);', 'source' => $return, 'remove' => 1, 'code' => 'invalid-return-statement'],
    'residual unsafe argument use' => ['source' => 'function scenario(): void { \FloatFixtures\consume('.$numeric.'); '.$numeric.'->missing(); }', 'remove' => 1, 'code' => 'invalid-argument', 'residual' => true],
    'strict caller weak parameter' => ['directive' => 'declare(strict_types=1);', 'source' => $argument, 'remove' => 0],
    'strict declaring return file' => ['directive' => 'declare(strict_types=1);', 'source' => $return, 'remove' => 0],
    'unknown ticks directive argument' => ['directive' => 'declare(ticks=1);', 'source' => $argument, 'remove' => 0],
    'unknown ticks directive return' => ['directive' => 'declare(ticks=1);', 'source' => $return, 'remove' => 0],
    'arbitrary string argument' => ['source' => 'function scenario(): void { \FloatFixtures\consume(\FloatFixtures\arbitrary()); }', 'remove' => 0],
    'arbitrary string return' => ['source' => 'function scenario(): float { return \FloatFixtures\arbitrary(); }', 'remove' => 0],
    'nonnumeric literal argument' => ['source' => 'function scenario(): void { \FloatFixtures\consume("text"); }', 'remove' => 0],
    'nullable numeric argument' => ['source' => '/** @param numeric-string|null $value */ function scenario(?string $value): void { \FloatFixtures\consume($value); }', 'remove' => 0],
    'nullable numeric return' => ['source' => '/** @param numeric-string|null $value */ function scenario(?string $value): float { return $value; }', 'remove' => 0],
    'mixed argument' => ['source' => 'function scenario(mixed $value): void { \FloatFixtures\consume($value); }', 'remove' => 0],
    'mixed return' => ['source' => 'function scenario(mixed $value): float { return $value; }', 'remove' => 0],
    'array argument' => ['source' => 'function scenario(): void { \FloatFixtures\consume([]); }', 'remove' => 0],
    'array return' => ['source' => 'function scenario(): float { return []; }', 'remove' => 0],
    'integer union outside initial domain' => ['source' => 'function scenario(bool $select): float { return $select ? '.$numeric.' : 1; }', 'remove' => 0],
    'narrow parameter documentation' => ['source' => 'function scenario(): void { \FloatFixtures\narrow('.$numeric.'); }', 'remove' => 0],
    'PHPDoc only parameter' => ['source' => 'function scenario(): void { \FloatFixtures\documented('.$numeric.'); }', 'remove' => 0],
    'PHPDoc only return' => ['source' => '/** @return float */ function scenario() { return '.$numeric.'; }', 'remove' => 0],
    'stronger return annotation' => ['source' => '/** @return float(1.0) */ function scenario(): float { return '.$numeric.'; }', 'remove' => 0],
    'reference parameter contract' => ['source' => 'function scenario(): void { $value = '.$numeric.'; \FloatFixtures\reference($value); }', 'remove' => 0],
    'variadic contract' => ['source' => 'function scenario(): void { \FloatFixtures\variadic('.$numeric.'); }', 'remove' => 0],
    'unpacked argument binding' => ['source' => 'function scenario(): void { \FloatFixtures\consume(...['.$numeric.']); }', 'remove' => 0],
    'unknown named parameter' => ['source' => 'function scenario(): void { \FloatFixtures\pair(value: '.$numeric.', unknown: "label"); }', 'remove' => 0],
    'missing required named parameter' => ['source' => 'function scenario(): void { \FloatFixtures\pair(value: '.$numeric.'); }', 'remove' => 0],
    'open static method dispatch' => ['source' => 'function scenario(): void { \FloatFixtures\OpenFloatSink::take('.$numeric.'); }', 'remove' => 0],
    'public instance method dispatch' => ['source' => 'class InvoiceTotals { public function accept(float $value): void {} public function scenario(): void { $this->accept('.$numeric.'); } }', 'remove' => 0],
    'dynamic function binding' => ['source' => 'function scenario(): void { $call = "FloatFixtures\\consume"; $call('.$numeric.'); \FloatFixtures\consume([]); }', 'remove' => 0],
    'dynamic method binding' => ['source' => 'class InvoiceTotals { private function accept(float $value): void {} public function scenario(): void { $method = "accept"; $this->{$method}('.$numeric.'); \FloatFixtures\consume([]); } }', 'remove' => 0],
    'reference caller storage' => ['source' => 'function scenario(): void { $value = '.$numeric.'; $alias =& $value; \FloatFixtures\consume($value); }', 'remove' => 0],
    'virtual property float assignment' => ['source' => 'function scenario(\FloatFixtures\VirtualFloatStorage $storage): void { $storage->amount = '.$numeric.'; }', 'remove' => 0, 'required' => 'invalid-property-assignment-value'],
    'virtual property float branch assignment' => ['source' => 'function scenario(\FloatFixtures\VirtualFloatStorage $storage, bool $select): void { $storage->amount = $select ? '.$numeric.' : 1.0; }', 'remove' => 0, 'required' => 'invalid-property-assignment-value'],
    'physical untyped documented float assignment' => ['source' => 'final class Storage { /** @var float */ public $amount; } function scenario(Storage $storage): void { $storage->amount = '.$numeric.'; }', 'remove' => 0, 'required' => 'invalid-property-assignment-value'],
];

$numeric = '\FloatFixtures\numeric()';
$private = 'class InvoiceTotals { private function accept(float $value): void {} public function scenario(): void { $this->accept('.$numeric.'); } }';
$local = 'class ContractMixin {} ';

return $base + [
    'physical private method with FQCN mixin' => ['source' => '/** @mixin \FloatFixtures\DecimalInput */ '.$private, 'remove' => 1],
    'physical private method with imported mixin' => ['source' => 'use FloatFixtures\DecimalInput as InputContract; /** @mixin InputContract */ '.$private, 'remove' => 1],
    'physical private method with namespace mixin' => ['source' => $local.'/** @mixin ContractMixin */ '.$private, 'remove' => 1],
    'physical private method with two named mixins' => ['source' => $local."/**\n * @mixin ContractMixin\n * @mixin \\FloatFixtures\\DecimalInput\n */ ".$private, 'remove' => 1],
    'physical private method takes precedence over conflicting mixin' => ['source' => 'class ContractMixin { public function accept(string $value): void {} } /** @mixin ContractMixin */ '.$private, 'remove' => 1],
    'physical final static method with mixin' => ['source' => $local.'/** @mixin ContractMixin */ final class FloatTarget { public static function accept(float $value): void {} } function scenario(): void { FloatTarget::accept('.$numeric.'); }', 'remove' => 1],
    'physical method float return with mixin' => ['source' => $local.'/** @mixin ContractMixin */ class FloatTarget { public function scenario(): float { return '.$numeric.'; } }', 'remove' => 1, 'code' => 'invalid-return-statement'],
    'native function return beside declared mixin owner' => ['source' => $local.'/** @mixin ContractMixin */ class FloatTarget {} function scenario(): float { return '.$numeric.'; }', 'remove' => 1, 'code' => 'invalid-return-statement'],
    'mixin only virtual callable has no physical owner declaration' => ['source' => 'class ContractMixin { public function accept(float $value): void {} } /** @mixin ContractMixin */ class InvoiceTotals { public function scenario(): void { $this->accept('.$numeric.'); } } function unsafe(): void { \FloatFixtures\consume([]); }', 'remove' => 0],
    'inherited method through named mixin context' => ['source' => $local.'class ParentTotals { public function accept(float $value): void {} } /** @mixin ContractMixin */ class InvoiceTotals extends ParentTotals { public function scenario(): void { $this->accept('.$numeric.'); } }', 'remove' => 0],
    'physical method stronger parameter doc with named mixin' => ['source' => $local.'/** @mixin ContractMixin */ class InvoiceTotals { /** @param float(1.0) $value */ private function accept(float $value): void {} public function scenario(): void { $this->accept('.$numeric.'); } }', 'remove' => 0],
    'physical method stronger return doc with named mixin' => ['source' => $local.'/** @mixin ContractMixin */ class InvoiceTotals { /** @return float(1.0) */ public function scenario(): float { return '.$numeric.'; } }', 'remove' => 0, 'code' => 'invalid-return-statement'],
    'strict physical private method with named mixin' => ['directive' => 'declare(strict_types=1);', 'source' => $local.'/** @mixin ContractMixin */ '.$private, 'remove' => 0],
    'generic mixin source contract remains deferred' => ['source' => '/** @template T */ class ContractMixin {} /** @mixin ContractMixin<int> */ '.$private, 'remove' => 0],
    'union mixin source contract remains deferred' => ['source' => $local.'/** @mixin ContractMixin|\FloatFixtures\DecimalInput */ '.$private, 'remove' => 0],
    'duplicate source mixins remain deferred' => ['source' => $local."/**\n * @mixin ContractMixin\n * @mixin ContractMixin\n */ ".$private, 'remove' => 0],
    'pseudo self mixin remains deferred' => ['source' => '/** @mixin self */ '.$private, 'remove' => 0],
    'unresolved imported mixin remains deferred' => ['source' => 'use Missing\ContractMixin as InputContract; /** @mixin InputContract */ '.$private, 'remove' => 0],
    'dialect mixin tag remains deferred' => ['source' => $local.'/** @phpstan-mixin ContractMixin */ '.$private, 'remove' => 0],
    'doc only float with named mixin remains deferred' => ['source' => $local.'/** @mixin ContractMixin */ class InvoiceTotals { /** @param float $value */ private function accept($value): void {} public function scenario(): void { $this->accept('.$numeric.'); } }', 'remove' => 0],
];
