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

/** Audited native stored-view identity assertion; no finder lookup is implied. */
final class NativeAssertViewIsContract
{
    public const TEST_RESPONSE = 'Illuminate\\Testing\\TestResponse';

    private ?bool $matches = null;

    public function __construct(
        private readonly string $root = '.',
    ) {}

    public function matches(Codebase $codebase): bool
    {
        if ($this->matches !== null) {
            return $this->matches;
        }
        $reflection = new ModelReflection($codebase, new PhpSource($this->root));
        $expected = $this->expected();
        if ($expected === null) {
            return $this->matches = false;
        }
        foreach ($expected as $name => $node) {
            $method = $codebase->getDeclaringMethod(self::TEST_RESPONSE, $name);
            if (
                $method === null
                || $method->identifier->class !== self::TEST_RESPONSE
                || ! str_ends_with(
                    str_replace('\\', '/', $method->location->file ?? ''),
                    '/laravel/framework/src/Illuminate/Testing/TestResponse.php',
                )
            ) {
                return $this->matches = false;
            }
            $actual = $reflection->methodNode($method);
            if ($actual === null || self::fingerprint($actual) !== self::fingerprint($node)) {
                return $this->matches = false;
            }
        }

        return $this->matches = true;
    }

    /** @return array<string, Node\Stmt\ClassMethod>|null */
    private function expected(): ?array
    {
        $source = <<<'PHP'
            <?php
            namespace Illuminate\Testing;
            use Illuminate\Contracts\View\View;
            use Illuminate\Testing\TestResponseAssert as PHPUnit;
            class TestResponse
            {
                public function assertViewIs($value)
                {
                    $this->ensureResponseHasView();
                    PHPUnit::withResponse($this)->assertEquals($value, $this->original->name());
                    return $this;
                }

                protected function ensureResponseHasView()
                {
                    if (! $this->responseHasView()) {
                        return PHPUnit::withResponse($this)->fail('The response is not a view.');
                    }
                    return $this;
                }

                protected function responseHasView()
                {
                    return isset($this->original) && $this->original instanceof View;
                }
            }
            PHP;
        try {
            $nodes = (new ParserFactory)
                ->createForNewestSupportedVersion()
                ->parse($source) ?? [];
            $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes);
        } catch (\PhpParser\Error) {
            return null;
        }
        $methods = [];
        foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Stmt\ClassMethod::class) as $method) {
            $methods[strtolower($method->name->toString())] = $method;
        }

        return count($methods) === 3 ? $methods : null;
    }

    private static function fingerprint(Node\Stmt\ClassMethod $method): string
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
