<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;

/** Reads literal Laravel regex rule parameters without executing the pattern. */
final class LiteralValidationRegex
{
    /**
     * @return list<array{rule: 'regex'|'not_regex', pattern: string, body: string, delimiter: string, modifiers: string}>|null
     */
    public static function from(Node\Expr $expression): ?array
    {
        $value = PhpSource::value($expression);
        if (is_string($value)) {
            return self::fromLiteral($value);
        }
        if (! $expression instanceof Node\Expr\Array_) {
            return null;
        }

        $rules = [];
        foreach ($expression->items as $item) {
            if ($item->unpack || $item->byRef || $item->key !== null) {
                return null;
            }
            $rule = PhpSource::value($item->value);
            if (! is_string($rule)) {
                return null;
            }
            $rules[] = $rule;
        }

        return self::fromLiteral($rules);
    }

    /**
     * Laravel explodes a scalar rule string on every pipe, but keeps each
     * string in a rule array intact. In particular, a regex alternation needs
     * an array entry to survive that first step.
     *
     * @param string|list<string> $literal
     * @return list<array{rule: 'regex'|'not_regex', pattern: string, body: string, delimiter: string, modifiers: string}>|null
     */
    public static function fromLiteral(string|array $literal): ?array
    {
        $rules = is_string($literal) ? explode('|', $literal) : $literal;
        $patterns = [];
        foreach ($rules as $rule) {
            $separator = strpos($rule, ':');
            $rawName = $separator === false ? $rule : substr($rule, 0, $separator);
            $name = strtolower($rawName);
            if (! in_array($name, ['regex', 'not_regex', 'notregex'], true)) {
                // Laravel tests the untrimmed name before Str::studly(trim()).
                // A later normalized Regex/NotRegex can receive CSV parameters.
                $dispatchName = strtolower((string) preg_replace('/[-_\s]+/u', '', trim($rawName)));
                if (in_array($dispatchName, ['regex', 'notregex'], true)) {
                    return null;
                }
                continue;
            }
            if ($separator === false) {
                return null;
            }
            $pattern = substr($rule, $separator + 1);
            $parts = self::delimited($pattern);
            if ($parts === null) {
                return null;
            }
            $patterns[] = [
                'rule' => $name === 'regex' ? 'regex' : 'not_regex',
                'pattern' => $pattern,
                'body' => $parts['body'],
                'delimiter' => $parts['delimiter'],
                'modifiers' => $parts['modifiers'],
            ];
        }

        return $patterns;
    }

    /** @return array{body: string, delimiter: string, modifiers: string}|null */
    private static function delimited(string $pattern): ?array
    {
        if ($pattern === '') {
            return null;
        }
        // PHP's PCRE wrapper skips leading ASCII whitespace before the delimiter.
        $start = strspn($pattern, " \t\n\r\v\f");
        if ($start === strlen($pattern)) {
            return null;
        }
        $opening = $pattern[$start];
        $byte = ord($opening);
        if ($byte < 33 || $byte > 126 || ctype_alnum($opening) || $opening === '\\') {
            return null;
        }
        $closing = match ($opening) {
            '(' => ')',
            '[' => ']',
            '{' => '}',
            '<' => '>',
            default => $opening,
        };
        $paired = $closing !== $opening;
        $depth = 1;
        $length = strlen($pattern);
        for ($index = $start + 1; $index < $length; $index++) {
            $current = $pattern[$index];
            if ($current === '\\') {
                if (++$index >= $length) {
                    return null;
                }
                continue;
            }
            // The PHP wrapper counts delimiter bytes even inside PCRE classes.
            if ($paired && $current === $opening) {
                $depth++;
            } elseif ($current === $closing && --$depth === 0) {
                $modifiers = substr($pattern, $index + 1);
                if (strspn($modifiers, "imnsxADSXUuJ \n\r") !== strlen($modifiers)) {
                    return null;
                }

                return [
                    'body' => substr($pattern, $start + 1, $index - $start - 1),
                    'delimiter' => $opening,
                    'modifiers' => $modifiers,
                ];
            }
        }

        return null;
    }
}
