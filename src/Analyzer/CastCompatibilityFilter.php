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

/** PHPStan accepts explicit casts whose PHP conversion is defined for the input. */
final class CastCompatibilityFilter implements IssueFilterHook, InitializationHook
{
    /** @var array<string, array<string, array<string, true>>> */
    private array $cache = [];

    public function initialize(InitializationContext $context): void
    {
        $this->cache = [];
    }

    public function getCodes(): array
    {
        return ['invalid-type-cast', 'redundant-cast', 'mixed-operand'];
    }

    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        if (! in_array($context->issue->code, $this->getCodes(), true) || strlen($context->contents) > 1024 * 1024) {
            return IssueFilterDecision::Keep;
        }
        // Match both the conversion and its destination: the operand may itself
        // be an invalid inner cast, sharing the outer operand's byte span.
        $category = match (true) {
            $context->issue->code === 'redundant-cast' => 'all',
            $context->issue->code === 'mixed-operand'
                && $context->issue->message === 'Casting `mixed` to `bool`.' => 'bool',
            $context->issue->code === 'invalid-type-cast'
                && preg_match('/(?:cast|Casting).* to `array`(?:\.| will )/i', $context->issue->message) === 1 => 'array',
            $context->issue->code === 'invalid-type-cast'
                && preg_match('/^Non numeric string of type `string` implicitly cast to `(int|float)`\.$/', $context->issue->message, $match) === 1 => $match[1],
            default => null,
        };
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
            $spans = ['all' => [], 'array' => [], 'bool' => [], 'int' => [], 'float' => []];
            foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Expr\Cast::class) as $cast) {
                $kind = match (true) {
                    $cast instanceof Node\Expr\Cast\Array_ => 'array',
                    $cast instanceof Node\Expr\Cast\Bool_ => 'bool',
                    $cast instanceof Node\Expr\Cast\Int_ => 'int',
                    $cast instanceof Node\Expr\Cast\Double => 'float',
                    default => 'all',
                };
                foreach ([$cast, $cast->expr] as $node) {
                    foreach (ParenthesizedExpressionSpans::collect($node, $cast, $parser->getTokens()) as $span) {
                        $spans['all'][$span] = true;
                        $spans[$kind][$span] = true;
                    }
                }
            }
            if (count($this->cache) >= 16) {
                unset($this->cache[array_key_first($this->cache)]);
            }
            $this->cache[$key] = $spans;
        }
        $spans = $this->cache[$key][$category];

        return isset($spans[$primary->span->start.':'.$primary->span->end])
            ? IssueFilterDecision::Remove
            : IssueFilterDecision::Keep;
    }
}
