<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Metadata;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\ParserFactory;

/**
 * Optional cross-locale translation-quality candidates from explicit sources.
 *
 * Every source must name its locale and logical catalog. The exporter does not
 * infer Laravel loader paths, locale fallback, JSON/PHP precedence, package
 * namespaces, or whether differing placeholders are intentional. Candidates
 * therefore describe source differences and never Laravel runtime failures.
 */
final class TranslationPlaceholderConsistencyExport
{
    private const MAX_FILES = 256;
    private const MAX_FILE_BYTES = 1048576;
    private const MAX_TOTAL_BYTES = 8388608;
    private const MAX_MESSAGES = 20000;
    private const MAX_CANDIDATES = 10000;
    private const MAX_UNCERTAINTIES = 20000;
    private const MAX_DEPTH = 32;
    private const MAX_LABEL_BYTES = 256;

    /**
     * @param array<array-key, mixed> $files
     * @return array<string, mixed>
     */
    public function export(string $root, array $files): array
    {
        clearstatcache(true);
        $resolvedRoot = realpath($root);
        if ($resolvedRoot === false || ! is_dir($resolvedRoot)) {
            throw new \InvalidArgumentException('Project root must be an existing directory.');
        }
        if (! array_is_list($files)) {
            throw new \InvalidArgumentException('Translation source associations must be a list.');
        }

        $root = str_replace('\\', '/', $resolvedRoot);
        $prefix = rtrim($root, '/').'/';
        $errors = [];
        $reasons = [];
        $uncertainties = [];
        $sources = [];
        $messages = [];
        $totalBytes = 0;
        $associations = [];
        $associationCounts = [];

        if (count($files) > self::MAX_FILES) {
            $reasons['file-limit'] = true;
        }

        /** @mago-expect analysis:mixed-assignment Direct callers receive the same validation as CLI input. */
        foreach (array_slice($files, 0, self::MAX_FILES) as $index => $association) {
            if (! is_array($association)) {
                $errors[] = self::error('(association '.$index.')', 'invalid-source-association');
                continue;
            }
            /** @mago-expect analysis:mixed-assignment Runtime validation narrows untyped input. */
            $locale = $association['locale'] ?? null;
            /** @mago-expect analysis:mixed-assignment Runtime validation narrows untyped input. */
            $catalog = $association['catalog'] ?? null;
            /** @mago-expect analysis:mixed-assignment Runtime validation narrows untyped input. */
            $file = $association['file'] ?? null;
            if (
                ! is_string($locale)
                || ! self::validLabel($locale)
                || ! is_string($catalog)
                || ! self::validLabel($catalog)
                || ! is_string($file)
                || $file === ''
            ) {
                $errors[] = self::error(
                    is_string($file) && $file !== '' ? $file : '(association '.$index.')',
                    'invalid-source-association',
                );
                continue;
            }
            $file = str_replace('\\', '/', $file);
            $key = $locale."\0".$catalog;
            $associationCounts[$key] = ($associationCounts[$key] ?? 0) + 1;
            $associations[] = [
                'locale' => $locale,
                'catalog' => $catalog,
                'file' => $file,
                'key' => $key,
            ];
        }

        foreach ($associations as $association) {
            $locale = $association['locale'];
            $catalog = $association['catalog'];
            $file = $association['file'];
            $associationCollision = $associationCounts[$association['key']] > 1;
            [$path, $format, $code] = self::resolveSource($prefix, $file);
            if ($code !== null || $path === null || $format === null) {
                $errors[] = self::error($file, $code ?? 'unreadable-source');
                $sources[] = self::source(
                    $locale,
                    $catalog,
                    $file,
                    $format,
                    null,
                    false,
                    [
                        'source-error',
                    ],
                );
                continue;
            }

            $remaining = max(0, self::MAX_TOTAL_BYTES - $totalBytes);
            $contents = @file_get_contents($path, false, null, 0, min(self::MAX_FILE_BYTES, $remaining) + 1);
            if ($contents === false) {
                $errors[] = self::error($file, 'unreadable-source');
                $sources[] = self::source($locale, $catalog, $path, $format, null, false, ['source-error']);
                continue;
            }
            $size = strlen($contents);
            $totalBytes += $size;
            if ($size > self::MAX_FILE_BYTES || $totalBytes > self::MAX_TOTAL_BYTES) {
                $reasons[$size > self::MAX_FILE_BYTES ? 'file-byte-limit' : 'total-byte-limit'] = true;
                $errors[] = self::error($file, 'source-byte-limit');
                $sources[] = self::source($locale, $catalog, $path, $format, null, false, ['source-error']);
                if ($totalBytes > self::MAX_TOTAL_BYTES) {
                    break;
                }
                continue;
            }

            $hash = hash('sha256', $contents);
            $sourceReasons = [];
            if ($associationCollision) {
                $sourceReasons[] = 'duplicate-locale-catalog-association';
                self::addUncertainty(
                    $uncertainties,
                    $reasons,
                    [
                        'kind' => 'source-association-collision',
                        'locale' => $locale,
                        'catalog' => $catalog,
                        'file' => $path,
                        'reason' => 'multiple-files-selected-for-one-locale-catalog',
                        'runtimeResolution' => 'unknown',
                    ],
                );
            }

            $beforeErrors = count($errors);
            $fileMessages = $format === 'php'
                ? self::phpMessages($contents, $path, $hash, $uncertainties, $reasons, $errors)
                : self::jsonMessages($contents, $path, $hash, $uncertainties, $reasons, $errors);
            $parsed = count($errors) === $beforeErrors;
            if (! $parsed) {
                $sourceReasons[] = 'source-error';
            }
            $selectedForComparison = $parsed && ! $associationCollision;
            $sourceIndex = count($sources);
            $sources[] = self::source(
                $locale,
                $catalog,
                $path,
                $format,
                $hash,
                $selectedForComparison,
                $sourceReasons,
            );
            foreach ($fileMessages as $message) {
                if (count($messages) >= self::MAX_MESSAGES) {
                    $reasons['message-limit'] = true;
                    break 2;
                }
                $message['locale'] = $locale;
                $message['catalog'] = $catalog;
                $message['sourceIndex'] = $sourceIndex;
                $message['sourceEligible'] = $selectedForComparison;
                $messages[] = $message;
            }
        }

        $candidates = self::compare($sources, $messages, $uncertainties, $reasons);

        return [
            'schemaVersion' => 1,
            'projectRoot' => $root,
            'scope' => [
                'kind' => 'translation-placeholder-consistency-candidates',
                'evidence' => 'explicit-selected-source-only',
                'semantics' => 'optional-translation-quality-advisory',
                'localeAssociationInferred' => false,
                'fallbackResolutionValidated' => false,
                'loaderPrecedenceValidated' => false,
                'pluralBranchAlignmentValidated' => false,
                'runtimeFailureClaimed' => false,
                'exhaustive' => false,
            ],
            'sources' => $sources,
            'candidates' => $candidates,
            'uncertainties' => $uncertainties,
            'errors' => $errors,
            'truncated' => $reasons !== [],
            'truncationReasons' => array_keys($reasons),
        ];
    }

