<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;

/** Native Mailer forwarding; custom declarations and helper shadowing defer. */
final class NativeMailRenderContract
{
    private const MEMBERS = [
        'render' => 'bfd7bba1a17c5710ae0994f0302eb17f583e5b373fb5767e8b3e6b16f65aa243',
        'parseView' => '159d2e65e965610d397294c39223b09056107ba1fea7988249d2df71d30d0343',
        'renderView' => '8694e07f7fdb50a72cfe3d807299c2164655fe2be95000cd2ae43b467f6f8472',
    ];

    public function __construct(
        private readonly string $root,
    ) {}

    public function matches(Codebase $codebase): bool
    {
        $class = 'Illuminate\\Mail\\Mailer';
        $metadata = $codebase->getClass($class);
        if (
            $metadata === null
            || $metadata->directParentClass !== null
            || $metadata->hasIncompleteHierarchy()
            || $metadata->pseudoMethods !== []
            || $metadata->mixins !== []
        ) {
            return false;
        }
        foreach (['is_string', 'is_array', 'value'] as $function) {
            if ($codebase->functionExists('Illuminate\\Mail\\'.$function)) {
                return false;
            }
        }
        $source = new PhpSource($this->root);
        $path = $metadata->location->file;
        if ($path === null) {
            return false;
        }
        $nodes = $source->read($path);
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
        $reflection = new ModelReflection($codebase, $source);
        foreach (self::MEMBERS as $name => $expected) {
            $method = $codebase->getDeclaringMethod($class, $name);
            if (
                $method === null
                || $method->identifier->class !== $class
                || ! self::frameworkFile($method->location->file, 'Mail/Mailer.php')
            ) {
                return false;
            }
            $node = $reflection->methodNode($method);
            if ($node === null || self::fingerprint($node) !== $expected) {
                return false;
            }
        }
        $helper = $codebase->getFunction('value');
        $path = $helper?->location->file;
        if ($path === null || ! self::frameworkFile($path, 'Collections/helpers.php')) {
            return false;
        }
        $node = (new NodeFinder)->findFirst(
            $source->read($path) ?? [],
            static fn (Node $node): bool => $node instanceof Node\Stmt\Function_ && $node->name->name === 'value',
        );

        return (
            $node !== null
            && self::fingerprint($node) === '2460f0125f5d7a1c00c22eb46813d6f0dbc5f44bd043cf53433a1125c42df73e'
        );
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
