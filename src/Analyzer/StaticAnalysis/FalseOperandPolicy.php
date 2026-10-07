<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ParenthesizedExpressionSpans;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Reporting\AnnotationKind;
use Mago\Sdk\Reporting\Level;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/** Bounded native false operand envelopes and current source operators, without changing inferred types. */
final class FalseOperandPolicy
{
    private array $files = [];

    public function reset(): void { $this->files = []; }

    public function proposal(IssueFilterContext $context): ?array
    {
        $issue = $context->issue;
        if ($context->cancellation->isCancelled() || $issue->level !== Level::Warning || $issue->code !== 'possibly-false-operand'
            || $issue->link !== null || $issue->edits !== [] || count($issue->annotations) !== 1
            || strlen($context->contents) > 1024 * 1024 || strlen($issue->message) > 2048) { return null; }
        $annotation = $issue->annotations[0];
        if ($annotation->kind !== AnnotationKind::Primary || $annotation->message !== 'This might be `false`'
            || ($annotation->file !== null && $annotation->file !== '') || $annotation->span->start < 0
            || $annotation->span->end <= $annotation->span->start || $annotation->span->end > strlen($context->contents)) { return null; }
        $kind = null; $operator = ''; $side = '';
        if (preg_match('/^(Left|Right) operand in `(<|>)` comparison might be `false` \(type `([^`]+)`\)\.$/D', $issue->message, $match) === 1) {
            $side = strtolower($match[1]); $operator = $match[2];
            if (! self::domain($match[3], 'comparison')
                || $issue->notes !== ["If this operand is `false` at runtime, PHP's specific comparison rules for `false` with `".$operator.'` will apply.']
                || $issue->help !== 'Ensure this operand is non-false or that comparison with `false` is intended and handled safely.') { return null; }
            $kind = 'comparison';
        } elseif (preg_match('/^Possibly false (left|right|middle) operand used in string concatenation \(type `([^`]+)`\)\.$/D', $issue->message, $match) === 1) {
            $side = $match[1]; $operator = '.';
            if (! self::domain($match[2], 'concat')
                || $issue->notes !== ["If this operand is `false` at runtime, it will be implicitly converted to an empty string `''`."]
                || $issue->help !== 'Ensure the operand is non-falsy before concatenation, or explicitly cast to string.') { return null; }
            $kind = 'concat';
        }
        if ($kind === null) { return null; }
        $hash = hash('sha256', $context->file."\0".$context->contents);
        if (! array_key_exists($hash, $this->files)) {
            if (count($this->files) >= 2) { $this->files = []; }
            $this->files[$hash] = self::sites($context->contents);
        }
        $key = $kind.'|'.$operator.'|'.$side.'|'.$annotation->span->start.':'.$annotation->span->end;
        $site = $this->files[$hash][$key] ?? null;
        return $site === null ? null : $site + ['sourceSha256' => hash('sha256', $context->contents)];
    }

    /** Only false plus integer atoms, or false plus supported string atoms. No conversion to 0 for comparison. */
    public static function domain(string $printed, string $kind): bool
    {
        if (strlen($printed) > 512 || ! in_array($kind, ['comparison', 'concat'], true)) { return false; }
        $parts = explode('|', $printed); $seen = []; $false = false; $values = 0;
        if (count($parts) > 8) { return false; }
        foreach ($parts as $part) {
            if (isset($seen[$part])) { return false; } $seen[$part] = true;
            if ($part === 'false') { $false = true; continue; }
            if ($kind === 'concat') {
                if (! in_array($part, ['string', 'non-empty-string', 'numeric-string'], true)) { return false; }
            } elseif (! self::integerAtom($part)) { return false; }
            ++$values;
        }
        return $false && $values > 0;
    }

    private static function integerAtom(string $atom): bool
    {
        if (in_array($atom, ['int', 'non-negative-int', 'positive-int'], true)) { return true; }
        if (preg_match('/^int\((-?(?:0|[1-9][0-9]*))\)$/D', $atom, $match) === 1) {
            return filter_var($match[1], FILTER_VALIDATE_INT) !== false && (string) (int) $match[1] === $match[1];
        }
        if (preg_match('/^int<(min|-?(?:0|[1-9][0-9]*)), (max|-?(?:0|[1-9][0-9]*))>$/D', $atom, $match) !== 1) { return false; }
        if ($match[1] !== 'min' && filter_var($match[1], FILTER_VALIDATE_INT) === false
            || $match[2] !== 'max' && filter_var($match[2], FILTER_VALIDATE_INT) === false) { return false; }
        $low = $match[1] === 'min' ? PHP_INT_MIN : (int) $match[1];
        $high = $match[2] === 'max' ? PHP_INT_MAX : (int) $match[2];
        return ($match[1] === 'min' || (string) $low === $match[1])
            && ($match[2] === 'max' || (string) $high === $match[2]) && $low <= $high;
    }

