<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Metadata\RouteNameDuplicateCandidates;
use Mago\Sdk\Analyzer\AfterAnalysisContext;
use Mago\Sdk\Analyzer\AfterAnalysisHook;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;

/** Report opt-in source-only duplicate route-name candidates at both declarations. */
final class RouteNameDuplicateCandidatesHook implements AfterAnalysisHook
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
        $files = self::configuredFiles($root);
        if ($files === null) {
            $this->enabled = false;
            $this->candidates = [];

            return;
        }
        $this->enabled = true;
        /**
         * @var array{
         *   candidates: list<array{
         *     name: string,
         *     file: string,
         *     start: int,
         *     end: int,
         *     contentHash: string,
         *     firstLocation: array{file: string, start: int, end: int, contentHash: string}
         *   }>
         * } $export
         */
        $export = (new RouteNameDuplicateCandidates)->export($root, $files);
        $this->candidates = $export['candidates'];
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
                Level::Note,
                'laramago-route-name-duplicate-candidate',
                Issue::at(
                    'Route name "'
                    .$candidate['name']
                    .'" is repeated in the explicitly selected source files. '
                    .'This source-only candidate does not prove an active route conflict.',
                    $primary,
                    'Repeated selected declaration',
                )->withSecondaryLocation($secondary, 'First selected declaration'),
            );
        }
    }

    /** @return list<string>|null */
    private static function configuredFiles(string $root): ?array
    {
        $text = @file_get_contents(rtrim($root, '/\\').'/composer.json');
        /** @var mixed $composer */
        $composer = $text === false ? null : json_decode($text, true);
        /** @var mixed $policy */
        $policy = is_array($composer)
            ? $composer['extra']['laramago']['route-name-duplicate-candidates'] ?? null
            : null;
        /** @var mixed $files */
        $files = is_array($policy) && ($policy['diagnose'] ?? null) === true ? $policy['files'] ?? null : null;
        if (! is_array($files) || ! array_is_list($files) || $files === []) {
            return null;
        }
        if (count(array_filter($files, static fn (mixed $file): bool => is_string($file))) !== count($files)) {
            return null;
        }

        /** @var list<string> $files */
        return $files;
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
