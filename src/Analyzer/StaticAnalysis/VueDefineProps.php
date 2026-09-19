<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** Reads literal prop names from an inline Vue script-setup block without running JavaScript. */
final class VueDefineProps
{
    /** @return array{names: list<string>, complete: bool}|null */
    public function read(string $source): ?array
    {
        if (strlen($source) > 524288 || str_contains($source, "\0")) {
            return null;
        }
        $script = $this->scriptSetup($source);
        if ($script === null) {
            return null;
        }
        $tokens = $this->tokens($script);
        if ($tokens === null) {
            return null;
        }

        $result = null;
        $stack = [];
        $pairs = ['(' => ')', '[' => ']', '{' => '}'];
        foreach ($tokens as $index => $token) {
            if ($token['kind'] === 'identifier' && $token['value'] === 'defineProps') {
                $previous = $tokens[$index - 1]['value'] ?? null;
                $directInitializer =
                    $previous === '='
                    && in_array($tokens[$index - 3]['value'] ?? null, ['const', 'let', 'var'], true)
                    && ($tokens[$index - 2]['kind'] ?? null) === 'identifier';
                if (
                    $result !== null
                    || $stack !== []
                    || ! in_array($previous, [null, ';'], true)
                    && ! $directInitializer
                ) {
                    return null;
                }
                $result = $this->macro($tokens, $index + 1);
                if ($result === null) {
                    return null;
                }
            }
            if ($token['kind'] !== 'punctuation') {
                continue;
            }
            $value = $token['value'];
            if (isset($pairs[$value])) {
                $stack[] = $pairs[$value];
                if (count($stack) > 64) {
                    return null;
                }
            } elseif (in_array($value, [')', ']', '}'], true)) {
                if (array_pop($stack) !== $value) {
                    return null;
                }
            }
        }

        return $stack === [] ? $result : null;
    }

    private function scriptSetup(string $source): ?string
    {
        $offset = 0;
        $found = null;
        $templateDepth = 0;
        while ($offset < strlen($source)) {
            $match = [];
            if (
                preg_match(
                    '~<!--.*?-->|</?[A-Za-z][A-Za-z0-9:-]*\b(?:"[^"]*"|\'[^\']*\'|[^\'">])*>~is',
                    substr($source, $offset),
                    $match,
                ) !== 1
            ) {
                break;
            }
            $tag = $match[0] ?? null;
            if (! is_string($tag)) {
                return null;
            }
            $position = strpos($source, $tag, $offset);
            if ($position === false) {
                return null;
            }
            $offset = $position + strlen($tag);
            if (str_starts_with($tag, '<!--')) {
                continue;
            }
            $tagName = [];
            if (preg_match('~^</?([A-Za-z][A-Za-z0-9:-]*)\b~i', $tag, $tagName) !== 1) {
                return null;
            }
            $kind = strtolower($tagName[1]);
            if (! in_array($kind, ['template', 'style', 'script'], true)) {
                if ($templateDepth === 0) {
                    // Unknown SFC blocks can contain arbitrary text that looks
                    // like a script tag. Their boundaries need a real SFC parser.
                    return null;
                }
                continue;
            }
            if (str_starts_with($tag, '</')) {
                if ($kind === 'template') {
                    $templateDepth--;
                    if ($templateDepth < 0) {
                        return null;
                    }
                }
                continue;
            }
            if ($kind === 'template') {
                if (! str_ends_with($tag, '/>')) {
                    $templateDepth++;
                }
                continue;
            }
            $close = stripos($source, '</'.$kind, $offset);
            if (
                $close === false
                || preg_match('~\A</'.preg_quote($kind, '~').'\s*>~i', substr($source, $close)) !== 1
            ) {
                return null;
            }
            $body = substr($source, $offset, $close - $offset);
            $offset = $close + strcspn(substr($source, $close), '>') + 1;
            if ($kind !== 'script' || $templateDepth !== 0) {
                continue;
            }
            $attributes = substr($tag, 7, -1);
            $attributeNames = preg_replace('/"[^"]*"|\'[^\']*\'/', '', $attributes);
            if ($attributeNames === null) {
                return null;
            }
            if (preg_match('/(?:^|\s)setup(?:\s|$)/i', trim($attributeNames)) !== 1) {
                continue;
            }
            if (preg_match('/(?:^|\s)src\s*=/i', $attributeNames) === 1 || $found !== null) {
                return null;
            }
            $found = $body;
        }

        return $templateDepth === 0 ? $found : null;
    }

