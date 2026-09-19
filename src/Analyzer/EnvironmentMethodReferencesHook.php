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

/** Check direct native Env::get literal keys against explicit complete names. */
final class EnvironmentMethodReferencesHook implements NodeAnalysisHook, InitializationHook
{
    private const ENV = 'Illuminate\\Support\\Env';

    private EnvironmentNameCatalog $catalog;
    private ?bool $native = null;
    private ?string $sourceHash = null;
    /** @var array<string, Node\Expr\StaticCall> */
    private array $calls = [];

    public function __construct(
        private readonly string $root = '.',
    ) {
        $this->catalog = new EnvironmentNameCatalog($root);
    }

    public function initialize(InitializationContext $context): void
    {
        $this->catalog = new EnvironmentNameCatalog($this->root);
        $this->native = null;
        $this->sourceHash = null;
        $this->calls = [];
    }

    public function getTargets(): array
    {
        return [NodeKind::StaticMethodCall];
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
        if (
            $call === null
            || $call->isFirstClassCallable()
            || ! $call->class instanceof Node\Name
            || strcasecmp($call->class->toString(), self::ENV) !== 0
            || ! $call->name instanceof Node\Identifier
            || strtolower($call->name->toString()) !== 'get'
            || ! self::validArguments($call->args)
            || ! $this->nativeMethod($context)
        ) {
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
    private static function validArguments(array $arguments): bool
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

    private function nativeMethod(NodeAnalysisContext $context): bool
    {
        if ($this->native !== null) {
            return $this->native;
        }
        $method = $context->codebase->getDeclaringMethod(self::ENV, 'get');
        if (
            $method === null
            || strcasecmp($method->identifier->class ?? '', self::ENV) !== 0
            || ! $method->static
            || $method->visibility !== Visibility::Public
            || $method->flags->contains(MetadataFlags::BY_REFERENCE)
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
        foreach ($method->parameters as $parameter) {
            if ($parameter->flags->contains(MetadataFlags::BY_REFERENCE)) {
                return $this->native = false;
            }
        }

        return $this->native = true;
    }

    private function call(NodeAnalysisContext $context): ?Node\Expr\StaticCall
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
            foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Expr\StaticCall::class) as $call) {
                $this->calls[$call->getStartFilePos().':'.($call->getEndFilePos() + 1)] = $call;
            }
        }

        return $this->calls[$context->node->span->start.':'.$context->node->span->end] ?? null;
    }
}
