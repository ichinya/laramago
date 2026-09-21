<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Metadata;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * Source-only parameter metadata for a bounded subset of named route declarations.
 *
 * The result describes declaration candidates. It does not prove that a route survives
 * later registrations or mutations, and declaration defaults are not URL defaults.
 */
final class RouteParameterMetadataExport
{
    private const MAX_FILES = 256;
    private const MAX_FILE_BYTES = 1024 * 1024;
    private const MAX_TOTAL_BYTES = 8 * 1024 * 1024;
    private const MAX_DECLARATIONS = 10000;
    private const MAX_GROUP_DEPTH = 32;
    private const MAX_COMPOSED_BYTES = 4096;

    /** @var list<string> */
    private const VERBS = ['get', 'post', 'put', 'patch', 'delete', 'options', 'any'];

    /** @var array<string, list<string>> */
    private const METHODS = [
        'get' => ['GET', 'HEAD'],
        'post' => ['POST'],
        'put' => ['PUT'],
        'patch' => ['PATCH'],
        'delete' => ['DELETE'],
        'options' => ['OPTIONS'],
        'any' => ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    ];

    /** @var list<string> */
    private const NEUTRAL_ROUTE_METHODS = [
        'can',
        'middleware',
        'missing',
        'scopebindings',
        'withoutmiddleware',
        'withoutscopedbindings',
        'withtrashed',
        'where',
        'wherealpha',
        'wherealphanumeric',
        'wherein',
        'wherenumber',
        'whereulid',
        'whereuuid',
    ];

    /** @var list<string> */
    private const NEUTRAL_GROUP_METHODS = [
        'can',
        'controller',
        'metadata',
        'middleware',
        'missing',
        'namespace',
        'scopebindings',
        'where',
        'withoutmiddleware',
        'withoutscopedbindings',
    ];

    /** @var list<string> */
    private const NEUTRAL_GROUP_KEYS = [
        'can',
        'controller',
        'excluded_middleware',
        'metadata',
        'middleware',
        'missing',
        'namespace',
        'scopeBindings',
        'scope_bindings',
        'where',
    ];

