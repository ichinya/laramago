<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\EffectiveRouteManifest;
use Mago\Sdk\Analyzer\AfterAnalysisContext;
use Mago\Sdk\Analyzer\AfterAnalysisHook;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;

/** Report duplicate names in an explicitly asserted final effective route collection. */
final class EffectiveRouteNameConflictsHook implements AfterAnalysisHook
{
    /**
     * @var list<array{
     *   name: string,
     *   file: string,
     *   start: int,
     *   end: int,
     *   contentHash: string,
     *   firstLocation: array{file: string, start: int, end: int, contentHash: string}
     * }>
     */
    private readonly array $candidates;
    private readonly bool $enabled;

    public function __construct(
        private readonly string $root = '.',
    ) {
        $manifest = new EffectiveRouteManifest($root);
        $this->enabled = $manifest->enabled;
        $this->candidates = $manifest->conflicts;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function afterAnalysis(AfterAnalysisContext $context): void
    {
        if (! $this->enabled) {
            return;
        }

        /** @var array<string, true> $wanted */
        $wanted = [];
        foreach ($this->candidates as $candidate) {
            $wanted[self::pathKey($candidate['file'])] = true;
            $wanted[self::pathKey($candidate['firstLocation']['file'])] = true;
        }

        /** @var array<string, array{file: string, contentHash: string, byteLength: int}> $indexed */
        $indexed = [];
        foreach ($context->analysis->files as $analysis) {
            $path = self::absolutePath($this->root, $analysis->file);
            if ($path === null || ! isset($wanted[self::pathKey($path)])) {
                continue;
            }
            $source = $analysis->getSourceFile();
            $sourcePath = self::absolutePath($this->root, $source->path);
            if ($sourcePath === null || self::pathKey($sourcePath) !== self::pathKey($path)) {
                continue;
            }
            $indexed[self::pathKey($path)] = [
                'file' => $source->path,
                'contentHash' => hash('sha256', $source->contents),
                'byteLength' => strlen($source->contents),
            ];
        }

        foreach ($this->candidates as $candidate) {
            $primary = self::indexedLocation($candidate, $indexed);
            $secondary = self::indexedLocation($candidate['firstLocation'], $indexed);
            if ($primary === null || $secondary === null) {
                continue;
            }
            $context->report(
                Level::Warning,
                'laramago-effective-route-name-conflict',
                Issue::at(
                    'Route name "'
                    .$candidate['name']
                    .'" belongs to distinct surviving routes in the asserted effective collection.',
                    $primary,
                    'Conflicting effective route',
                )->withSecondaryLocation($secondary, 'First effective route with this name'),
            );
        }
    }

    /**
     * @param array{
     *   file: string,
     *   start: int,
     *   end: int,
     *   contentHash: string,
     *   name?: string,
     *   firstLocation?: array{file: string, start: int, end: int, contentHash: string}
     * } $location
     * @param array<string, array{file: string, contentHash: string, byteLength: int}> $indexed
     */
    private static function indexedLocation(array $location, array $indexed): ?SourceLocation
    {
        $source = $indexed[self::pathKey($location['file'])] ?? null;
        if (
            $source === null
            || $source['contentHash'] !== $location['contentHash']
            || $location['start'] < 0
            || $location['end'] <= $location['start']
            || $location['end'] > $source['byteLength']
        ) {
            return null;
        }

        return new SourceLocation($source['file'], new Span($location['start'], $location['end']));
    }

    private static function absolutePath(string $root, string $file): ?string
    {
        $path = preg_match('~^(?:[A-Za-z]:[/\\\\]|[/\\\\])~', $file) === 1
            ? $file
            : rtrim($root, '/\\').'/'.$file;
        $resolved = realpath($path);

        return $resolved === false ? null : str_replace('\\', '/', $resolved);
    }

    private static function pathKey(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }
}
