<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ModelReflection;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ReferenceCatalogs;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\MethodCallAnalysisHook;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/** Check literal page names in the native Inertia test component assertion. */
final class InertiaTestComponentReferencesHook implements MethodCallAnalysisHook, InitializationHook
{
    private const ASSERTABLE = 'Inertia\\Testing\\AssertableInertia';

    private ?ReferenceCatalogs $catalogs = null;
    private ?PhpSource $source = null;
    private ?bool $nativeComponent = null;
    private string $sourceHash = '';
    /** @var array<string, Node\Expr\MethodCall> */
    private array $calls = [];

    public function __construct(
        private readonly string $root = '.',
    ) {}

    public function initialize(InitializationContext $context): void
    {
        $this->catalogs = null;
        $this->source = null;
        $this->nativeComponent = null;
        $this->sourceHash = '';
        $this->calls = [];
    }

    public function getTargets(): array
    {
        return [MethodTarget::exact(self::ASSERTABLE, 'component')];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::ReceiverType, FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $receiver = $context->receiverType?->atomicTypes[0] ?? null;
        if (
            $context->receiverType === null
            || count($context->receiverType->atomicTypes) !== 1
            || ! $receiver instanceof NamedObjectType
            || strcasecmp($receiver->name, self::ASSERTABLE) !== 0
            || ! $this->nativeComponent($context)
        ) {
            return;
        }
        $call = $this->call($context);
        if ($call === null || $call->isFirstClassCallable()) {
            return;
        }
        $value = null;
        foreach ($call->getArgs() as $offset => $argument) {
            if ($argument->unpack) {
                return;
            }
            if (
                $argument->name === null
                && $offset === 1
                || $argument->name !== null
                && $argument->name->name === 'shouldExist'
            ) {
                if (
                    ! $argument->value instanceof Node\Expr\ConstFetch
                    || ! in_array(strtolower($argument->value->name->toString()), ['true', 'null'], true)
                ) {
                    return;
                }
            }
            if (
                $argument->name === null
                && $offset === 0
                || $argument->name !== null
                && $argument->name->name === 'value'
            ) {
                $value = $argument->value;
            }
        }
        if (! $value instanceof Node\Scalar\String_) {
            return;
        }
        $catalogs = $this->catalogs ??= new ReferenceCatalogs($this->root);
        if ($catalogs->containsInertiaPage($value->value) !== false) {
            return;
        }
        $context->report(
            Level::Warning,
            'laramago-missing-inertia-page',
            Issue::at(
                'Inertia page "'.$value->value.'" is absent from the explicitly complete inertia-pages catalog.',
                new SourceLocation($context->source->path, $context->node->span),
            ),
        );
    }

    private function nativeComponent(NodeAnalysisContext $context): bool
    {
        return $this->nativeComponent ??= $this->matchesNativeComponent($context);
    }

    private function matchesNativeComponent(NodeAnalysisContext $context): bool
    {
        $class = $context->codebase->getClassLike(self::ASSERTABLE);
        $method = $context->codebase->getDeclaringMethod(self::ASSERTABLE, 'component');
        if (
            $class === null
            || $class->hasIncompleteHierarchy()
            || $method === null
            || $method->static
            || strcasecmp($method->identifier->class ?? '', self::ASSERTABLE) !== 0
            || ! str_ends_with(
                str_replace('\\', '/', $method->location->file ?? ''),
                '/inertiajs/inertia-laravel/src/Testing/AssertableInertia.php',
            )
            || count($method->parameters) !== 2
            || $method->parameters[0]->name !== '$value'
            || $method->parameters[1]->name !== '$shouldExist'
        ) {
            return false;
        }
        $source = $this->source ??= new PhpSource($this->root);
        $actual = (new ModelReflection($context->codebase, $source))->methodNode($method);
        if ($actual?->stmts === null) {
            return false;
        }
        foreach (['inertia.testing.view-finder', 'inertia.view-finder'] as $finder) {
            $expected = (new ParserFactory)
                ->createForNewestSupportedVersion()
                ->parse(
                    '<?php class Expected { public function component(?string $value = null, $shouldExist = null): self {'
                    .' \\PHPUnit\\Framework\\Assert::assertSame($value, $this->component, \'Unexpected Inertia page component.\');'
                    .' if ($shouldExist || (is_null($shouldExist) && config(\'inertia.testing.ensure_pages_exist\', true))) {'
                    .' try { app(\''
                    .$finder
                    .'\')->find($value); }'
                    .' catch (\\InvalidArgumentException $exception) {'
                    .' \\PHPUnit\\Framework\\Assert::fail(sprintf(\'Inertia page component file [%s] does not exist.\', $value)); }'
                    .' } return $this; } }',
                );
            $expectedClass = $expected[0] ?? null;
            $candidate = $expectedClass instanceof Node\Stmt\Class_ ? $expectedClass->stmts[0] ?? null : null;
            if (! $candidate instanceof Node\Stmt\ClassMethod) {
                continue;
            }
            $actualStatements = clone $actual;
            $expectedStatements = clone $candidate;
            foreach ([$actualStatements, $expectedStatements] as $node) {
                (new NodeTraverser(new class extends NodeVisitorAbstract {
                    public function enterNode(Node $node): ?Node
                    {
                        $node->setAttribute('comments', []);

                        return null;
                    }
                }))->traverse([$node]);
            }
            $printer = new Standard;
            if ($printer->prettyPrint([$actualStatements]) === $printer->prettyPrint([$expectedStatements])) {
                return true;
            }
        }

        return false;
    }

    private function call(NodeAnalysisContext $context): ?Node\Expr\MethodCall
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
            foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Expr\MethodCall::class) as $call) {
                $this->calls[$call->getStartFilePos().':'.($call->getEndFilePos() + 1)] = $call;
            }
        }
        $call = $this->calls[$context->node->span->start.':'.$context->node->span->end] ?? null;

        return $call?->name instanceof Node\Identifier && strcasecmp($call->name->name, 'component') === 0
            ? $call
            : null;
    }
}
