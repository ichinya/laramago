<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Metadata;

/**
 * Advisory duplicate declaration candidates from explicitly selected dotenv templates.
 *
 * Matching is exact-case and limited to repeated valid literal declarations within
 * one file. The result does not assert dotenv runtime invalidity or compare files
 * whose effective load order is unknown. Parser errors and uncertainties remain in
 * the export, so candidates from valid entries never imply that a file was complete.
 */
final class EnvironmentTemplateDuplicates
{
    /**
     * @param array<array-key, string> $files Explicit project-relative `.env.example` or `.env.template` files.
     * @return array<string, mixed>
     */
    public function export(string $root, array $files): array
    {
        /**
         * @var array{
         *   projectRoot: string,
         *   declarations: list<array{name: string, file: string, start: int, end: int, line: int, contentHash: string, confidence: string}>,
         *   errors: list<array<string, mixed>>,
         *   uncertainties: list<array<string, mixed>>,
         *   truncated: bool,
         *   truncationReasons: list<string>
         * } $metadata
         */
        $metadata = (new EnvironmentTemplateReferences)->export($root, $files);
        $firstDeclarations = [];
        $candidates = [];

        foreach ($metadata['declarations'] as $declaration) {
            $file = $declaration['file'];
            $name = $declaration['name'];
            if (! isset($firstDeclarations[$file][$name])) {
                $firstDeclarations[$file][$name] = self::location($declaration);

                continue;
            }

            $candidates[] = [
                ...self::location($declaration),
                'firstLocation' => $firstDeclarations[$file][$name],
            ];
        }

        return [
            'schemaVersion' => 1,
            'projectRoot' => $metadata['projectRoot'],
            'scope' => [
                'kind' => 'environment-duplicates',
                'evidence' => 'source-only',
                'semantics' => 'advisory-duplicate-declaration-candidates',
                'matching' => 'exact-case',
                'exhaustive' => false,
            ],
            'candidates' => $candidates,
            'errors' => $metadata['errors'],
            'uncertainties' => $metadata['uncertainties'],
            'truncated' => $metadata['truncated'],
            'truncationReasons' => $metadata['truncationReasons'],
        ];
    }

    /**
     * @param array{name: string, file: string, start: int, end: int, line: int, contentHash: string, confidence: string} $declaration
     * @return array{name: string, file: string, start: int, end: int, line: int, contentHash: string, confidence: string}
     */
    private static function location(array $declaration): array
    {
        return [
            'name' => $declaration['name'],
            'file' => $declaration['file'],
            'start' => $declaration['start'],
            'end' => $declaration['end'],
            'line' => $declaration['line'],
            'contentHash' => $declaration['contentHash'],
            'confidence' => $declaration['confidence'],
        ];
    }
}
