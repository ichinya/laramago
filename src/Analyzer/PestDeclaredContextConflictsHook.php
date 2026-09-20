<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\PestDeclaredContextConflicts;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PestUsesCatalog;
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

/** Warn about opt-in, source-declared Pest test-case overlaps. */
final class PestDeclaredContextConflictsHook implements NodeAnalysisHook, InitializationHook
{
    private ?bool $enabled = null;
    /** @var array<string, list<array{source: string, sourceHash: string, callStart: int, start: int, end: int, file: string, firstClass: string, secondClass: string}>>|null */
    private ?array $conflicts = null;

    public function __construct(
        private readonly string $root = '.',
    ) {}

    public function initialize(InitializationContext $context): void
    {
        $this->enabled = null;
        $this->conflicts = null;
    }

    public function getTargets(): array
    {
        return [NodeKind::FunctionCall];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        if ($this->enabled === null) {
            $this->enabled = $this->enabled();
        }
        if (! $this->enabled) {
            return;
        }
        if ($this->conflicts === null) {
            $catalog = new PestUsesCatalog($this->root);
            $this->conflicts = [];
            foreach (PestDeclaredContextConflicts::find(
                $catalog,
                static function (string $name) use ($context): ?string {
                    if ($context->codebase->getClass($name) !== null) {
                        return 'class';
                    }

                    return $context->codebase->getTrait($name) !== null ? 'trait' : null;
                },
            ) as $conflict) {
                $this->conflicts[$conflict['source']][] = $conflict;
            }
        }
        $path = str_replace('\\', '/', $context->source->path);
        $path = preg_replace('~^//\?/([A-Za-z]:/)~', '$1', $path) ?? $path;
        if (! str_starts_with($path, '/') && preg_match('~^[A-Za-z]:/~', $path) !== 1) {
            $path = rtrim(str_replace('\\', '/', $this->root), '/').'/'.$path;
        }
        foreach ($this->conflicts[$path] ?? [] as $conflict) {
            if (
                $context->node->span->start !== $conflict['callStart']
                || hash('sha256', $context->source->contents) !== $conflict['sourceHash']
            ) {
                continue;
            }
            $context->report(
                Level::Warning,
                'laramago-pest-declared-test-context-conflict',
                Issue::at(
                    'Selected Pest declarations for "'
                    .$conflict['file']
                    .'" contain both "'
                    .$conflict['firstClass']
                    .'" and "'
                    .$conflict['secondClass']
                    .'" as test classes. Pest rejects a second non-default test class if both registrations execute and it builds this file\'s test case.',
                    new SourceLocation($context->source->path, new Span($conflict['start'], $conflict['end'])),
                ),
            );
        }
    }

    private function enabled(): bool
    {
        $text = @file_get_contents(rtrim($this->root, '/\\').'/composer.json');
        /** @var mixed $composer */
        $composer = $text === false ? null : json_decode($text, true);

        return (
            is_array($composer)
            && ($composer['extra']['laramago']['pest-uses']['diagnose-declared-conflicts'] ?? null) === true
        );
    }
}
