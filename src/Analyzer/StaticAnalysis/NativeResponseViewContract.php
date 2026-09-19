<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;

/** Audited native forwarding only; changed declarations or bodies defer. */
final class NativeResponseViewContract
{
    private const MEMBERS = [
        '__construct' => 'f8e4014ddb0f4b34935692245a190b8ba97898edce4e18844c9005bdd0d87962',
        'view' => 'fd5ccaa23e39ae1db11d16ca93b8dce0ee649ce82a31276d3c87e006daeca687',
        'make' => '84d5247ac009df7ad0badce6ada8793668469bcee1df80123fbc07609d8b61d8',
    ];
    private const HELPERS = [
        'response' => '020cbab6f5f6d4f22fe589d4ff839cea6a2154efb1185b58d939d6c3ce0d7912',
        'app' => '367bb46d5732d1cbd0c71f4c0add05ec208f0eb107bf4ddaa3aa15d03b4ff597',
    ];

    public function __construct(
        private readonly string $root,
    ) {}

    public function matches(Codebase $codebase): bool
    {
        $class = 'Illuminate\\Routing\\ResponseFactory';
        $metadata = $codebase->getClass($class);
        if (
            $metadata === null
            || $metadata->directParentClass !== null
            || $metadata->hasIncompleteHierarchy()
            || $metadata->pseudoMethods !== []
            || $metadata->mixins !== []
            || $codebase->functionExists('Illuminate\\Routing\\is_array')
        ) {
            return false;
        }
        $source = new PhpSource($this->root);
        $path = $metadata->location->file;
        if ($path === null) {
            return false;
        }
        $nodes = $source->read(str_starts_with($path, '//?/') ? substr($path, 4) : $path);
        $declaration = (new NodeFinder)->findFirstInstanceOf($nodes ?? [], Node\Stmt\Class_::class);
        if (
            ! $declaration instanceof Node\Stmt\Class_
            || $declaration->namespacedName?->toString() !== $class
            || $declaration->getDocComment() !== null
        ) {
            return false;
        }
        $reflection = new ModelReflection($codebase, $source);
        foreach (self::MEMBERS as $name => $expected) {
            $method = $codebase->getDeclaringMethod($class, $name);
            if (
                $method === null
                || $method->identifier->class !== $class
                || ! self::frameworkFile($method->location->file, 'Routing/ResponseFactory.php')
            ) {
                return false;
            }
            $node = $reflection->methodNode($method);
            if ($node === null || self::fingerprint($node) !== $expected) {
                return false;
            }
        }

        return true;
    }

    public function helpersMatch(Codebase $codebase): bool
    {
        $source = new PhpSource($this->root);
        foreach (self::HELPERS as $name => $expected) {
            $function = $codebase->getFunction($name);
            $path = $function?->location->file;
            if ($path === null || ! self::frameworkFile($path, 'Foundation/helpers.php')) {
                return false;
            }
            $nodes = $source->read(str_starts_with($path, '//?/') ? substr($path, 4) : $path);
            $node = (new NodeFinder)->findFirst(
                $nodes ?? [],
                static fn (Node $node): bool => $node instanceof Node\Stmt\Function_ && $node->name->name === $name,
            );
            if ($node === null || self::fingerprint($node) !== $expected) {
                return false;
            }
        }

        return true;
    }

    private static function frameworkFile(?string $path, string $suffix): bool
    {
        return str_ends_with(str_replace('\\', '/', $path ?? ''), '/laravel/framework/src/Illuminate/'.$suffix);
    }

    private static function fingerprint(Node $node): string
    {
        $normalized = '';
        foreach (token_get_all('<?php '.(new Standard)->prettyPrint([$node])) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT], true)) {
                    continue;
                }
                $normalized .= str_replace(["\r\n", "\r"], "\n", $token[1]);
            } else {
                $normalized .= $token;
            }
        }

        return hash('sha256', $normalized);
    }
}
