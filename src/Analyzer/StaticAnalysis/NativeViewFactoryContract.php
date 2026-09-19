<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;

/** Audited native name normalization and required finder lookup, never runtime factory execution. */
final class NativeViewFactoryContract
{
    private readonly PhpSource $source;

    public function __construct(string $root = '.')
    {
        $this->source = new PhpSource($root);
    }

    public function matches(Codebase $codebase): bool
    {
        foreach (['str_contains', 'str_replace', 'explode'] as $function) {
            if ($codebase->functionExists('Illuminate\\View\\'.$function)) {
                return false;
            }
        }
        // Class PHPDoc can replace an existing method contract without appearing in pseudoMethods.
        foreach (['Illuminate\\View\\Factory', 'Illuminate\\View\\ViewName'] as $class) {
            $metadata = $codebase->getClassLike($class);
            $path = $metadata?->location->file;
            if ($path === null || ! self::nativeFile($path, $class)) {
                return false;
            }
            $nodes = $this->source->read($path);
            $declaration = (new NodeFinder)->findFirst(
                $nodes ?? [],
                static fn (Node $node): bool => (
                    $node instanceof Node\Stmt\Class_
                    && $node->namespacedName?->toString() === $class
                ),
            );
            if (! $declaration instanceof Node\Stmt\Class_ || $declaration->getDocComment() !== null) {
                return false;
            }
        }
        $reflection = new ModelReflection($codebase, $this->source);
        // Laravel native methods, retained in the licensed view-reference fixtures.
        foreach ([
            ['Illuminate\\View\\Factory', 'make', 'b90ec030f88cf9a97fcc5c5b7e7333e3450f62bd9273488a6cd75e48ac194e1a'],
            [
                'Illuminate\\View\\Factory',
                'normalizeName',
                '69f1a9b5935e6cccdb075f24d4ebd5979cdfb5f69c6e85c62a3c0b742f33be7d',
            ],
            [
                'Illuminate\\View\\ViewName',
                'normalize',
                '6157c45236b2b5e44d06d1493db7a7f4d27b45cfd9fd5b852ed5441777832115',
            ],
        ] as [$class, $name, $hash]) {
            $method = $codebase->getDeclaringMethod($class, $name);
            if (
                $method === null
                || $method->identifier->class !== $class
                || ! self::nativeFile($method->location->file, $class)
            ) {
                return false;
            }
            $node = $reflection->methodNode($method);
            if ($node === null || self::fingerprint($node) !== $hash) {
                return false;
            }
        }
        $interface = $codebase->getClassLike('Illuminate\\View\\ViewFinderInterface');
        $path = $interface?->location->file;
        if (! self::nativeFile($path, 'Illuminate\\View\\ViewFinderInterface')) {
            return false;
        }
        $nodes = $path === null ? null : $this->source->read($path);
        $declaration = (new NodeFinder)->findFirstInstanceOf($nodes ?? [], Node\Stmt\Interface_::class);
        if (
            ! $declaration instanceof Node\Stmt\Interface_
            || $declaration->namespacedName?->toString() !== 'Illuminate\\View\\ViewFinderInterface'
        ) {
            return false;
        }
        foreach ($declaration->getConstants() as $constant) {
            foreach ($constant->consts as $value) {
                if ($value->name->name === 'HINT_PATH_DELIMITER') {
                    return $value->value instanceof Node\Scalar\String_ && $value->value->value === '::';
                }
            }
        }

        return false;
    }

    private static function fingerprint(Node $node): string
    {
        $normalized = '';
        foreach (token_get_all('<?php '.(new Standard)->prettyPrint([$node])) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_OPEN_TAG], true)) {
                    continue;
                }
                $normalized .= str_replace(["\r\n", "\r"], "\n", $token[1]);
            } else {
                $normalized .= $token;
            }
        }

        return hash('sha256', $normalized);
    }

    private static function nativeFile(?string $path, string $class): bool
    {
        return str_ends_with(
            str_replace('\\', '/', $path ?? ''),
            '/laravel/framework/src/'.str_replace('\\', '/', $class).'.php',
        );
    }
}