    /** Bounded source grammar. Parameters are native int and have no writes; concatenation peers are string literals. */
    public static function sites(string $contents): array
    {
        if (strlen($contents) > 1024 * 1024) { return []; }
        try { $parser = (new ParserFactory)->createForNewestSupportedVersion(); $nodes = $parser->parse($contents) ?? []; }
        catch (\PhpParser\Error) { return []; }
        $finder = new NodeFinder;
        $all = $finder->find($nodes, static fn (Node $node): bool => true);
        if (count($all) > 20000) { return []; }
        $scopes = array_values(array_filter($all, static fn (Node $node): bool => $node instanceof Node\FunctionLike));
        $binary = array_values(array_filter($all, static fn (Node $node): bool => $node instanceof Node\Expr\BinaryOp));
        $concatChildren = [];
        foreach ($binary as $node) {
            if (! $node instanceof Node\Expr\BinaryOp\Concat) { continue; }
            foreach ([$node->left, $node->right] as $child) {
                if ($child instanceof Node\Expr\BinaryOp\Concat) { $concatChildren[spl_object_id($child)] = true; }
            }
        }
        $sites = [];
        foreach ($binary as $node) {
            $operator = $node instanceof Node\Expr\BinaryOp\Smaller ? '<' : ($node instanceof Node\Expr\BinaryOp\Greater ? '>' : null);
            if ($operator !== null) {
                foreach (['left' => [$node->left, $node->right], 'right' => [$node->right, $node->left]] as $side => [$operand, $peer]) {
                    if (! self::intPeer($peer, $node, $scopes, $finder)) { continue; }
                    foreach (ParenthesizedExpressionSpans::collect($operand, $node, $parser->getTokens()) as $span) {
                        $sites['comparison|'.$operator.'|'.$side.'|'.$span] = self::site($node, $operand, $peer, 'comparison', $operator, $side, $parser->getTokens());
                    }
                }
            }
            if (! $node instanceof Node\Expr\BinaryOp\Concat || isset($concatChildren[spl_object_id($node)])) { continue; }
            $leaves = self::concatLeaves($node);
            if (count($leaves) > 16) { continue; }
            foreach ($leaves as $index => $operand) {
                $literalPeers = true;
                foreach ($leaves as $other => $peer) {
                    if ($other !== $index && ! $peer instanceof Node\Scalar\String_) { $literalPeers = false; break; }
                }
                if (! $literalPeers) { continue; }
                $side = $index === 0 ? 'left' : ($index === count($leaves) - 1 ? 'right' : 'middle');
                foreach (ParenthesizedExpressionSpans::collect($operand, $node, $parser->getTokens()) as $span) {
                    $sites['concat|.|'.$side.'|'.$span] = self::site($node, $operand, null, 'concat', '.', $side, $parser->getTokens());
                }
            }
        }
        return $sites;
    }

    private static function concatLeaves(Node\Expr $node): array
    {
        return $node instanceof Node\Expr\BinaryOp\Concat ? [...self::concatLeaves($node->left), ...self::concatLeaves($node->right)] : [$node];
    }

    private static function site(Node\Expr $node, Node\Expr $operand, ?Node\Expr $peer, string $kind, string $operator, string $side, array $tokens): array
    {
        $operatorSpan = null;
        for ($index = $node->left->getEndTokenPos() + 1; $index < $node->right->getStartTokenPos(); ++$index) {
            if ($tokens[$index]->text === $operator) { $operatorSpan = [$tokens[$index]->pos, $tokens[$index]->pos + strlen($operator)]; break; }
        }
        return ['kind' => $kind, 'operator' => $operator, 'side' => $side,
            'operatorSpan' => $operatorSpan,
            'operation' => [$node->getStartFilePos(), $node->getEndFilePos() + 1],
            'operand' => [$operand->getStartFilePos(), $operand->getEndFilePos() + 1],
            'peer' => $peer === null ? null : [$peer->getStartFilePos(), $peer->getEndFilePos() + 1]];
    }

    private static function intPeer(Node\Expr $peer, Node\Expr $operation, array $scopes, NodeFinder $finder): bool
    {
        if ($peer instanceof Node\Scalar\Int_ || $peer instanceof Node\Expr\UnaryMinus && $peer->expr instanceof Node\Scalar\Int_) { return true; }
        if (! $peer instanceof Node\Expr\Variable || ! is_string($peer->name)) { return false; }
        $scope = null;
        foreach ($scopes as $candidate) {
            if ($candidate->getStartFilePos() <= $operation->getStartFilePos() && $candidate->getEndFilePos() >= $operation->getEndFilePos()
                && ($scope === null || $candidate->getEndFilePos() - $candidate->getStartFilePos() < $scope->getEndFilePos() - $scope->getStartFilePos())) { $scope = $candidate; }
        }
        if ($scope === null) { return false; }
        $parameter = null;
        foreach ($scope->getParams() as $candidate) { if ($candidate->var instanceof Node\Expr\Variable && $candidate->var->name === $peer->name) { $parameter = $candidate; } }
        if ($parameter === null || $parameter->byRef || $parameter->variadic || ! $parameter->type instanceof Node\Identifier || $parameter->type->name !== 'int') { return false; }
        // The target must be the parameter's first use, outside a loop. Later reads cannot change this earlier comparison.
        foreach ($finder->find($scope->getStmts() ?? [], static fn (Node $node): bool => $node instanceof Node\Stmt\For_
            || $node instanceof Node\Stmt\Foreach_ || $node instanceof Node\Stmt\While_ || $node instanceof Node\Stmt\Do_) as $loop) {
            if ($loop->getStartFilePos() <= $operation->getStartFilePos() && $loop->getEndFilePos() >= $operation->getEndFilePos()) { return false; }
        }
        foreach ($finder->find($scope->getStmts() ?? [], static fn (Node $node): bool => $node instanceof Node\Expr\Variable && $node->name === $peer->name) as $use) {
            if ($use->getStartFilePos() < $peer->getStartFilePos()) { return false; }
        }
        foreach ($finder->find($scope->getStmts() ?? [], static fn (Node $node): bool => $node instanceof Node\Expr\Eval_
            || $node instanceof Node\Expr\Include_ || $node instanceof Node\Expr\Variable && ! is_string($node->name)
            || $node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name && in_array(strtolower($node->name->toString()), ['extract','parse_str'], true)) as $dynamic) {
            if ($dynamic->getStartFilePos() < $operation->getEndFilePos()) { return false; }
        }
        return true;
    }
}
