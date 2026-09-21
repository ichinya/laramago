<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/** Fail closed when native Route middleware registration semantics differ. */
final class NativeRouteMiddlewareContract
{
    private ?bool $matches = null;

    public function __construct(
        private readonly string $root = '.',
    ) {}

    public function matches(Codebase $codebase): bool
    {
        if ($this->matches !== null) {
            return $this->matches;
        }
        foreach (['is_null', 'is_array', 'func_get_args', 'array_merge'] as $function) {
            if ($codebase->functionExists('Illuminate\\Routing\\'.$function)) {
                return $this->matches = false;
            }
        }
        $method = $codebase->getDeclaringMethod('Illuminate\\Routing\\Route', 'middleware');
        if (
            $method === null
            || $method->identifier->class !== 'Illuminate\\Routing\\Route'
            || $method->static
            || ! str_ends_with(
                str_replace('\\', '/', $method->location->file ?? ''),
                '/laravel/framework/src/Illuminate/Routing/Route.php',
            )
        ) {
            return $this->matches = false;
        }
        $actual = (new ModelReflection($codebase, new PhpSource($this->root)))->methodNode($method);
        $expected = $this->expected();

        return $this->matches =
            $actual !== null && $expected !== null && $this->fingerprint($actual) === $this->fingerprint($expected);
    }

    private function expected(): ?Node\Stmt\ClassMethod
    {
        $source = <<<'PHP'
            <?php
            namespace Illuminate\Routing;
            class Route
            {
                public function middleware($middleware = null)
                {
                    if (is_null($middleware)) {
                        return (array) ($this->action['middleware'] ?? []);
                    }
                    if (! is_array($middleware)) {
                        $middleware = func_get_args();
                    }
                    foreach ($middleware as $index => $value) {
                        $middleware[$index] = (string) $value;
                    }
                    $this->action['middleware'] = array_merge(
                        (array) ($this->action['middleware'] ?? []), $middleware
                    );
                    return $this;
                }
            }
            PHP;
        $nodes = (new ParserFactory)
            ->createForNewestSupportedVersion()
            ->parse($source) ?? [];
        $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes);

        return (new NodeFinder)->findFirstInstanceOf($nodes, Node\Stmt\ClassMethod::class);
    }

    private function fingerprint(Node\Stmt\ClassMethod $method): string
    {
        $normalized = '';
        foreach (token_get_all('<?php '.(new Standard)->prettyPrint([$method])) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
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
