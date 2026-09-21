<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Metadata;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * Selected declaration evidence for Laravel's policy discovery boundaries.
 * This exporter never chooses an effective policy.
 */
final class PolicyDiscoveryBoundaryExport
{
    private const MAX_FILES = 256;
    private const MAX_FILE_BYTES = 1024 * 1024;
    private const MAX_TOTAL_BYTES = 8 * 1024 * 1024;
    private const MAX_DECLARATIONS = 20000;
    private const MAX_GUESS_CANDIDATES = 50000;

    /**
     * @param array<array-key, string> $files Explicit project-relative PHP class sources.
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
                'Policy discovery sources must be a list of project-relative PHP files.',
            );
        }

        $projectRoot = str_replace('\\', '/', $resolvedRoot);
        $directory = rtrim($projectRoot, '/').'/';
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $sources = [];
        $errors = [];
        $reasons = count($files) > self::MAX_FILES ? ['file-limit' => true] : [];
        $totalBytes = 0;
        $seen = [];

        foreach (array_slice($files, 0, self::MAX_FILES) as $file) {
            if (! self::validSourceName($file)) {
                $errors[] = self::error('invalid-source', self::errorFile($file));
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
            $sources[] = [
                'file' => $path,
                'contents' => $contents,
                'contentHash' => hash('sha256', $contents),
                'nodes' => $nodes,
            ];
        }

        /**
         * @var list<array{
         *     class: string,
         *     node: Node\Stmt\Class_,
         *     file: string,
         *     contents: string,
         *     contentHash: string,
         *     parent: ?string,
         *     directPolicyAttribute: array<string, mixed>
         * }> $declarations
         */
        $declarations = [];
        foreach ($sources as $source) {
            foreach (self::topLevel($source['nodes']) as $statement) {
                if (! $statement instanceof Node\Stmt\Class_ || $statement->namespacedName === null) {
                    continue;
                }
                if (count($declarations) >= self::MAX_DECLARATIONS) {
                    $reasons['declaration-limit'] = true;
                    break 2;
                }
                $name = $statement->namespacedName->toString();
                $declarations[] = [
                    'class' => $name,
                    'node' => $statement,
                    'file' => $source['file'],
                    'contents' => $source['contents'],
                    'contentHash' => $source['contentHash'],
                    'parent' => $statement->extends?->toString(),
                    'directPolicyAttribute' => self::policyAttribute($statement, $name, $source),
                ];
            }
        }

        /** @var array<string, list<int>> $classes */
        $classes = [];
        foreach ($declarations as $index => $declaration) {
            $classes[strtolower($declaration['class'])][] = $index;
        }

        $subjects = [];
        $guessCandidateCount = 0;
        $ambiguousClasses = 0;
        foreach ($classes as $indexes) {
            if (count($indexes) !== 1) {
                $ambiguousClasses++;
                continue;
            }
            $declaration = $declarations[$indexes[0]];
            $guesses = self::defaultGuessBoundary($declaration['class']);
            $nextGuessCount = $guessCandidateCount + count($guesses['probeOrder']);
            if ($nextGuessCount > self::MAX_GUESS_CANDIDATES) {
                $reasons['guess-candidate-limit'] = true;
                break;
            }
            $guessCandidateCount = $nextGuessCount;
            $inheritance = self::inheritanceBoundary($declaration, $declarations, $classes);
            $subjects[] = [
                'class' => $declaration['class'],
                'declaration' => self::declarationLocation($declaration),
                'effectivePolicy' => [
                    'status' => 'unknown',
                    'reason' => 'runtime-resolver-boundaries',
                ],
                'channels' => [
                    'exactPolicyMap' => [
                        'status' => 'external-runtime-state',
                        'source' => 'Gate::policy and provider registration',
                    ],
                    'directPolicyAttribute' => $declaration['directPolicyAttribute'],
                    'policyNameGuessing' => [
                        'status' => 'conditional-candidates',
                        'customCallbackState' => 'unknown',
                        'runtimeClassExistence' => 'unknown',
                        'defaultResolver' => $guesses,
                    ],
                    'parentPolicyMap' => [
                        'status' => 'external-runtime-state',
                        'selectedParentChain' => $inheritance['parents'],
                        'chainComplete' => $inheritance['complete'],
                    ],
                    'inheritedPolicyAttributes' => [
                        'status' => $inheritance['complete'] ? 'selected-chain-only' : 'selected-chain-incomplete',
                        'candidates' => $inheritance['attributes'],
                    ],
                ],
            ];
        }

