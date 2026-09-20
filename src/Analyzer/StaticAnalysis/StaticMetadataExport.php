<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** Versioned, source-only configuration metadata for external editor tools. */
final class StaticMetadataExport
{
    public const SCHEMA_VERSION = 1;
    private const MAX_CATALOGS = 4096;
    private const MAX_DECLARATIONS = 100000;
    private const MAX_DEPTH = 32;

    /**
     * @param list<string> $arrayKeys
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    public function configuration(string $root, array $arrayKeys = [], array $keys = []): array
    {
        $source = new PhpSource($root);
        $index = new ConfigurationIndex($source);
        $all = $arrayKeys === [] && $keys === [];

        if ($all) {
            foreach (glob($source->path('config/*.php')) ?: [] as $file) {
                $arrayKeys[] = pathinfo($file, PATHINFO_FILENAME);
            }
        }
        foreach ($keys as $key) {
            $separator = strrpos($key, '.');
            if ($separator === false) {
                throw new \InvalidArgumentException('Configuration keys require a dotted parent array key.');
            }
            $arrayKeys[] = substr($key, 0, $separator);
        }

        $pending = array_values($arrayKeys);
        $cursor = 0;
        $seen = [];
        $catalogs = [];
        $declarationCount = 0;
        $truncationReasons = [];
        $truncated = false;
        while ($cursor < count($pending)) {
            if (count($catalogs) >= self::MAX_CATALOGS) {
                $truncationReasons['catalog-limit'] = true;
                $truncated = true;
                break;
            }
            $arrayKey = $pending[$cursor++];
            $depth = substr_count($arrayKey, '.');
            $seenKey = "\0".$arrayKey;
            if (isset($seen[$seenKey])) {
                continue;
            }
            $seen[$seenKey] = true;

            $catalog = $index->declarations($arrayKey);
            $declarations = [];
            foreach ($catalog?->declarations ?? [] as $declaration) {
                if ($declarationCount >= self::MAX_DECLARATIONS) {
                    $truncationReasons['declaration-limit'] = true;
                    $truncated = true;
                    break;
                }
                $declarations[] = self::declaration($declaration);
                $declarationCount++;
                if ($all && $declaration->key !== null && $declaration->sourceSelected) {
                    if ($depth < self::MAX_DEPTH) {
                        $pending[] = $declaration->key;
                    } else {
                        $truncationReasons['depth-limit'] = true;
                        $truncated = true;
                    }
                }
            }
            $catalogs[] = [
                'arrayKey' => $arrayKey,
                'sourceComplete' => isset($truncationReasons['declaration-limit'])
                    ? null
                    : $catalog?->sourceComplete,
                'declarations' => $declarations,
            ];
            if (isset($truncationReasons['declaration-limit'])) {
                break;
            }
        }
        usort($catalogs, static fn (array $a, array $b): int => strcmp($a['arrayKey'], $b['arrayKey']));

        $requests = [];
        foreach (array_unique($keys) as $key) {
            $separator = strrpos($key, '.');
            if ($separator === false) {
                throw new \InvalidArgumentException('Configuration keys require a dotted parent array key.');
            }
            $arrayKey = substr($key, 0, $separator);
            $name = substr($key, $separator + 1);
            $requests[] = [
                'key' => $key,
                'arrayKey' => $arrayKey,
                'name' => $name,
                'confidence' => self::confidence($index->stringKeyConfidence($arrayKey, $name)),
                'declaration' => ($declaration = $index->declaration($key)) === null
                    ? null
                    : self::declaration($declaration),
            ];
        }

        $errors = [];
        foreach ($source->warnings as $file => $warning) {
            $unreadable = str_starts_with($warning, 'Cannot read');
            $errors[] = [
                'code' => $unreadable ? 'unreadable-source' : 'parse-failure',
                'file' => $file,
                'message' => $unreadable
                    ? 'Unable to read static configuration source.'
                    : 'Unable to parse static configuration source.',
            ];
        }
        usort($errors, static fn (array $a, array $b): int => strcmp($a['file'], $b['file']));

        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'projectRoot' => $root,
            'scope' => ['kind' => 'configuration', 'evidence' => 'source-only'],
            'truncated' => $truncated,
            'truncationReasons' => array_keys($truncationReasons),
            'catalogs' => $catalogs,
            'requests' => $requests,
            'errors' => $errors,
        ];
    }

    /** @return array<string, mixed> */
    private static function declaration(ConfigurationDeclaration $declaration): array
    {
        return [
            'key' => $declaration->key,
            'arrayKey' => $declaration->arrayKey,
            'name' => $declaration->name,
            'file' => $declaration->file,
            'start' => $declaration->start,
            'end' => $declaration->end,
            'line' => $declaration->line,
            'contentHash' => $declaration->contentHash,
            'sourceSelected' => $declaration->sourceSelected,
            'confidence' => 'known-positive',
        ];
    }

    private static function confidence(MetadataConfidence $confidence): string
    {
        return match ($confidence) {
            MetadataConfidence::KnownPositive => 'known-positive',
            MetadataConfidence::CompleteAbsent => 'complete-absent',
            MetadataConfidence::Unknown => 'unknown',
        };
    }
}
