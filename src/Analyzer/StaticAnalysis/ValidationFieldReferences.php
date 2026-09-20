<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;

/** Positive source hints for fields named by literal Laravel validation rules. */
final class ValidationFieldReferences
{
    /**
     * @return list<array{field: string, rule: string, reference: string, implicit: bool, source: Node\Scalar\String_}>
     */
    public static function from(Node\Expr $rules): array
    {
        if (! $rules instanceof Node\Expr\Array_) {
            return [];
        }

        $effective = [];
        foreach ($rules->items as $item) {
            // A dynamic key or unpack may replace an earlier declaration.
            if ($item->unpack || $item->byRef || ! $item->key instanceof Node\Scalar\String_) {
                return [];
            }
            $effective[$item->key->value] = [$item->key->value, $item->value];
        }

        $references = [];
        foreach ($effective as [$field, $value]) {
            array_push($references, ...self::fromValue($field, $value));
        }

        return $references;
    }

    /**
     * Use for a source-proven single field rule value, including Validator::sometimes().
     *
     * @return list<array{field: string, rule: string, reference: string, implicit: bool, source: Node\Scalar\String_}>
     */
    public static function fromValue(string $field, Node\Expr $value): array
    {
        if ($value instanceof Node\Scalar\String_) {
            $rules = explode('|', $value->value);
            $source = $value;
        } elseif ($value instanceof Node\Expr\Array_) {
            $rules = [];
            foreach ($value->items as $item) {
                if ($item->unpack || $item->byRef || $item->key !== null) {
                    return [];
                }
                if ($item->value instanceof Node\Scalar\String_) {
                    $rules[] = $item->value;
                }
            }
            $source = null;
        } else {
            return [];
        }

        $references = [];
        foreach ($rules as $rule) {
            $literal = $rule instanceof Node\Scalar\String_ ? $rule : $source;
            if (! $literal instanceof Node\Scalar\String_) {
                continue;
            }
            $text = $rule instanceof Node\Scalar\String_ ? $rule->value : $rule;
            $colon = strpos($text, ':');
            $name = $colon === false ? $text : substr($text, 0, $colon);
            $key = strtolower(str_replace(['-', '_', ' '], '', trim($name)));
            $positions = self::fieldPositions($key);
            if ($positions === null) {
                continue;
            }
            /** @var list<string|null> $parameters */
            $parameters = $colon === false ? [] : str_getcsv(substr($text, $colon + 1), escape: '\\');
            if ($key === 'confirmed' && ! isset($parameters[0])) {
                $references[] = [
                    'field' => $field,
                    'rule' => strtolower(trim($name)),
                    'reference' => $field.'_confirmation',
                    'implicit' => true,
                    'source' => $literal,
                ];
                continue;
            }
            foreach ($parameters as $position => $parameter) {
                if (! is_string($parameter) || $parameter === '' || ! self::isFieldPosition($positions, $position)) {
                    continue;
                }
                $references[] = [
                    'field' => $field,
                    'rule' => strtolower(trim($name)),
                    'reference' => $parameter,
                    'implicit' => false,
                    'source' => $literal,
                ];
            }
        }

        return $references;
    }

    /** 0 is the first parameter only; -1 means every parameter. */
    private static function fieldPositions(string $rule): ?int
    {
        return match ($rule) {
            'same',
            'confirmed',
            'inarray',
            'excludeif',
            'excludeunless',
            'excludewith',
            'excludewithout',
            'requiredif',
            'requiredunless',
            'requiredifaccepted',
            'requiredifdeclined',
            'acceptedif',
            'declinedif',
            'presentif',
            'presentunless',
            'prohibitedif',
            'prohibitedunless',
            'prohibitedifaccepted',
            'prohibitedifdeclined',
            'missingif',
            'missingunless',
                => 0,
            'different',
            'requiredwith',
            'requiredwithall',
            'requiredwithout',
            'requiredwithoutall',
            'presentwith',
            'presentwithall',
            'missingwith',
            'missingwithall',
            'prohibits',
                => -1,
            default => null,
        };
    }

    private static function isFieldPosition(int $positions, int $position): bool
    {
        return $positions === -1 || $position === $positions;
    }
}
