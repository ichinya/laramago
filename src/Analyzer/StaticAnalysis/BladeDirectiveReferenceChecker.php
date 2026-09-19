<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** Checks bounded Blade source against an explicitly complete effective directive registry. */
final class BladeDirectiveReferenceChecker
{
    private const MAX_BYTES = 262144;

    /** @var array<string, true> */
    private array $native = [];
    /** @var array<string, true> */
    private array $custom = [];
    private bool $complete = false;

    /**
     * $nativeNames comes from BladeNativeDirectiveCatalog::names(). $customDirectives
     * can be BladeDirectiveCatalog::directives(); only its exact string keys matter.
     * $effectiveComplete asserts no unselected directives, compiler extensions,
     * precompilers, compiler subclass overrides or conditional registrations.
     *
     * @param list<string>|null $nativeNames
     * @param array<array-key, mixed>|null $customDirectives
     */
    public function __construct(?array $nativeNames, ?array $customDirectives, bool $effectiveComplete)
    {
        $valid = true;
        foreach ($nativeNames ?? [] as $name) {
            if (! self::validName($name)) {
                $valid = false;
                continue;
            }
            $this->native[strtolower($name)] = true;
        }
        foreach ($customDirectives ?? [] as $name => $_declaration) {
            $name = (string) $name;
            if (! self::validName($name)) {
                $valid = false;
                continue;
            }
            $this->custom[$name] = true;
        }
        $this->complete = $valid && $effectiveComplete && $nativeNames !== null && $customDirectives !== null;
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }

    /**
     * Returns null for oversized or structurally ambiguous source. A missing result
     * is emitted only when the effective registry is explicitly complete.
     *
     * @return list<BladeDirectiveReference>|null
     */
    public function check(string $source): ?array
    {
        $length = strlen($source);
        if ($length > self::MAX_BYTES) {
            return null;
        }
        // Blade compiles statements only in T_INLINE_HTML. The PHP tokenizer
        // keeps a quoted PHP closing tag opaque, unlike a delimiter search.
        $inline = [];
        $offset = 0;
        foreach (token_get_all($source) as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $end = $offset + strlen($text);
            if (is_array($token) && $token[0] === T_INLINE_HTML) {
                $inline[] = [$offset, $end];
            }
            $offset = $end;
        }
        $references = [];
        $region = 0;
        for ($i = 0; $i < $length;) {
            while (isset($inline[$region]) && $i >= $inline[$region][1]) {
                $region++;
            }
            if (! isset($inline[$region])) {
                break;
            }
            if ($i < $inline[$region][0]) {
                $i = $inline[$region][0];
                continue;
            }
            $opaque = $this->opaqueEnd($source, $i);
            if ($opaque !== null) {
                if ($opaque < 0) {
                    return null;
                }
                $i = $opaque;
                continue;
            }
            if ($source[$i] !== '@' || $i > 0 && self::word($source[$i - 1])) {
                $i++;
                continue;
            }
            $match = [];
            if (preg_match('/\G@(@?[A-Za-z0-9_]+(?:::[A-Za-z0-9_]+)?)/A', $source, $match, 0, $i) !== 1) {
                $i++;
                continue;
            }
            $end = $i + strlen($match[0]);
            if ($match[1][0] !== '@') {
                $name = $match[1];
                $status = isset($this->custom[$name]) || isset($this->native[strtolower($name)])
                    ? 'known'
                    : ($this->complete ? 'missing' : 'unknown');
                $references[] = new BladeDirectiveReference($name, $i, $end, $status);
            }
            $open = $end;
            while ($open < $length && ($source[$open] === ' ' || $source[$open] === "\t")) {
                $open++;
            }
            if ($open < $length && $source[$open] === '(') {
                $close = self::parenthesisEnd($source, $open);
                if ($close === null) {
                    return null;
                }
                $i = $close;
            } else {
                $i = $end;
            }
        }

        return $references;
    }

