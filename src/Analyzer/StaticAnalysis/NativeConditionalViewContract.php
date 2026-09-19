<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard;

/** Audited forwarding and branch selection for native conditional view rendering. */
final class NativeConditionalViewContract
{
    private readonly PhpSource $source;
    private readonly NativeViewFactoryContract $factory;

    public function __construct(string $root = '.')
    {
        $this->source = new PhpSource($root);
        $this->factory = new NativeViewFactoryContract($root);
    }

    public function matches(Codebase $codebase): bool
    {
        if (! $this->factory->matches($codebase)) {
            return false;
        }
        foreach (['count', 'str_starts_with', 'substr'] as $function) {
            if ($codebase->functionExists('Illuminate\\View\\'.$function)) {
                return false;
            }
        }
        $reflection = new ModelReflection($codebase, $this->source);
        // Laravel native methods, retained in the licensed view-reference fixtures.
        foreach ([
            [
                'Illuminate\\View\\Factory',
                'renderWhen',
                'b54b1fd36073f756a15e5a985af3dd0ef09794fada64f223b490fca3c166006e',
            ],
            [
                'Illuminate\\View\\Factory',
                'renderUnless',
                '7687ddd44d2bd76dafe15270b21b59d2ef5555323d902caa748f4bfd5a42f49b',
            ],
            [
                'Illuminate\\View\\Factory',
                'renderEach',
                '4383a4c682954857086137b9dfd5053994dcac7369cdee080dbfd0c8046090e0',
            ],
            [
                'Illuminate\\View\\Factory',
                'parseData',
                '0daa82b72d520375da55fbd3e8ee5064a38bda9174082eacc32619b3786fc8a9',
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
            '/laravel/framework/src/'.str_replace('\\', '/', $class).'.php',
        );
    }
}
