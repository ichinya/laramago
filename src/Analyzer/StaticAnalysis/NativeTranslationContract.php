<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;

/** Audited native translation lookup and facade forwarding, without executing Laravel. */
final class NativeTranslationContract
{
    private readonly PhpSource $source;
    private ?bool $matched = null;

    public function __construct(string $root = '.')
    {
        $this->source = new PhpSource($root);
    }

    public function matches(Codebase $codebase): bool
    {
        return $this->matched ??= $this->inspect($codebase);
    }

    private function inspect(Codebase $codebase): bool
    {
        foreach ([
            'Illuminate\\Translation' => ['is_null', 'is_array', 'is_string', 'array_filter', 'strtr'],
            'Illuminate\\Support' => [
                'explode',
                'str_contains',
                'array_key_exists',
                'count',
                'array_shift',
                'implode',
                'is_array',
            ],
        ] as $namespace => $functions) {
            foreach ($functions as $function) {
                if ($codebase->functionExists($namespace.'\\'.$function)) {
                    return false;
                }
            }
        }
        foreach ([
            'Illuminate\\Translation\\Translator' => '8a454724fad83e618a38e326ff59cf96e51adc2e2749905b7a257a217e715f8f',
            'Illuminate\\Support\\NamespacedItemResolver' => '626dba8a3c13d4cafa8c45891d5b87685f64032c5bec51b8d0cebc8110d35c4e',
            'Illuminate\\Support\\Facades\\Lang' => '6773dd9d29eb95d944ad45316a22e51d24297563533ca60b6e71fd279629f1cb',
        ] as $class => $hash) {
            $path = $codebase->getClassLike($class)?->location->file;
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
            if (! $declaration instanceof Node\Stmt\Class_ || self::fingerprint($declaration) !== $hash) {
                return false;
            }
        }
        $reflection = new ModelReflection($codebase, $this->source);
        foreach ([
            [
                'Illuminate\\Support\\Facades\\Facade',
                '__callStatic',
                '8813e8c2f49c311d7a726a5e2715730809f4691de133e99cec786ed30b7295a0',
            ],
            [
                'Illuminate\\Support\\Facades\\Facade',
                'getFacadeRoot',
                '6ac85dc54ed317a1024b3fd2f590266b39faa66d40d2c654e8a3ae052d6d1709',
            ],
            [
                'Illuminate\\Support\\Facades\\Facade',
                'resolveFacadeInstance',
                '81fef13fdf551d0ce6cb37e495816d59886a034375a34ccc1630a6f48c854e4e',
            ],
            ['Illuminate\\Support\\Arr', 'get', 'bfa2749397ff12d24a59b65dbcded66247d5ffe79cbc7eadd332bb77de0c7c0e'],
            ['Illuminate\\Support\\Arr', 'exists', '83f0d73d7c5a44e15a57e67dea6fa872c65823e2d809d5449865473c632dbcb3'],
            [
                'Illuminate\\Support\\Arr',
                'accessible',
                '081a7249bd94809155f4dee15abb671c6fdeb8ba39e14103d67fae0f548433c7',
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

        return true;
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
            '/laravel/framework/src/'
            .str_replace('Support/Arr', 'Collections/Arr', str_replace('\\', '/', $class))
            .'.php',
        );
    }
}
