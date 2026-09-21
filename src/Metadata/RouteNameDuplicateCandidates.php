<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Metadata;

/**
 * Advisory duplicate-name candidates from explicitly selected route sources.
 *
 * Matching follows RouteMetadataExport's selected-file and source order. Repeated
 * literal names are useful review candidates, but they are not active-route
 * conflicts: this source subset does not prove method/domain/URI replacement,
 * activation, later mutation, or the effective runtime route collection.
 */
final class RouteNameDuplicateCandidates
{
    /**
     * @param array<array-key, string> $files Explicit project-relative PHP route sources.
     * @return array<string, mixed>
     */
    public function export(string $root, array $files): array
    {
        /**
         * @var array{
         *   projectRoot: string,
         *   declarations: list<array{
         *     name: string,
         *     file: string,
         *     start: int,
         *     end: int,
         *     line: int,
         *     contentHash: string,
         *     confidence: string,
         *     rawName?: string,
         *     nameProvenance?: array{
         *       kind: string,
         *       tokens: list<array{role: string, value: string, start: int, end: int, line: int}>
         *     }
         *   }>,
         *   errors: list<array<string, mixed>>,
         *   truncated: bool,
         *   truncationReasons: list<string>
         * } $metadata
         */
        $metadata = (new RouteMetadataExport)->export($root, $files);
        $firstDeclarations = [];
        $candidates = [];

        foreach ($metadata['declarations'] as $declaration) {
            $name = $declaration['name'];
            if (! isset($firstDeclarations[$name])) {
                $firstDeclarations[$name] = self::location($declaration);

                continue;
            }

            $candidates[] = [
                ...self::location($declaration),
                'confidence' => 'source-only-candidate',
                'activeRouteConflict' => 'unknown',
                'firstLocation' => $firstDeclarations[$name],
            ];
        }

        return [
            'schemaVersion' => 1,
            'projectRoot' => $metadata['projectRoot'],
            'scope' => [
                'kind' => 'route-name-duplicates',
                'evidence' => 'source-only',
                'semantics' => 'advisory-duplicate-name-candidates',
                'matching' => 'exact-case',
                'ordering' => 'selected-file-then-source',
                'exhaustive' => false,
            ],
            'candidates' => $candidates,
            'errors' => $metadata['errors'],
            'truncated' => $metadata['truncated'],
            'truncationReasons' => $metadata['truncationReasons'],
        ];
    }

    /**
     * @param array{
     *   name: string,
     *   file: string,
     *   start: int,
     *   end: int,
     *   line: int,
     *   contentHash: string,
     *   confidence: string,
     *   rawName?: string,
     *   nameProvenance?: array{
     *     kind: string,
     *     tokens: list<array{role: string, value: string, start: int, end: int, line: int}>
     *   }
     * } $declaration
     * @return array<string, mixed>
     */
    private static function location(array $declaration): array
    {
        $location = [
            'name' => $declaration['name'],
            'file' => $declaration['file'],
            'start' => $declaration['start'],
            'end' => $declaration['end'],
            'line' => $declaration['line'],
            'contentHash' => $declaration['contentHash'],
            'declarationConfidence' => $declaration['confidence'],
        ];
        if (isset($declaration['rawName'], $declaration['nameProvenance'])) {
            $location['rawName'] = $declaration['rawName'];
            $location['nameProvenance'] = $declaration['nameProvenance'];
        }

        return $location;
    }
}
