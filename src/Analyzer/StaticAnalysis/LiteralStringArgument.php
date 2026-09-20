<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;

/** A direct PHP string literal, with PHP-Parser's decoded value and original span. */
final class LiteralStringArgument
{
    public static function from(?Node\Expr $expression): ?Node\Scalar\String_
    {
        // Constants, concatenation, interpolation and inferred string types need
        // separate proof before a missing-name diagnostic can use their values.
        return $expression instanceof Node\Scalar\String_ ? $expression : null;
    }
}