    /**
     * @param array<array-key, string> $files Project-relative PHP source list.
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
            throw new \InvalidArgumentException('Route sources must be a list of project-relative PHP files.');
        }

        $projectRoot = str_replace('\\', '/', $resolvedRoot);
        $prefix = rtrim($projectRoot, '/').'/';
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $declarations = [];
        $errors = [];
        $reasons = [];
        $totalBytes = 0;
        $seen = [];
        if (count($files) > self::MAX_FILES) {
            $reasons['file-limit'] = true;
        }

        foreach (array_slice($files, 0, self::MAX_FILES) as $file) {
            if (
                $file === ''
                || str_contains($file, "\0")
                || str_contains($file, ':')
                || preg_match('~^(?:[/\\\\])|(?:^|[/\\\\])\.\.(?:[/\\\\]|$)~', $file)
                || ! str_ends_with(strtolower($file), '.php')
            ) {
                $errors[] = self::error('invalid-source', null);
                continue;
            }
            clearstatcache(true);
            $path = realpath($prefix.$file);
            if ($path === false || ! is_file($path)) {
                $errors[] = self::error('unreadable-source', $file);
                continue;
            }
            $path = str_replace('\\', '/', $path);
            $contained = DIRECTORY_SEPARATOR === '\\'
                ? str_starts_with(strtolower($path), strtolower($prefix))
                : str_starts_with($path, $prefix);
            if (! $contained || ! str_ends_with(strtolower($path), '.php')) {
                $errors[] = self::error('invalid-source', $file);
                continue;
            }
            $identity = DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
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

            $hash = hash('sha256', $contents);
            foreach (self::declarations($nodes, self::emptyContext(), reasons: $reasons) as $candidate) {
                if (count($declarations) >= self::MAX_DECLARATIONS) {
                    $reasons['declaration-limit'] = true;
                    break 2;
                }
                $name = $candidate['nameLeaf'];
                $declarations[] = [
                    'name' => $candidate['name'],
                    'uri' => $candidate['uri'],
                    'domain' => $candidate['domain'],
                    'parameters' => $candidate['parameters'],
                    'declarationDefaults' => [
                        'complete' => $candidate['defaultsComplete'],
                        'entries' => $candidate['defaults'],
                    ],
                    'registrationIdentity' => [
                        'methods' => self::METHODS[$candidate['verb']],
                        'domain' => $candidate['domain'],
                        'uri' => $candidate['uri'],
                        'survival' => 'unresolved',
                    ],
                    'provenance' => [
                        'name' => $candidate['nameTokens'],
                        'uri' => $candidate['uriTokens'],
                        'domain' => $candidate['domainTokens'],
                    ],
                    'file' => $path,
                    'start' => $name['start'],
                    'end' => $name['end'],
                    'line' => $name['line'],
                    'contentHash' => $hash,
                    'confidence' => 'declaration-candidate',
                ];
            }
        }

        return [
            'schemaVersion' => 1,
            'projectRoot' => $projectRoot,
            'scope' => [
                'kind' => 'route-parameters',
                'evidence' => 'source-only',
                'effectiveRuntime' => false,
                'declarationCoverage' => 'bounded-positive-only',
            ],
            'declarations' => $declarations,
            'errors' => $errors,
            'truncated' => $reasons !== [],
            'truncationReasons' => array_keys($reasons),
        ];
    }

    /**
     * @param array<array-key, Node> $nodes
     * @param array{name: string, nameTokens: list<array{role: string, value: string, start: int, end: int, line: int}>,
     *   prefix: string, prefixTokens: list<array{role: string, value: string, start: int, end: int, line: int}>,
     *   domain: string|null, domainTokens: list<array{role: string, value: string, start: int, end: int, line: int}>} $context
     * @param array<string, true> $reasons
     * @return \Generator<int, array{name: string,
     *   nameTokens: non-empty-list<array{role: string, value: string, start: int, end: int, line: int}>,
     *   nameLeaf: array{role: string, value: string, start: int, end: int, line: int}, uri: string,
     *   uriTokens: non-empty-list<array{role: string, value: string, start: int, end: int, line: int}>,
     *   domain: string|null, domainTokens: list<array{role: string, value: string, start: int, end: int, line: int}>,
     *   parameters: list<array{name: string, source: string, declaredOptional: bool, optional: bool,
     *     bindingField: string|null, urlDefaultKey: string}>,
     *   defaults: list<array{key: string, valueKind: string, effective: bool,
     *     keyToken: array{role: string, value: string, start: int, end: int, line: int},
     *     valueToken: array{role: string, start: int, end: int, line: int}}>,
     *   defaultsComplete: bool, verb: string}>
     */
    private static function declarations(
        array $nodes,
        array $context,
        int $depth = 0,
        array &$reasons = [],
    ): \Generator {
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                yield from self::declarations($node->stmts, $context, $depth, $reasons);
                continue;
            }
            if (! $node instanceof Node\Stmt\Expression) {
                continue;
            }
            $group = self::group($node->expr);
            if ($group !== null) {
                if ($depth >= self::MAX_GROUP_DEPTH) {
                    $reasons['group-depth-limit'] = true;
                    continue;
                }
                $nested = self::mergeContext($context, $group);
                if (
                    self::composedBytes($nested['name'], $nested['prefix'], $nested['domain'])
                    > self::MAX_COMPOSED_BYTES
                ) {
                    $reasons['composed-value-byte-limit'] = true;
                    continue;
                }
                yield from self::declarations($group['callback']->stmts, $nested, $depth + 1, $reasons);
                continue;
            }
            $route = self::route($node->expr, $context);
            if (
                $route !== null
                && self::composedBytes($route['name'], $route['uri'], $route['domain']) <= self::MAX_COMPOSED_BYTES
            ) {
                yield $route;
            } elseif ($route !== null) {
                $reasons['composed-value-byte-limit'] = true;
            }
        }
    }

    /**
     * @param array{name: string, nameTokens: list<array{role: string, value: string, start: int, end: int, line: int}>,
     *   prefix: string, prefixTokens: list<array{role: string, value: string, start: int, end: int, line: int}>,
     *   domain: string|null, domainTokens: list<array{role: string, value: string, start: int, end: int, line: int}>} $context
     * @return array{name: string,
     *   nameTokens: non-empty-list<array{role: string, value: string, start: int, end: int, line: int}>,
     *   nameLeaf: array{role: string, value: string, start: int, end: int, line: int}, uri: string,
     *   uriTokens: non-empty-list<array{role: string, value: string, start: int, end: int, line: int}>,
     *   domain: string|null, domainTokens: list<array{role: string, value: string, start: int, end: int, line: int}>,
     *   parameters: list<array{name: string, source: string, declaredOptional: bool, optional: bool,
     *     bindingField: string|null, urlDefaultKey: string}>,
     *   defaults: list<array{key: string, valueKind: string, effective: bool,
     *     keyToken: array{role: string, value: string, start: int, end: int, line: int},
     *     valueToken: array{role: string, start: int, end: int, line: int}}>,
     *   defaultsComplete: bool, verb: string}|null
     */
    private static function route(Node\Expr $expression, array $context): ?array
    {
        [$root, $calls] = self::chain($expression);
        if (
            ! $root instanceof Node\Expr\StaticCall
            || ! self::routeFacadeCall($root)
            || ! $root->name instanceof Node\Identifier
            || ! in_array($verb = strtolower($root->name->toString()), self::VERBS, true)
            || ! self::positional($root->args, 2)
            || ! $root->args[0]->value instanceof Node\Scalar\String_
        ) {
            return null;
        }

        $rawUri = $root->args[0]->value;
        $uri = self::joinUri($context['prefix'], $rawUri->value);
        $uriTokens = [...$context['prefixTokens'], self::token($rawUri, 'route-uri')];
        [$uri, $bindingFields] = self::parseBindingFields($uri);
        $domain = $context['domain'];
        $domainTokens = $context['domainTokens'];
        if ($domain !== null) {
            [$domain, $domainFields] = self::parseBindingFields(self::normalizeDomain($domain));
            $bindingFields = array_merge($bindingFields, $domainFields);
        }
        $name = $context['name'];
        $nameTokens = $context['nameTokens'];
        $defaults = [];
        $defaultsComplete = true;

        foreach ($calls as $call) {
            if (! $call->name instanceof Node\Identifier) {
                return null;
            }
            $method = strtolower($call->name->toString());
            if ($method === 'name') {
                if (
                    $call->name->toString() !== 'name'
                    || ! self::positional($call->args, 1)
                    || ! $call->args[0]->value instanceof Node\Scalar\String_
                ) {
                    return null;
                }
                $literal = $call->args[0]->value;
                $name .= $literal->value;
                $nameTokens[] = self::token($literal, 'route-name');
                continue;
            }
            if ($method === 'domain') {
                if (
                    ! self::positional($call->args, 1)
                    || ! $call->args[0]->value instanceof Node\Scalar\String_
                ) {
                    return null;
                }
                $literal = $call->args[0]->value;
                [$domain, $domainFields] = self::parseBindingFields(self::normalizeDomain($literal->value));
                $bindingFields = array_merge($bindingFields, $domainFields);
                $domainTokens = [self::token($literal, 'route-domain')];
                continue;
            }
            if ($method === 'prefix') {
                if (
                    ! self::positional($call->args, 1)
                    || ! $call->args[0]->value instanceof Node\Scalar\String_
                ) {
                    return null;
                }
                $literal = $call->args[0]->value;
                $uri = self::joinUri($literal->value, $uri);
                $uriTokens = [self::token($literal, 'route-prefix'), ...$uriTokens];
                [$uri, $bindingFields] = self::parseBindingFields($uri);
                continue;
            }
            if ($method === 'defaults') {
                if (! self::positional($call->args, 2)) {
                    return null;
                }
                $key = $call->args[0]->value;
                if (! $key instanceof Node\Scalar\String_) {
                    $defaultsComplete = false;
                    continue;
                }
                $valueArgument = $call->args[1] ?? null;
                if (! $valueArgument instanceof Node\Arg) {
                    return null;
                }
                foreach ($defaults as &$entry) {
                    if ($entry['key'] === $key->value) {
                        $entry['effective'] = false;
                    }
                }
                unset($entry);
                $value = self::literalValue($valueArgument->value);
                $entry = [
                    'key' => $key->value,
                    'valueKind' => $value['kind'],
                    'effective' => true,
                    'keyToken' => self::token($key, 'default-key'),
                    'valueToken' => self::locationToken($valueArgument->value, 'default-value'),
                ];
                if (! $value['known']) {
                    $defaultsComplete = false;
                }
                $defaults[] = $entry;
                continue;
            }
            if (! in_array($method, self::NEUTRAL_ROUTE_METHODS, true)) {
                return null;
            }
        }

        if ($nameTokens === []) {
            return null;
        }
        $nameLeaf = $nameTokens[array_key_last($nameTokens)];

        return [
            'name' => $name,
            'nameTokens' => $nameTokens,
            'nameLeaf' => $nameLeaf,
            'uri' => $uri,
            'uriTokens' => $uriTokens,
            'domain' => $domain,
            'domainTokens' => $domainTokens,
            'parameters' => self::parameters($domain, $uri, $bindingFields),
            'defaults' => $defaults,
            'defaultsComplete' => $defaultsComplete,
            'verb' => $verb,
        ];
    }

    /**
     * @return array{name: string,
     *   nameTokens: list<array{role: string, value: string, start: int, end: int, line: int}>, hasName: bool,
     *   prefix: string, prefixTokens: list<array{role: string, value: string, start: int, end: int, line: int}>,
     *   hasPrefix: bool, domain: string|null,
     *   domainTokens: list<array{role: string, value: string, start: int, end: int, line: int}>,
     *   hasDomain: bool, callback: Node\Expr\Closure}|null
     */
    private static function group(Node\Expr $expression): ?array
    {
        $attributes = self::emptyGroup();
        if ($expression instanceof Node\Expr\StaticCall) {
            if (
                ! self::routeFacadeCall($expression)
                || ! $expression->name instanceof Node\Identifier
                || strtolower($expression->name->toString()) !== 'group'
            ) {
                return null;
            }
            if (
                self::positional($expression->args, 2)
                && $expression->args[0]->value instanceof Node\Expr\Array_
                && $expression->args[1]->value instanceof Node\Expr\Closure
            ) {
                $parsed = self::arrayGroup($expression->args[0]->value);
                if ($parsed === null) {
                    return null;
                }
                $attributes = $parsed;
                $callback = $expression->args[1]->value;
            } else {
                return null;
            }
        } elseif ($expression instanceof Node\Expr\MethodCall) {
            [$root, $calls] = self::chain($expression);
            $group = array_pop($calls);
            if (
                ! $group instanceof Node\Expr\MethodCall
                || ! $group->name instanceof Node\Identifier
                || strtolower($group->name->toString()) !== 'group'
                || ! self::positional($group->args, 1)
                || ! $group->args[0]->value instanceof Node\Expr\Closure
                || ! $root instanceof Node\Expr\StaticCall
                || ! self::routeFacadeCall($root)
                || ! $root->name instanceof Node\Identifier
            ) {
                return null;
            }
            $callback = $group->args[0]->value;
            $all = [$root, ...$calls];
            foreach ($all as $call) {
                if (! $call->name instanceof Node\Identifier) {
                    return null;
                }
                $method = strtolower($call->name->toString());
                $attributeName = match ($method) {
                    'scopebindings' => 'scopeBindings',
                    'withoutmiddleware' => 'withoutMiddleware',
                    'withoutscopedbindings' => 'withoutScopedBindings',
                    default => $method,
                };
                if ($call->name->toString() !== $attributeName) {
                    return null;
                }
                if (in_array($method, ['name', 'prefix', 'domain'], true)) {
                    if (
                        $method === 'name'
                        && $call->name->toString() !== 'name'
                        || ! self::positional($call->args, 1)
                        || ! $call->args[0]->value instanceof Node\Scalar\String_
                    ) {
                        return null;
                    }
                    $literal = $call->args[0]->value;
                    if ($method === 'name') {
                        $attributes['name'] = $literal->value;
                        $attributes['nameTokens'] = [self::token($literal, 'group-name')];
                        $attributes['hasName'] = true;
                    } elseif ($method === 'prefix') {
                        $attributes['prefix'] = $literal->value;
                        $attributes['prefixTokens'] = [self::token($literal, 'group-prefix')];
                        $attributes['hasPrefix'] = true;
                    } else {
                        $attributes['domain'] = $literal->value;
                        $attributes['domainTokens'] = [self::token($literal, 'group-domain')];
                        $attributes['hasDomain'] = true;
                    }
                } elseif (! in_array($method, self::NEUTRAL_GROUP_METHODS, true)) {
                    return null;
                }
            }
        } else {
            return null;
        }

        if ($callback->params !== [] || $callback->uses !== []) {
            return null;
        }
        $attributes['callback'] = $callback;

        return $attributes;
    }

    /**
     * @return array{name: string,
     *   nameTokens: list<array{role: string, value: string, start: int, end: int, line: int}>, hasName: bool,
     *   prefix: string, prefixTokens: list<array{role: string, value: string, start: int, end: int, line: int}>,
     *   hasPrefix: bool, domain: string|null,
     *   domainTokens: list<array{role: string, value: string, start: int, end: int, line: int}>, hasDomain: bool}|null
     */
    private static function arrayGroup(Node\Expr\Array_ $array): ?array
    {
        $attributes = self::emptyGroup();
        foreach ($array->items as $item) {
            if (
                $item->unpack
                || $item->byRef
                || ! $item->key instanceof Node\Scalar\String_
            ) {
                return null;
            }
            $key = $item->key->value;
            if (in_array($key, ['as', 'prefix', 'domain'], true)) {
                if (! $item->value instanceof Node\Scalar\String_) {
                    return null;
                }
                if ($key === 'as') {
                    $attributes['name'] = $item->value->value;
                    $attributes['nameTokens'] = [self::token($item->value, 'group-name')];
                    $attributes['hasName'] = true;
                } elseif ($key === 'prefix') {
                    $attributes['prefix'] = $item->value->value;
                    $attributes['prefixTokens'] = [self::token($item->value, 'group-prefix')];
                    $attributes['hasPrefix'] = true;
                } else {
                    $attributes['domain'] = $item->value->value;
                    $attributes['domainTokens'] = [self::token($item->value, 'group-domain')];
                    $attributes['hasDomain'] = true;
                }
            } elseif (! in_array($key, self::NEUTRAL_GROUP_KEYS, true)) {
                return null;
            }
        }

        return $attributes;
    }

    /** @return array{Node\Expr, list<Node\Expr\MethodCall>} */
    private static function chain(Node\Expr $expression): array
    {
        /** @var list<Node\Expr\MethodCall> $calls */
        $calls = [];
        while ($expression instanceof Node\Expr\MethodCall) {
            array_unshift($calls, $expression);
            $expression = $expression->var;
        }

        return [$expression, $calls];
    }

    /**
     * @return array{name: string,
     *   nameTokens: list<array{role: string, value: string, start: int, end: int, line: int}>,
     *   prefix: string, prefixTokens: list<array{role: string, value: string, start: int, end: int, line: int}>,
     *   domain: string|null, domainTokens: list<array{role: string, value: string, start: int, end: int, line: int}>}
     */
    private static function emptyContext(): array
    {
        return [
            'name' => '',
            'nameTokens' => [],
            'prefix' => '',
            'prefixTokens' => [],
            'domain' => null,
            'domainTokens' => [],
        ];
    }

    /**
     * @return array{name: string,
     *   nameTokens: list<array{role: string, value: string, start: int, end: int, line: int}>, hasName: bool,
     *   prefix: string, prefixTokens: list<array{role: string, value: string, start: int, end: int, line: int}>,
     *   hasPrefix: bool, domain: string|null,
     *   domainTokens: list<array{role: string, value: string, start: int, end: int, line: int}>, hasDomain: bool}
     */
    private static function emptyGroup(): array
    {
        return [
            'name' => '',
            'nameTokens' => [],
            'hasName' => false,
            'prefix' => '',
            'prefixTokens' => [],
            'hasPrefix' => false,
            'domain' => null,
            'domainTokens' => [],
            'hasDomain' => false,
        ];
    }

    /**
     * @param array{name: string,
     *   nameTokens: list<array{role: string, value: string, start: int, end: int, line: int}>,
     *   prefix: string, prefixTokens: list<array{role: string, value: string, start: int, end: int, line: int}>,
     *   domain: string|null, domainTokens: list<array{role: string, value: string, start: int, end: int, line: int}>} $context
     * @param array{name: string,
     *   nameTokens: list<array{role: string, value: string, start: int, end: int, line: int}>, hasName: bool,
     *   prefix: string, prefixTokens: list<array{role: string, value: string, start: int, end: int, line: int}>,
     *   hasPrefix: bool, domain: string|null,
     *   domainTokens: list<array{role: string, value: string, start: int, end: int, line: int}>,
     *   hasDomain: bool, callback?: Node\Expr\Closure} $group
     * @return array{name: string,
     *   nameTokens: list<array{role: string, value: string, start: int, end: int, line: int}>,
     *   prefix: string, prefixTokens: list<array{role: string, value: string, start: int, end: int, line: int}>,
     *   domain: string|null, domainTokens: list<array{role: string, value: string, start: int, end: int, line: int}>}
     */
    private static function mergeContext(array $context, array $group): array
    {
        if ($group['hasName']) {
            $context['name'] .= $group['name'];
            $context['nameTokens'] = [...$context['nameTokens'], ...$group['nameTokens']];
        }
        if ($group['hasPrefix']) {
            $context['prefix'] = self::joinPrefix($context['prefix'], $group['prefix']);
            $context['prefixTokens'] = [...$context['prefixTokens'], ...$group['prefixTokens']];
        }
        if ($group['hasDomain']) {
            $context['domain'] = $group['domain'];
            $context['domainTokens'] = $group['domainTokens'];
        }

        return $context;
    }

    /** @return array{string, array<string, string>} */
    private static function parseBindingFields(string $template): array
    {
        /** @var array{0: list<string>, 1: list<string>} $matches */
        $matches = [[], []];
        preg_match_all('/\{([\w\:]+?)\??\}/', $template, $matches);
        $fields = [];
        foreach ($matches[0] as $match) {
            if (! str_contains($match, ':')) {
                continue;
            }
            $segments = explode(':', trim($match, '{}?'));
            $fields[$segments[0]] = $segments[1];
            $template = str_replace(
                $match,
                '{'.$segments[0].(str_contains($match, '?') ? '?' : '').'}',
                $template,
            );
        }

        return [$template, $fields];
    }

    /**
     * @param array<string, string> $bindingFields
     * @return list<array{name: string, source: string, declaredOptional: bool, optional: bool,
     *   bindingField: string|null, urlDefaultKey: string}>
     */
    private static function parameters(?string $domain, string $uri, array $bindingFields): array
    {
        $parameters = [];
        foreach ([['domain', $domain], ['uri', $uri]] as [$source, $template]) {
            if ($template === null) {
                continue;
            }
            /** @var array{0: list<string>, 1: list<string>} $matches */
            $matches = [[], []];
            preg_match_all('/\{(.*?)\}/', $template, $matches);
            foreach ($matches[1] as $placeholder) {
                $name = trim($placeholder, '?');
                $bindingField = $bindingFields[$name] ?? null;
                $parameters[] = [
                    'name' => $name,
                    'source' => $source,
                    'declaredOptional' => str_ends_with($placeholder, '?'),
                    'optional' => $source === 'uri' && preg_match('/^\w+\?$/D', $placeholder) === 1,
                    'bindingField' => $bindingField,
                    'urlDefaultKey' => $bindingField === null ? $name : $name.':'.$bindingField,
                ];
            }
        }

        return $parameters;
    }

    /** @return array{known: bool, kind: string} */
    private static function literalValue(Node\Expr $expression): array
    {
        if ($expression instanceof Node\Scalar\String_) {
            return ['known' => true, 'kind' => 'string'];
        }
        if ($expression instanceof Node\Scalar\Int_) {
            return ['known' => true, 'kind' => 'int'];
        }
        if ($expression instanceof Node\Scalar\Float_) {
            return ['known' => true, 'kind' => 'float'];
        }
        if ($expression instanceof Node\Expr\ConstFetch) {
            $name = strtolower($expression->name->toString());
            if (in_array($name, ['true', 'false', 'null'], true)) {
                return ['known' => true, 'kind' => $name === 'null' ? 'null' : 'bool'];
            }
        }

        return ['known' => false, 'kind' => 'dynamic'];
    }

    private static function routeFacadeCall(Node\Expr\StaticCall $call): bool
    {
        return (
            $call->class instanceof Node\Name\FullyQualified
            && strtolower($call->class->toString()) === 'illuminate\\support\\facades\\route'
        );
    }

    private static function normalizeDomain(string $domain): string
    {
        return str_replace(['http://', 'https://'], '', $domain);
    }

    private static function joinPrefix(string $old, string $new): string
    {
        return trim($old, '/').'/'.trim($new, '/');
    }

    private static function joinUri(string $prefix, string $uri): string
    {
        return trim(trim($prefix, '/').'/'.trim($uri, '/'), '/') ?: '/';
    }

    private static function composedBytes(string $first, string $second, ?string $third): int
    {
        return strlen($first) + strlen($second) + strlen($third ?? '');
    }

    /** @return array{role: string, value: string, start: int, end: int, line: int} */
    private static function token(Node\Scalar\String_ $literal, string $role): array
    {
        return [
            'role' => $role,
            'value' => $literal->value,
            'start' => $literal->getStartFilePos(),
            'end' => $literal->getEndFilePos() + 1,
            'line' => $literal->getStartLine(),
        ];
    }

    /** @return array{role: string, start: int, end: int, line: int} */
    private static function locationToken(Node\Expr $expression, string $role): array
    {
        return [
            'role' => $role,
            'start' => $expression->getStartFilePos(),
            'end' => $expression->getEndFilePos() + 1,
            'line' => $expression->getStartLine(),
        ];
    }

    /**
     * @param array<array-key, Node\Arg|Node\VariadicPlaceholder> $args
     * @phpstan-assert-if-true list<Node\Arg> $args
     */
    private static function positional(array $args, int $count): bool
    {
        if (count($args) !== $count || ! array_is_list($args)) {
            return false;
        }
        foreach ($args as $arg) {
            if (! $arg instanceof Node\Arg || $arg->unpack || $arg->byRef || $arg->name !== null) {
                return false;
            }
        }

        return true;
    }

    /** @return array{code: string, file: ?string, message: string} */
    private static function error(string $code, ?string $file): array
    {
        return [
            'code' => $code,
            'file' => $file,
            'message' => match ($code) {
                'parse-failure' => 'Unable to parse static route source.',
                'source-limit' => 'Static route source byte limit exceeded.',
                'invalid-source' => 'Route source must be a project-relative PHP file contained in the project.',
                default => 'Unable to read static route source.',
            },
        ];
    }
}
