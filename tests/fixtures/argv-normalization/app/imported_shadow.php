<?php
declare(strict_types=1);
namespace Invented\Argv\Case_Imported_shadow;
file_put_contents(__DIR__.'/fixture-body-executed.txt', 'forbidden');
use function IndependentPredicate\acceptArray as is_array;
$incoming = $_SERVER['argv'] ?? [];
$normalized = [];
if (is_array($incoming)) {
    foreach ($incoming as $entry) {
        if (! is_string($entry)) { exit(7); }
        $normalized[] = $entry;
    }
}
function requireNumber(int $value): void {}
requireNumber('independent-invalid-argument');
namespace IndependentPredicate;
function acceptArray(mixed $value): bool { return true; }
