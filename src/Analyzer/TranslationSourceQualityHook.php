<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Metadata\TranslationPlaceholderConsistencyExport;
use Ichinya\Laramago\Metadata\TranslationReplacementNameAdvisoryExport;
use Mago\Sdk\Analyzer\AfterAnalysisContext;
use Mago\Sdk\Analyzer\AfterAnalysisHook;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;

/** Explicit source-quality conventions, independent of runtime translation loading. */
final class TranslationSourceQualityHook implements AfterAnalysisHook
{
    /** @var list<array{file:string,start:int,end:int,contentHash:string,code:string,secondary?:array{file:string,start:int,end:int,contentHash:string}}> */
    private array $findings = [];
    private bool $enabled = false;

    public function __construct(
        private readonly string $root = '.',
    ) {
        $text = @file_get_contents(rtrim($root, '/\\').'/composer.json');
        /** @var mixed $composer */
        $composer = $text === false ? null : json_decode($text, true);
        /** @var mixed $policy */
        $policy = is_array($composer) ? $composer['extra']['laramago']['translation-source-quality'] ?? null : null;
        if (! is_array($policy) || ($policy['diagnose'] ?? null) !== true) {
            return;
        }
        $this->enabled = true;
        /** @var mixed $sources */
        $sources = $policy['equal-placeholder-sources'] ?? null;
        if (is_array($sources) && array_is_list($sources) && count($sources) <= 256) {
            /** @var array{candidates:list<array{locations:list<array{file:string,keyStart:int,keyEnd:int,contentHash:string,pluralBranchesPresent:bool,names:list<string>}>}>} $export */
            $export = (new TranslationPlaceholderConsistencyExport)->export($root, $sources);
            foreach ($export['candidates'] as $candidate) {
                if (
                    count($candidate['locations']) < 2
                    || array_filter(
                        $candidate['locations'],
                        static fn (array $location): bool => $location['pluralBranchesPresent'],
                    ) !== []
                ) {
                    continue;
                }
                $first = self::location($candidate['locations'][0]);
                foreach (array_slice($candidate['locations'], 1) as $location) {
                    if ($location['names'] === $candidate['locations'][0]['names']) {
                        continue;
                    }
                    $this->findings[] = [
                        ...self::location($location),
                        'secondary' => $first,
                        'code' => 'placeholder-parity',
                    ];
                }
            }
        }
        /** @var mixed $files */
        $files = $policy['ascii-replacement-name-files'] ?? null;
        if (
            ! is_array($files)
            || ! array_is_list($files)
            || count($files) > 256
            || count(array_filter($files, 'is_string')) !== count($files)
        ) {
            return;
        }
        /** @var list<string> $files */
        /** @var array{advisories:list<array{file:string,start:int,end:int,contentHash:string,sourceSelected:bool}>} $export */
        $export = (new TranslationReplacementNameAdvisoryExport)->export($root, $files);
        foreach ($export['advisories'] as $advisory) {
            if ($advisory['sourceSelected']) {
                $this->findings[] = [
                    'file' => $advisory['file'],
                    'start' => $advisory['start'],
                    'end' => $advisory['end'],
                    'contentHash' => $advisory['contentHash'],
                    'code' => 'replacement-name-convention',
                ];
            }
        }
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function afterAnalysis(AfterAnalysisContext $context): void
    {
        if ($this->findings === []) {
            return;
        }
        /** @var array<string,array{file:string,hash:string,length:int}> $indexed */
        $indexed = [];
        foreach ($context->analysis->files as $analysis) {
            $source = $analysis->getSourceFile();
            $path = realpath($source->path) ?: realpath(rtrim($this->root, '/\\').'/'.$source->path);
            if ($path === false) {
                continue;
            }
            $indexed[self::key($path)] = [
                'file' => $source->path,
                'hash' => hash('sha256', $source->contents),
                'length' => strlen($source->contents),
            ];
        }
        foreach ($this->findings as $finding) {
            $primary = self::indexedLocation($finding, $indexed);
            if ($primary === null) {
                continue;
            }
            $secondary = isset($finding['secondary']) ? self::indexedLocation($finding['secondary'], $indexed) : null;
            if (isset($finding['secondary']) && $secondary === null) {
                continue;
            }
            $message = $finding['code'] === 'placeholder-parity'
                ? 'Explicitly associated locale messages have different placeholder sets under the selected parity convention. This is source-quality advice, not a missing runtime replacement.'
                : 'This literal replacement key is outside the selected ASCII colon-word naming convention. Laravel accepts other names; this is source-quality advice.';
            $issue = Issue::at($message, $primary);
            if ($secondary !== null) {
                $issue = $issue->withSecondaryLocation($secondary, 'Compared selected locale declaration');
            }
            $context->report(Level::Note, 'laramago-translation-'.$finding['code'], $issue);
        }
    }

    /** @param array{file:string,keyStart:int,keyEnd:int,contentHash:string,pluralBranchesPresent:bool,names:list<string>} $location
     * @return array{file:string,start:int,end:int,contentHash:string}
     */
    private static function location(array $location): array
    {
        return [
            'file' => $location['file'],
            'start' => $location['keyStart'],
            'end' => $location['keyEnd'],
            'contentHash' => $location['contentHash'],
        ];
    }

    /** @param array{file:string,start:int,end:int,contentHash:string,code?:string,secondary?:array{file:string,start:int,end:int,contentHash:string}} $location
     * @param array<string,array{file:string,hash:string,length:int}> $indexed
     */
    private static function indexedLocation(array $location, array $indexed): ?SourceLocation
    {
        $source = $indexed[self::key($location['file'])] ?? null;
        if (
            $source === null
            || $source['hash'] !== $location['contentHash']
            || $location['start'] < 0
            || $location['end'] <= $location['start']
            || $location['end'] > $source['length']
        ) {
            return null;
        }

        return new SourceLocation($source['file'], new Span($location['start'], $location['end']));
    }

    private static function key(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }
}
