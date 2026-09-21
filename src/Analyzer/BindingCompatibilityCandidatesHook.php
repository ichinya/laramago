<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Metadata\BindingCompatibilityExport;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\NodeAnalysisHook;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;
use Mago\Sdk\Syntax\NodeKind;

/** Optional declaration-policy notes, never a claim about the effective container. */
final class BindingCompatibilityCandidatesHook implements NodeAnalysisHook, InitializationHook
{
    /** @var array<string, array{file: string, start: int, end: int, contentHash: string, method: string, abstract: string, concrete: string}> */
    private array $candidates = [];

    /** @var array<string, string> */
    private array $hashes = [];
    private ?string $sourcePath = null;
    private ?string $sourceContents = null;
    private ?string $sourceHash = null;
    private ?bool $hierarchyCurrent = null;

    public function __construct(
        private readonly string $root = '.',
    ) {
        $this->load();
    }

    public function initialize(InitializationContext $context): void
    {
        $this->candidates = [];
        $this->hashes = [];
        $this->sourcePath = null;
        $this->sourceContents = null;
        $this->sourceHash = null;
        $this->hierarchyCurrent = null;
        $this->load();
    }

    private function load(): void
    {
        $text = @file_get_contents(rtrim($this->root, '/\\').'/composer.json');
        /** @var mixed $composer */
        $composer = $text === false ? null : json_decode($text, true);
        /** @var mixed $policy */
        $policy = is_array($composer)
            ? $composer['extra']['laramago']['binding-compatibility-candidates'] ?? null
            : null;
        /** @var mixed $files */
        $files = is_array($policy) && ($policy['diagnose'] ?? null) === true ? $policy['files'] ?? null : null;
        if (
            ! is_array($files)
            || ! array_is_list($files)
            || $files === []
            || count(array_filter($files, is_string(...))) !== count($files)
        ) {
            return;
        }
        /** @var list<string> $files */
        /** @var array{errors: list<mixed>, truncated: bool, declarations: list<array{file: string, contentHash: string}>, candidates: list<array{compatibility: array{status: string}, registration: array{file: string, start: int, end: int, contentHash: string, method: string}, abstract: array{class: string}, concrete: array{class: ?string}}>} $export */
        $export = (new BindingCompatibilityExport)->export($this->root, $files);
        if ($export['errors'] !== [] || $export['truncated']) {
            return;
        }
        foreach ($export['declarations'] as $declaration) {
            $this->hashes[$declaration['file']] = $declaration['contentHash'];
        }
        foreach ($export['candidates'] as $candidate) {
            if (
                $candidate['compatibility']['status'] !== 'incompatible-under-selected-declarations'
                || $candidate['concrete']['class'] === null
            ) {
                continue;
            }
            $this->candidates[self::key($candidate['registration']['file'])
                .':'
                .$candidate['registration']['start']
                .':'
                .$candidate['registration']['end']] = $candidate['registration']
            + ['abstract' => $candidate['abstract']['class'], 'concrete' => $candidate['concrete']['class']];
        }
    }

    public function enabled(): bool
    {
        return $this->candidates !== [];
    }

    public function getTargets(): array
    {
        return [NodeKind::MethodCall];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::ReceiverType, FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $receiver = $context->receiverType;
        $object = $receiver?->atomicTypes[0] ?? null;
        if ($receiver === null || count($receiver->atomicTypes) !== 1 || ! $object instanceof NamedObjectType) {
            return;
        }
        $path = realpath($context->source->path);
        if ($path === false) {
            $path = realpath(rtrim($this->root, '/\\').'/'.$context->source->path);
        }
        if ($path === false) {
            return;
        }
        $candidate =
            $this->candidates[self::key($path).':'.$context->node->span->start.':'.$context->node->span->end] ?? null;
        if ($candidate === null) {
            return;
        }
        if ($this->sourcePath !== $path || $this->sourceContents !== $context->source->contents) {
            $this->sourcePath = $path;
            $this->sourceContents = $context->source->contents;
            $this->sourceHash = hash('sha256', $context->source->contents);
            $this->hierarchyCurrent = null;
        }
        if ($this->sourceHash !== $candidate['contentHash'] || ! $this->currentHierarchy()) {
            return;
        }
        $class = $context->codebase->getClass($object->name);
        if ($class === null || $class->hasIncompleteHierarchy()) {
            return;
        }
        foreach ([$object->name, ...$context->codebase->getClassAncestors($object->name)] as $ancestor) {
            $metadata = $context->codebase->getClass($ancestor);
            foreach ([...($metadata?->pseudoMethods ?? []), ...($metadata?->staticPseudoMethods ?? [])] as $name) {
                if (strcasecmp($name, $candidate['method']) === 0) {
                    return;
                }
            }
        }
        $method = $context->codebase->getDeclaringMethod($object->name, $candidate['method']);
        if (
            $method === null
            || $method->static
            || strcasecmp($method->identifier->class ?? '', 'Illuminate\\Container\\Container') !== 0
            || ! str_ends_with(
                str_replace('\\', '/', $method->location->file ?? ''),
                '/laravel/framework/src/Illuminate/Container/Container.php',
            )
        ) {
            return;
        }
        $context->report(Level::Note, 'laramago-binding-compatibility-candidate', Issue::at(
            'Selected binding declaration pairs "'
            .$candidate['abstract']
            .'" with "'
            .$candidate['concrete']
            .'", which does not extend or implement it in the selected complete hierarchy. This declaration-policy note does not prove a container runtime failure.',
            new SourceLocation($context->source->path, new Span($candidate['start'], $candidate['end'])),
        ));
    }

    private function currentHierarchy(): bool
    {
        if ($this->hierarchyCurrent !== null) {
            return $this->hierarchyCurrent;
        }
        foreach ($this->hashes as $file => $hash) {
            $contents = @file_get_contents($file, false, null, 0, (1024 * 1024) + 1);
            if ($contents === false || hash('sha256', $contents) !== $hash) {
                return $this->hierarchyCurrent = false;
            }
        }

        return $this->hierarchyCurrent = true;
    }

    private static function key(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }
}
