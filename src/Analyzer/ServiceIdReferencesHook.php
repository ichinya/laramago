<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ContainerNativeContract;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ServiceIdCatalog;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\NodeAnalysisHook;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;
use Mago\Sdk\Syntax\NodeKind;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/** Check literal native container helper references against an explicit permitted-ID policy. */
final class ServiceIdReferencesHook implements NodeAnalysisHook, InitializationHook
{
    private ?ServiceIdCatalog $catalog = null;
    private ?PhpSource $source = null;
    /** @var array<string, bool> */
    private array $native = [];
    private ?string $sourceHash = null;
    /** @var array<string, Node\Expr\FuncCall> */
    private array $calls = [];

    public function __construct(
        private readonly string $root = '.',
    ) {}

    public function initialize(InitializationContext $context): void
    {
        $this->catalog = null;
        $this->source = null;
        $this->native = [];
        $this->sourceHash = null;
        $this->calls = [];
    }

    public function getTargets(): array
    {
        return [NodeKind::FunctionCall];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $catalog = $this->catalog ??= new ServiceIdCatalog($this->root);
        if (! $catalog->isComplete()) {
            return;
        }
        $call = $this->call($context);
        if ($call === null || $call->isFirstClassCallable()) {
            return;
        }
        $name = $this->helperName($call, $context);
        if ($name === null || ! $this->validArguments($call->args, $name) || ! $this->nativeHelper($name, $context)) {
            return;
        }
        $key = PhpSource::argument($call->args, 0, $name === 'app' ? 'abstract' : 'name');
        if (! $key instanceof Node\Scalar\String_ || $catalog->contains($key->value) !== false) {
            return;
        }

        $context->report(
            Level::Warning,
            'laramago-uncataloged-service-id',
            Issue::at(
                'Service ID "'.$key->value.'" is not listed in the configured permitted service ID catalog.',
                new SourceLocation(
                    $context->source->path,
                    new Span($key->getStartFilePos(), $key->getEndFilePos() + 1),
                ),
            ),
        );
    }

    /** @param array<array-key, Node\Arg|Node\VariadicPlaceholder> $arguments */
    private function validArguments(array $arguments, string $helper): bool
    {
        if (count($arguments) < 1 || count($arguments) > 2) {
            return false;
        }
        $key = $helper === 'app' ? 'abstract' : 'name';
        $seen = [];
        $position = 0;
        $named = false;
        foreach ($arguments as $argument) {
            if (
                ! $argument instanceof Node\Arg
                || $argument->unpack
                || $argument->byRef
                || $argument->name !== null
                && ! in_array($argument->name->toString(), [$key, 'parameters'], true)
            ) {
                return false;
            }
            if ($argument->name === null) {
                if ($named) {
                    return false;
                }
                $slot = $position++ === 0 ? $key : 'parameters';
            } else {
                $named = true;
                $slot = $argument->name->toString();
            }
            if ($slot === 'parameters' && ! $argument->value instanceof Node\Expr\Array_) {
                return false;
            }
            if (isset($seen[$slot])) {
                return false;
            }
            $seen[$slot] = true;
        }

        return isset($seen[$key]);
    }

    private function helperName(Node\Expr\FuncCall $call, NodeAnalysisContext $context): ?string
    {
        if (! $call->name instanceof Node\Name) {
            return null;
        }
        $name = $call->name->toString();
        /** @var mixed $namespaced */
        $namespaced = $call->name->getAttribute('namespacedName');
        if ($namespaced instanceof Node\Name && $context->codebase->getFunction($namespaced->toString()) !== null) {
            $name = $namespaced->toString();
        }
        $name = strtolower($name);

        return in_array($name, ['app', 'resolve'], true) ? $name : null;
    }

    private function nativeHelper(string $name, NodeAnalysisContext $context): bool
    {
        if (isset($this->native[$name])) {
            return $this->native[$name];
        }
        $function = $context->codebase->getFunction($name);
        $file = str_replace('\\', '/', $function?->location->file ?? '');
        if (
            $function === null
            || ! str_ends_with($file, '/laravel/framework/src/Illuminate/Foundation/helpers.php')
            || ! ContainerNativeContract::helper($function, $name)
            || $function->flags->contains(MetadataFlags::BY_REFERENCE)
        ) {
            return $this->native[$name] = false;
        }
        $node = (new NodeFinder)->findFirst(
            ($this->source ??= new PhpSource($this->root))->read(
                str_starts_with($file, '//?/') ? substr($file, 4) : $file,
            ) ?? [],
            static fn (Node $node): bool => $node instanceof Node\Stmt\Function_ && $node->name->toString() === $name,
        );
        if (! $node instanceof Node\Stmt\Function_) {
            return $this->native[$name] = false;
        }
        $expected = $name === 'app'
            ? 'function app($abstract = null, array $parameters = []) { if (is_null($abstract)) { return \\Illuminate\\Container\\Container::getInstance(); } return \\Illuminate\\Container\\Container::getInstance()->make($abstract, $parameters); }'
            : 'function resolve($name, array $parameters = []) { return app($name, $parameters); }';
        $expectedNodes = (new ParserFactory)
            ->createForNewestSupportedVersion()
            ->parse('<?php '.$expected) ?? [];
        $expectedNodes = (new NodeTraverser(new NameResolver))->traverse($expectedNodes);
        $copy = clone $node;
        $copy->setAttribute('comments', []);
        $printer = new Standard;
        if ($printer->prettyPrint([$copy]) !== $printer->prettyPrint($expectedNodes)) {
            return $this->native[$name] = false;
        }

        return $this->native[$name] = $name !== 'resolve' || $this->nativeHelper('app', $context);
    }

    private function call(NodeAnalysisContext $context): ?Node\Expr\FuncCall
    {
        $hash = hash('sha256', $context->source->path."\0".$context->source->contents);
        if ($hash !== $this->sourceHash) {
            $this->sourceHash = $hash;
            $this->calls = [];
            try {
                $nodes = (new ParserFactory)
                    ->createForNewestSupportedVersion()
                    ->parse($context->source->contents);
                $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes ?? []);
            } catch (\PhpParser\Error) {
                return null;
            }
            foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Expr\FuncCall::class) as $call) {
                $this->calls[$call->getStartFilePos().':'.($call->getEndFilePos() + 1)] = $call;
            }
        }

        return $this->calls[$context->node->span->start.':'.$context->node->span->end] ?? null;
    }
}
