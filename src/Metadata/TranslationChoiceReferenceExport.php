<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Metadata;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * Source-only candidates from calls resolved lexically to global trans_choice.
 *
 * Native choice selects requested versus fallback locale before get(), and get()
 * checks JSON at that selected locale before PHP catalogs. This exporter does not
 * infer either the selected locale or an effective translation catalog.
 */
final class TranslationChoiceReferenceExport
{
    private const MAX_FILES = 256;
    private const MAX_FILE_BYTES = 1024 * 1024;
    private const MAX_TOTAL_BYTES = 8 * 1024 * 1024;
    private const MAX_REFERENCES = 20000;

    /**
     * @param array<array-key, string> $files Explicit project-relative PHP sources.
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
            throw new \InvalidArgumentException(
                'Translation choice sources must be a list of project-relative PHP files.',
            );
        }

        $projectRoot = str_replace('\\', '/', $resolvedRoot);
        $prefix = rtrim($projectRoot, '/').'/';
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $finder = new NodeFinder;
        $references = [];
        $errors = [];
        $reasons = count($files) > self::MAX_FILES ? ['file-limit' => true] : [];
        $totalBytes = 0;
        $seen = [];
        $selectedFiles = 0;
        $resolvedCalls = 0;
        $unsupportedCalls = 0;

        foreach (array_slice($files, 0, self::MAX_FILES) as $file) {
            if (! self::validSourceName($file)) {
                $errors[] = self::error('invalid-source', $file);
                continue;
            }

            clearstatcache(true);
            $path = realpath($prefix.str_replace('\\', '/', $file));
            if ($path === false || ! is_file($path)) {
                $errors[] = self::error('unreadable-source', $file);
                continue;
            }
            $path = str_replace('\\', '/', $path);
            $contained = PHP_OS_FAMILY === 'Windows'
                ? str_starts_with(strtolower($path), strtolower($prefix))
                : str_starts_with($path, $prefix);
            if (! $contained || ! str_ends_with(strtolower($path), '.php')) {
                $errors[] = self::error('invalid-source', $file);
                continue;
            }
            $identity = PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;

            $remaining = self::MAX_TOTAL_BYTES - $totalBytes;
            if ($remaining <= 0) {
                $reasons['total-byte-limit'] = true;
                $errors[] = self::error('source-limit', $file);
                break;
            }
            $contents = @file_get_contents($path, false, null, 0, min(self::MAX_FILE_BYTES, $remaining) + 1);
            if ($contents === false) {
                $errors[] = self::error('unreadable-source', $file);
                continue;
            }
            $size = strlen($contents);
            $totalBytes += $size;
            if ($totalBytes > self::MAX_TOTAL_BYTES) {
                $reasons['total-byte-limit'] = true;
                $errors[] = self::error('source-limit', $file);
                break;
            }
            if ($size > self::MAX_FILE_BYTES) {
                $reasons['file-byte-limit'] = true;
                $errors[] = self::error('source-limit', $file);
                continue;
            }

            try {
                $nodes = (new NodeTraverser(new NameResolver))->traverse($parser->parse($contents) ?? []);
            } catch (Error) {
                $errors[] = self::error('parse-failure', $file);
                continue;
            }
            ++$selectedFiles;
            $hash = hash('sha256', $contents);
            foreach ($finder->findInstanceOf($nodes, Node\Expr\FuncCall::class) as $call) {
                if (
                    ! $call->name instanceof Node\Name\FullyQualified
                    || strcasecmp($call->name->toString(), 'trans_choice') !== 0
                ) {
                    continue;
                }
                ++$resolvedCalls;
                $arguments = self::arguments($call->getArgs());
                if ($arguments === null || ! ($arguments['key'] ?? null) instanceof Node\Scalar\String_) {
                    ++$unsupportedCalls;
                    continue;
                }
                if (count($references) >= self::MAX_REFERENCES) {
                    $reasons['reference-limit'] = true;
                    break 2;
                }

                $key = $arguments['key'];
                $locale = self::localeEvidence($arguments['locale'] ?? null);
                $number = self::numberEvidence($arguments['number'] ?? null);
                $references[] = [
                    'file' => $path,
                    'contentHash' => $hash,
                    'name' => $key->value,
                    'start' => $key->getStartFilePos(),
                    'end' => $key->getEndFilePos() + 1,
                    'line' => $key->getStartLine(),
                    'callStart' => $call->getStartFilePos(),
                    'callEnd' => $call->getEndFilePos() + 1,
                    'callLine' => $call->getStartLine(),
                    'dispatchEvidence' => 'lexically-global-function',
                    'nativeHelperProven' => false,
                    'number' => $number,
                    'requestedLocale' => $locale,
                    'actualLocale' => [
                        'state' => 'unknown',
                        'maySelectFallback' => true,
                        'reason' => 'native-choice-checks-has-for-locale-before-get',
                    ],
                    'lookupChannels' => ['selected-locale-json', 'selected-locale-php-with-fallback'],
                    'runtimeLookupProven' => false,
                    'missingNameDiagnostic' => false,
                    'confidence' => 'literal-reference-candidate',
                ];
            }
        }

        usort(
            $references,
            static fn (array $left, array $right): int => (
                [$left['file'], $left['start']] <=> [$right['file'], $right['start']]
            ),
        );

        return [
            'schemaVersion' => 1,
            'projectRoot' => $projectRoot,
            'scope' => [
                'kind' => 'translation-choice-reference-candidates',
                'evidence' => 'source-only',
                'semantics' => 'navigation-candidates',
                'exhaustive' => false,
                'actualLocaleProven' => false,
                'runtimeLookupProven' => false,
                'missingNameDiagnostic' => false,
            ],
            'references' => $references,
            'selection' => [
                'selectedFiles' => $selectedFiles,
                'lexicallyGlobalCalls' => $resolvedCalls,
                'literalReferences' => count($references),
                'unsupportedCalls' => $unsupportedCalls,
            ],
            'errors' => $errors,
            'truncated' => $reasons !== [],
            'truncationReasons' => array_keys($reasons),
        ];
    }

    /**
     * @param array<array-key, Node\Arg|Node\VariadicPlaceholder> $arguments
     * @return array{key?: Node\Expr, number?: Node\Expr, replace?: Node\Expr, locale?: Node\Expr}|null
     */
    private static function arguments(array $arguments): ?array
    {
        $parameters = ['key', 'number', 'replace', 'locale'];
        $resolved = [];
        $position = 0;
        $named = false;
        foreach ($arguments as $argument) {
            if (! $argument instanceof Node\Arg || $argument->unpack || $argument->byRef) {
                return null;
            }
            if ($argument->name === null) {
                if ($named || ! isset($parameters[$position])) {
                    return null;
                }
                $name = $parameters[$position++];
            } else {
                $named = true;
                $name = $argument->name->toString();
                if (! in_array($name, $parameters, true)) {
                    return null;
                }
            }
            if (isset($resolved[$name])) {
                return null;
            }
            $resolved[$name] = $argument->value;
        }

        return $resolved;
    }