        return [
            'schemaVersion' => 1,
            'projectRoot' => $projectRoot,
            'scope' => [
                'kind' => 'policy-discovery-boundaries',
                'evidence' => 'selected-source-only',
                'exhaustive' => false,
                'effectivePolicyResolved' => false,
                'nativeResolutionOrder' => [
                    'exact-policy-map',
                    'direct-use-policy-attribute',
                    'policy-name-guessing',
                    'parent-policy-map',
                    'inherited-use-policy-attribute',
                ],
                'unverifiedRuntimeBoundaries' => [
                    'gate-binding',
                    'policy-map-registration-and-mutation',
                    'custom-policy-name-guesser',
                    'autoloaded-class-existence',
                    'runtime-parent-hierarchy',
                    'container-policy-resolution',
                ],
            ],
            'declarations' => array_map(self::publicDeclaration(...), $declarations),
            'subjects' => $subjects,
            'selection' => [
                'sourceFiles' => count($sources),
                'classDeclarations' => count($declarations),
                'uniqueClasses' => count($classes) - $ambiguousClasses,
                'ambiguousClasses' => $ambiguousClasses,
                'exportedSubjects' => count($subjects),
                'defaultGuessCandidates' => $guessCandidateCount,
            ],
            'errors' => $errors,
            'truncated' => $reasons !== [],
            'truncationReasons' => array_keys($reasons),
        ];
    }

    /**
     * @param array{file: string, contents: string, contentHash: string, nodes: array<array-key, Node>} $source
     * @return array<string, mixed>
     */
    private static function policyAttribute(Node\Stmt\Class_ $class, string $className, array $source): array
    {
        $attributes = [];
        foreach ($class->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (
                    strcasecmp(
                        $attribute->name->toString(),
                        'Illuminate\Database\Eloquent\Attributes\UsePolicy',
                    ) === 0
                ) {
                    $attributes[] = $attribute;
                }
            }
        }
        if ($attributes === []) {
            return ['status' => 'absent-in-selected-declaration'];
        }
        if (count($attributes) !== 1) {
            return [
                'status' => 'uncertain',
                'reason' => 'multiple-direct-use-policy-attributes',
                'locations' => array_map(
                    static fn (Node\Attribute $attribute): array => self::nodeLocation(
                        $attribute,
                        $source['file'],
                        $source['contentHash'],
                    ),
                    $attributes,
                ),
            ];
        }

        $attribute = $attributes[0];
        if (count($attribute->args) !== 1) {
            return self::uncertainAttribute($attribute, $source, 'unsupported-attribute-argument-count');
        }
        $argument = $attribute->args[0];
        if (
            $argument->unpack
            || $argument->byRef
            || $argument->name !== null
            && $argument->name->toString() !== 'class'
        ) {
            return self::uncertainAttribute($attribute, $source, 'unsupported-attribute-argument');
        }
        $policy = self::classValue($argument->value, $className);
        if ($policy === null) {
            return self::uncertainAttribute($attribute, $source, 'unresolved-policy-class');
        }

        return [
            'status' => 'declared',
            'policy' => $policy,
            'confidence' => 'direct-source-declaration',
            'location' => self::nodeLocation($attribute, $source['file'], $source['contentHash']),
            'valueLocation' => self::nodeLocation($argument->value, $source['file'], $source['contentHash']),
        ];
    }

    /**
     * @param array{file: string, contents: string, contentHash: string, nodes: array<array-key, Node>} $source
     * @return array<string, mixed>
     */
    private static function uncertainAttribute(
        Node\Attribute $attribute,
        array $source,
        string $reason,
    ): array {
        return [
            'status' => 'uncertain',
            'reason' => $reason,
            'location' => self::nodeLocation($attribute, $source['file'], $source['contentHash']),
        ];
    }

    private static function classValue(Node\Expr $expression, string $className): ?string
    {
        if ($expression instanceof Node\Scalar\String_) {
            return self::validClassName($expression->value) ? $expression->value : null;
        }
        if (
            ! $expression instanceof Node\Expr\ClassConstFetch
            || ! $expression->class instanceof Node\Name
            || ! $expression->name instanceof Node\Identifier
            || strcasecmp($expression->name->toString(), 'class') !== 0
        ) {
            return null;
        }
        $name = $expression->class->toString();
        if (strcasecmp($name, 'self') === 0) {
            return $className;
        }
        if (in_array(strtolower($name), ['parent', 'static'], true)) {
            return null;
        }

        return self::validClassName($name) ? $name : null;
    }

    private static function validClassName(string $name): bool
    {
        return (
            preg_match(
                '~^\\\\?[a-zA-Z_\\x80-\\xff][a-zA-Z0-9_\\x80-\\xff]*(?:\\\\[a-zA-Z_\\x80-\\xff][a-zA-Z0-9_\\x80-\\xff]*)*$~',
                $name,
            ) === 1
        );
    }

    /** @return array{probeOrder: list<array{name: string, probePosition: int}>, fallback: string} */
    private static function defaultGuessBoundary(string $class): array
    {
        $classDirname = str_replace('/', '\\', dirname(str_replace('\\', '/', $class)));
        $segments = explode('\\', $classDirname);
        $separator = strrpos($class, '\\');
        $basename = $separator === false ? $class : substr($class, $separator + 1);
        $candidates = [];
        for ($index = 1; $index <= count($segments); $index++) {
            $candidates[] = implode('\\', array_slice($segments, 0, $index)).'\\Policies\\'.$basename.'Policy';
        }
        if (str_contains($classDirname, '\\Models\\')) {
            $candidates[] = str_replace('\\Models\\', '\\Policies\\', $classDirname).'\\'.$basename.'Policy';
            $candidates[] = str_replace('\\Models\\', '\\Models\\Policies\\', $classDirname).'\\'.$basename.'Policy';
        }
        $probeOrder = [];
        foreach (array_reverse($candidates) as $index => $candidate) {
            $probeOrder[] = [
                'name' => $candidate,
                'probePosition' => $index + 1,
            ];
        }

        return [
            'probeOrder' => $probeOrder,
            'fallback' => $classDirname.'\\Policies\\'.$basename.'Policy',
        ];
    }

    /**
     * @param array{class: string, node: Node\Stmt\Class_, file: string, contents: string, contentHash: string, parent: ?string, directPolicyAttribute: array<string, mixed>} $declaration
     * @param list<array{class: string, node: Node\Stmt\Class_, file: string, contents: string, contentHash: string, parent: ?string, directPolicyAttribute: array<string, mixed>}> $declarations
     * @param array<string, list<int>> $classes
     * @return array{parents: list<array<string, mixed>>, attributes: list<array<string, mixed>>, complete: bool}
     */
    private static function inheritanceBoundary(array $declaration, array $declarations, array $classes): array
    {
        $parents = [];
        $attributes = [];
        $complete = true;
        $visited = [strtolower($declaration['class']) => true];
        $parent = $declaration['parent'];
        while ($parent !== null) {
            $key = strtolower($parent);
            if (isset($visited[$key])) {
                $parents[] = ['class' => $parent, 'selection' => 'cycle'];
                $complete = false;
                break;
            }
            $visited[$key] = true;
            $indexes = $classes[$key] ?? [];
            if (count($indexes) !== 1) {
                $parents[] = [
                    'class' => $parent,
                    'selection' => $indexes === [] ? 'outside-selection' : 'ambiguous-selected-declaration',
                ];
                $complete = false;
                break;
            }
            $selected = $declarations[$indexes[0]];
            $parents[] = [
                'class' => $selected['class'],
                'selection' => 'unique-selected-declaration',
                'declaration' => self::declarationLocation($selected),
            ];
            if ($selected['directPolicyAttribute']['status'] !== 'absent-in-selected-declaration') {
                $attributes[] = [
                    'class' => $selected['class'],
                    'attribute' => $selected['directPolicyAttribute'],
                ];
            }
            $parent = $selected['parent'];
        }

        return [
            'parents' => $parents,
            'attributes' => $attributes,
            'complete' => $complete,
        ];
    }

    /**
     * @param array{class: string, node: Node\Stmt\Class_, file: string, contents: string, contentHash: string, parent: ?string, directPolicyAttribute: array<string, mixed>} $declaration
     * @return array<string, mixed>
     */
    private static function publicDeclaration(array $declaration): array
    {
        return [
            'class' => $declaration['class'],
            'parent' => $declaration['parent'],
            'location' => self::declarationLocation($declaration),
            'directPolicyAttribute' => $declaration['directPolicyAttribute'],
        ];
    }

    /**
     * @param array{class: string, node: Node\Stmt\Class_, file: string, contents: string, contentHash: string, parent: ?string, directPolicyAttribute: array<string, mixed>} $declaration
     * @return array{file: string, start: int, end: int, line: int, contentHash: string}
     */
    private static function declarationLocation(array $declaration): array
    {
        return self::nodeLocation(
            $declaration['node']->name ?? $declaration['node'],
            $declaration['file'],
            $declaration['contentHash'],
        );
    }

    /** @return array{file: string, start: int, end: int, line: int, contentHash: string} */
    private static function nodeLocation(Node $node, string $file, string $contentHash): array
    {
        return [
            'file' => $file,
            'start' => $node->getStartFilePos(),
            'end' => $node->getEndFilePos() + 1,
            'line' => $node->getStartLine(),
            'contentHash' => $contentHash,
        ];
    }

    private static function validSourceName(mixed $file): bool
    {
        return (
            is_string($file)
            && $file !== ''
            && ! str_contains($file, "\0")
            && ! str_contains($file, ':')
            && ! preg_match('~^(?:[/\\\\])|(?:^|[/\\\\])\.\.(?:[/\\\\]|$)~', $file)
            && str_ends_with(strtolower($file), '.php')
        );
    }

    private static function errorFile(mixed $file): ?string
    {
        return is_string($file) ? $file : null;
    }

    /** @param array<array-key, Node> $nodes
     * @return \Generator<int, Node\Stmt>
     */
    private static function topLevel(array $nodes): \Generator
    {
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                yield from self::topLevel($node->stmts);
            } elseif ($node instanceof Node\Stmt) {
                yield $node;
            }
        }
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
