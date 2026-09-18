<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type\Visibility;
use PhpParser\Node;
use PhpParser\NodeFinder;

/** Installed native methods required before signed calls can consult a route catalog. */
final class SignedRouteMethods
{
    private readonly PhpSource $source;

    public function __construct(string $root)
    {
        $this->source = new PhpSource($root);
    }

    /** @return list<string> */
    public static function parameters(string $method, bool $redirect): array
    {
        $name = $redirect ? 'route' : 'name';
        $tail = $redirect ? ['status', 'headers'] : ['absolute'];

        return (
            $method === 'signedroute'
                ? [$name, 'parameters', 'expiration', ...$tail]
                : [$name, 'expiration', 'parameters', ...$tail]
        );
    }

    public function native(Codebase $codebase, string $method, bool $redirect): bool
    {
        $methods = ['route', 'signedRoute'];
        if ($method === 'temporarysignedroute') {
            $methods[] = 'temporarySignedRoute';
        }
        foreach ($methods as $name) {
            if (! $this->declaration($codebase, 'Illuminate\\Routing\\UrlGenerator', $name)) {
                return false;
            }
        }

        return ! $redirect || $this->declaration($codebase, 'Illuminate\\Routing\\Redirector', $method);
    }

    private function declaration(Codebase $codebase, string $class, string $name): bool
    {
        $method = $codebase->getDeclaringMethod($class, $name);
        if (
            $method === null
            || $method->static
            || $method->abstract
            || $method->visibility !== Visibility::Public
            || $method->flags->contains(MetadataFlags::BY_REFERENCE)
            || strcasecmp($method->identifier->class ?? '', $class) !== 0
            || ! str_ends_with(
                str_replace('\\', '/', $method->location->file ?? ''),
                '/laravel/framework/src/'.str_replace('\\', '/', $class).'.php',
            )
        ) {
            return false;
        }
        $redirect = $class === 'Illuminate\\Routing\\Redirector';
        $parameters = strtolower($name) === 'route'
            ? ($redirect ? ['route', 'parameters', 'status', 'headers'] : ['name', 'parameters', 'absolute'])
            : self::parameters(strtolower($name), $redirect);

        if (
            array_map(static fn ($parameter): string => $parameter->name, $method->parameters) !== array_map(
                static fn (string $parameter): string => '$'.$parameter,
                $parameters,
            )
        ) {
            return false;
        }
        $file = $method->location->file ?? '';
        $node = (new NodeFinder)->findFirst(
            $this->source->read(str_starts_with($file, '//?/') ? substr($file, 4) : $file) ?? [],
            static fn (Node $node): bool => (
                $node instanceof Node\Stmt\Class_
                && strcasecmp($node->namespacedName?->toString() ?? '', $class) === 0
            ),
        );
        $declaration = $node instanceof Node\Stmt\Class_ ? $node->getMethod($name) : null;
        if ($declaration === null || $declaration->byRef || count($declaration->params) !== count($parameters)) {
            return false;
        }
        $defaults = ['parameters' => [], 'absolute' => true, 'status' => 302, 'headers' => []];
        if (strtolower($name) === 'signedroute') {
            $defaults['expiration'] = null;
        }
        foreach ($declaration->params as $offset => $parameter) {
            $parameterName = $parameters[$offset];
            if (
                $parameter->byRef
                || $parameter->variadic
                || ! $parameter->var instanceof Node\Expr\Variable
                || $parameter->var->name !== $parameterName
                || ($parameter->default !== null) !== array_key_exists($parameterName, $defaults)
                || $parameter->default !== null
                && PhpSource::value($parameter->default) !== $defaults[$parameterName]
            ) {
                return false;
            }
        }

        return true;
    }
}
