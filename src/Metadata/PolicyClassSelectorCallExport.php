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
 * Source-only candidates for Laravel's removal of a leading class-string
 * selector before a policy method is invoked.
 */
final class PolicyClassSelectorCallExport
{
    private const FACADE = 'Illuminate\\Support\\Facades\\Gate';
    private const MAX_FILES = 256;
    private const MAX_FILE_BYTES = 1024 * 1024;
    private const MAX_TOTAL_BYTES = 8 * 1024 * 1024;
    private const MAX_CALLS = 20000;

    /** @var array<string, string> */
    private const ABILITY_PARAMETERS = [
        'allows' => 'ability',
        'denies' => 'ability',
        'check' => 'abilities',
        'any' => 'abilities',
        'none' => 'abilities',
        'authorize' => 'ability',
        'inspect' => 'ability',
        'raw' => 'ability',
    ];

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
            throw new \InvalidArgumentException('Policy call sources must be a list of project-relative PHP files.');
        }

        $projectRoot = str_replace('\\', '/', $resolvedRoot);
        $directory = rtrim($projectRoot, '/').'/';
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $calls = [];
        $errors = [];
        $reasons = count($files) > self::MAX_FILES ? ['file-limit' => true] : [];
        $totalBytes = 0;
        $seen = [];
        $selectedFiles = 0;
        $gateCalls = 0;
        $unsupportedCandidates = 0;

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
            foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Expr\StaticCall::class) as $call) {
                if (! self::gateCall($call)) {
                    continue;
                }
                ++$gateCalls;
                $candidate = self::candidate($call, $path, $hash);
                if ($candidate === null) {
                    ++$unsupportedCandidates;
                    continue;
                }
                if (count($calls) >= self::MAX_CALLS) {
                    $reasons['call-limit'] = true;
                    break 2;
                }
                $calls[] = $candidate;
            }
        }

        return [
            'schemaVersion' => 1,
            'projectRoot' => $projectRoot,
            'scope' => [
                'kind' => 'policy-class-selector-call-candidates',
                'evidence' => 'source-only',
                'semantics' => 'remove-leading-literal-class-string-before-policy-invocation',
                'exhaustive' => false,
                'runtimePolicyResolved' => false,
            ],
            'calls' => $calls,
            'selection' => [
                'selectedFiles' => $selectedFiles,
                'nativeFacadeSyntaxCalls' => $gateCalls,
                'classSelectorCandidates' => count($calls),
                'unsupportedCandidates' => $unsupportedCandidates,
            ],
            'errors' => $errors,
            'truncated' => $reasons !== [],
            'truncationReasons' => array_keys($reasons),
        ];
    }

    private static function gateCall(Node\Expr\StaticCall $call): bool
    {
        return (
            $call->class instanceof Node\Name\FullyQualified
            && strcasecmp($call->class->toString(), self::FACADE) === 0
            && $call->name instanceof Node\Identifier
            && isset(self::ABILITY_PARAMETERS[strtolower($call->name->toString())])
        );
    }

    /** @return array<string, mixed>|null */
    private static function candidate(Node\Expr\StaticCall $call, string $file, string $hash): ?array
    {
        if (! $call->name instanceof Node\Identifier) {
            return null;
        }
        $method = strtolower($call->name->toString());
        $abilityParameter = self::ABILITY_PARAMETERS[$method];
        $arguments = self::arguments($call->getArgs(), [$abilityParameter, 'arguments']);
        if ($arguments === null || ! isset($arguments[$abilityParameter], $arguments['arguments'])) {
            return null;
        }
        $abilities = self::abilities($arguments[$abilityParameter], $method);
        $selector = self::selector($arguments['arguments']);
        if ($abilities === null || $selector === null) {
            return null;
        }

        [$class, $expression, $inputShape, $policyExpressions] = $selector;
        $policyArguments = [];
        foreach ($policyExpressions as $position => $policyExpression) {
            $policyArguments[] = [
                'positionAfterUser' => $position,
                'start' => $policyExpression->getStartFilePos(),
                'end' => $policyExpression->getEndFilePos() + 1,
                'line' => $policyExpression->getStartLine(),
                'expressionKind' => $policyExpression->getType(),
            ];
        }

        return [
            'file' => $file,
            'contentHash' => $hash,
            'method' => $method,
            'start' => $call->getStartFilePos(),
            'end' => $call->getEndFilePos() + 1,
            'line' => $call->getStartLine(),
            'abilities' => $abilities,
            'selector' => [
                'class' => $class,
                'start' => $expression->getStartFilePos(),
                'end' => $expression->getEndFilePos() + 1,
                'line' => $expression->getStartLine(),
                'inputShape' => $inputShape,
                'normalizedIndex' => 0,
                'removedBeforePolicyInvocation' => true,
            ],
            'policyArguments' => $policyArguments,
            'transformation' => [
                'normalization' => 'Arr::wrap',
                'condition' => 'first-normalized-argument-is-string',
                'operation' => 'remove-first-normalized-argument',
                'policyArgumentCount' => count($policyArguments),
            ],
            'confidence' => 'literal-class-selector-transformation',
            'runtimePolicyResolved' => false,
        ];
    }

    /**
     * @param array<array-key, Node\Arg|Node\VariadicPlaceholder> $arguments
     * @param list<string> $parameters
     * @return array<string, Node\Expr>|null
     */
    private static function arguments(array $arguments, array $parameters): ?array
    {
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

    /** @return list<array{value: string, start: int, end: int, line: int}>|null */
    private static function abilities(Node\Expr $expression, string $method): ?array
    {
        if ($expression instanceof Node\Scalar\String_) {
            return [self::literal($expression)];
        }
        if (
            ! in_array($method, ['allows', 'denies', 'check', 'any', 'none'], true)
            || ! $expression instanceof Node\Expr\Array_
            || $expression->items === []
        ) {
            return null;
        }

        $abilities = [];
        foreach ($expression->items as $item) {
            if (
                $item->key !== null
                || $item->unpack
                || $item->byRef
                || ! $item->value instanceof Node\Scalar\String_
            ) {
                return null;
            }
            $abilities[] = self::literal($item->value);
        }

        return $abilities;
    }

    /**
     * @return array{string, Node\Expr\ClassConstFetch, string, list<Node\Expr>}|null
     */
    private static function selector(Node\Expr $expression): ?array
    {
        $class = self::classString($expression);
        if ($class !== null && $expression instanceof Node\Expr\ClassConstFetch) {
            return [$class, $expression, 'scalar-class-constant', []];
        }
        if (! $expression instanceof Node\Expr\Array_ || $expression->items === []) {
            return null;
        }

        $values = [];
        foreach ($expression->items as $item) {
            if (
                $item->key !== null
                || $item->unpack
                || $item->byRef
            ) {
                return null;
            }
            $values[] = $item->value;
        }
        $first = $values[0] ?? null;
        if (! $first instanceof Node\Expr\ClassConstFetch || ($class = self::classString($first)) === null) {
            return null;
        }

        return [$class, $first, 'literal-list', array_slice($values, 1)];
    }

    private static function classString(Node\Expr $expression): ?string
    {
        if (
            ! $expression instanceof Node\Expr\ClassConstFetch
            || ! $expression->class instanceof Node\Name\FullyQualified
            || ! $expression->name instanceof Node\Identifier
            || strcasecmp($expression->name->toString(), 'class') !== 0
        ) {
            return null;
        }

        return $expression->class->toString();
    }

    /** @return array{value: string, start: int, end: int, line: int} */
    private static function literal(Node\Scalar\String_ $literal): array
    {
        return [
            'value' => $literal->value,
            'start' => $literal->getStartFilePos(),
            'end' => $literal->getEndFilePos() + 1,
            'line' => $literal->getStartLine(),
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
