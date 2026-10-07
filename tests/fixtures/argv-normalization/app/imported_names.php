<?php
declare(strict_types=1);
namespace Invented\Argv\Case_Imported_names;
file_put_contents(__DIR__.'/fixture-body-executed.txt', 'forbidden');
use function is_array as arrayPredicate;
use function is_string as textPredicate;
$incoming = $_SERVER['argv'] ?? [];
$normalized = [];
if (arrayPredicate($incoming)) {
    foreach ($incoming as $entry) {
        if (! textPredicate($entry)) { exit(7); }
        $normalized[] = $entry;
    }
}
function requireNumber(int $value): void {}
requireNumber('independent-invalid-argument');
