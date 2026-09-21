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
 * Source-only navigation candidates from literal native MailMessage view setters.
 *
 * A candidate is not evidence that a message is sent or rendered. MailMessage
 * view state is mutable, and Markdown rendering replaces the mail namespace
 * independently for HTML and text component paths.
 */
final class MailMessageViewReferenceExport
{
    private const MAIL_MESSAGE = 'Illuminate\\Notifications\\Messages\\MailMessage';
    private const MAX_FILES = 256;
    private const MAX_FILE_BYTES = 1024 * 1024;
    private const MAX_TOTAL_BYTES = 8 * 1024 * 1024;
    private const MAX_REFERENCES = 20000;
    private const MAX_RECEIVER_CHAIN = 32;

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
            throw new \InvalidArgumentException('MailMessage sources must be a list of project-relative PHP files.');
        }

        $projectRoot = str_replace('\\', '/', $resolvedRoot);
        $directory = rtrim($projectRoot, '/').'/';
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $references = [];
        $errors = [];
        $reasons = count($files) > self::MAX_FILES ? ['file-limit' => true] : [];
        $totalBytes = 0;
        $seen = [];
        $selectedFiles = 0;
        $nativeSetterCalls = 0;
        $unsupportedSetterCalls = 0;
        $nonReferenceSetterCalls = 0;

        foreach (array_slice($files, 0, self::MAX_FILES) as $file) {
            if (! self::validSourceName($file)) {
                $errors[] = self::error('invalid-source', $file);
                continue;
            }

            clearstatcache(true);
            $path = realpath($directory.$file);
            if ($path === false || ! is_file($path)) {
                $errors[] = self::error('unreadable-source', $file);
                continue;
            }
            $path = str_replace('\\', '/', $path);
            $contained = PHP_OS_FAMILY === 'Windows'
                ? str_starts_with(strtolower($path), strtolower($directory))
                : str_starts_with($path, $directory);
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
            foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Expr\MethodCall::class) as $call) {
                if (
                    ! $call->name instanceof Node\Identifier
                    || ! in_array(strtolower($call->name->toString()), ['view', 'markdown'], true)
                ) {
                    continue;
                }
                $receiverProof = self::receiverProof($call->var);
                if ($receiverProof === null) {
                    continue;
                }
                ++$nativeSetterCalls;
                $method = strtolower($call->name->toString());
                $arguments = self::arguments($call->getArgs());
                if ($arguments === null || ! isset($arguments['view'])) {
                    ++$unsupportedSetterCalls;
                    continue;
                }
                $candidates = $method === 'view'
                    ? self::viewCandidates($arguments['view'])
                    : self::markdownCandidates($arguments['view']);
                if ($candidates === null) {
                    ++$unsupportedSetterCalls;
                    continue;
                }
                if ($candidates === []) {
                    ++$nonReferenceSetterCalls;
                    continue;
                }
                foreach ($candidates as $candidate) {
                    if (count($references) >= self::MAX_REFERENCES) {
                        $reasons['reference-limit'] = true;
                        break 3;
                    }
                    $literal = $candidate['literal'];
                    $references[] = [
                        'file' => $path,
                        'contentHash' => $hash,
                        'method' => $method,
                        'name' => $literal->value,
                        'referenceKind' => $method === 'view' ? 'view' : 'markdown',
                        'viewSlot' => $candidate['slot'],
                        'start' => $literal->getStartFilePos(),
                        'end' => $literal->getEndFilePos() + 1,
                        'line' => $literal->getStartLine(),
                        'callStart' => $call->getStartFilePos(),
                        'callEnd' => $call->getEndFilePos() + 1,
                        'callLine' => $call->getStartLine(),
                        'receiverProof' => $receiverProof,
                        'finderContexts' => self::finderContexts($method),
                        'runtimeLookupProven' => false,
                        'selectedForRenderProven' => false,
                        'required' => false,
                        'confidence' => 'literal-reference-candidate',
                    ];
                }
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
                'kind' => 'mail-message-view-reference-candidates',
                'evidence' => 'source-only',
                'semantics' => 'navigation-candidates',
                'exhaustive' => false,
                'runtimeLookupProven' => false,
                'missingNameDiagnostic' => false,
            ],
            'references' => $references,
            'selection' => [
                'selectedFiles' => $selectedFiles,
                'nativeSetterCalls' => $nativeSetterCalls,
                'literalReferences' => count($references),
                'nonReferenceSetterCalls' => $nonReferenceSetterCalls,
                'unsupportedSetterCalls' => $unsupportedSetterCalls,
            ],
            'errors' => $errors,
            'truncated' => $reasons !== [],
            'truncationReasons' => array_keys($reasons),
        ];
    }

    private static function receiverProof(Node\Expr $receiver, int $depth = 0): ?string
    {
        if ($depth > self::MAX_RECEIVER_CHAIN) {
            return null;
        }
        if (
            $receiver instanceof Node\Expr\New_
            && $receiver->class instanceof Node\Name\FullyQualified
            && strcasecmp($receiver->class->toString(), self::MAIL_MESSAGE) === 0
            && $receiver->getArgs() === []
        ) {
            return 'direct-native-construction';
        }
        if (
            ! $receiver instanceof Node\Expr\MethodCall
            || ! $receiver->name instanceof Node\Identifier
            || ! in_array(strtolower($receiver->name->toString()), ['view', 'markdown'], true)
        ) {
            return null;
        }
        $arguments = self::arguments($receiver->getArgs());
        if (
            $arguments === null
            || ! isset($arguments['view'])
            || self::receiverProof($receiver->var, $depth + 1) === null
        ) {
            return null;
        }

        return 'audited-native-view-setter-chain';
    }

    /**
     * @param array<array-key, Node\Arg|Node\VariadicPlaceholder> $arguments
     * @return array{view?: Node\Expr, data?: Node\Expr}|null
     */
    private static function arguments(array $arguments): ?array
    {
        $parameters = ['view', 'data'];
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

    /** @return list<array{literal: Node\Scalar\String_, slot: string}>|null */
    private static function viewCandidates(Node\Expr $expression): ?array
    {
        if ($expression instanceof Node\Scalar\String_) {
            return [['literal' => $expression, 'slot' => 'single']];
        }
        if (! $expression instanceof Node\Expr\Array_) {
            return null;
        }

        $implicitList = true;
        foreach ($expression->items as $item) {
            if ($item->key !== null) {
                $implicitList = false;
                break;
            }
        }
        if ($implicitList) {
            if (
                count($expression->items) !== 2
                || $expression->items[0]->key !== null
                || $expression->items[1]->key !== null
                || $expression->items[0]->unpack
                || $expression->items[1]->unpack
                || $expression->items[0]->byRef
                || $expression->items[1]->byRef
                || ! $expression->items[0]->value instanceof Node\Scalar\String_
                || ! $expression->items[1]->value instanceof Node\Scalar\String_
            ) {
                return null;
            }

            return [
                ['literal' => $expression->items[0]->value, 'slot' => 'html'],
                ['literal' => $expression->items[1]->value, 'slot' => 'text'],
            ];
        }

        $values = [];
        foreach ($expression->items as $item) {
            if (
                $item->unpack
                || $item->byRef
                || ! $item->key instanceof Node\Scalar\String_
                || ! in_array($item->key->value, ['html', 'text', 'raw'], true)
                || isset($values[$item->key->value])
            ) {
                return null;
            }
            $values[$item->key->value] = $item->value;
        }

        $candidates = [];
        foreach (['html', 'text'] as $slot) {
            if (($values[$slot] ?? null) instanceof Node\Scalar\String_) {
                $candidates[] = ['literal' => $values[$slot], 'slot' => $slot];
            }
        }

        return $candidates;
    }

    /** @return list<array{literal: Node\Scalar\String_, slot: string}>|null */
    private static function markdownCandidates(Node\Expr $expression): ?array
    {
        if (! $expression instanceof Node\Scalar\String_) {
            return null;
        }

        return [['literal' => $expression, 'slot' => 'markdown']];
    }

    /** @return list<array<string, string|null>> */
    private static function finderContexts(string $method): array
    {
        if ($method === 'view') {
            return [[
                'renderer' => 'mailer-view-finder',
                'output' => null,
                'mailNamespaceVariant' => null,
            ]];
        }

        return [
            [
                'renderer' => 'markdown-view-finder',
                'output' => 'html',
                'mailNamespaceVariant' => 'html-components',
            ],
            [
                'renderer' => 'markdown-view-finder',
                'output' => 'text',
                'mailNamespaceVariant' => 'text-components',
            ],
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
