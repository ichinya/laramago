<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Reporting\AnnotationKind;
use Mago\Sdk\Reporting\Level;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;

/** Error compatibility only. Types, Warning order, presence, and TValue stay unchanged. */
final class NullableCollectionOffsetIssueFilter implements IssueFilterHook
{
    public readonly NullableCollectionOffsetContract $contract;
    public function __construct(string $root, string $support = 'Illuminate\\Support\\Collection')
    {
        $this->contract = new NullableCollectionOffsetContract($root, $support);
    }
    public function getCodes(): array { return ['invalid-array-index']; }
    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        $issue = $context->issue;
        if ($context->cancellation->isCancelled() || $issue->level !== Level::Error || $issue->code !== 'invalid-array-index'
            || $issue->link !== null || $issue->edits !== [] || strlen($issue->message) > 4096 || strlen($context->contents) > 1024 * 1024
            || preg_match('/^Invalid index type `([^`]+)` used for array access on `([^`]+)`\.$/D', $issue->message, $match) !== 1
            || ! self::domain($match[1]) || count($issue->annotations) !== 1
            || $issue->notes !== ['The only valid index type for `'.$match[2].'` is `array-key`.']
            || $issue->help !== 'Ensure the index expression evaluates to `array-key`.') { return IssueFilterDecision::Keep; }
        $primary = $issue->annotations[0];
        if ($primary->kind !== AnnotationKind::Primary || $primary->message !== 'Type `'.$match[1].'` cannot be used as an index here.'
            || $primary->file !== null || $primary->span->end <= $primary->span->start || $primary->span->end > strlen($context->contents)) { return IssueFilterDecision::Keep; }
        $receiver = self::receiverClass($match[2]);
        if ($receiver === null) { return IssueFilterDecision::Keep; }
        if (@file_get_contents($this->contract->path($context->file)) !== $context->contents) { return IssueFilterDecision::Keep; }
        try {
            $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($context->contents) ?? [];
            $nodes = (new NodeTraverser(new ParentConnectingVisitor))->traverse($nodes);
            $finder = new NodeFinder;
            $matches = $finder->find($nodes, static fn (Node $node): bool => $node instanceof Node\Expr\ArrayDimFetch && $node->dim !== null
                && [$node->dim->getStartFilePos(), $node->dim->getEndFilePos() + 1] === [$primary->span->start, $primary->span->end]);
            if (count($matches) !== 1) { return IssueFilterDecision::Keep; }
            $child = $matches[0];
            while (($parent = $child->getAttribute('parent')) instanceof Node) {
                if (($parent instanceof Node\Expr\Assign || $parent instanceof Node\Expr\AssignRef || $parent instanceof Node\Expr\AssignOp)
                        && $parent->var === $child
                    || $parent instanceof Node\Stmt\Unset_
                    || $parent instanceof Node\Expr\PreInc || $parent instanceof Node\Expr\PostInc
                    || $parent instanceof Node\Expr\PreDec || $parent instanceof Node\Expr\PostDec) { return IssueFilterDecision::Keep; }
                $child = $parent;
            }
            // References, dynamic storage, and an incomplete source AST are not native-array certificates.
            if ($finder->findFirst($nodes, static fn (Node $node): bool => $node instanceof Node\Expr\AssignRef || $node instanceof Node\Expr\Eval_
                || $node instanceof Node\Stmt\Global_ || $node instanceof Node\Expr\Variable && ! is_string($node->name)
                || $node instanceof Node\Arg && $node->byRef || $node instanceof Node\Param && $node->byRef
                || $node instanceof Node\ArrayItem && $node->byRef
                || $node instanceof Node\Expr\ClosureUse && $node->byRef || $node instanceof Node\Stmt\Foreach_ && $node->byRef) !== null) { return IssueFilterDecision::Keep; }
        } catch (\PhpParser\Error) { return IssueFilterDecision::Keep; }
        return $this->contract->current($context, $receiver) ? IssueFilterDecision::Remove : IssueFilterDecision::Keep;
    }

    public static function domain(string $printed): bool
    {
        $seen = [];
        foreach (explode('|', $printed) as $part) {
            if (isset($seen[$part]) || ! in_array($part, ['int', 'string', 'null', 'array-key'], true)) { return false; }
            $seen[$part] = true;
        }
        return isset($seen['null']) && (! isset($seen['array-key']) || count($seen) <= 2);
    }
    public static function receiverClass(string $printed): ?string
    {
        if (preg_match('/^([a-z_\x80-\xff][a-z0-9_\x80-\xff]*(?:\\\\[a-z_\x80-\xff][a-z0-9_\x80-\xff]*)*)(.*)$/iD', $printed, $match) !== 1) { return null; }
        $suffix = $match[2];
        if (str_ends_with($suffix, '&static')) { $suffix = substr($suffix, 0, -7); }
        if ($suffix === '') { return $match[1]; }
        if (! str_starts_with($suffix, '<') || ! str_ends_with($suffix, '>')) { return null; }
        $depth = 0; $quote = null; $escape = false;
        foreach (str_split($suffix) as $index => $character) {
            if ($quote !== null) {
                if ($escape) { $escape = false; continue; }
                if ($character === '\\') { $escape = true; continue; }
                if ($character === $quote) { $quote = null; }
                continue;
            }
            if ($character === "'" || $character === '"') { $quote = $character; continue; }
            if ($character === '<') { $depth++; }
            elseif ($character === '>') { $depth--; if ($depth === 0 && $index !== strlen($suffix) - 1 || $depth < 0) { return null; } }
        }
        return $depth === 0 && $quote === null ? $match[1] : null;
    }
}
