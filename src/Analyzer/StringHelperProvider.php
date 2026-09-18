<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\FunctionReturnTypeProvider;
use Mago\Sdk\Analyzer\FunctionTarget;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/** Distinguishes the installed helper's zero-argument proxy from explicit values. */
final class StringHelperProvider implements FunctionReturnTypeProvider, InitializationHook
{
    private bool $resolved = false;
    private ?string $proxy = null;

    public function __construct(
        private readonly string $root = '.',
    ) {}

    public function initialize(InitializationContext $context): void
    {
        $this->resolved = false;
        $this->proxy = null;
    }

    public function getTargets(): array
    {
        return [FunctionTarget::exact('str')];
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $proxy = $this->proxy($context->codebase);
        if ($proxy === null) {
            return null;
        }
        $arguments = $context->invocation->arguments;
        if ($arguments === []) {
            return Type::namedObject($proxy);
        }
        $argument = $context->invocation->getArgument(0, 'string');
        if (
            count($arguments) !== 1
            || $argument === null
            || $argument->unpacked
            || $argument->placeholder
            || $argument->type === null
        ) {
            return null;
        }
        if (! $context->types->isContainedBy($argument->type, Type::union(Type::string(), Type::null()))) {
            return null;
        }

        return Type::namedObject('Illuminate\\Support\\Stringable');
    }

    /** Locate Mago's real anonymous class only after checking the installed helper syntax. */
    public function proxy(Codebase $codebase): ?string
    {
        if ($this->resolved) {
            return $this->proxy;
        }
        $this->resolved = true;
        $function = $codebase->getFunction('str');
        $file = $function?->location->file;
        if (
            $function === null
            || $file === null
            || $function->declaredReturnType !== null
            || ! str_ends_with(str_replace('\\', '/', $file), '/laravel/framework/src/Illuminate/Support/helpers.php')
        ) {
            return null;
        }
        $return = $function->returnType?->type;
        $conditional = $return?->atomicTypes[0] ?? null;
        if (
            count($return?->atomicTypes ?? []) !== 1
            || ! $conditional instanceof \Mago\Sdk\Analyzer\Type\ConditionalType
            || $conditional->negated
            || ! $conditional->subject->atomicTypes[0] instanceof \Mago\Sdk\Analyzer\Type\VariableType
            || ltrim($conditional->subject->atomicTypes[0]->name, '$') !== 'string'
            || (string) $conditional->target !== 'null'
            || (string) $conditional->then !== 'object'
            || (string) $conditional->otherwise !== 'Illuminate\\Support\\Stringable'
        ) {
            return null;
        }
        $nodes = (new PhpSource($this->root))->read(str_starts_with($file, '//?/') ? substr($file, 4) : $file);
        $node = (new NodeFinder)->findFirst(
            $nodes ?? [],
            static fn (Node $node): bool => $node instanceof Node\Stmt\Function_ && $node->name->toString() === 'str',
        );
        if (
            ! $node instanceof Node\Stmt\Function_
            || $node->byRef
            || count($node->params) !== 1
            || $node->params[0]->byRef
            || $node->params[0]->variadic
            || $node->params[0]->type !== null
        ) {
            return null;
        }
        $doc = preg_replace('/[\s*]+/', '', $node->getDocComment()?->getText() ?? '');
        if ($doc === null || ! str_contains($doc, '@return($stringisnull?object:\\Illuminate\\Support\\Stringable)')) {
            return null;
        }
        $expected = (new ParserFactory)
            ->createForNewestSupportedVersion()
            ->parse(<<<'PHP'
                <?php
                if (\func_num_args() === 0) {
                    return new class {
                        public function __call($method, $parameters) {
                            return \Illuminate\Support\Str::$method(...$parameters);
                        }
                        public function __toString() { return ''; }
                    };
                }
                return new \Illuminate\Support\Stringable($string);
                PHP);
        $printer = new Standard;
        if ($printer->prettyPrint($node->stmts) !== $printer->prettyPrint($expected ?? [])) {
            return null;
        }
        foreach (array_chunk($codebase->getClassNames(), 200) as $names) {
            foreach ($codebase->getMultipleClasses($names) as $class) {
                if (
                    $class !== null
                    && $class->location->file === $file
                    && $function->location->span->contains($class->location->span)
                    && $class->methods === ['__call', '__tostring']
                ) {
                    return $this->proxy = $class->name;
                }
            }
        }

        return null;
    }
}