    private function opaqueEnd(string $source, int $offset): ?int
    {
        if (preg_match('/\G<(?:\s*|\/\s*)x[-:]/A', $source, offset: $offset) === 1) {
            return self::componentTagEnd($source, $offset) ?? -1;
        }
        foreach (['{{--' => '--}}', '<!--' => '-->'] as $start => $end) {
            if (substr_compare($source, $start, $offset, strlen($start)) === 0) {
                $position = strpos($source, $end, $offset + strlen($start));

                return $position === false ? -1 : $position + strlen($end);
            }
        }
        foreach (['{{{' => '}}}', '{{' => '}}', '{!!' => '!!}'] as $start => $end) {
            if (substr_compare($source, $start, $offset, strlen($start)) === 0) {
                return self::bladeEchoEnd($source, $offset + strlen($start), $end) ?? -1;
            }
        }
        if (
            substr_compare($source, '@verbatim', $offset, 9) === 0
            && ($offset === 0
            || $source[$offset - 1] !== '@')
        ) {
            $end = strpos($source, '@endverbatim', $offset + 9);
            if ($end !== false) {
                return $end + 12;
            }
            if (! self::word($source[$offset + 9] ?? '')) {
                return -1;
            }
        }
        if (
            substr_compare($source, '@php', $offset, 4) === 0
            && ($offset === 0
            || $source[$offset - 1] !== '@')
        ) {
            $end = strpos($source, '@endphp', $offset + 4);
            if ($end !== false) {
                if (self::ambiguousRawPhpEnd($source, $offset + 4, $end)) {
                    return -1;
                }

                return $end + 7;
            }
            if (
                ! self::word($source[$offset + 4] ?? '')
                && preg_match('/\G@php[ \t]*\(/A', $source, offset: $offset) !== 1
            ) {
                return -1;
            }
        }

        return null;
    }

    private static function ambiguousRawPhpEnd(string $source, int $bodyStart, int $end): bool
    {
        $quote = null;
        $blockComment = false;
        $lineComment = false;
        for ($i = $bodyStart; $i < $end; $i++) {
            $character = $source[$i];
            if ($lineComment) {
                if ($character === "\n") {
                    $lineComment = false;
                }
                continue;
            }
            if ($blockComment) {
                if (substr_compare($source, '*/', $i, 2) === 0) {
                    $blockComment = false;
                    $i++;
                }
                continue;
            }
            if ($quote !== null) {
                if ($character === '\\') {
                    $i++;
                } elseif ($character === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($character === '"' || $character === "'" || $character === '`') {
                $quote = $character;
            } elseif (substr_compare($source, '/*', $i, 2) === 0) {
                $blockComment = true;
                $i++;
            } elseif (substr_compare($source, '//', $i, 2) === 0 || $character === '#') {
                $lineComment = true;
            } elseif (substr_compare($source, '<<<', $i, 3) === 0) {
                return true;
            }
        }

        return $quote !== null || $blockComment || $lineComment;
    }

    private static function parenthesisEnd(string $source, int $open): ?int
    {
        $depth = 0;
        $quote = null;
        for ($i = $open, $length = strlen($source); $i < $length; $i++) {
            $character = $source[$i];
            if ($quote !== null) {
                if ($character === '\\') {
                    $i++;
                } elseif ($character === $quote) {
                    $quote = null;
                }
            } elseif ($character === '"' || $character === "'") {
                $quote = $character;
            } elseif (substr_compare($source, '/*', $i, 2) === 0) {
                $end = strpos($source, '*/', $i + 2);
                if ($end === false) {
                    return null;
                }
                $i = $end + 1;
            } elseif (substr_compare($source, '//', $i, 2) === 0 || $character === '#') {
                $end = strpos($source, "\n", $i + 1);
                if ($end === false) {
                    return null;
                }
                $i = $end;
            } elseif (substr_compare($source, '<<<', $i, 3) === 0) {
                return null;
            } elseif ($character === '(') {
                $depth++;
            } elseif ($character === ')' && --$depth === 0) {
                return $i + 1;
            }
        }

        return null;
    }

    private static function bladeEchoEnd(string $source, int $offset, string $delimiter): ?int
    {
        $quote = null;
        for ($i = $offset, $length = strlen($source); $i < $length; $i++) {
            $character = $source[$i];
            if ($quote !== null) {
                if ($character === '\\') {
                    $i++;
                } elseif ($character === $quote) {
                    $quote = null;
                }
            } elseif ($character === '"' || $character === "'") {
                $quote = $character;
            } elseif (substr_compare($source, $delimiter, $i, strlen($delimiter)) === 0) {
                return $i + strlen($delimiter);
            }
        }

        return null;
    }

    private static function componentTagEnd(string $source, int $offset): ?int
    {
        $quote = null;
        for ($i = $offset, $length = strlen($source); $i < $length; $i++) {
            $character = $source[$i];
            if ($quote !== null) {
                if ($character === '\\') {
                    $i++;
                } elseif ($character === $quote) {
                    $quote = null;
                }
            } elseif ($character === '"' || $character === "'") {
                $quote = $character;
            } elseif ($character === '>') {
                return $i + 1;
            }
        }

        return null;
    }

    private static function word(string $character): bool
    {
        return $character !== '' && preg_match('/[A-Za-z0-9_]/', $character) === 1;
    }

    private static function validName(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9_]+(?:::[A-Za-z0-9_]+)?$/D', $name) === 1;
    }
}
