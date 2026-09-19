<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\ParserFactory;

/** Conservatively extracts a single top-level literal @props array without compiling Blade. */
final class BladePropsParser
{
    private const MAX_BYTES = 262144;

    public function parse(string $source): ?BladePropsMetadata
    {
        $length = strlen($source);
        if ($length > self::MAX_BYTES) {
            return null;
        }

        $found = null;
        $earlierDirective = false;
        for ($i = 0; $i < $length;) {
            if (substr_compare($source, '@php', $i, 4) === 0 || substr_compare($source, '<?', $i, 2) === 0) {
                $earlierDirective = true;
            }
            $opaque = $this->opaqueEnd($source, $i);
            if ($opaque !== null) {
                if ($opaque < 0) {
                    return null;
                }
                $i = $opaque;
                continue;
            }

            $character = $source[$i];
            if ($character === '"' || $character === "'") {
                $end = $this->quotedEnd($source, $i);
                if ($end === null) {
                    return null;
                }
                if (str_contains(substr($source, $i, $end - $i), '@props')) {
                    return null;
                }
                $i = $end;
                continue;
            }
            if ($character !== '@' || $i > 0 && preg_match('/[A-Za-z0-9_@]/', $source[$i - 1]) === 1) {
                $i++;
                continue;
            }
            $match = [];
            if (preg_match('/\G@([A-Za-z_][A-Za-z0-9_]*)/A', $source, $match, 0, $i) !== 1) {
                $i++;
                continue;
            }

            $name = $match[1];
            $afterName = $i + strlen($match[0]);
            if ($name !== 'props') {
                $earlierDirective = true;
                $i = $afterName;
                continue;
            }
            if ($found !== null || $earlierDirective) {
                return null;
            }
            $open = $afterName;
            while ($open < $length && ($source[$open] === ' ' || $source[$open] === "\t")) {
                $open++;
            }
            if ($open >= $length || $source[$open] !== '(') {
                return null;
            }
            $close = $this->closingParenthesis($source, $open);
            if ($close === null) {
                return null;
            }
            $declarations = $this->parseArray(substr($source, $open + 1, $close - $open - 1));
            if ($declarations === null) {
                return null;
            }
            $found = new BladePropsMetadata(true, $declarations, $i);
            $i = $close + 1;
        }

        return $found ?? new BladePropsMetadata(false, [], null);
    }

    private function opaqueEnd(string $source, int $offset): ?int
    {
        foreach ([
            '{{--' => '--}}',
            '<!--' => '-->',
            '@verbatim' => '@endverbatim',
            '@php' => '@endphp',
            '<?' => '?>',
            '{{' => '}}',
            '{!!' => '!!}',
        ] as $start => $end) {
            if (substr_compare($source, $start, $offset, strlen($start)) !== 0) {
                continue;
            }
            if (($start === '@php' || $start === '@verbatim') && $offset > 0 && $source[$offset - 1] === '@') {
                return null;
            }
            // @php(...) is an expression directive, not an opaque block.
            if ($start === '@php' && preg_match('/^@php[ \t]*\(/', substr($source, $offset)) === 1) {
                return null;
            }
            if (
                ($start === '@php'
                || $start === '@verbatim')
                && preg_match('/[A-Za-z0-9_]/', $source[$offset + strlen($start)] ?? '') === 1
            ) {
                return null;
            }
            $position = strpos($source, $end, $offset + strlen($start));

            if (
                $position !== false
                && ($start === '<!--'
                || $start === '{{'
                || $start === '{!!')
                && str_contains(substr($source, $offset, $position + strlen($end) - $offset), '@props')
            ) {
                return -1;
            }

            return $position === false ? -1 : $position + strlen($end);
        }

        return null;
    }

    private function quotedEnd(string $source, int $offset): ?int
    {
        $quote = $source[$offset];
        for ($i = $offset + 1, $length = strlen($source); $i < $length; $i++) {
            if ($source[$i] === '\\') {
                $i++;
            } elseif ($source[$i] === $quote) {
                return $i + 1;
            }
        }

        return null;
    }

    private function closingParenthesis(string $source, int $open): ?int
    {
        $depth = 0;
        for ($i = $open, $length = strlen($source); $i < $length; $i++) {
            if ($source[$i] === '"' || $source[$i] === "'") {
                $end = $this->quotedEnd($source, $i);
                if ($end === null) {
                    return null;
                }
                $i = $end - 1;
                continue;
            }
            if ($source[$i] === '(') {
                $depth++;
            } elseif ($source[$i] === ')' && --$depth === 0) {
                return $i;
            }
        }

        return null;
    }

    /** @return list<BladePropDeclaration>|null */
    private function parseArray(string $expression): ?array
    {
        $source = '<?php return '.$expression.';';
        try {
            $statements = (new ParserFactory)
                ->createForNewestSupportedVersion()
                ->parse($source);
        } catch (\PhpParser\Error) {
            return null;
        }
        if ($statements === null || count($statements) !== 1) {
            return null;
        }
        $statement = $statements[0];
        if (
            ! $statement instanceof Return_
            || ! $statement->expr instanceof Array_
            || $statement->getEndFilePos() !== (strlen($source) - 1)
        ) {
            return null;
        }

        $declarations = [];
        $seen = [];
        foreach ($statement->expr->items as $item) {
            if ($item->unpack || $item->byRef) {
                return null;
            }
            if ($item->key === null && $item->value instanceof String_) {
                $name = $item->value->value;
                $hasDefault = false;
            } elseif ($item->key instanceof String_) {
                $name = $item->key->value;
                $hasDefault = true;
            } else {
                return null;
            }
            // Numeric keys become PHP integer keys; Laravel treats them as unkeyed entries.
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1 || isset($seen[$name])) {
                return null;
            }
            $seen[$name] = true;
            $declarations[] = new BladePropDeclaration($name, $hasDefault);
        }

        return $declarations;
    }
}
