<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\NodeAnalysisHook;
use Mago\Sdk\Analyzer\Type;
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

/** Check explicitly asserted final contextual injection results without resolving a container. */
final class ContextualInjectionContractsHook implements NodeAnalysisHook, InitializationHook
{
    /** @var array<string, array<string, string>> */
    private array $contracts = [];
    private ?string $sourceHash = null;
    /** @var array<int, Node\Stmt\Class_> */
    private array $classes = [];

    public function __construct(
        private readonly string $root = '.',
    ) {
        $this->load();
    }

    public function initialize(InitializationContext $context): void
    {
        $this->sourceHash = null;
        $this->classes = [];
        $this->load();
    }

    private function load(): void
    {
        $this->contracts = [];
        $text = @file_get_contents(rtrim($this->root, '/\\').'/composer.json');
        /** @var mixed $composer */
        $composer = $text === false ? null : json_decode($text, true);
        /** @var mixed $policy */
        $policy = is_array($composer) ? $composer['extra']['laramago']['contextual-injection-contracts'] ?? null : null;
        /** @var mixed $entries */
        $entries = is_array($policy) && ($policy['effective-resolution-asserted'] ?? null) === true
            ? $policy['entries'] ?? null
            : null;
        if (! is_array($entries) || ! array_is_list($entries) || count($entries) > 1024) {
            return;
        }
        $duplicates = [];
        /** @var mixed $entry */
        foreach ($entries as $entry) {
            if (
                ! is_array($entry)
                || ! is_string($entry['consumer'] ?? null)
                || ! is_string($entry['parameter'] ?? null)
                || ! is_string($entry['concrete'] ?? null)
            ) {
                continue;
            }
            $consumer = strtolower(ltrim($entry['consumer'], '\\'));
            $parameter = $entry['parameter'];
            $concrete = ltrim($entry['concrete'], '\\');
            if ($consumer === '' || $parameter === '' || $concrete === '') {
                continue;
            }
            $key = $consumer.'::'.$parameter;
            if (isset($this->contracts[$consumer][$parameter]) || isset($duplicates[$key])) {
                unset($this->contracts[$consumer][$parameter]);
                $duplicates[$key] = true;
                continue;
            }
            $this->contracts[$consumer][$parameter] = $concrete;
        }
    }

    public function enabled(): bool
    {
        return $this->contracts !== [];
    }

    public function getTargets(): array
    {
        return [NodeKind::Class_];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        if (! $this->enabled()) {
            return;
        }
        $hash = hash('sha256', $context->source->path."\0".$context->source->contents);
        if ($hash !== $this->sourceHash) {
            $this->sourceHash = $hash;
            $this->classes = [];
            try {
                $nodes = (new ParserFactory)
                    ->createForNewestSupportedVersion()
                    ->parse($context->source->contents);
                $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes ?? []);
            } catch (\PhpParser\Error) {
                return;
            }
            foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Stmt\Class_::class) as $class) {
                $this->classes[$class->getStartFilePos()] = $class;
            }
        }
        $class = $this->classes[$context->node->span->start] ?? null;
        if ($class === null || $class->namespacedName === null) {
            return;
        }
        $owner = $class->namespacedName->toString();
        $contracts = $this->contracts[strtolower($owner)] ?? [];
        $constructor = $class->getMethod('__construct');
        if (
            $contracts === []
            || $constructor === null
            || ! $constructor->isPublic()
            || $constructor->isStatic()
            || $constructor->isAbstract()
        ) {
            return;
        }
        foreach ($constructor->params as $parameter) {
            if (
                ! $parameter->var instanceof Node\Expr\Variable
                || ! is_string($parameter->var->name)
                || $parameter->variadic
                || $parameter->byRef
                || ! $parameter->type instanceof Node\Name
            ) {
                continue;
            }
            $concrete = $contracts[$parameter->var->name] ?? null;
            $expected = $parameter->type->toString();
            if ($concrete === null || in_array(strtolower($expected), ['self', 'parent', 'static'], true)) {
                continue;
            }
            $actualClass = $context->codebase->getClass($concrete);
            $expectedClass = $context->codebase->getClassLike($expected);
            if (
                $actualClass === null
                || $actualClass->hasIncompleteHierarchy()
                || $expectedClass === null
                || $expectedClass->hasIncompleteHierarchy()
            ) {
                continue;
            }
            if ($context->types->isContainedBy(Type::namedObject($concrete), Type::namedObject($expected))) {
                continue;
            }
            $context->report(Level::Warning, 'laramago-incompatible-contextual-injection', Issue::at(
                'Asserted final injection of "'
                .$concrete
                .'" into '
                .$owner
                .'::__construct($'
                .$parameter->var->name
                .') cannot satisfy native type "'
                .$expected
                .'".',
                new SourceLocation(
                    $context->source->path,
                    new Span($parameter->getStartFilePos(), $parameter->getEndFilePos() + 1),
                ),
            ));
        }
    }
}
