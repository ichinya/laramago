<?php
declare(strict_types=1);
namespace Invented\Argv\Case_Root_try;
file_put_contents(__DIR__.'/fixture-body-executed.txt', 'forbidden');
try {
$incoming = $_SERVER['argv'] ?? [];
$normalized = [];
if (is_array($incoming)) {
    foreach ($incoming as $entry) {
        if (! is_string($entry)) { exit(7); }
        $normalized[] = $entry;
    }
}
} catch (\Throwable $failure) { exit(8); }
function requireNumber(int $value): void {}
requireNumber('independent-invalid-argument');
