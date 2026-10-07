<?php namespace InventedWarningCases\A03; use Testo\Assert; final class Example { public function check(string $fault): void {
    $bag = []; $flag = false; $dispatcher = new \InventedWarningContracts\StageDispatcher;
    $dispatcher->invoke(function (string $stage) use (&$bag, &$flag, $fault): void {
        $bag[] = $stage;
        if (!$flag && $stage === $fault) { $flag = true; throw new \RuntimeException("fault {$stage}"); }
    });
    $flag = true; Assert::true($flag);
    $index = array_search('done', $bag, true);
    Assert::same($bag[array_key_last($bag)], 'done');
} public function independentUnsafe(): void { (new \stdClass)->missing(); } } file_put_contents(__DIR__."/case-executed", "Fixture bodies must never execute.");