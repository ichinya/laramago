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
 * Raw literal pipe parameters from explicitly selected native Pipeline chains.
 *
 * This exporter describes parsePipeString input. The parse is conditional because
 * Pipeline dispatch checks is_callable($pipe) before it parses string pipes.
 */
final class MiddlewareParameterMetadataExport
{
    private const MAX_FILES = 256;
    private const MAX_FILE_BYTES = 1024 * 1024;
    private const MAX_TOTAL_BYTES = 8 * 1024 * 1024;
    private const MAX_REFERENCES = 10000;

    /** @var list<string> */
    private const PIPELINE_CHAIN_METHODS = [
        'finally',
        'pipe',
        'send',
        'through',
        'via',
        'withintransaction',
    ];

    /**
     * @param array<array-key, string> $files Project-relative PHP source list.
     * @return array<string, mixed>
     */
    public function export(string $root, array $files): array
    {
        clearstatcache(true);
        $directory = realpath($root);
        if ($directory === false || ! is_dir($directory)) {
            throw new \InvalidArgumentException('Project root must be an existing directory.');
        }
        if (! array_is_list($files)) {
            throw new \InvalidArgumentException(
                'Middleware parameter sources must be a list of project-relative PHP files.',
            );
        }
        $projectRoot = str_replace('\\', '/', $directory);
        $directory = rtrim($projectRoot, '/').'/';
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $references = [];
        $unresolvedConsumers = [];
        $errors = [];
        $truncated = count($files) > self::MAX_FILES;
        $reasons = $truncated ? ['file-limit' => true] : [];
        $totalBytes = 0;
        $seen = [];

        foreach (array_slice($files, 0, self::MAX_FILES) as $file) {
            if (! self::validSource($file)) {
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
            $contents = @file_get_contents(
                $path,
                false,
                null,
                0,
                min(self::MAX_FILE_BYTES, self::MAX_TOTAL_BYTES - $totalBytes) + 1,
            );
            if ($contents === false) {
                $errors[] = self::error('unreadable-source', $file);
                continue;
            }
            $size = strlen($contents);
            $totalBytes += $size;
            if ($totalBytes > self::MAX_TOTAL_BYTES) {
                $truncated = true;
                $reasons['total-byte-limit'] = true;
                $errors[] = self::error('source-limit', $file);
                break;
            }
            if ($size > self::MAX_FILE_BYTES) {
                $truncated = true;
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
            $hash = hash('sha256', $contents);
            $calls = (new NodeFinder)->findInstanceOf($nodes, Node\Expr\MethodCall::class);
            usort(
                $calls,
                static fn (Node\Expr\MethodCall $left, Node\Expr\MethodCall $right): int => (
                    $left->getStartFilePos() <=> $right->getStartFilePos()
                    ?: $left->getEndFilePos() <=> $right->getEndFilePos()
                ),
            );
            foreach ($calls as $call) {
                if (! self::nativePipeCall($call) || ! $call->name instanceof Node\Identifier) {
                    continue;
                }
                $consumer = strtolower($call->name->toString());
                $literals = self::literalPipes($call);
                if ($literals === null) {
                    if (count($unresolvedConsumers) >= self::MAX_REFERENCES) {
                        $truncated = true;
                        $reasons['reference-limit'] = true;
                        break 2;
                    }
                    $unresolvedConsumers[] = [
                        'file' => $path,
                        'start' => $call->getStartFilePos(),
                        'end' => $call->getEndFilePos() + 1,
                        'line' => $call->getStartLine(),
                        'contentHash' => $hash,
                        'consumer' => $consumer,
                        'reason' => 'dynamic-or-unsupported-pipes',
                    ];
                    continue;
                }
                foreach ($literals as $literal) {
                    if (count($references) >= self::MAX_REFERENCES) {
                        $truncated = true;
                        $reasons['reference-limit'] = true;
                        break 3;
                    }
                    $references[] = self::reference(
                        $literal,
                        $consumer,
                        $path,
                        $hash,
                    );
                }
            }
        }

        return [
            'schemaVersion' => 1,
            'projectRoot' => $projectRoot,
            'scope' => [
                'kind' => 'middleware-parameters',
                'evidence' => 'source-only',
                'consumer' => 'direct-native-pipeline-chain',
                'frameworkContract' => 'required-by-consumer',
                'parseCondition' => 'pipe-is-not-callable',
                'resolution' => 'unresolved-container-target',
            ],
            'references' => $references,
            'unresolvedConsumers' => $unresolvedConsumers,
            'errors' => $errors,
            'truncated' => $truncated,
            'truncationReasons' => array_keys($reasons),
        ];
    }

    private static function validSource(string $file): bool
    {
        return (
            $file !== ''
            && ! str_contains($file, "\0")
            && ! str_contains($file, ':')
            && ! preg_match('~^(?:[/\\\\])|(?:^|[/\\\\])\.\.(?:[/\\\\]|$)~', $file)
            && str_ends_with(strtolower($file), '.php')
        );
    }

    private static function nativePipeCall(Node\Expr\MethodCall $call): bool
    {
        if (
            ! $call->name instanceof Node\Identifier
            || ! in_array(strtolower($call->name->toString()), ['pipe', 'through'], true)
        ) {
            return false;
        }
        $receiver = $call->var;
        while ($receiver instanceof Node\Expr\MethodCall) {
            if (
                ! $receiver->name instanceof Node\Identifier
                || ! in_array(strtolower($receiver->name->toString()), self::PIPELINE_CHAIN_METHODS, true)
            ) {
                return false;
            }
            $receiver = $receiver->var;
        }

        return (
            $receiver instanceof Node\Expr\New_
            && $receiver->class instanceof Node\Name\FullyQualified
            && strtolower($receiver->class->toString()) === 'illuminate\\pipeline\\pipeline'
        );
    }

    /** @return list<Node\Scalar\String_>|null */
    private static function literalPipes(Node\Expr\MethodCall $call): ?array
    {
        if ($call->args === []) {
            return null;
        }
        $first = $call->args[0];
        if (! $first instanceof Node\Arg || $first->name !== null || $first->unpack || $first->byRef) {
            return null;
        }
        if ($first->value instanceof Node\Expr\Array_) {
            $literals = [];
            foreach ($first->value->items as $item) {
                if (
                    $item->key !== null
                    || $item->unpack
                    || $item->byRef
                    || ! $item->value instanceof Node\Scalar\String_
                ) {
                    return null;
                }
                $literals[] = $item->value;
            }

            return $literals;
        }
        $literals = [];
        foreach ($call->args as $argument) {
            if (
                ! $argument instanceof Node\Arg
                || $argument->name !== null
                || $argument->unpack
                || $argument->byRef
                || ! $argument->value instanceof Node\Scalar\String_
            ) {
                return null;
            }
            $literals[] = $argument->value;
        }

        return $literals;
    }

    /** @return array<string, mixed> */
    private static function reference(
        Node\Scalar\String_ $literal,
        string $consumer,
        string $file,
        string $hash,
    ): array {
        $raw = $literal->value;
        $colon = strpos($raw, ':');
        $nameEnd = $colon === false ? strlen($raw) : $colon;
        $tokens = [];
        if ($colon !== false) {
            $start = $colon + 1;
            $index = 0;
            while (true) {
                $comma = strpos($raw, ',', $start);
                $end = $comma === false ? strlen($raw) : $comma;
                $tokens[] = [
                    'index' => $index++,
                    'value' => substr($raw, $start, $end - $start),
                    'decodedStart' => $start,
                    'decodedEnd' => $end,
                ];
                if ($comma === false) {
                    break;
                }
                $start = $comma + 1;
            }
        }

        return [
            'raw' => $raw,
            'name' => substr($raw, 0, $nameEnd),
            'parameters' => array_column($tokens, 'value'),
            'argumentTokens' => $tokens,
            'file' => $file,
            'start' => $literal->getStartFilePos(),
            'end' => $literal->getEndFilePos() + 1,
            'line' => $literal->getStartLine(),
            'contentHash' => $hash,
            'consumer' => $consumer,
            'parseCondition' => 'pipe-is-not-callable',
            'confidence' => 'known-positive',
        ];
    }

    /** @return array{code: string, file: string|null, message: string} */
    private static function error(string $code, ?string $file): array
    {
        return [
            'code' => $code,
            'file' => $file,
            'message' => match ($code) {
                'invalid-source' => 'Source must be a project-relative PHP file within the project root.',
                'parse-failure' => 'Unable to parse PHP source.',
                'source-limit' => 'PHP source exceeds the bounded read budget.',
                default => 'Unable to read PHP source.',
            },
        ];
    }
}
