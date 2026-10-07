<?php
namespace Example\ConfigurationMembers\effectful_diagnostic;
final class Validator
{
    /** @return list<string> */
    public function inspect(): array
    {
        $errors = [];
        $rows = config('inventory.records', []);
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
                    $errors[] = consume($details);
                }
            }
        }
        return $errors;
    }
}