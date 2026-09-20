<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;

/** Positive class and trait candidates from selected Pest use declarations. */
final class PestTestFileMapping
{
    /** @var array<string, array{declaredBaseClass: ?string, classCandidates: list<string>, traits: list<string>, unresolvedNames: list<string>, declarations: list<array{source: string, line: int}>}>|null */
    private ?array $files = null;

    /**
     * The classifier must use static class/trait metadata and return class, trait or null.
     * It must not autoload or execute application classes.
     *
     * @param callable(string): ('class'|'trait'|null) $classify
     */
    public function __construct(PestUsesCatalog $catalog, callable $classify)
    {
        $declarations = $catalog->declarations();
        if ($declarations === null) {
            return;
        }

        $files = [];
        foreach ($declarations as $declaration) {
            foreach ($declaration['files'] as $file) {
                $files[$file] ??= [
                    'declaredBaseClass' => null,
                    'classCandidates' => [],
                    'traits' => [],
                    'unresolvedNames' => [],
                    'declarations' => [],
                ];
                $files[$file]['declarations'][] = [
                    'source' => $declaration['source'],
                    'line' => $declaration['line'],
                ];
                foreach ($declaration['names'] as $name) {
                    $kind = $classify($name);
                    if ($kind === 'class') {
                        $files[$file]['classCandidates'][] = $name;
                    } elseif ($kind === 'trait') {
                        $files[$file]['traits'][] = $name;
                    } else {
                        $files[$file]['unresolvedNames'][] = $name;
                    }
                }
            }
        }

        foreach ($files as &$mapping) {
            if (count($mapping['classCandidates']) === 1 && $mapping['unresolvedNames'] === []) {
                $mapping['declaredBaseClass'] = $mapping['classCandidates'][0];
            }
        }
        unset($mapping);

        $this->files = $files;
    }

    /** Classify candidates through Mago's frozen source metadata. */
    public static function fromCodebase(PestUsesCatalog $catalog, Codebase $codebase): self
    {
        return new self($catalog, static function (string $name) use ($codebase): ?string {
            if ($codebase->getClass($name) !== null) {
                return 'class';
            }

            return $codebase->getTrait($name) !== null ? 'trait' : null;
        });
    }

    /**
     * Only files matched by parsed declarations appear. Entries do not exclude
     * other unparsed declarations or runtime registrations affecting a file.
     *
     * @return array<string, array{declaredBaseClass: ?string, classCandidates: list<string>, traits: list<string>, unresolvedNames: list<string>, declarations: list<array{source: string, line: int}>}>|null
     */
    public function files(): ?array
    {
        return $this->files;
    }

    /**
     * @return array{declaredBaseClass: ?string, classCandidates: list<string>, traits: list<string>, unresolvedNames: list<string>, declarations: list<array{source: string, line: int}>}|null
     */
    public function file(string $path): ?array
    {
        return $this->files[$path] ?? null;
    }
}