    private static function validLabel(string $value): bool
    {
        return $value !== '' && strlen($value) <= self::MAX_LABEL_BYTES && ! str_contains($value, "\0");
    }

    /** @return array{?string, ?string, ?string} */
    private static function resolveSource(string $prefix, string $file): array
    {
        if (
            str_starts_with($file, '/')
            || str_contains($file, ':')
            || str_contains($file, "\0")
            || in_array('..', explode('/', $file), true)
        ) {
            return [null, null, 'invalid-source-path'];
        }
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (! in_array($extension, ['php', 'json'], true)) {
            return [null, null, 'unsupported-source-format'];
        }
        $path = realpath($prefix.$file);
        if ($path === false || ! is_file($path)) {
            return [null, $extension, 'unreadable-source'];
        }
        $path = str_replace('\\', '/', $path);
        $contained = DIRECTORY_SEPARATOR === '\\'
            ? str_starts_with(strtolower($path), strtolower($prefix))
            : str_starts_with($path, $prefix);
        if (! $contained) {
            return [null, $extension, 'invalid-source-path'];
        }
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== $extension) {
            return [null, $extension, 'unsupported-source-format'];
        }

        return [$path, $extension, null];
    }

    /**
     * @param list<string> $uncertaintyReasons
     * @return array{locale: string, catalog: string, file: string, format: ?string,
     *   contentHash: ?string, selectedForComparison: bool, uncertaintyReasons: list<string>}
     */
    private static function source(
        string $locale,
        string $catalog,
        string $file,
        ?string $format,
        ?string $hash,
        bool $selected,
        array $uncertaintyReasons,
    ): array {
        return [
            'locale' => $locale,
            'catalog' => $catalog,
            'file' => $file,
            'format' => $format,
            'contentHash' => $hash,
            'selectedForComparison' => $selected,
            'uncertaintyReasons' => $uncertaintyReasons,
        ];
    }

    /**
     * @param list<array<string, mixed>> $uncertainties
     * @param array<string, bool> $reasons
     * @param list<array<string, mixed>> $errors
     * @return list<array{
     *   identity: string, message: array<string, mixed>, file: string, contentHash: string,
     *   keyStart: int, keyEnd: int, keyLine: int, selected: bool, valueKnown: bool,
     *   names: list<string>, pluralBranches: bool, messageStart: ?int, messageEnd: ?int,
     *   messageLine: ?int
     * }>
     */
    private static function phpMessages(
        string $contents,
        string $file,
        string $hash,
        array &$uncertainties,
        array &$reasons,
        array &$errors,
    ): array {
        try {
            $nodes = (new ParserFactory)
                ->createForNewestSupportedVersion()
                ->parse($contents) ?? [];
        } catch (Error) {
            $errors[] = self::error($file, 'parse-failure');

            return [];
        }
        $returned = self::returnedArray($nodes);
        if ($returned === null) {
            $errors[] = self::error($file, 'unsupported-return');

            return [];
        }
        $messages = [];
        self::collectPhp(
            $returned,
            [],
            [],
            true,
            $file,
            $hash,
            $messages,
            $uncertainties,
            $reasons,
        );

        return $messages;
    }

    /** @param array<array-key, Node> $nodes */
    private static function returnedArray(array $nodes): ?Node\Expr\Array_
    {
        $returned = null;
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Return_ && $returned === null && $node->expr instanceof Node\Expr\Array_) {
                $returned = $node->expr;
            } elseif (
                ! $node instanceof Node\Stmt\Nop
                && ! $node instanceof Node\Stmt\Use_
                && ! $node instanceof Node\Stmt\GroupUse
                && ! ($node instanceof Node\Stmt\Declare_
                && $node->stmts === null)
            ) {
                return null;
            }
        }

        return $returned;
    }

    /**
     * @param list<string|int> $segments
     * @param list<string|int> $normalizedSegments
     * @param list<array{
     *   identity: string, message: array<string, mixed>, file: string, contentHash: string,
     *   keyStart: int, keyEnd: int, keyLine: int, selected: bool, valueKnown: bool,
     *   names: list<string>, pluralBranches: bool, messageStart: ?int, messageEnd: ?int,
     *   messageLine: ?int
     * }> $messages
     * @param list<array<string, mixed>> $uncertainties
     * @param array<string, bool> $reasons
     */
    private static function collectPhp(
        Node\Expr\Array_ $array,
        array $segments,
        array $normalizedSegments,
        bool $parentSelected,
        string $file,
        string $hash,
        array &$messages,
        array &$uncertainties,
        array &$reasons,
    ): void {
        if (count($segments) >= self::MAX_DEPTH) {
            $reasons['depth-limit'] = true;

            return;
        }
        $seen = [];
        $unknown = false;
        foreach (array_reverse($array->items) as $item) {
            $keyNode = $item->key;
            $key = self::literalKey($keyNode);
            if ($item->unpack || $key === null || $keyNode === null) {
                $unknown = true;
                self::addUncertainty($uncertainties, $reasons, [
                    'kind' => 'dynamic-array-entry',
                    'file' => $file,
                    'start' => $item->getStartFilePos(),
                    'end' => $item->getEndFilePos() + 1,
                    'line' => $item->getStartLine(),
                    'reason' => 'dynamic-key-or-unpack-can-change-message-selection',
                ]);
                continue;
            }
            $normalized = array_key_first([$key => true]);
            $identity = serialize($normalized);
            $duplicate = isset($seen[$identity]);
            $selected = $parentSelected && ! $unknown && ! $duplicate && ! $item->byRef;
            $seen[$identity] = true;
            $childSegments = [...$segments, $key];
            $childNormalized = [...$normalizedSegments, $normalized];
            $message = [
                'identity' => 'php:'.base64_encode(serialize($childNormalized)),
                'message' => ['kind' => 'php-key', 'segments' => $childSegments],
                'file' => $file,
                'contentHash' => $hash,
                'keyStart' => $keyNode->getStartFilePos(),
                'keyEnd' => $keyNode->getEndFilePos() + 1,
                'keyLine' => $keyNode->getStartLine(),
                'selected' => $selected,
            ];
            if ($duplicate || $unknown || $item->byRef || ! $parentSelected) {
                self::addUncertainty($uncertainties, $reasons, [
                    'kind' => $duplicate ? 'shadowed-message-declaration' : 'message-selection-uncertain',
                    'file' => $file,
                    'message' => $message['message'],
                    'keyStart' => $message['keyStart'],
                    'keyEnd' => $message['keyEnd'],
                    'keyLine' => $message['keyLine'],
                    'reason' => $duplicate
                        ? 'later-literal-key-selects-another-declaration'
                        : 'dynamic-or-parent-selection-prevents-an-exact-source-choice',
                ]);
            }
            if ($item->value instanceof Node\Expr\Array_) {
                self::collectPhp(
                    $item->value,
                    $childSegments,
                    $childNormalized,
                    $selected,
                    $file,
                    $hash,
                    $messages,
                    $uncertainties,
                    $reasons,
                );
                continue;
            }
            if (! $item->value instanceof Node\Scalar\String_) {
                self::addUncertainty($uncertainties, $reasons, [
                    'kind' => 'dynamic-message-value',
                    'file' => $file,
                    'message' => $message['message'],
                    'keyStart' => $message['keyStart'],
                    'keyEnd' => $message['keyEnd'],
                    'keyLine' => $message['keyLine'],
                    'reason' => 'message-value-is-not-one-literal-string',
                ]);
                $message['valueKnown'] = false;
                $message['names'] = [];
                $message['pluralBranches'] = false;
                $message['messageStart'] = null;
                $message['messageEnd'] = null;
                $message['messageLine'] = null;
                $messages[] = $message;
                continue;
            }
            $names = TranslationPlaceholderExport::placeholderNames($item->value->value);
            sort($names, SORT_STRING);
            $message['valueKnown'] = true;
            $message['names'] = $names;
            $message['pluralBranches'] = str_contains($item->value->value, '|');
            $message['messageStart'] = $item->value->getStartFilePos();
            $message['messageEnd'] = $item->value->getEndFilePos() + 1;
            $message['messageLine'] = $item->value->getStartLine();
            $messages[] = $message;
        }
    }

    /**
     * @param list<array<string, mixed>> $uncertainties
     * @param array<string, bool> $reasons
     * @param list<array<string, mixed>> $errors
     * @return list<array{
     *   identity: string, message: array<string, mixed>, file: string, contentHash: string,
     *   keyStart: int, keyEnd: int, keyLine: int, selected: bool, valueKnown: bool,
     *   names: list<string>, pluralBranches: bool, messageStart: ?int, messageEnd: ?int,
     *   messageLine: ?int
     * }>
     */
    private static function jsonMessages(
        string $contents,
        string $file,
        string $hash,
        array &$uncertainties,
        array &$reasons,
        array &$errors,
    ): array {
        try {
            json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $errors[] = self::error($file, 'parse-failure');

            return [];
        }
        $length = strlen($contents);
        $offset = strspn($contents, " \t\r\n");
        if (($contents[$offset] ?? null) !== '{') {
            $errors[] = self::error($file, 'unsupported-root');

            return [];
        }
        $offset++;
        $messages = [];
        $selected = [];
        while ($offset < $length) {
            $offset += strspn($contents, " \t\r\n", $offset);
            if (($contents[$offset] ?? null) === '}') {
                break;
            }
            $keyStart = $offset;
            $keyEnd = self::jsonStringEnd($contents, $offset);
            $literal = substr($contents, $keyStart, $keyEnd - $keyStart);
            /** @var string $name */
            $name = json_decode($literal, true, 2, JSON_THROW_ON_ERROR);
            $phpKey = array_key_first([$name => true]);
            $identity = 'json:'.hash('sha256', serialize($phpKey));
            $publicMessage = ['kind' => 'json-key-hash', 'sha256' => hash('sha256', serialize($phpKey))];
            $offset = $keyEnd + strspn($contents, " \t\r\n", $keyEnd) + 1;
            $offset += strspn($contents, " \t\r\n", $offset);
            $valueStart = $offset;
            $valueEnd = self::jsonValueEnd($contents, $offset);
            $message = [
                'identity' => $identity,
                'message' => $publicMessage,
                'file' => $file,
                'contentHash' => $hash,
                'keyStart' => $keyStart,
                'keyEnd' => $keyEnd,
                'keyLine' => substr_count(substr($contents, 0, $keyStart), "\n") + 1,
                'messageStart' => $valueStart,
                'messageEnd' => $valueEnd,
                'messageLine' => substr_count(substr($contents, 0, $valueStart), "\n") + 1,
                'selected' => true,
                'valueKnown' => false,
                'names' => [],
                'pluralBranches' => false,
            ];
            if (isset($selected[$identity])) {
                $messages[$selected[$identity]]['selected'] = false;
                self::addUncertainty($uncertainties, $reasons, [
                    'kind' => 'shadowed-message-declaration',
                    'file' => $file,
                    'message' => $publicMessage,
                    'keyStart' => $messages[$selected[$identity]]['keyStart'],
                    'keyEnd' => $messages[$selected[$identity]]['keyEnd'],
                    'keyLine' => $messages[$selected[$identity]]['keyLine'],
                    'reason' => 'later-json-key-selects-another-declaration',
                ]);
            }
            if (($contents[$valueStart] ?? null) === '"') {
                $valueLiteral = substr($contents, $valueStart, $valueEnd - $valueStart);
                /** @var string $value */
                $value = json_decode($valueLiteral, true, 2, JSON_THROW_ON_ERROR);
                $names = TranslationPlaceholderExport::placeholderNames($value);
                sort($names, SORT_STRING);
                $message['valueKnown'] = true;
                $message['names'] = $names;
                $message['pluralBranches'] = str_contains($value, '|');
            }
            if (! $message['valueKnown']) {
                self::addUncertainty($uncertainties, $reasons, [
                    'kind' => 'dynamic-message-value',
                    'file' => $file,
                    'message' => $publicMessage,
                    'keyStart' => $keyStart,
                    'keyEnd' => $keyEnd,
                    'keyLine' => $message['keyLine'],
                    'reason' => 'json-translation-value-is-not-a-string',
                ]);
            }
            $selected[$identity] = count($messages);
            $messages[] = $message;
            if (count($messages) >= self::MAX_MESSAGES) {
                $reasons['message-limit'] = true;
                break;
            }
            $offset = $valueEnd + strspn($contents, " \t\r\n", $valueEnd);
            if (($contents[$offset] ?? null) === ',') {
                $offset++;
            }
        }

        return $messages;
    }

    private static function jsonStringEnd(string $contents, int $offset): int
    {
        $length = strlen($contents);
        $offset++;
        while ($offset < $length) {
            if ($contents[$offset] === '\\') {
                $offset += 2;
                continue;
            }
            if ($contents[$offset] === '"') {
                return $offset + 1;
            }
            $offset++;
        }

        return $length;
    }

    private static function jsonValueEnd(string $contents, int $offset): int
    {
        $length = strlen($contents);
        $depth = 0;
        while ($offset < $length) {
            $character = $contents[$offset];
            if ($character === '"') {
                $offset = self::jsonStringEnd($contents, $offset);
                continue;
            }
            if ($character === '{' || $character === '[') {
                $depth++;
            } elseif (($character === ']' || $character === '}') && $depth > 0) {
                $depth--;
            } elseif ($depth === 0 && ($character === ',' || $character === '}')) {
                break;
            }
            $offset++;
        }

        return $offset;
    }

    /**
     * @param list<array{locale: string, catalog: string, file: string, format: ?string,
     *   contentHash: ?string, selectedForComparison: bool, uncertaintyReasons: list<string>}> $sources
     * @param list<array{
     *   identity: string, message: array<string, mixed>, file: string, contentHash: string,
     *   keyStart: int, keyEnd: int, keyLine: int, selected: bool, valueKnown: bool,
     *   names: list<string>, pluralBranches: bool, messageStart: ?int, messageEnd: ?int,
     *   messageLine: ?int, locale: string, catalog: string, sourceIndex: int,
     *   sourceEligible: bool
     * }> $messages
     * @param list<array<string, mixed>> $uncertainties
     * @param array<string, bool> $reasons
     * @return list<array<string, mixed>>
     */
    private static function compare(array $sources, array $messages, array &$uncertainties, array &$reasons): array
    {
        /** @var array<string, array<int, string>> $catalogSources */
        $catalogSources = [];
        foreach ($sources as $index => $source) {
            if ($source['selectedForComparison']) {
                $catalogSources[$source['catalog']][$index] = $source['locale'];
            }
        }
        /**
         * @var array<string, array{
         *   catalog: string, message: array<string, mixed>, messages: array<int, array{
         *     identity: string, message: array<string, mixed>, file: string, contentHash: string,
         *     keyStart: int, keyEnd: int, keyLine: int, selected: bool, valueKnown: bool,
         *     names: list<string>, pluralBranches: bool, messageStart: ?int, messageEnd: ?int,
         *     messageLine: ?int, locale: string, catalog: string, sourceIndex: int,
         *     sourceEligible: bool
         *   }>
         * }> $groups
         */
        $groups = [];
        /** @var array<string, array<int, bool>> $observed */
        $observed = [];
        foreach ($messages as $message) {
            if (! $message['sourceEligible']) {
                continue;
            }
            $groupKey = $message['catalog']."\0".$message['identity'];
            $observed[$groupKey][$message['sourceIndex']] = true;
            if ($message['selected'] && $message['valueKnown']) {
                $groups[$groupKey]['catalog'] = $message['catalog'];
                $groups[$groupKey]['message'] = $message['message'];
                $groups[$groupKey]['messages'][$message['sourceIndex']] = $message;
            }
        }

        $candidates = [];
        foreach ($groups as $groupKey => $group) {
            $catalog = $group['catalog'];
            foreach ($catalogSources[$catalog] ?? [] as $sourceIndex => $locale) {
                if (! isset($group['messages'][$sourceIndex])) {
                    self::addUncertainty($uncertainties, $reasons, [
                        'kind' => isset($observed[$groupKey][$sourceIndex])
                            ? 'message-comparison-uncertain'
                            : 'message-missing-from-selected-source',
                        'locale' => $locale,
                        'catalog' => $catalog,
                        'file' => $sources[$sourceIndex]['file'],
                        'message' => $group['message'],
                        'reason' => isset($observed[$groupKey][$sourceIndex])
                            ? 'selected-literal-message-value-is-not-proven'
                            : 'fallback-or-intentional-omission-is-not-resolved',
                        'runtimeResolution' => 'unknown',
                    ]);
                }
            }
            if (count($group['messages']) < 2) {
                continue;
            }
            $sets = [];
            foreach ($group['messages'] as $message) {
                $sets[json_encode($message['names'], JSON_THROW_ON_ERROR)] = true;
            }
            if (count($sets) < 2) {
                continue;
            }
            if (count($candidates) >= self::MAX_CANDIDATES) {
                $reasons['candidate-limit'] = true;
                break;
            }
            $locations = [];
            $plural = false;
            foreach ($group['messages'] as $message) {
                $plural = $plural || $message['pluralBranches'];
                $locations[] = [
                    'locale' => $message['locale'],
                    'file' => $message['file'],
                    'names' => $message['names'],
                    'messageStart' => $message['messageStart'],
                    'messageEnd' => $message['messageEnd'],
                    'messageLine' => $message['messageLine'],
                    'keyStart' => $message['keyStart'],
                    'keyEnd' => $message['keyEnd'],
                    'keyLine' => $message['keyLine'],
                    'contentHash' => $message['contentHash'],
                    'pluralBranchesPresent' => $message['pluralBranches'],
                ];
            }
            $candidates[] = [
                'catalog' => $catalog,
                'message' => $group['message'],
                'status' => 'placeholder-set-difference',
                'locations' => $locations,
                'advisory' => true,
                'runtimeFailure' => false,
                'uncertaintyReasons' => $plural ? ['plural-branch-alignment-unvalidated'] : [],
            ];
        }

        return $candidates;
    }

    private static function literalKey(?Node\Expr $node): string|int|null
    {
        if ($node instanceof Node\Scalar\String_ || $node instanceof Node\Scalar\Int_) {
            return $node->value;
        }
        if ($node instanceof Node\Expr\UnaryMinus && $node->expr instanceof Node\Scalar\Int_) {
            return -$node->expr->value;
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $uncertainties
     * @param array<string, bool> $reasons
     * @param array<string, mixed> $uncertainty
     */
    private static function addUncertainty(array &$uncertainties, array &$reasons, array $uncertainty): void
    {
        if (count($uncertainties) >= self::MAX_UNCERTAINTIES) {
            $reasons['uncertainty-limit'] = true;

            return;
        }
        $uncertainties[] = $uncertainty;
    }

    /** @return array{file: string, code: string, message: string} */
    private static function error(string $file, string $code): array
    {
        return [
            'file' => $file,
            'code' => $code,
            'message' => match ($code) {
                'invalid-source-association'
                    => 'Each source must explicitly provide non-empty locale, catalog, and file strings.',
                'invalid-source-path' => 'Source must be a project-relative file within the project root.',
                'unsupported-source-format' => 'Only PHP and JSON translation sources are supported.',
                'parse-failure' => 'Unable to parse translation source.',
                'unsupported-return' => 'PHP source must directly return one literal array.',
                'unsupported-root' => 'JSON translation source must contain one root object.',
                'source-byte-limit' => 'Translation source exceeds the bounded read budget.',
                default => 'Unable to read translation source.',
            },
        ];
    }
}
