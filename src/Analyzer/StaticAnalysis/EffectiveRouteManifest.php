<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Ichinya\Laramago\Metadata\RouteMetadataExport;

/** An asserted final collection, never inferred from route source order. */
final class EffectiveRouteManifest
{
    public readonly bool $enabled;
    /** @var list<array{name: string, file: string, start: int, end: int, contentHash: string, firstLocation: array{file: string, start: int, end: int, contentHash: string}}> */
    public readonly array $conflicts;

    public function __construct(string $root)
    {
        $conflicts = $this->read($root);
        $this->enabled = $conflicts !== null;
        $this->conflicts = $conflicts ?? [];
    }

    /** @return list<array{name: string, file: string, start: int, end: int, contentHash: string, firstLocation: array{file: string, start: int, end: int, contentHash: string}}>|null */
    private function read(string $root): ?array
    {
        $text = @file_get_contents(rtrim($root, '/\\').'/composer.json');
        /** @var mixed $composer */
        $composer = $text === false ? null : json_decode($text, true);
        /** @var mixed $policy */
        $policy = is_array($composer) ? $composer['extra']['laramago']['effective-route-manifest'] ?? null : null;
        if (
            ! is_array($policy)
            || ($policy['native-collection'] ?? null) !== true
            || ($policy['final-surviving-routes'] ?? null) !== true
            || ($policy['selection-complete'] ?? null) !== true
            || ! in_array($policy['cache-selection'] ?? null, ['cached', 'uncached'], true)
            || array_diff(array_keys($policy), [
                'native-collection',
                'final-surviving-routes',
                'selection-complete',
                'cache-selection',
                'routes',
            ]) !== []
        ) {
            return null;
        }
        /** @var mixed $routes */
        $routes = $policy['routes'] ?? null;
        if (! is_array($routes) || ! array_is_list($routes) || count($routes) > 256) {
            return null;
        }
        $files = [];
        $identities = [];
        $locations = [];
        /** @var mixed $route */
        foreach ($routes as $route) {
            if (! is_array($route) || array_diff(array_keys($route), [
                'name',
                'methods',
                'domain',
                'uri',
                'file',
                'start',
                'contentHash',
            ]) !== [] || ! is_string($route['name'] ?? null) || $route['name'] === '' || ! is_string($route['domain'] ?? null) || ! is_string($route['uri'] ?? null) || $route['uri'] === '' || ! is_string($route['file'] ?? null) || $route['file'] === '' || ! is_int($route['start'] ?? null) || $route['start'] < 0 || ! is_string($route['contentHash'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $route['contentHash']) !== 1 || ! is_array($route['methods'] ?? null) || ! array_is_list($route['methods']) || $route['methods'] === []) {
                return null;
            }
            $methods = [];
            /** @var mixed $method */
            foreach ($route['methods'] as $method) {
                if (! is_string($method) || preg_match('/^[A-Z]+$/D', $method) !== 1 || isset($methods[$method])) {
                    return null;
                }
                $methods[$method] = true;
                // Defer overlapping dispatch keys rather than interpreting replacement order.
                $identity = json_encode([$method, $route['domain'].$route['uri']]);
                if ($identity === false || isset($identities[$identity])) {
                    return null;
                }
                $identities[$identity] = true;
            }
            $file = str_replace('\\', '/', $route['file']);
            if (str_starts_with($file, '/') || str_contains($file, ':') || in_array('..', explode('/', $file), true)) {
                return null;
            }
            $absolute = realpath(rtrim($root, '/\\').'/'.$file);
            if ($absolute === false) {
                return null;
            }
            $key = str_replace('\\', '/', $absolute).':'.$route['start'];
            if (isset($locations[$key])) {
                return null;
            }
            $locations[$key] = $route;
            $files[$file] = true;
        }
        /** @var array{declarations: list<array{name: string, file: string, start: int, end: int, contentHash: string}>, errors: array<array-key, mixed>, truncated: bool} $metadata */
        $metadata = (new RouteMetadataExport)->export($root, array_keys($files));
        if ($metadata['truncated'] || $metadata['errors'] !== []) {
            return null;
        }
        $matched = [];
        foreach ($metadata['declarations'] as $declaration) {
            $key = $declaration['file'].':'.$declaration['start'];
            $route = $locations[$key] ?? null;
            if (
                $route !== null
                && $route['name'] === $declaration['name']
                && $route['contentHash'] === $declaration['contentHash']
            ) {
                $matched[$key] = $declaration;
            }
        }
        if (count($matched) !== count($locations)) {
            return null;
        }
        $first = [];
        $conflicts = [];
        foreach ($locations as $key => $_route) {
            $declaration = $matched[$key];
            $location = [
                'file' => $declaration['file'],
                'start' => $declaration['start'],
                'end' => $declaration['end'],
                'contentHash' => $declaration['contentHash'],
            ];
            if (isset($first[$declaration['name']])) {
                $conflicts[] = [
                    'name' => $declaration['name'],
                    ...$location,
                    'firstLocation' => $first[$declaration['name']],
                ];
            } else {
                $first[$declaration['name']] = $location;
            }
        }

        return $conflicts;
    }
}
