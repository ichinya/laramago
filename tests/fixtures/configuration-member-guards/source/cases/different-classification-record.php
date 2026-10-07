<?php
namespace Example\ConfigurationMembers\different_classification_record;
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
            if (($other['mode'] ?? '') === 'remote') {
                $details = $row['details'] ?? null;
                if (!is_array($details) || !isset($details['path'], $details['verification'])) {
                    $errors[] = 'Remote records need a path and verification.';
                }
            }
        }
        return $errors;
    }
}