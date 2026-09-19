<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/** Positive, source-positioned Livewire names in a bounded Blade buffer. */
final class BladeLivewireReferenceParser
{
    private const MAX_BYTES = 262_144;

    private ?Parser $phpParser = null;

    public function parse(string $source): ?BladeReferenceScan
    {
        $length = strlen($source);
        if ($length > self::MAX_BYTES || str_contains($source, "\0")) {
            return null;
        }

        // Blade statements and component tags are compiled in inline HTML, not PHP.
        $inline = [];
        $position = 0;
        foreach (token_get_all($source) as $token) {
            $text = is_array($token) ? $token[1] : $token;
            if (is_array($token) && $token[0] === T_INLINE_HTML) {
                $inline[] = [$position, $position + strlen($text)];
            }
            $position += strlen($text);
        }

        $references = [];
        $complete = true;
        foreach ($inline as [$start, $end]) {
            for ($i = $start; $i < $end;) {
                $comment = null;
                foreach (['{{--' => '--}}', '<!--' => '-->'] as $open => $close) {
                    if (substr_compare($source, $open, $i, strlen($open)) === 0) {
                        $comment = strpos($source, $close, $i + strlen($open));
                        if ($comment === false || ($comment + strlen($close)) > $end) {
                            return null;
                        }
                        $i = $comment + strlen($close);
                        continue 2;
                    }
                }
                foreach (['{{{' => '}}}', '{{' => '}}', '{!!' => '!!}'] as $open => $close) {
                    if (substr_compare($source, $open, $i, strlen($open)) === 0) {
                        $stop = self::quotedEnd($source, $i + strlen($open), $end, $close);
                        if ($stop === null) {
                            return null;
                        }
                        $i = $stop;
                        continue 2;
                    }
                }
                if ($source[$i] === '@' && ($i === $start || ! self::word($source[$i - 1]))) {
                    if (substr_compare($source, '@@', $i, 2) === 0) {
                        $i += 2;
                        if (($match = self::directive($source, $i)) !== null) {
                            $i += strlen($match);
                            $i = self::pastArguments($source, $i, $end) ?? $end;
                        }
                        continue;
                    }
                    $directive = self::directive($source, $i + 1);
                    if ($directive !== null) {
                        $after = $i + 1 + strlen($directive);
                        if ($directive === 'verbatim' && ! self::word($source[$after] ?? '')) {
                            $close = strpos($source, '@endverbatim', $after);
                            if ($close === false || ($close + 12) > $end) {
                                return null;
                            }
                            $i = $close + 12;
                            continue;
                        }
                        if ($directive === 'php' && self::argumentOpen($source, $after, $end) === null) {
                            $close = self::rawPhpEnd($source, $after, $end);
                            if ($close === null) {
                                return null;
                            }
                            $i = $close;
                            continue;
                        }
                        $open = self::argumentOpen($source, $after, $end);
                        if ($open !== null) {
                            $close = self::parenthesisEnd($source, $open, $end);
                            if ($close === null) {
                                return null;
                            }
                            if ($directive === 'livewire') {
                                $reference = $this->directiveReference($source, $open, $close);
                                if ($reference === null) {
                                    $complete = false;
                                } else {
                                    $references[] = $reference;
                                }
                            }
                            $i = $close;
                            continue;
                        }
                        if ($directive === 'livewire') {
                            $complete = false;
                        }
                        $i = $after;
                        continue;
                    }
                }
                if ($source[$i] === '<') {
                    if (
                        substr_compare($source, '<livewire:', $i, 10) === 0
                        && preg_match('/\G<livewire:[A-Za-z0-9][A-Za-z0-9_.:-]*(?=[\s\/>])/A', $source, offset: $i)
                            !== 1
                    ) {
                        $complete = false;
                    }
                    $match = [];
                    if (
                        preg_match('/\G<livewire:([A-Za-z0-9][A-Za-z0-9_.:-]*)(?=[\s\/>])/A', $source, $match, 0, $i)
                        === 1
                    ) {
                        $tagEnd = self::tagEnd($source, $i, $end);
                        if ($tagEnd === null) {
                            return null;
                        }
                        if ($match[1] === 'is') {
                            // <livewire:is> selects its target from an attribute.
                            $complete = false;
                        } elseif ($i === 0 || $source[$i - 1] !== '@') {
                            $nameStart = $i + strlen('<livewire:');
                            $references[] = new BladeReference(
                                'livewire',
                                $match[1],
                                '<livewire:>',
                                'required',
                                $nameStart,
                                $nameStart + strlen($match[1]),
                            );
                        }
                        $i = $tagEnd;
                        continue;
                    }
                    if (preg_match('/\G<\/?[A-Za-z!][^>]*?/A', $source, $match, 0, $i) === 1) {
                        $tagEnd = self::tagEnd($source, $i, $end);
                        if ($tagEnd === null) {
                            return null;
                        }
                        $i = $tagEnd;
                        continue;
                    }
                }
                $i++;
            }
        }

        return new BladeReferenceScan($references, $complete);
    }

