<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\NamedRouteCatalog;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\MethodCallAnalysisHook;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/** Missing named routes require an explicit complete application catalog. */
final class NamedRouteContractsHook implements MethodCallAnalysisHook
{
    private readonly NamedRouteCatalog $catalog;

    private string $sourceHash = '';

    /**
     * @var array<int, Node\Expr\MethodCall>
     */
    private array $calls = [];

    public function __construct(string $root = '.')
    {
        $this->catalog = new NamedRouteCatalog($root);
    }

    public function getTargets(): array
    {
        return [
            MethodTarget::exact('Illuminate\\Routing\\UrlGenerator', 'route'),
            MethodTarget::exact('Illuminate\\Routing\\Redirector', 'route'),
        ];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::ReceiverType, FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        if (! $this->catalog->enabled()) {
            return;
        }
        $atoms = $context->receiverType?->atomicTypes ?? [];
        if (
            count($atoms) !== 1
            || ! $atoms[0] instanceof NamedObjectType
            || ! in_array(
                $atoms[0]->name,
                ['Illuminate\\Routing\\UrlGenerator', 'Illuminate\\Routing\\Redirector'],
                true,
            )
        ) {
            return;
        }
        $owner = $context->codebase->getDeclaringMethod($atoms[0]->name, 'route');
        if (
            $owner === null
            || $owner->identifier->class !== $atoms[0]->name
            || $owner->static
            || ! str_ends_with(
                str_replace('\\', '/', $owner->location->file ?? ''),
                '/laravel/framework/src/'.str_replace('\\', '/', $atoms[0]->name).'.php',
            )
        ) {
            return;
        }
        $hash = hash('sha256', $context->source->contents);
        if ($hash !== $this->sourceHash) {
            $this->sourceHash = $hash;
            $this->calls = [];
            try {
                $nodes = (new ParserFactory)
                    ->createForNewestSupportedVersion()
                    ->parse($context->source->contents);
                foreach ((new NodeFinder)->findInstanceOf($nodes ?? [], Node\Expr\MethodCall::class) as $node) {
                    $this->calls[$node->getStartFilePos()] = $node;
                }
            } catch (\PhpParser\Error) {
                return;
            }
        }
        $call = $this->calls[$context->node->span->start] ?? null;
        if (
            ! $call instanceof Node\Expr\MethodCall
            || ($call->getEndFilePos() + 1) !== $context->node->span->end
            || ! $call->name instanceof Node\Identifier
            || strtolower($call->name->name) !== 'route'
            || $call->isFirstClassCallable()
        ) {
            return;
        }
        $name = null;
        foreach ($call->getArgs() as $offset => $argument) {
            if ($argument->unpack) {
                return;
            }
            if (
                $argument->name === null
                && $offset === 0
                || $argument->name !== null
                && $argument->name->name
                    === ($atoms[0]->name === 'Illuminate\\Routing\\UrlGenerator' ? 'name' : 'route')
            ) {
                $name = $argument->value;
            }
        }
        if (! $name instanceof Node\Scalar\String_ || ! $this->catalog->missing($name->value)) {
            return;
        }
        $context->report(
            Level::Warning,
            'laramago-missing-named-route',
            Issue::at(
                'Named route "'.$name->value.'" is absent from the explicitly complete named-routes catalog.',
                new SourceLocation($context->source->path, $context->node->span),
            ),
        );
    }
}
