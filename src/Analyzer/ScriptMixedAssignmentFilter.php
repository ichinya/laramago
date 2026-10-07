<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Reporting\AnnotationKind;
use Mago\Sdk\Reporting\Level;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * Diagnostic policy for storing an include result, not include return inference.
 * Ordinary function-result globals and every unsafe-use diagnostic remain native.
 */
final class ScriptMixedAssignmentFilter implements IssueFilterHook, InitializationHook
{
    private const MAX_FILE_BYTES = 1024 * 1024;
    private const MAX_CACHE_BYTES = 8 * 1024 * 1024;
    private const MAX_CACHE_ENTRIES = 32;
    private const MAX_NODES = 20000;

    /** @var array<string, array{spans: array<string, true>, bytes: int}> */
    private array $cache = [];
    private int $cacheBytes = 0;

    public function initialize(InitializationContext $context): void
    {
        $this->cache = [];
        $this->cacheBytes = 0;
    }

    public function getCodes(): array { return ['mixed-assignment']; }

    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        $issue = $context->issue;
        if ($context->cancellation->isCancelled() || strlen($context->contents) > self::MAX_FILE_BYTES
            || $issue->code !== 'mixed-assignment' || $issue->level !== Level::Warning
            || $issue->message !== 'Assigning `mixed` type to a variable may lead to unexpected behavior.'
            || $issue->notes !== ['Using `mixed` can lead to runtime errors if the variable is used in a way that assumes a specific type.']
            || $issue->help !== 'Consider using a more specific type to avoid potential issues.'
            || $issue->link !== null || $issue->edits !== [] || count($issue->annotations) !== 1) {
            return IssueFilterDecision::Keep;
        }
        $primary = $issue->annotations[0];
        if ($primary->kind !== AnnotationKind::Primary || $primary->file !== null && $primary->file !== ''
            || $primary->message !== 'Assigning `mixed` type here.'
            || $primary->span->start < 0 || $primary->span->end <= $primary->span->start
            || $primary->span->end > strlen($context->contents)) {
            return IssueFilterDecision::Keep;
        }

        // The SDK's in-memory file is authoritative; no include or disk read occurs.
        $key = hash('sha256', $context->file."\0".$context->contents);
        if (isset($this->cache[$key])) {
            $entry = $this->cache[$key];
            unset($this->cache[$key]);
            $this->cache[$key] = $entry;
        } else {
            $spans = $this->bindings($context->contents);
            if ($spans === null || $context->cancellation->isCancelled()) {
                return IssueFilterDecision::Keep;
            }
            $entry = ['spans' => $spans, 'bytes' => strlen($context->contents) + count($spans) * 64];
            while ($this->cache !== [] && (count($this->cache) >= self::MAX_CACHE_ENTRIES
                || $this->cacheBytes + $entry['bytes'] > self::MAX_CACHE_BYTES)) {
                $oldest = array_key_first($this->cache);
                $this->cacheBytes -= $this->cache[$oldest]['bytes'];
                unset($this->cache[$oldest]);
            }
            if ($entry['bytes'] <= self::MAX_CACHE_BYTES) {
                $this->cache[$key] = $entry;
                $this->cacheBytes += $entry['bytes'];
            }
        }
        return isset($entry['spans'][$primary->span->start.':'.$primary->span->end])
            ? IssueFilterDecision::Remove : IssueFilterDecision::Keep;
    }

    /** @return array<string, true>|null */
    private function bindings(string $contents): ?array
    {
        // Even malformed or extension-specific variable annotations defer. A
        // spelling in a string is conservatively retained, too.
        if (preg_match('/@(?:phpstan-|psalm-|mago-)?var\b/i', $contents) === 1) { return []; }
        try {
            $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($contents) ?? [];
        } catch (Error) {
            return null;
        }
        $visitor = new class(self::MAX_NODES) extends NodeVisitorAbstract {
            public bool $uncertain = false;
            private int $count = 0;
            /** @var array<string, true> */
            public array $forbidden = [];

            public function __construct(private readonly int $maximum) {}

            public function enterNode(Node $node): ?int
            {
                if (++$this->count > $this->maximum) {
                    $this->uncertain = true;
                    return NodeTraverser::STOP_TRAVERSAL;
                }
                if ($node instanceof Node\Expr\Variable && ! is_string($node->name)
                    || $node instanceof Node\Expr\Eval_
                    || $node instanceof Node\Expr\AssignRef
                    || $node instanceof Node\Arg && $node->byRef) {
                    $this->uncertain = true;
                }
                if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name
                    && in_array(strtolower($node->name->getLast()), ['extract', 'parse_str'], true)) {
                    $this->uncertain = true;
                }
                if ($node instanceof Node\Stmt\Global_) {
                    foreach ($node->vars as $var) { $this->forbid($var); }
                } elseif ($node instanceof Node\Stmt\Static_) {
                    foreach ($node->vars as $var) { $this->forbid($var->var); }
                } elseif ($node instanceof Node\Param && $node->byRef) {
                    $this->forbid($node->var);
                } elseif ($node instanceof Node\Expr\ClosureUse && $node->byRef) {
                    $this->forbid($node->var);
                } elseif ($node instanceof Node\Stmt\Foreach_ && $node->byRef) {
                    $this->forbid($node->valueVar);
                }
                return null;
            }

            private function forbid(Node\Expr $var): void
            {
                if ($var instanceof Node\Expr\Variable && is_string($var->name)) {
                    $this->forbidden[$var->name] = true;
                } else {
                    $this->uncertain = true;
                }
            }
        };
        try {
            $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes);
            (new NodeTraverser($visitor))->traverse($nodes);
        } catch (Error) {
            return null;
        }
        if ($visitor->uncertain) { return []; }

        // Only whole assignments directly in the script or a namespace body.
        // Recursing into a condition, function, class or destructuring would
        // change the supported storage policy.
        $statements = [];
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                array_push($statements, ...$node->stmts);
            } else {
                $statements[] = $node;
            }
        }
        $spans = [];
        foreach ($statements as $statement) {
            if (! $statement instanceof Node\Stmt\Expression
                || ! $statement->expr instanceof Node\Expr\Assign
                || ! $statement->expr->expr instanceof Node\Expr\Include_
                || ! $statement->expr->var instanceof Node\Expr\Variable
                || ! is_string($statement->expr->var->name)) { continue; }
            $variable = $statement->expr->var;
            $name = $variable->name;
            if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $name) !== 1
                || isset($visitor->forbidden[$name]) || in_array($name, [
                    'this', 'GLOBALS', '_SERVER', '_GET', '_POST', '_FILES', '_COOKIE', '_SESSION', '_REQUEST', '_ENV',
                ], true)) { continue; }
            $start = $variable->getStartFilePos();
            $end = $variable->getEndFilePos() + 1;
            if ($start < 0 || $end <= $start || $end > strlen($contents)
                || substr($contents, $start, $end - $start) !== '$'.$name) { continue; }
            $spans[$start.':'.$end] = true;
        }
        return $spans;
    }
}
