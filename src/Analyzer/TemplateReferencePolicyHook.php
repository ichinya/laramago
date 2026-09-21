<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Metadata\MailContentReferenceExport;
use Ichinya\Laramago\Metadata\MailMessageViewReferenceExport;
use Ichinya\Laramago\Metadata\PaginationViewReferenceExport;
use Ichinya\Laramago\Metadata\RouteViewReferenceExport;
use Mago\Sdk\Analyzer\AfterAnalysisContext;
use Mago\Sdk\Analyzer\AfterAnalysisHook;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;

/** Explicit source-reference policy, not a claim about an effective view finder. */
final class TemplateReferencePolicyHook implements AfterAnalysisHook
{
    /** @var list<array{name: string, file: string, start: int, end: int, contentHash: string, context: string}> */
    private array $references = [];
    private bool $enabled = false;

    public function __construct(
        private readonly string $root = '.',
    ) {
        $text = @file_get_contents(rtrim($root, '/\\').'/composer.json');
        /** @var mixed $composer */
        $composer = $text === false ? null : json_decode($text, true);
        /** @var mixed $policy */
        $policy = is_array($composer) ? $composer['extra']['laramago']['template-reference-policy'] ?? null : null;
        if (! is_array($policy) || ($policy['enabled'] ?? null) !== true) {
            return;
        }
        /** @var mixed $files */
        $files = $policy['files'] ?? null;
        /** @var mixed $permitted */
        $permitted = $policy['permitted'] ?? null;
        if (
            ! is_array($files)
            || ! array_is_list($files)
            || $files === []
            || count($files) > 256
            || ! is_array($permitted)
            || $permitted === []
        ) {
            return;
        }
        $selectedFiles = [];
        /** @var mixed $file */
        foreach ($files as $file) {
            if (
                ! is_string($file)
                || $file === ''
                || str_contains($file, "\0")
                || str_contains($file, ':')
                || preg_match('~^(?:[/\\\\])|(?:^|[/\\\\])\.\.(?:[/\\\\]|$)~', $file)
                || ! str_ends_with(strtolower($file), '.php')
            ) {
                return;
            }
            $selectedFiles[] = $file;
        }
        $allowed = [];
        /** @var mixed $names */
        foreach ($permitted as $context => $names) {
            if (
                ! in_array(
                    $context,
                    ['route', 'mail-html', 'mail-text', 'markdown-html', 'markdown-text', 'pagination'],
                    true,
                )
                || ! is_array($names)
                || ! array_is_list($names)
                || count($names) > 20000
            ) {
                return;
            }
            $validNames = [];
            /** @var mixed $name */
            foreach ($names as $name) {
                if (! is_string($name)) {
                    return;
                }
                $validNames[] = $name;
            }
            $allowed[$context] = $validNames;
        }
        $this->enabled = true;
        foreach ([
            'route' => new RouteViewReferenceExport,
            'message' => new MailMessageViewReferenceExport,
            'content' => new MailContentReferenceExport,
            'pagination' => new PaginationViewReferenceExport,
        ] as $kind => $exporter) {
            /** @var array{references: list<array{name: string, file: string, start: int, end: int, contentHash: string, referenceKind?: string, viewSlot?: string, paginatorReceiver?: string}>} $export */
            $export = $exporter->export($root, $selectedFiles);
            foreach ($export['references'] as $reference) {
                foreach (self::contexts($kind, $reference) as $context) {
                    if (! isset($allowed[$context]) || in_array($reference['name'], $allowed[$context], true)) {
                        continue;
                    }
                    $this->references[] = [
                        'name' => $reference['name'],
                        'file' => $reference['file'],
                        'start' => $reference['start'],
                        'end' => $reference['end'],
                        'contentHash' => $reference['contentHash'],
                        'context' => $context,
                    ];
                }
            }
        }
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function afterAnalysis(AfterAnalysisContext $context): void
    {
        if (! $this->enabled || $this->references === []) {
            return;
        }
        /** @var array<string, list<array{name: string, file: string, start: int, end: int, contentHash: string, context: string}>> $wanted */
        $wanted = [];
        foreach ($this->references as $reference) {
            $wanted[self::key($reference['file'])][] = $reference;
        }
        foreach ($context->analysis->files as $analysis) {
            $file = $analysis->file;
            $absolute = preg_match('~^(?:[A-Za-z]:[/\\\\]|[/\\\\])~', $file) === 1
                ? $file
                : rtrim($this->root, '/\\').'/'.$file;
            $resolved = realpath($absolute);
            if ($resolved === false || ! isset($wanted[self::key($resolved)])) {
                continue;
            }
            $source = $analysis->getSourceFile();
            $sourceAbsolute = preg_match('~^(?:[A-Za-z]:[/\\\\]|[/\\\\])~', $source->path) === 1
                ? $source->path
                : rtrim($this->root, '/\\').'/'.$source->path;
            $sourceResolved = realpath($sourceAbsolute);
            if ($sourceResolved === false || self::key($sourceResolved) !== self::key($resolved)) {
                continue;
            }
            $hash = hash('sha256', $source->contents);
            foreach ($wanted[self::key($resolved)] as $reference) {
                if (! self::matches($reference, $hash, strlen($source->contents))) {
                    continue;
                }
                $name = json_encode($reference['name'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $context->report(Level::Note, 'laramago-template-reference-outside-policy', Issue::at(
                    'Template reference '
                    .($name === false ? '"<unprintable>"' : $name)
                    .' is outside the explicit '
                    .$reference['context']
                    .' source-reference policy. '
                    .'This does not prove a missing runtime template or a rendering failure.',
                    new SourceLocation($source->path, new Span($reference['start'], $reference['end'])),
                ));
            }
        }
    }

    /** @param array{contentHash: string, start: int, end: int, name?: string, file?: string, context?: string} $reference */
    private static function matches(array $reference, string $hash, int $length): bool
    {
        return (
            $reference['contentHash'] === $hash
            && $reference['start'] >= 0
            && $reference['end'] > $reference['start']
            && $reference['end'] <= $length
        );
    }

    /** @param array{name: string, file: string, start: int, end: int, contentHash: string, referenceKind?: string, viewSlot?: string, paginatorReceiver?: string} $reference
     * @return list<string>
     */
    private static function contexts(string $kind, array $reference): array
    {
        if ($kind === 'route') {
            return ['route'];
        }
        if ($kind === 'pagination') {
            // links()/render() source syntax alone does not prove a paginator receiver.
            return ($reference['paginatorReceiver'] ?? null) === 'exact-static-paginator' ? ['pagination'] : [];
        }
        if (in_array($reference['referenceKind'] ?? null, ['markdown', 'markdown-view'], true)) {
            return ['markdown-html', 'markdown-text'];
        }

        return (
            ($reference['referenceKind'] ?? null) === 'text-view' || ($reference['viewSlot'] ?? null) === 'text'
                ? ['mail-text']
                : ['mail-html']
        );
    }

    private static function key(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }
}
