<?php
namespace Example\ConfigurationMembers\facade_positive;
function requireInteger(int $value): void {}
final class Validator
{
    /** @return list<string> */
    public function inspect(): array
    {
        $errors = [];
        $rows = \Illuminate\Support\Facades\Config::get('inventory.records', []);
        if (!is_array($rows)) {
            $errors[] = 'Records must be an array.';
            $rows = [];
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                $errors[] = 'A record must be an array.';
                continue;
            }
            if (($row['mode'] ?? '') === 'remote') {
                $details = $row['details'] ?? null;
                if (!is_array($details) || !isset($details['path'], $details['verification'])) {
                    $errors[] = 'Remote records need a path and verification.';
                }
            }
        }
        requireInteger($errors); // Independent argument Error must remain.
        return $errors;
    }
}