    /** @return list<array{kind: string, value: string}>|null */
    private function tokens(string $source): ?array
    {
        $tokens = [];
        $length = strlen($source);
        for ($i = 0; $i < $length;) {
            if (count($tokens) > 50000) {
                return null;
            }
            $char = $source[$i];
            if (ctype_space($char)) {
                $i++;
                continue;
            }
            if ($char === '/' && ($source[$i + 1] ?? '') === '/') {
                $end = strpos($source, "\n", $i + 2);
                $i = $end === false ? $length : $end + 1;
                continue;
            }
            if ($char === '/' && ($source[$i + 1] ?? '') === '*') {
                $end = strpos($source, '*/', $i + 2);
                if ($end === false) {
                    return null;
                }
                $i = $end + 2;
                continue;
            }
            if ($char === '\'' || $char === '"' || $char === '`') {
                $quote = $char;
                $value = '';
                $escaped = false;
                $i++;
                for (; $i < $length && $source[$i] !== $quote; $i++) {
                    if ($source[$i] === '\\') {
                        if (++$i >= $length) {
                            return null;
                        }
                        $escaped = true;
                        $value .= $source[$i];
                    } else {
                        $value .= $source[$i];
                    }
                }
                if ($i >= $length) {
                    return null;
                }
                $i++;
                // Template strings may interpolate code. Never inspect their contents.
                $tokens[] = [
                    'kind' => $quote === '`' ? 'template' : ($escaped ? 'escaped-string' : 'string'),
                    'value' => $value,
                ];
                continue;
            }
            if (preg_match('/[A-Za-z_$]/', $char) === 1) {
                $start = $i++;
                while ($i < $length && preg_match('/[A-Za-z0-9_$]/', $source[$i]) === 1) {
                    $i++;
                }
                $tokens[] = ['kind' => 'identifier', 'value' => substr($source, $start, $i - $start)];
                continue;
            }
            if ($char === '/') {
                $startsRegex = $this->startsRegex($tokens);
                $end = $i + 1;
                $inClass = false;
                for (; $end < $length && $source[$end] !== "\n" && $source[$end] !== "\r"; $end++) {
                    if ($source[$end] === '\\') {
                        $end++;
                    } elseif ($source[$end] === '[') {
                        $inClass = true;
                    } elseif ($source[$end] === ']') {
                        $inClass = false;
                    } elseif ($source[$end] === '/' && ! $inClass) {
                        break;
                    }
                }
                $closed = $end < $length && $source[$end] === '/' && ! $inClass;
                if ($startsRegex) {
                    if (! $closed) {
                        return null;
                    }
                    $i = $end + 1;
                    while ($i < $length && ctype_alpha($source[$i])) {
                        $i++;
                    }
                    $tokens[] = ['kind' => 'regex', 'value' => ''];
                    continue;
                }
                // Slash can follow a closing condition or parenthesis and still
                // start a regex. If that uncertain span mentions the macro,
                // refuse the file instead of tokenizing regex text as code.
                if ($closed && str_contains(substr($source, $i + 1, $end - $i - 1), 'defineProps')) {
                    return null;
                }
            }
            if (substr($source, $i, 3) === '...') {
                $tokens[] = ['kind' => 'punctuation', 'value' => '...'];
                $i += 3;
                continue;
            }
            if (substr($source, $i, 2) === '=>') {
                $tokens[] = ['kind' => 'punctuation', 'value' => '=>'];
                $i += 2;
                continue;
            }
            $tokens[] = ['kind' => 'punctuation', 'value' => $char];
            $i++;
        }

        return $tokens;
    }

    /** @param list<array{kind: string, value: string}> $tokens */
    private function startsRegex(array $tokens): bool
    {
        $previous = $tokens[count($tokens) - 1]['value'] ?? null;

        return $previous === null
        || in_array($previous, ['=', '(', '[', '{', ',', ':', ';', '!', '?', '=>', 'return', 'throw'], true);
    }

