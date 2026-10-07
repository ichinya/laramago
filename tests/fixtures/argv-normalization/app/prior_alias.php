<?php
declare(strict_types=1);
namespace Invented\Argv\Case_Prior_alias;
file_put_contents(__DIR__.'/fixture-body-executed.txt', 'forbidden');
$alias =& $_SERVER['argv'];
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