    /** @return array{kind: string, value: int|float|null, start: int|null, end: int|null, line: int|null} */
    private static function numberEvidence(?Node\Expr $number): array
    {
        $value = null;
        if ($number instanceof Node\Scalar\Int_ || $number instanceof Node\Scalar\Float_) {
            $value = $number->value;
        } elseif (
            $number instanceof Node\Expr\UnaryMinus
            && ($number->expr instanceof Node\Scalar\Int_
            || $number->expr instanceof Node\Scalar\Float_)
        ) {
            $value = -$number->expr->value;
        }

        $nonFinite = is_float($value) && ! is_finite($value);
        if ($nonFinite) {
            $value = null;
        }

        return [
            'kind' => $nonFinite
                ? 'non-finite-number'
                : ($value !== null ? 'literal-number' : ($number === null ? 'missing' : 'dynamic-or-countable')),
            'value' => $value,
            'start' => $number?->getStartFilePos(),
            'end' => $number === null ? null : $number->getEndFilePos() + 1,
            'line' => $number?->getStartLine(),
        ];
    }

    /** @return array{kind: string, value: string|null, mayUseDefault: bool, start: int|null, end: int|null, line: int|null} */
    private static function localeEvidence(?Node\Expr $locale): array
    {
        $value = $locale instanceof Node\Scalar\String_ ? $locale->value : null;
        $kind = match (true) {
            $locale === null => 'omitted',
            $locale instanceof Node\Expr\ConstFetch && strtolower($locale->name->toString()) === 'null' => 'null',
            $locale instanceof Node\Scalar\String_ => 'literal-string',
            default => 'dynamic',
        };

        return [
            'kind' => $kind,
            'value' => $value,
            'mayUseDefault' => $kind !== 'literal-string' || $value === '' || $value === '0',
            'start' => $locale?->getStartFilePos(),
            'end' => $locale === null ? null : $locale->getEndFilePos() + 1,
            'line' => $locale?->getStartLine(),
        ];
    }

    private static function validSourceName(string $file): bool
    {
        return (
            $file !== ''
            && ! str_contains($file, "\0")
            && ! str_contains($file, ':')
            && ! preg_match('~^(?:[/\\\\])|(?:^|[/\\\\])\.\.(?:[/\\\\]|$)~', $file)
            && str_ends_with(strtolower($file), '.php')
        );
    }

    /** @return array{code: string, file: ?string, message: string} */
    private static function error(string $code, ?string $file): array
    {
        return [
            'code' => $code,
            'file' => $file,
            'message' => match ($code) {
                'invalid-source' => 'Source must be a project-relative PHP file within the project root.',
                'parse-failure' => 'Unable to parse selected PHP source.',
                'source-limit' => 'Selected PHP source exceeds the bounded read budget.',
                default => 'Unable to read selected PHP source.',
            },
        ];
    }
}
