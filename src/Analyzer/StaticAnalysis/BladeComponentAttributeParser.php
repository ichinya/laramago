<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** Parse one isolated x- or x: opening tag without compiling Blade or PHP. */
final class BladeComponentAttributeParser
{
    private const MAX_TAG_BYTES = 65_536;

    private const MAX_ATTRIBUTES = 256;

    public function parse(string $source): ?BladeComponentOpeningTag
    {
        $tagMatch = [];
        $length = strlen($source);
        $matchSource = $length > self::MAX_TAG_BYTES ? substr($source, 0, self::MAX_TAG_BYTES) : $source;

        if (preg_match('/\A<\s*(x[-:]([A-Za-z0-9_.:-]+))/', $matchSource, $tagMatch) !== 1) {
            return null;
        }

        $rawTag = $tagMatch[1];
        $tag = $tagMatch[2];
        $offset = strlen($tagMatch[0]);
        $attributes = [];

        if ($length > self::MAX_TAG_BYTES) {
            return new BladeComponentOpeningTag($tag, $rawTag, false, false, []);
        }

        while ($offset < $length) {
            $separated = $this->skipWhitespace($source, $offset, $length);

            if (substr($source, $offset) === '>') {
                return new BladeComponentOpeningTag($tag, $rawTag, false, true, $attributes);
            }

            if (substr($source, $offset) === '/>') {
                return new BladeComponentOpeningTag($tag, $rawTag, true, true, $attributes);
            }

            if (! $separated || $offset >= $length) {
                break;
            }

            if (count($attributes) >= self::MAX_ATTRIBUTES) {
                break;
            }

            $start = $offset;
            $shortMatch = [];
            $short = preg_match('/\G:\$([A-Za-z_][A-Za-z0-9_]*)/A', $source, $shortMatch, 0, $offset) === 1;

            if ($short) {
                $rawName = $shortMatch[0];
                $name = $shortMatch[1];
                $offset += strlen($rawName);

                if (! $this->atAttributeBoundary($source, $offset, $length)) {
                    break;
                }

                $attributes[] = [
                    'name' => $name,
                    'rawName' => $rawName,
                    'kind' => 'short',
                    'value' => '$'.$name,
                    'start' => $start,
                    'end' => $offset,
                    'valueStart' => null,
                    'valueEnd' => null,
                ];

                continue;
            }

            $nameMatch = [];

            if (preg_match('/\G([A-Za-z0-9_:.-][A-Za-z0-9_:.@%\-]*)/A', $source, $nameMatch, 0, $offset) !== 1) {
                break;
            }

            $rawName = $nameMatch[1];
            $offset += strlen($rawName);

            if (str_contains($rawName, '@') || str_starts_with($rawName, 'bind:')) {
                break;
            }

            $escaped = str_starts_with($rawName, '::');
            $bound = ! $escaped && str_starts_with($rawName, ':');
            $name = $escaped ? substr($rawName, 1) : ($bound ? substr($rawName, 1) : $rawName);

            if ($name === '' || $bound && ($name === 'attributes' || str_contains($name, '%'))) {
                break;
            }

            $value = null;
            $valueStart = null;
            $valueEnd = null;

            if ($offset < $length && $source[$offset] === '=') {
                $offset++;

                if ($offset >= $length) {
                    break;
                }

                $quote = $source[$offset];

                if ($quote === '"' || $quote === "'") {
                    $valueStart = ++$offset;
                    $close = strpos($source, $quote, $offset);

                    if ($close === false) {
                        break;
                    }

                    $valueEnd = $close;
                    $value = substr($source, $valueStart, $valueEnd - $valueStart);
                    $offset = $close + 1;
                } else {
                    $valueStart = $offset;

                    while (
                        $offset < $length
                        && ! ctype_space($source[$offset])
                        && $source[$offset] !== '>'
                        && substr($source, $offset, 2) !== '/>'
                    ) {
                        if (
                            $source[$offset] === '='
                            || $source[$offset] === '"'
                            || $source[$offset] === "'"
                            || $source[$offset] === '<'
                        ) {
                            break 2;
                        }

                        $offset++;
                    }

                    $valueEnd = $offset;
                    $value = substr($source, $valueStart, $valueEnd - $valueStart);

                    if ($value === '') {
                        break;
                    }
                }

                if ($this->hasDynamicSyntax($value)) {
                    break;
                }

                $kind = $escaped ? 'escaped' : ($bound ? 'bound' : 'literal');
            } elseif ($escaped) {
                $kind = 'escaped';
            } elseif ($bound) {
                // A lone :name is not Laravel's bound-value syntax.
                break;
            } else {
                $kind = 'boolean';
            }

            if (! $this->atAttributeBoundary($source, $offset, $length)) {
                break;
            }

            $attributes[] = [
                'name' => $name,
                'rawName' => $rawName,
                'kind' => $kind,
                'value' => $value,
                'start' => $start,
                'end' => $offset,
                'valueStart' => $valueStart,
                'valueEnd' => $valueEnd,
            ];
        }

        return new BladeComponentOpeningTag($tag, $rawTag, false, false, $attributes);
    }

    private function skipWhitespace(string $source, int &$offset, int $length): bool
    {
        $initial = $offset;

        while ($offset < $length && ctype_space($source[$offset])) {
            $offset++;
        }

        return $offset > $initial;
    }

    private function atAttributeBoundary(string $source, int $offset, int $length): bool
    {
        return (
            $offset < $length
            && (ctype_space($source[$offset]) || substr($source, $offset, 2) === '/>' || $source[$offset] === '>')
        );
    }

    private function hasDynamicSyntax(string $value): bool
    {
        return (
            str_contains($value, '{{')
            || str_contains($value, '{!!')
            || str_contains($value, '<?')
            || str_contains($value, '?>')
            || str_contains($value, '@')
        );
    }
}
