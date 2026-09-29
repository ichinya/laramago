<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Reporting\AnnotationKind;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** A final unconditional for loop cannot reach an implicit null return. */
final class InfiniteForReturnFilter implements IssueFilterHook, InitializationHook
{
    /** @var array<string, array<string, string>> */
    private array $cache = [];

    public function initialize(InitializationContext $context): void
    {
        $this->cache = [];
    }

    public function getCodes(): array
    {
        return ['missing-return-statement'];
    }

    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        if (
            $context->issue->code !== 'missing-return-statement'
            || strlen($context->contents) > 1024 * 1024
            || preg_match('/^Missing return statement in function `([^`]+)`$/D', $context->issue->message, $match) !== 1
        ) {
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
                $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($context->contents) ?? [];
                $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes);
            } catch (Error) {
                return IssueFilterDecision::Keep;
            }
            if (count($this->cache) >= 32) {
                unset($this->cache[array_key_first($this->cache)]);
            }
            $this->cache[$key] = $this->candidates($nodes);
        }
        $candidate = $this->cache[$key][$primary->span->start.':'.$primary->span->end] ?? null;

        return $candidate !== null && strcasecmp($candidate, $match[1]) === 0
            ? IssueFilterDecision::Remove
            : IssueFilterDecision::Keep;
    }

    /**
     * @param list<Node> $nodes
     * @return array<string, string> Exact name spans mapped to Mago's diagnostic callable names.
     */
    private function candidates(array $nodes, ?string $class = null): array
    {
        $matches = [];
        foreach ($nodes as $node) {
            $scope = $class;
            if ($node instanceof Node\Stmt\ClassLike) {
                $scope = $node->namespacedName?->toString();
            }
            $name = null;
            if ($node instanceof Node\Stmt\Function_) {
                $name = $node->namespacedName?->toString();
            } elseif ($node instanceof Node\Stmt\ClassMethod && $scope !== null) {
                $name = $node->name->toString();
            }
            if ($name !== null && $this->cannotFallThrough($node)) {
                $matches[$node->name->getStartFilePos().':'.($node->name->getEndFilePos() + 1)] = $name;
            }
            foreach ($node->getSubNodeNames() as $property) {
                $child = $node->{$property};
                $children = $child instanceof Node ? [$child] : (is_array($child) ? $child : []);
                $children = array_values(array_filter($children, static fn ($item): bool => $item instanceof Node));
                $matches += $this->candidates($children, $scope);
            }
        }

        return $matches;
    }

    private function cannotFallThrough(Node\Stmt\Function_|Node\Stmt\ClassMethod $function): bool
    {
        $statements = $function->stmts ?? [];
        $last = $statements === [] ? null : $statements[array_key_last($statements)];
        if (! $last instanceof Node\Stmt\For_) {
            return false;
        }
        if ($last->cond !== []) {
            if (
                count($last->cond) !== 1
                || ! $last->cond[0] instanceof Node\Expr\ConstFetch
                || strtolower($last->cond[0]->name->toString()) !== 'true'
            ) {
                return false;
            }
        }
        foreach ($statements as $statement) {
            if ($this->hasUnsupportedControl($statement)) {
                return false;
            }
        }

        return true;
    }

    private function hasUnsupportedControl(Node $node): bool
    {
        // A nested callable has its own returns, generators, and control-flow targets.
        if ($node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike) {
            return false;
        }
        if (
            $node instanceof Node\Stmt\Break_
            || $node instanceof Node\Stmt\Goto_
            || $node instanceof Node\Stmt\Label
            || $node instanceof Node\Expr\Yield_
            || $node instanceof Node\Expr\YieldFrom
            || $node instanceof Node\Expr\Eval_
            || $node instanceof Node\Stmt\Return_ && $node->expr === null
        ) {
            return true;
        }
        if (
            $node instanceof Node\Stmt\Continue_
            && $node->num !== null
            && (! $node->num instanceof Node\Scalar\Int_ || $node->num->value !== 1)
        ) {
            return true;
        }
        foreach ($node->getSubNodeNames() as $property) {
            $child = $node->{$property};
            $children = $child instanceof Node ? [$child] : (is_array($child) ? $child : []);
            foreach ($children as $item) {
                if ($item instanceof Node && $this->hasUnsupportedControl($item)) {
                    return true;
                }
            }
        }

        return false;
    }
}
