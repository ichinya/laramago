<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** A source-only snapshot of one isolated Blade component opening tag. */
final class BladeComponentOpeningTag
{
    /**
     * Offsets are zero-based byte offsets into the original tag string; ends are exclusive.
     * A short binding has a generated value expression but no value span in the source.
     *
     * @param list<array{name: string, rawName: string, kind: 'literal'|'bound'|'short'|'boolean'|'escaped', value: ?string, start: int, end: int, valueStart: ?int, valueEnd: ?int}> $attributes
     */
    public function __construct(
        public readonly string $tag,
        public readonly string $rawTag,
        public readonly bool $selfClosing,
        public readonly bool $complete,
        public readonly array $attributes,
    ) {}

    /**
     * Names eligible to satisfy a component constructor parameter. Alpine's
     * escaped colon attributes remain in the attribute bag, not component data.
     *
     * @return list<string>
     */
    public function attributeNames(): array
    {
        $names = [];

        foreach ($this->attributes as $attribute) {
            if (
                $attribute['kind'] !== 'escaped'
                && preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/D', $attribute['name']) === 1
            ) {
                $names[] = $attribute['name'];
            }
        }

        return $names;
    }
}
