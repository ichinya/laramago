<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;

/** Audited native Gate entry points; changed methods or facade PHPDoc defer. */
final class NativeGateAbilityContract
{
    public const FACADE = 'Illuminate\\Support\\Facades\\Gate';
    public const GATE = 'Illuminate\\Auth\\Access\\Gate';
    public const ACCESSOR = 'Illuminate\\Contracts\\Auth\\Access\\Gate';

    /** Laravel framework 7c75fbf. */
    private const METHODS = [
        'allows' => '1266b39f1b1588dd57cf5a851fa94f4664fc5fb4c4f0c757453664e61622316e',
        'denies' => 'ce2ab9042a4f26b670b7b70356771539e7d888dbfa687cc748f9b2f1fced0ebe',
        'check' => '0047c0e700dd2ee5a0c21039c1ed08a517763abedfb69ca3e1be68f35d16806e',
        'any' => '98a334301849b1efc93dbf9e01a94f29eddf89f6ea203f3d80ddbe4461deb5b9',
        'none' => '4c3b46a8a822c1bcb6eded523a685e26b8d13ce8dcb5f6cf94744d80200e67e5',
        'authorize' => '94faa67a50c1be450c8f1b39c7f456f49494835dc448eb0561efa9db97319ccc',
        'inspect' => '2e655b43d2331bdcda31a1150c271dd6bd21dffb03c74c5f7e4bccd6c4e23a67',
        'raw' => '38b8d4013b440bd8f33feb3fd9677516520a1accaeecb4ac1f7d267befd38aeb',
    ];
    private const FACADE_HASH = '77e80c010404d56d6432fa2d3dabc11017a0011e3dc8817a2abd71640a108758';

    private readonly PhpSource $source;
    private ?bool $methodsMatch = null;
    private ?bool $facadeMatches = null;

    public function __construct(string $root)
    {
        $this->source = new PhpSource($root);
    }

    public static function supports(string $method): bool
    {
        return array_key_exists(strtolower($method), self::METHODS);
    }

    public static function abilityParameter(string $method): string
    {
        return in_array(strtolower($method), ['check', 'any', 'none'], true) ? 'abilities' : 'ability';
    }

    public function matchesMethods(Codebase $codebase): bool
    {
        if ($this->methodsMatch !== null) {
            return $this->methodsMatch;
        }
        $metadata = $codebase->getClassLike(self::GATE);
        $path = $metadata?->location->file;
        if (
            $path === null
            || ! self::frameworkFile($path, 'Auth/Access/Gate.php')
        ) {
            return $this->methodsMatch = false;
        }
        $nodes = $this->source->read(str_starts_with($path, '//?/') ? substr($path, 4) : $path);
        $class = (new NodeFinder)->findFirst(
            $nodes ?? [],
            static fn (Node $node): bool => (
                $node instanceof Node\Stmt\Class_
                && $node->namespacedName?->toString() === self::GATE
            ),
        );
        if (! $class instanceof Node\Stmt\Class_ || $class->getDocComment() !== null) {
            return $this->methodsMatch = false;
        }
        foreach (self::METHODS as $name => $expected) {
            $node = $class->getMethod($name);
            if ($node === null || self::fingerprint($node) !== $expected) {
                return $this->methodsMatch = false;
            }
        }

        return $this->methodsMatch = true;
    }

    public function matchesFacade(Codebase $codebase): bool
    {
        if ($this->facadeMatches !== null) {
            return $this->facadeMatches;
        }
        $metadata = $codebase->getClassLike(self::FACADE);
        $path = $metadata?->location->file;
        if ($path === null || ! self::frameworkFile($path, 'Support/Facades/Gate.php')) {
            return $this->facadeMatches = false;
        }
        $nodes = $this->source->read(str_starts_with($path, '//?/') ? substr($path, 4) : $path);
        $class = (new NodeFinder)->findFirst(
            $nodes ?? [],
            static fn (Node $node): bool => (
                $node instanceof Node\Stmt\Class_
                && $node->namespacedName?->toString() === self::FACADE
            ),
        );

        return $this->facadeMatches =
            $class instanceof Node\Stmt\Class_
            && self::fingerprint($class) === self::FACADE_HASH
            && $this->matchesMethods($codebase);
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

    private static function frameworkFile(?string $path, string $suffix): bool
    {
        return str_ends_with(str_replace('\\', '/', $path ?? ''), '/laravel/framework/src/Illuminate/'.$suffix);
    }
}
