<?php namespace InventedWarningCases\K02; use Testo\Assert; final class Example { public function check(): void {
    $bag = []; $dispatcher = new \InventedWarningContracts\DirectDispatcher;
    $first = $dispatcher->invoke(new \stdClass, function (\stdClass $input) use (&$bag): \stdClass {
        $bag[0] = \InventedWarningContracts\nullableValue($input); return new \stdClass;
    });
    $second = $dispatcher->invoke(new \stdClass, function (\stdClass $input) use (&$bag): \stdClass {
        $bag[1] = \InventedWarningContracts\nullableValue($input); return new \stdClass;
    });
    $bag = []; Assert::same($bag[0], null);
} public function independentUnsafe(): void { (new \stdClass)->missing(); } } file_put_contents(__DIR__."/case-executed", "Fixture bodies must never execute.");