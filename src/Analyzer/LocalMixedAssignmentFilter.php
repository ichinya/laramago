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
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

/** PHPStan does not reject storing mixed in a plain local; unsafe uses retain native diagnostics. */
final class LocalMixedAssignmentFilter implements IssueFilterHook, InitializationHook
{
    private const CODE = 'mixed-assignment';
    private const MAX_FILE_BYTES = 1024 * 1024;
    private const MAX_CACHE_BYTES = 8 * 1024 * 1024;
    private const MAX_CACHE_ENTRIES = 32;

    /** @var array<string, array{spans: array<string, true>, bytes: int}> */
    private array $cache = [];
    private int $cacheBytes = 0;

    public function initialize(InitializationContext $context): void
    {
        $this->cache = [];
        $this->cacheBytes = 0;
    }

    public function getCodes(): array
    {
        return [self::CODE];
    }

    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        if ($context->issue->code !== self::CODE || strlen($context->contents) > self::MAX_FILE_BYTES) {
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
        $start = $primary->span->start;
        $end = $primary->span->end;
        if ($start < 0 || $end <= $start || $end > strlen($context->contents)) {
            return IssueFilterDecision::Keep;
        }

        $key = hash('sha256', $context->file."\0".$context->contents);
        if (isset($this->cache[$key])) {
            $entry = $this->cache[$key];
            unset($this->cache[$key]);
            $this->cache[$key] = $entry;
        } else {
            $spans = $this->localBindings($context->contents);
            if ($spans === null) {
                return IssueFilterDecision::Keep;
            }
            $entry = ['spans' => $spans, 'bytes' => strlen($context->contents) + count($spans) * 64];
            while (
                $this->cache !== []
                && (count($this->cache) >= self::MAX_CACHE_ENTRIES
                    || $this->cacheBytes + $entry['bytes'] > self::MAX_CACHE_BYTES)
            ) {
                $oldest = array_key_first($this->cache);
                $this->cacheBytes -= $this->cache[$oldest]['bytes'];
                unset($this->cache[$oldest]);
            }
            if ($entry['bytes'] <= self::MAX_CACHE_BYTES) {
                $this->cache[$key] = $entry;
                $this->cacheBytes += $entry['bytes'];
            }
        }

        return isset($entry['spans'][$start.':'.$end])
            ? IssueFilterDecision::Remove
            : IssueFilterDecision::Keep;
    }

    /** @return array<string, true>|null Exact source spans, or null when parsing is uncertain. */
    private function localBindings(string $contents): ?array
    {
        try {
            $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($contents) ?? [];
        } catch (Error) {
            return null;
        }

        $visitor = new class($contents) extends NodeVisitorAbstract {
            /** @var list<array{forbidden: array<string, true>, byRef: bool, candidates: list<array{name: string, start: int, end: int}>}> */
            private array $scopes = [];
            /** @var array<string, true> */
            private array $safe = [];

            public function __construct(private readonly string $contents) {}

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Node\Expr\Closure && $this->scopes !== []) {
                    $outer = count($this->scopes) - 1;
                    foreach ($node->uses as $use) {
                        if ($use->byRef) {
                            $this->forbid($outer, $use->var);
                        }
                    }
                }
                if ($node instanceof Node\FunctionLike) {
                    $forbidden = [];
                    foreach ($node->getParams() as $parameter) {
                        if ($parameter->byRef && is_string($parameter->var->name)) {
                            $forbidden[$parameter->var->name] = true;
                        }
                    }
                    if ($node instanceof Node\Expr\Closure) {
                        foreach ($node->uses as $use) {
                            if ($use->byRef && is_string($use->var->name)) {
                                $forbidden[$use->var->name] = true;
                            }
                        }
                    }
                    $this->scopes[] = [
                        'forbidden' => $forbidden,
                        'byRef' => property_exists($node, 'byRef') && $node->byRef,
                        'candidates' => [],
                    ];
                }
                if ($this->scopes === []) {
                    return null;
                }
                $index = count($this->scopes) - 1;
                if ($node instanceof Node\Stmt\Global_) {
                    foreach ($node->vars as $variable) {
                        $this->forbid($index, $variable);
                    }
                } elseif ($node instanceof Node\Stmt\Static_) {
                    foreach ($node->vars as $variable) {
                        $this->forbid($index, $variable->var);
                    }
                } elseif ($node instanceof Node\Expr\AssignRef) {
                    $this->forbid($index, $node->var);
                    $this->forbid($index, $node->expr);
                } elseif ($node instanceof Node\Expr\Assign) {
                    $this->candidate($index, $node->var);
                } elseif ($node instanceof Node\Stmt\Foreach_) {
                    if ($node->byRef) {
                        $this->forbid($index, $node->valueVar);
                    } else {
                        $this->candidate($index, $node->keyVar);
                        $this->candidate($index, $node->valueVar);
                    }
                }

                return null;
            }

            public function leaveNode(Node $node): ?int
            {
                if (! $node instanceof Node\FunctionLike) {
                    return null;
                }
                $scope = array_pop($this->scopes);
                foreach ($scope['candidates'] as $candidate) {
                    if (! $scope['byRef'] && ! isset($scope['forbidden'][$candidate['name']])) {
                        $this->safe[$candidate['start'].':'.$candidate['end']] = true;
                    }
                }

                return null;
            }

            private function forbid(int $index, Node\Expr $variable): void
            {
                if ($variable instanceof Node\Expr\Variable && is_string($variable->name)) {
                    $this->scopes[$index]['forbidden'][$variable->name] = true;
                }
            }

            private function candidate(int $index, ?Node\Expr $variable): void
            {
                if (! $variable instanceof Node\Expr\Variable || ! is_string($variable->name)) {
                    return;
                }
                $name = $variable->name;
                if (
                    $name === 'this'
                    || in_array($name, [
                        'GLOBALS', '_SERVER', '_GET', '_POST', '_FILES', '_COOKIE', '_SESSION', '_REQUEST', '_ENV',
                    ], true)
                ) {
                    return;
                }
                $start = $variable->getStartFilePos();
                $end = $variable->getEndFilePos() + 1;
                if (
                    $start < 0
                    || $end > strlen($this->contents)
                    || substr($this->contents, $start, $end - $start) !== '$'.$name
                ) {
                    return;
                }
                $this->scopes[$index]['candidates'][] = compact('name', 'start', 'end');
            }

            /** @return array<string, true> */
            public function spans(): array
            {
                return $this->safe;
            }
        };
        (new NodeTraverser($visitor))->traverse($nodes);

        return $visitor->spans();
    }
}
