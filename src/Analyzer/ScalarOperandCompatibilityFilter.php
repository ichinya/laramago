<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ParenthesizedExpressionSpans;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Reporting\AnnotationKind;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/** Match PHPStan's scalar conversions without accepting mixed arithmetic or concatenation. */
final class ScalarOperandCompatibilityFilter implements IssueFilterHook, InitializationHook
{
    /** @var array<string, array<string, array<string, true>>> */
    private array $cache = [];

    public function initialize(InitializationContext $context): void
    {
        $this->cache = [];
    }

    public function getCodes(): array
    {
        return ['possibly-null-operand', 'possibly-false-operand', 'mixed-operand', 'invalid-operand'];
    }

    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        if (! in_array($context->issue->code, $this->getCodes(), true) || strlen($context->contents) > 1024 * 1024) {
            return IssueFilterDecision::Keep;
        }
        $message = $context->issue->message;
        $category = null;
        if ($context->issue->code === 'mixed-operand') {
            if (preg_match('/^(Left|Right) operand in `(&&|\|\||and|or|xor)` operation has `mixed` type\.$/', $message) === 1) {
                $category = 'boolean';
            } elseif (preg_match('/^(Left|Right) operand in (?:`(?:==|!=|<>|>|>=|<|<=)` comparison|spaceship comparison \(`<=>`\)) has `mixed` type\.$/', $message) === 1) {
                $category = 'comparison';
            }
        } elseif ($context->issue->code === 'invalid-operand') {
            if (preg_match('/^Invalid type `bool` for (left|right|middle) operand in string concatenation\.$/', $message) === 1) {
                $category = 'concat';
            }
        } elseif (preg_match('/^Possibly (null|false) (left|right|middle) operand used in string concatenation \(type `((?:null|false|true|bool|int|float|string|numeric-string)(?:\|(?:null|false|true|bool|int|float|string|numeric-string))*)`\)\.$/', $message) === 1) {
            $category = 'concat';
        } elseif (preg_match('/^(Left|Right) operand in arithmetic operation might be `(null|false)` \(type `((?:null|false|true|bool|int|float|numeric-string|positive-int|non-negative-int)(?:\|(?:null|false|true|bool|int|float|numeric-string|positive-int|non-negative-int))*)`\)\.$/', $message) === 1) {
            $category = 'arithmetic';
        }
        if ($category === null) {
            return IssueFilterDecision::Keep;
        }
        $primary = null;
        foreach ($context->issue->annotations as $annotation) {
            if ($annotation->kind !== AnnotationKind::Primary) {
                continue;
            }
            if ($primary !== null || $annotation->file !== null && $annotation->file !== '') {
                return IssueFilterDecision::Keep;
            }
            $primary = $annotation;
        }
        if ($primary === null) {
            return IssueFilterDecision::Keep;
        }
        $key = hash('sha256', $context->file."\0".$context->contents);
        if (! isset($this->cache[$key])) {
            try {
                $parser = (new ParserFactory)->createForNewestSupportedVersion();
                $nodes = $parser->parse($context->contents) ?? [];
            } catch (Error) {
                return IssueFilterDecision::Keep;
            }
            $spans = [];
            foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Expr\BinaryOp::class) as $node) {
                $kind = match (true) {
                    $node instanceof Node\Expr\BinaryOp\Concat => 'concat',
                    $node instanceof Node\Expr\BinaryOp\BooleanAnd,
                    $node instanceof Node\Expr\BinaryOp\BooleanOr,
                    $node instanceof Node\Expr\BinaryOp\LogicalAnd,
                    $node instanceof Node\Expr\BinaryOp\LogicalOr,
                    $node instanceof Node\Expr\BinaryOp\LogicalXor => 'boolean',
                    $node instanceof Node\Expr\BinaryOp\Equal,
                    $node instanceof Node\Expr\BinaryOp\NotEqual,
                    $node instanceof Node\Expr\BinaryOp\Greater,
                    $node instanceof Node\Expr\BinaryOp\GreaterOrEqual,
                    $node instanceof Node\Expr\BinaryOp\Smaller,
                    $node instanceof Node\Expr\BinaryOp\SmallerOrEqual,
                    $node instanceof Node\Expr\BinaryOp\Spaceship => 'comparison',
                    $node instanceof Node\Expr\BinaryOp\Plus,
                    $node instanceof Node\Expr\BinaryOp\Minus,
                    $node instanceof Node\Expr\BinaryOp\Mul,
                    $node instanceof Node\Expr\BinaryOp\Div,
                    $node instanceof Node\Expr\BinaryOp\Mod,
                    $node instanceof Node\Expr\BinaryOp\Pow => 'arithmetic',
                    default => null,
                };
                if ($kind === null) {
                    continue;
                }
                foreach ([$node->left, $node->right] as $operand) {
                    foreach (ParenthesizedExpressionSpans::collect($operand, $node, $parser->getTokens()) as $span) {
                        $spans[$kind][$span] = true;
                    }
                }
            }
            if (count($this->cache) >= 16) {
                unset($this->cache[array_key_first($this->cache)]);
            }
            $this->cache[$key] = $spans;
        }

        return isset($this->cache[$key][$category][$primary->span->start.':'.$primary->span->end])
            ? IssueFilterDecision::Remove
            : IssueFilterDecision::Keep;
    }
}
