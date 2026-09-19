<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\NodeAnalysisHook;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;
use Mago\Sdk\Syntax\NodeKind;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/** Check required files only when Mago has resolved an absolute literal path. */
final class RequiredFileReferencesHook implements NodeAnalysisHook
{
    private ?string $sourceHash = null;
    /** @var array<string, Node\Expr\Include_> */
    private array $requires = [];

    public function getTargets(): array
    {
        return [NodeKind::RequireConstruct, NodeKind::RequireOnceConstruct];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $require = $this->requiredExpression($context);
        if ($require === null) {
            return;
        }

        $expression = $require->expr;
        $span = new Span($expression->getStartFilePos(), $expression->getEndFilePos() + 1);
        $path = $context->analysis->getExpressionType($span)?->getLiteralString();
        if ($path === null || ! self::absoluteLocalPath($path)) {
            return;
        }

        // A failed stat cannot distinguish absence from an inaccessible entry.
        // Only a successful parent listing that lacks the name proves absence.
        if (@file_exists($path) || @is_link($path)) {
            return;
        }
        $entries = @scandir(dirname($path));
        if ($entries === false) {
            return;
        }
        $name = basename($path);
        foreach ($entries as $entry) {
            if (strcasecmp($entry, $name) === 0) {
                return;
            }
        }

        $context->report(
            Level::Warning,
            'laramago-missing-required-file',
            Issue::at(
                'Required file "'.$path.'" is absent from the analyzed filesystem snapshot.',
                new SourceLocation($context->source->path, $span),
            ),
        );
    }

    private static function absoluteLocalPath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '://')) {
            return false;
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            return str_starts_with($path, '/');
        }

        $normalized = str_replace('\\', '/', $path);

        return (
            preg_match('~^[A-Za-z]:/[^:]*$~', $normalized) === 1
            || preg_match('~^//(?![?.]/)[^/]+/[^/]+/[^:]*$~', $normalized) === 1
        );
    }

    private function requiredExpression(NodeAnalysisContext $context): ?Node\Expr\Include_
    {
        $hash = hash('sha256', $context->source->path."\0".$context->source->contents);
        if ($hash !== $this->sourceHash) {
            $this->sourceHash = $hash;
            $this->requires = [];
            try {
                $nodes = (new ParserFactory)
                    ->createForNewestSupportedVersion()
                    ->parse($context->source->contents);
            } catch (\PhpParser\Error) {
                return null;
            }
            foreach ((new NodeFinder)->findInstanceOf($nodes ?? [], Node\Expr\Include_::class) as $include) {
                if (! in_array(
                    $include->type,
                    [Node\Expr\Include_::TYPE_REQUIRE, Node\Expr\Include_::TYPE_REQUIRE_ONCE],
                    true,
                )) {
                    continue;
                }
                $this->requires[$include->getStartFilePos().':'.($include->getEndFilePos() + 1)] = $include;
            }
        }

        return $this->requires[$context->node->span->start.':'.$context->node->span->end] ?? null;
    }
}
