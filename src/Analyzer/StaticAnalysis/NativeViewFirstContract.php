<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;

/** Native fallback selection, including the helper used when no candidate matches. */
final class NativeViewFirstContract
{
    private ?bool $result = null;

    public function __construct(
        private readonly string $root,
    ) {}

    public function matches(Codebase $codebase): bool
    {
        return $this->result ??= $this->check($codebase);
    }

    private function check(Codebase $codebase): bool
    {
        if (! (new NativeViewFactoryContract($this->root))->matches($codebase)) {
            return false;
        }
        foreach (['is_null', 'is_array', 'array_find_key', 'value'] as $function) {
            if ($codebase->functionExists('Illuminate\\Support\\'.$function)) {
                return false;
            }
        }
        if (! ($codebase->getFunction('array_find_key')?->flags->contains(MetadataFlags::BUILTIN) ?? false)) {
            return false;
        }
        $source = new PhpSource($this->root);
        $reflection = new ModelReflection($codebase, $source);
        foreach ([
            [
                'Illuminate\\View\\Factory',
                'first',
                'View/Factory.php',
                'd85efed508598bb7db7a280bc495fe3c55815a688fe045e0e3ed448a8446ee06',
            ],
            [
                'Illuminate\\View\\Factory',
                'exists',
                'View/Factory.php',
                'f280396350b3e2c0740663ac902b3e9aed070787f8f74d6611b6d1428d5559a4',
            ],
            [
                'Illuminate\\Support\\Arr',
                'first',
                'Collections/Arr.php',
                '5e61953b981c91c92723ba2f7aba9163b6b9765415678ea19edccc54ae60662a',
            ],
            [
                'Illuminate\\Support\\Arr',
                'from',
                'Collections/Arr.php',
                'ea663323bb49b41e6dd951a73ac502b071aaf3e13e5886a2c5c5e6ba6027a6ba',
            ],
        ] as [$class, $name, $suffix, $hash]) {
            $method = $codebase->getDeclaringMethod($class, $name);
            if (
                $method === null
                || $method->identifier->class !== $class
                || ! self::nativeFile($method->location->file, $suffix)
            ) {
                return false;
            }
            $node = $reflection->methodNode($method);
            if ($node === null || self::fingerprint($node) !== $hash) {
                return false;
            }
        }
        $arr = $codebase->getClassLike('Illuminate\\Support\\Arr');
        $path = $arr?->location->file;
        if ($path === null) {
            return false;
        }
        $declaration = (new NodeFinder)->findFirstInstanceOf($source->read($path) ?? [], Node\Stmt\Class_::class);
        if (! $declaration instanceof Node\Stmt\Class_ || $declaration->getDocComment() !== null) {
            return false;
        }
        $function = $codebase->getFunction('value');
        $path = $function?->location->file;
        if ($path === null || ! self::nativeFile($path, 'Collections/helpers.php')) {
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

    private static function nativeFile(?string $path, string $suffix): bool
    {
        return str_ends_with(str_replace('\\', '/', $path ?? ''), '/laravel/framework/src/Illuminate/'.$suffix);
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
}
