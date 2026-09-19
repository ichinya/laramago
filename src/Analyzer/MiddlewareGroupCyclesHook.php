<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\MiddlewareGroupCatalog;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\NodeAnalysisHook;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;
use Mago\Sdk\Syntax\NodeKind;

/** Report graph integrity at effective declarations, without predicting route dispatch. */
final class MiddlewareGroupCyclesHook implements NodeAnalysisHook, InitializationHook
{
    private ?MiddlewareGroupCatalog $catalog = null;

    public function __construct(
        private readonly string $root,
    ) {}

    public function hasSources(): bool
    {
        $this->catalog ??= new MiddlewareGroupCatalog($this->root);

        return $this->catalog->locations() !== [];
    }

    public function initialize(InitializationContext $context): void
    {
        $this->catalog = null;
    }

    public function getTargets(): array
    {
        return [NodeKind::Program];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $this->catalog ??= new MiddlewareGroupCatalog($this->root);
        $groups = $this->catalog->groups();
        if ($groups === null) {
            return;
        }
        $path = realpath($context->source->path);
        if ($path === false) {
            return;
        }
        $path = str_replace('\\', '/', $path);
        $hash = hash('sha256', $context->source->contents);
        foreach ($this->catalog->locations() as $name => $location) {
            if ($location['path'] !== $path || $location['hash'] !== $hash || ! self::cyclic($groups, $name)) {
                continue;
            }
            $context->report(
                Level::Warning,
                'laramago-middleware-group-cycle',
                Issue::at(
                    'Effective middleware group "'.$name.'" participates in a declared nested-group cycle.',
                    new SourceLocation($context->source->path, new Span($location['start'], $location['end'])),
                ),
            );
        }
    }

    /** @param array<string, list<string>> $groups */
    private static function cyclic(array $groups, string $name): bool
    {
        $pending = $groups[$name];
        $visited = [];
        while ($pending !== []) {
            $member = array_pop($pending);
            if ($member === $name) {
                return true;
            }
            if (isset($visited[$member]) || ! isset($groups[$member])) {
                continue;
            }
            $visited[$member] = true;
            // Laravel nested expansion tests the exact raw group key before aliases.
            // Parameter-bearing names are neither split nor normalized here.
            foreach ($groups[$member] as $nested) {
                $pending[] = $nested;
            }
        }

        return false;
    }
}