    /**
     * @param list<array{kind: string, value: string}> $tokens
     * @return array{names: list<string>, complete: bool}|null
     */
    private function macro(array $tokens, int $index): ?array
    {
        $type = false;
        $body = null;
        if (($tokens[$index]['value'] ?? null) === '<') {
            $type = true;
            $index++;
            if (($tokens[$index]['value'] ?? null) !== '{') {
                return null;
            }
            $body = $this->delimited($tokens, $index, '{', '}');
            if ($body === null || ($tokens[$body['next']]['value'] ?? null) !== '>') {
                return null;
            }
            $index = $body['next'] + 1;
        }
        if (($tokens[$index]['value'] ?? null) !== '(') {
            return null;
        }
        $arguments = $this->delimited($tokens, $index, '(', ')');
        if ($arguments === null) {
            return null;
        }
        if ($type && $body !== null) {
            return $arguments['body'] === [] ? $this->names($body['body'], true, true) : null;
        }
        $argument = $arguments['body'];
        $open = $argument[0]['value'] ?? null;
        if ($open !== '[' && $open !== '{') {
            return null;
        }
        $close = $open === '[' ? ']' : '}';
        $literal = $this->delimited($argument, 0, $open, $close);
        if ($literal === null || $literal['next'] !== count($argument)) {
            return null;
        }

        return $this->names($literal['body'], $open === '{', false);
    }

    /**
     * @param list<array{kind: string, value: string}> $tokens
     * @return array{body: list<array{kind: string, value: string}>, next: int}|null
     */
    private function delimited(array $tokens, int $start, string $open, string $close): ?array
    {
        if (($tokens[$start]['value'] ?? null) !== $open) {
            return null;
        }
        $stack = [$close];
        $pairs = ['(' => ')', '[' => ']', '{' => '}'];
        for ($i = $start + 1, $count = count($tokens); $i < $count; $i++) {
            if ($tokens[$i]['kind'] !== 'punctuation') {
                continue;
            }
            $value = $tokens[$i]['value'];
            if (isset($pairs[$value])) {
                $stack[] = $pairs[$value];
                if (count($stack) > 64) {
                    return null;
                }
            } elseif (in_array($value, [')', ']', '}'], true)) {
                if (array_pop($stack) !== $value) {
                    return null;
                }
                if ($stack === []) {
                    return ['body' => array_slice($tokens, $start + 1, $i - $start - 1), 'next' => $i + 1];
                }
            }
        }

        return null;
    }

    /**
     * @param list<array{kind: string, value: string}> $tokens
     * @return array{names: list<string>, complete: bool}|null
     */
    private function names(array $tokens, bool $object, bool $typed): ?array
    {
        $names = [];
        $complete = true;
        $parts = [];
        $current = [];
        $stack = [];
        $pairs = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'];
        foreach ($tokens as $token) {
            $value = $token['value'];
            if ($token['kind'] === 'punctuation' && $stack === [] && ($value === ',' || $object && $value === ';')) {
                $parts[] = $current;
                $current = [];
                continue;
            }
            $current[] = $token;
            if ($token['kind'] !== 'punctuation') {
                continue;
            }
            if (isset($pairs[$value])) {
                $stack[] = $pairs[$value];
                if (count($stack) > 64) {
                    return null;
                }
            } elseif (in_array($value, [')', ']', '}', '>'], true) && $stack !== [] && end($stack) === $value) {
                array_pop($stack);
            }
        }
        $parts[] = $current;
        if ($stack !== []) {
            $complete = false;
        }
        foreach ($parts as $part) {
            if ($part === []) {
                continue;
            }
            if (! $object) {
                if (count($part) === 1 && $part[0]['kind'] === 'string') {
                    $names[] = $part[0]['value'];
                } else {
                    $complete = false;
                }
                continue;
            }
            if (
                $typed
                && $part[0]['value'] === 'readonly'
                && isset($part[1])
                && in_array($part[1]['kind'], ['identifier', 'string'], true)
            ) {
                array_shift($part);
            }
            $key = $part[0] ?? null;
            $next = ($part[1]['kind'] ?? null) === 'punctuation' && ($part[1]['value'] ?? null) === '?' ? 2 : 1;
            if (
                $key === null
                || ! in_array($key['kind'], ['identifier', 'string'], true)
                || ($part[$next]['kind'] ?? null) !== 'punctuation'
                || ($part[$next]['value'] ?? null) !== ':'
                || ! isset($part[$next + 1])
            ) {
                $complete = false;
                continue;
            }
            $names[] = $key['value'];
        }

        return ['names' => array_values(array_unique($names)), 'complete' => $complete];
    }
}