    private function directiveReference(string $source, int $open, int $close): ?BladeReference
    {
        $prefix = '<?php __livewire(';
        $snippet = $prefix.substr($source, $open + 1, $close - $open - 2).');';
        try {
            $nodes = ($this->phpParser ??= (new ParserFactory)->createForNewestSupportedVersion())->parse($snippet);
        } catch (\PhpParser\Error) {
            return null;
        }
        if (
            $nodes === null
            || count($nodes) !== 1
            || ! $nodes[0] instanceof Expression
            || ! $nodes[0]->expr instanceof FuncCall
            || $nodes[0]->getEndFilePos() !== (strlen($snippet) - 1)
            || $nodes[0]->expr->getEndFilePos() !== (strlen($snippet) - 2)
        ) {
            return null;
        }
        $argument = $nodes[0]->expr->args[0] ?? null;
        if (
            ! $argument instanceof Arg
            || $argument->unpack
            || $argument->byRef
            || $argument->name !== null
            && $argument->name->toString() !== 'name'
            || ! $argument->value instanceof String_
            || $argument->value->value === ''
        ) {
            return null;
        }
        $literal = $argument->value;
        $first = $literal->getStartFilePos();
        $last = $literal->getEndFilePos();
        if (
            $first < 0
            || $last <= $first
            || ! in_array(substr($snippet, $first, 1), ["'", '"'], true)
            || substr($snippet, $last, 1) !== substr($snippet, $first, 1)
        ) {
            return null;
        }
        $base = $open + 1 - strlen($prefix);

        return new BladeReference(
            'livewire',
            $literal->value,
            '@livewire',
            'required',
            $base + $first + 1,
            $base + $last,
        );
    }

    private static function directive(string $source, int $offset): ?string
    {
        $match = [];

        return preg_match('/\G([A-Za-z_][A-Za-z0-9_]*)/A', $source, $match, 0, $offset) === 1 ? $match[1] : null;
    }

    private static function word(string $character): bool
    {
        return $character !== '' && preg_match('/[A-Za-z0-9_]/', $character) === 1;
    }

    private static function argumentOpen(string $source, int $offset, int $end): ?int
    {
        while ($offset < $end && ($source[$offset] === ' ' || $source[$offset] === "\t")) {
            $offset++;
        }

        return $offset < $end && $source[$offset] === '(' ? $offset : null;
    }

    private static function pastArguments(string $source, int $offset, int $end): ?int
    {
        $open = self::argumentOpen($source, $offset, $end);

        return $open === null ? $offset : self::parenthesisEnd($source, $open, $end);
    }

    private static function parenthesisEnd(string $source, int $open, int $end): ?int
    {
        $depth = 0;
        $quote = null;
        for ($i = $open; $i < $end; $i++) {
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
                $stop = strpos($source, '*/', $i + 2);
                if ($stop === false || ($stop + 2) > $end) {
                    return null;
                }
                $i = $stop + 1;
            } elseif (substr_compare($source, '//', $i, 2) === 0 || $character === '#') {
                $lineEnd = strcspn($source, "\r\n", $i);
                $i += $lineEnd;
                if ($i >= $end) {
                    return null;
                }
            } elseif ($character === '(') {
                $depth++;
            } elseif ($character === ')' && --$depth === 0) {
                return $i + 1;
            }
        }

        return null;
    }

    private static function quotedEnd(string $source, int $start, int $end, string $delimiter): ?int
    {
        $quote = null;
        for ($i = $start; $i < $end; $i++) {
            if ($quote !== null) {
                if ($source[$i] === '\\') {
                    $i++;
                } elseif ($source[$i] === $quote) {
                    $quote = null;
                }
            } elseif ($source[$i] === '"' || $source[$i] === "'") {
                $quote = $source[$i];
            } elseif (substr_compare($source, $delimiter, $i, strlen($delimiter)) === 0) {
                return $i + strlen($delimiter);
            }
        }

        return null;
    }

    private static function tagEnd(string $source, int $start, int $end): ?int
    {
        $quote = null;
        for ($i = $start + 1; $i < $end; $i++) {
            if ($quote !== null) {
                if ($source[$i] === $quote) {
                    $quote = null;
                }
            } elseif ($source[$i] === '"' || $source[$i] === "'") {
                $quote = $source[$i];
            } elseif (substr_compare($source, '{{', $i, 2) === 0 || substr_compare($source, '{!!', $i, 3) === 0) {
                return null;
            } elseif ($source[$i] === '>') {
                return $i + 1;
            }
        }

        return null;
    }

    private static function rawPhpEnd(string $source, int $start, int $end): ?int
    {
        $firstTextual = strpos($source, '@endphp', $start);
        if ($firstTextual === false || ($firstTextual + 7) > $end) {
            return null;
        }
        $quote = null;
        $blockComment = false;
        $lineComment = false;
        for ($i = $start; $i < $end; $i++) {
            $character = $source[$i];
            if ($lineComment) {
                if ($character === "\n") {
                    $lineComment = false;
                }
            } elseif ($blockComment) {
                if (substr_compare($source, '*/', $i, 2) === 0) {
                    $blockComment = false;
                    $i++;
                }
            } elseif ($quote !== null) {
                if ($character === '\\') {
                    $i++;
                } elseif ($character === $quote) {
                    $quote = null;
                }
            } elseif (substr_compare($source, '@endphp', $i, 7) === 0) {
                // Blade's raw-block regex uses the first textual delimiter.
                // PHP lexical disagreement makes the source ambiguous.
                return $i === $firstTextual ? $i + 7 : null;
            } elseif ($character === '"' || $character === "'" || $character === '`') {
                $quote = $character;
            } elseif (substr_compare($source, '/*', $i, 2) === 0) {
                $blockComment = true;
                $i++;
            } elseif (substr_compare($source, '//', $i, 2) === 0 || $character === '#') {
                $lineComment = true;
                if ($character === '/') {
                    $i++;
                }
            } elseif (substr_compare($source, '<<<', $i, 3) === 0) {
                return null;
            }
        }

        return null;
    }
}
