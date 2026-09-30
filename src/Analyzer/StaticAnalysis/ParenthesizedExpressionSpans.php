<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node\Expr;
use PhpParser\Token;

/** Byte spans for an operand and its grouping parentheses inside its operation. */
final class ParenthesizedExpressionSpans
{
    /**
     * PHP-Parser omits grouping parentheses from the operand node, whereas
     * Mago can include them in an operand diagnostic. Walk only adjacent token
     * pairs inside the owning operation; never trim arbitrary diagnostic text.
     *
     * @param list<Token> $tokens
     * @return list<string>
     */
    public static function collect(Expr $operand, Expr $operation, array $tokens): array
    {
        $start = $operand->getStartTokenPos();
        $end = $operand->getEndTokenPos();
        $lower = $operation->getStartTokenPos();
        $upper = $operation->getEndTokenPos();
        if ($start < 0 || $end < $start || $start < $lower || $end > $upper
            || ! isset($tokens[$start], $tokens[$end])) {
            return [];
        }

        $spans = [$operand->getStartFilePos().':'.($operand->getEndFilePos() + 1)];
        for ($depth = 0; $depth < 64; ++$depth) {
            $before = $start - 1;
            $after = $end + 1;
            while ($before >= $lower && self::trivia($tokens[$before])) {
                --$before;
            }
            while ($after <= $upper && self::trivia($tokens[$after])) {
                ++$after;
            }
            if ($before < $lower || $after > $upper
                || $tokens[$before]->text !== '(' || $tokens[$after]->text !== ')') {
                break;
            }
            $start = $before;
            $end = $after;
            $spans[] = $tokens[$start]->pos.':'.($tokens[$end]->pos + 1);
        }

        return $spans;
    }

    private static function trivia(Token $token): bool
    {
        return in_array($token->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }
}
