<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\EnvironmentNameCatalog;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\NodeAnalysisHook;
use Mago\Sdk\Analyzer\Type\Visibility;
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

/** Check native env() literal keys against an explicitly complete name catalog. */
final class EnvironmentHelperReferencesHook implements NodeAnalysisHook, InitializationHook
{
    private const ENV = 'Illuminate\\Support\\Env';

    private EnvironmentNameCatalog $catalog;
    private PhpSource $source;
    private ?bool $native = null;
    private ?string $sourceHash = null;
    /** @var array<string, Node\Expr\FuncCall> */
    private array $calls = [];

    public function __construct(
        private readonly string $root = '.',
    ) {
        $this->catalog = new EnvironmentNameCatalog($root);
        $this->source = new PhpSource($root);
    }

    public function initialize(InitializationContext $context): void
    {
        $this->catalog = new EnvironmentNameCatalog($this->root);
        $this->source = new PhpSource($this->root);
        $this->native = null;
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
        if (! $this->catalog->isComplete()) {
            return;
        }
        $call = $this->call($context);
        if ($call === null || $call->isFirstClassCallable() || ! $this->validArguments($call->args)) {
            return;
        }
        if (! $this->nativeHelper($call, $context)) {
            return;
        }
        $key = PhpSource::argument($call->args, 0, 'key');
        if (! $key instanceof Node\Scalar\String_ || $this->catalog->contains($key->value) !== false) {
            return;
        }

        $context->report(
            Level::Warning,
            'laramago-uncataloged-environment-name',
            Issue::at(
                'Environment name "'
                .$key->value
                .'" is uncataloged under the explicitly complete permitted/expected names assertion.',
                new SourceLocation(
                    $context->source->path,
                    new Span($key->getStartFilePos(), $key->getEndFilePos() + 1),
                ),
            ),
        );
    }

    /** @param array<array-key, Node\Arg|Node\VariadicPlaceholder> $arguments */
    private function validArguments(array $arguments): bool
    {
        if (count($arguments) < 1 || count($arguments) > 2) {
            return false;
        }
        $seen = [];
        $position = 0;
        $named = false;
        foreach ($arguments as $argument) {
            if (
                ! $argument instanceof Node\Arg
                || $argument->unpack
                || $argument->name !== null
                && ! in_array($argument->name->toString(), ['key', 'default'], true)
            ) {
                return false;
            }
            if ($argument->name === null) {
                if ($named) {
                    return false;
                }
                $slot = $position++ === 0 ? 'key' : 'default';
            } else {
                $named = true;
                $slot = $argument->name->toString();
            }
            if (isset($seen[$slot])) {
                return false;
            }
            $seen[$slot] = true;
        }

        return isset($seen['key']);
    }

    private function nativeHelper(Node\Expr\FuncCall $call, NodeAnalysisContext $context): bool
    {
        if (! $call->name instanceof Node\Name) {
            return false;
        }
        $name = $call->name->toString();
        /** @var mixed $namespaced */
        $namespaced = $call->name->getAttribute('namespacedName');
        if ($namespaced instanceof Node\Name && $context->codebase->getFunction($namespaced->toString()) !== null) {
            $name = $namespaced->toString();
        }
        if (strtolower($name) !== 'env') {
            return false;
        }
        if ($this->native !== null) {
            return $this->native;
        }

        $function = $context->codebase->getFunction($name);
        $file = str_replace('\\', '/', $function?->location->file ?? '');
        $method = $context->codebase->getDeclaringMethod(self::ENV, 'get');
        if (
            ! str_ends_with($file, '/laravel/framework/src/Illuminate/Support/helpers.php')
            || array_map(static fn ($parameter): string => $parameter->name, $function?->parameters ?? []) !== [
                '$key',
                '$default',
            ]
            || $method === null
            || strcasecmp($method->identifier->class ?? '', self::ENV) !== 0
            || ! $method->static
            || $method->visibility !== Visibility::Public
            || ! str_ends_with(
                str_replace('\\', '/', $method->location->file ?? ''),
                '/laravel/framework/src/Illuminate/Support/Env.php',
            )
            || array_map(static fn ($parameter): string => $parameter->name, $method->parameters) !== [
                '$key',
                '$default',
            ]
        ) {
            return $this->native = false;
        }
        foreach ([...($function?->parameters ?? []), ...$method->parameters] as $parameter) {
            if ($parameter->flags->contains(MetadataFlags::BY_REFERENCE)) {
                return $this->native = false;
            }
        }
        $node = (new NodeFinder)->findFirst(
            $this->source->read(str_starts_with($file, '//?/') ? substr($file, 4) : $file) ?? [],
            static fn (Node $node): bool => (
                $node instanceof Node\Stmt\Function_
                && strtolower($node->name->toString()) === 'env'
            ),
        );
        if (
            ! $node instanceof Node\Stmt\Function_
            || $node->byRef
            || count($node->params) !== 2
            || count($node->stmts) !== 1
            || ! $node->stmts[0] instanceof Node\Stmt\Return_
            || ! $node->stmts[0]->expr instanceof Node\Expr\StaticCall
        ) {
            return $this->native = false;
        }
        foreach (['key', 'default'] as $index => $parameterName) {
            $parameter = $node->params[$index];
            if (
                $parameter->byRef
                || $parameter->variadic
                || ! $parameter->var instanceof Node\Expr\Variable
                || $parameter->var->name !== $parameterName
            ) {
                return $this->native = false;
            }
        }
        $forward = $node->stmts[0]->expr;
        if (
            ! $forward->class instanceof Node\Name
            || strcasecmp($forward->class->toString(), self::ENV) !== 0
            || ! $forward->name instanceof Node\Identifier
            || strtolower($forward->name->toString()) !== 'get'
            || count($forward->args) !== 2
        ) {
            return $this->native = false;
        }
        foreach (['key', 'default'] as $index => $parameterName) {
            $argument = $forward->args[$index];
            if (
                ! $argument instanceof Node\Arg
                || $argument->unpack
                || $argument->name !== null
                || ! $argument->value instanceof Node\Expr\Variable
                || $argument->value->name !== $parameterName
            ) {
                return $this->native = false;
            }
        }

        return $this->native = true;
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
