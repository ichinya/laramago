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
 * Literal contextual-binding declaration candidates from explicitly selected
 * sources. These records do not describe effective container resolution.
 */
final class ContextualBindingMetadataExport
{
    private const MAX_FILES = 256;
    private const MAX_FILE_BYTES = 1024 * 1024;
    private const MAX_TOTAL_BYTES = 8 * 1024 * 1024;
    private const MAX_CANDIDATES = 10000;

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
                'Contextual binding sources must be a list of project-relative PHP files.',
            );
        }

        $projectRoot = str_replace('\\', '/', $resolvedRoot);
        $directory = rtrim($projectRoot, '/').'/';
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $finder = new NodeFinder;
        $declarations = [];
        $uncertainties = [];
        $errors = [];
        $reasons = count($files) > self::MAX_FILES ? ['file-limit' => true] : [];
        $totalBytes = 0;
        $selectedFiles = 0;
        $candidateChains = 0;
        $unsupportedCandidates = 0;
        $seen = [];

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
            $selectedFiles++;
            $hash = hash('sha256', $contents);
            /** @var list<Node\Expr\MethodCall> $calls */
            $calls = $finder->findInstanceOf($nodes, Node\Expr\MethodCall::class);
            foreach ($calls as $call) {
                $candidate = self::chain($call);
                if ($candidate === null) {
                    continue;
                }
                $candidateChains++;
                if ($candidateChains > self::MAX_CANDIDATES) {
                    $reasons['candidate-limit'] = true;
                    break 2;
                }

                [$when, $needs, $terminal, $terminalName] = $candidate;
                $receiver = self::receiver($when->var);
                $whenArguments = self::arguments($when->args, ['concrete'], 1);
                $needsArguments = self::arguments($needs->args, ['abstract'], 1);
                $terminalArguments = self::arguments(
                    $terminal->args,
                    match ($terminalName) {
                        'give' => ['implementation'],
                        'givetagged' => ['tag'],
                        default => ['key', 'default'],
                    },
                    1,
                    $terminalName === 'giveconfig' ? 2 : 1,
                );

                if ($receiver === null) {
                    $unsupportedCandidates++;
                    $uncertainties[] = self::uncertainty('unproven-receiver', $path, $terminal);
                    continue;
                }
                if ($whenArguments === null || $needsArguments === null || $terminalArguments === null) {
                    $unsupportedCandidates++;
                    $uncertainties[] = self::uncertainty('unsupported-arguments', $path, $terminal);
                    continue;
                }
                $contexts = self::tokens($whenArguments[0], $contents, 'context', true);
                $need = self::token($needsArguments[0], $contents, 'need');
                $provision = self::provision($terminalName, $terminalArguments, $contents);
                if ($contexts === null) {
                    $unsupportedCandidates++;
                    $uncertainties[] = self::uncertainty('unsupported-context', $path, $terminal);
                    continue;
                }
                if ($need === null) {
                    $unsupportedCandidates++;
                    $uncertainties[] = self::uncertainty('unsupported-need', $path, $terminal);
                    continue;
                }
                if ($provision === null) {
                    $unsupportedCandidates++;
                    $uncertainties[] = self::uncertainty('unsupported-provision', $path, $terminal);
                    continue;
                }

                $declarations[] = [
                    'contexts' => $contexts,
                    'need' => [
                        ...$need,
                        'needKind' => $need['kind'] === 'string' && str_starts_with($need['value'], '$')
                            ? 'primitive-parameter'
                            : 'abstract',
                    ],
                    'provision' => $provision,
                    'receiver' => $receiver,
                    'file' => $path,
                    'start' => $when->getStartFilePos(),
                    'end' => $terminal->getEndFilePos() + 1,
                    'line' => $when->getStartLine(),
                    'contentHash' => $hash,
                    'sourceSelected' => true,
                    'confidence' => 'literal-declaration-candidate',
                    'receiverNativeValidated' => false,
                    'runtimeRegistrationValidated' => false,
                    'effectiveResolutionValidated' => false,
                ];
            }
        }

        return [
            'schemaVersion' => 1,
            'projectRoot' => $projectRoot,
            'scope' => [
                'kind' => 'contextual-binding-declaration-candidates',
                'evidence' => 'selected-source-only',
                'semantics' => 'literal-container-shaped-when-needs-give-chain',
                'exhaustive' => false,
                'receiverNativeValidated' => false,
                'runtimeRegistrationValidated' => false,
                'effectiveResolutionValidated' => false,
            ],
            'declarations' => $declarations,
            'selection' => [
                'selectedFiles' => $selectedFiles,
                'candidateChains' => $candidateChains,
                'literalDeclarations' => count($declarations),
                'unsupportedCandidates' => $unsupportedCandidates,
            ],
            'uncertainties' => $uncertainties,
            'errors' => $errors,
            'truncated' => $reasons !== [],
            'truncationReasons' => array_keys($reasons),
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

    /**
     * @return array{Node\Expr\MethodCall, Node\Expr\MethodCall, Node\Expr\MethodCall, string}|null
     */
    private static function chain(Node\Expr\MethodCall $terminal): ?array
    {
        $terminalName = self::methodName($terminal);
        if (! in_array($terminalName, ['give', 'givetagged', 'giveconfig'], true)) {
            return null;
        }
        $needs = $terminal->var;
        if (! $needs instanceof Node\Expr\MethodCall || self::methodName($needs) !== 'needs') {
            return null;
        }
        $when = $needs->var;
        if (! $when instanceof Node\Expr\MethodCall || self::methodName($when) !== 'when') {
            return null;
        }

        return [$when, $needs, $terminal, $terminalName];
    }

    private static function methodName(Node\Expr\MethodCall $call): ?string
    {
        return $call->name instanceof Node\Identifier ? strtolower($call->name->toString()) : null;
    }

    private static function receiver(Node\Expr $receiver): ?string
    {
        if (
            $receiver instanceof Node\Expr\FuncCall
            && $receiver->name instanceof Node\Name\FullyQualified
            && strtolower($receiver->name->toString()) === 'app'
            && $receiver->args === []
        ) {
            return 'global-app-helper-syntax';
        }
        if (
            $receiver instanceof Node\Expr\StaticCall
            && $receiver->class instanceof Node\Name\FullyQualified
            && strcasecmp($receiver->class->toString(), 'Illuminate\Container\Container') === 0
            && $receiver->name instanceof Node\Identifier
            && strtolower($receiver->name->toString()) === 'getinstance'
            && $receiver->args === []
        ) {
            return 'container-singleton-syntax';
        }

        return null;
    }

    /**
     * @param array<array-key, Node\Arg|Node\VariadicPlaceholder> $arguments
     * @param list<string> $names
     * @return array<int, Node\Expr>|null
     */
    private static function arguments(array $arguments, array $names, int $minimum, ?int $maximum = null): ?array
    {
        $maximum ??= $minimum;
        if (count($arguments) < $minimum || count($arguments) > $maximum) {
            return null;
        }
        $result = [];
        $next = 0;
        foreach ($arguments as $argument) {
            if (! $argument instanceof Node\Arg || $argument->unpack || $argument->byRef) {
                return null;
            }
            if ($argument->name === null) {
                while (array_key_exists($next, $result)) {
                    $next++;
                }
                $position = $next++;
            } else {
                $position = array_search($argument->name->toString(), $names, true);
                if ($position === false) {
                    return null;
                }
            }
            if ($position >= $maximum || array_key_exists($position, $result)) {
                return null;
            }
            $result[$position] = $argument->value;
        }
        for ($position = 0; $position < $minimum; $position++) {
            if (! array_key_exists($position, $result)) {
                return null;
            }
        }
        ksort($result);

        return $result;
    }

    /** @return list<array<string, mixed>>|null */
    private static function tokens(Node\Expr $expression, string $contents, string $role, bool $allowArray): ?array
    {
        $token = self::token($expression, $contents, $role);
        if ($token !== null) {
            return [$token];
        }
        if (! $allowArray || ! $expression instanceof Node\Expr\Array_ || $expression->items === []) {
            return null;
        }
        $tokens = [];
        foreach ($expression->items as $item) {
            if ($item->unpack || $item->byRef || $item->key !== null) {
                return null;
            }
            $token = self::token($item->value, $contents, $role);
            if ($token === null) {
                return null;
            }
            $tokens[] = $token;
        }

        return $tokens;
    }

    /**
     * @param array<int, Node\Expr> $arguments
     * @return array<string, mixed>|null
     */
    private static function provision(string $terminal, array $arguments, string $contents): ?array
    {
        if ($terminal === 'give') {
            $implementation = $arguments[0];
            $tokens = self::tokens($implementation, $contents, 'implementation', true);

            return (
                $tokens === null
                    ? null
                    : [
                        'kind' => $implementation instanceof Node\Expr\Array_
                            ? 'literal-implementation-list'
                            : 'literal-implementation',
                        'tokens' => $tokens,
                    ]
            );
        }
        $role = $terminal === 'givetagged' ? 'tag' : 'config-key';
        $token = self::token($arguments[0], $contents, $role);
        if ($token === null || $token['kind'] !== 'string') {
            return null;
        }

        return (
            $terminal === 'givetagged'
                ? ['kind' => 'tagged', 'tag' => $token]
                : ['kind' => 'config', 'key' => $token, 'hasDefault' => isset($arguments[1])]
        );
    }

    /** @return array{role: string, kind: string, value: string, source: string, start: int, end: int, line: int}|null */
    private static function token(Node\Expr $expression, string $contents, string $role): ?array
    {
        if ($expression instanceof Node\Scalar\String_) {
            $kind = 'string';
            $value = $expression->value;
        } elseif (
            $expression instanceof Node\Expr\ClassConstFetch
            && $expression->class instanceof Node\Name
            && $expression->name instanceof Node\Identifier
            && strcasecmp($expression->name->toString(), 'class') === 0
            && ! in_array(strtolower($expression->class->toString()), ['self', 'static', 'parent'], true)
        ) {
            $kind = 'class-string';
            $value = ltrim($expression->class->toString(), '\\');
        } else {
            return null;
        }
        $start = $expression->getStartFilePos();
        $end = $expression->getEndFilePos() + 1;

        return [
            'role' => $role,
            'kind' => $kind,
            'value' => $value,
            'source' => substr($contents, $start, $end - $start),
            'start' => $start,
            'end' => $end,
            'line' => $expression->getStartLine(),
        ];
    }

    /** @return array{code: string, file: string, start: int, end: int, line: int, message: string} */
    private static function uncertainty(string $code, string $file, Node $node): array
    {
        return [
            'code' => $code,
            'file' => $file,
            'start' => $node->getStartFilePos(),
            'end' => $node->getEndFilePos() + 1,
            'line' => $node->getStartLine(),
            'message' => match ($code) {
                'unproven-receiver'
                    => 'Contextual chain receiver does not use recognized container entry-point syntax.',
                'unsupported-arguments' => 'Contextual chain uses unsupported argument structure.',
                'unsupported-context' => 'Contextual chain context is not a literal string or class-string list.',
                'unsupported-need' => 'Contextual chain need is not a literal string or class-string.',
                default => 'Contextual chain provision is not a supported literal give, tag, or config declaration.',
            },
        ];
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
