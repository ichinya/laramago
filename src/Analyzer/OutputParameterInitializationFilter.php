<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Reporting\AnnotationKind;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** PHP builtins accept fresh local variables for their by-reference output parameters. */
final class OutputParameterInitializationFilter implements IssueFilterHook, InitializationHook
{
    private const OUTPUTS = [
        'preg_match' => 'matches',
        'preg_match_all' => 'matches',
        'proc_open' => 'pipes',
    ];

    /** @var array<string, array<string, string>> */
    private array $cache = [];

    public function initialize(InitializationContext $context): void
    {
        $this->cache = [];
    }

    public function getCodes(): array
    {
        return ['reference-to-undefined-variable', 'mixed-argument'];
    }

    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        if (! in_array($context->issue->code, $this->getCodes(), true) || strlen($context->contents) > 1024 * 1024) {
            return IssueFilterDecision::Keep;
        }
        if ($context->issue->code === 'mixed-argument'
            && preg_match('/^Invalid argument type for argument #[1-9][0-9]* of `(preg_match|preg_match_all|proc_open)`: expected `[^`]+`, but found `mixed`\.$/', $context->issue->message) !== 1) {
            return IssueFilterDecision::Keep;
        }
        $primary = null;
        foreach ($context->issue->annotations as $annotation) {
            if ($annotation->kind !== AnnotationKind::Primary) {
                continue;
            }
            if ($primary !== null || $annotation->file !== null && $annotation->file !== '') {
                return IssueFilterDecision::Keep;
            }
            $primary = $annotation;
        }
        if ($primary === null) {
            return IssueFilterDecision::Keep;
        }
        $key = hash('sha256', $context->file."\0".$context->contents);
        if (! isset($this->cache[$key])) {
            $candidates = $this->candidates($context);
            if ($candidates === null) {
                return IssueFilterDecision::Keep;
            }
            if (count($this->cache) >= 32) {
                unset($this->cache[array_key_first($this->cache)]);
            }
            $this->cache[$key] = $candidates;
        }

        return isset($this->cache[$key][$primary->span->start.':'.$primary->span->end])
            ? IssueFilterDecision::Remove
            : IssueFilterDecision::Keep;
    }

    /** @return array<string, string>|null Exact variable spans whose native call initializes them. */
    private function candidates(IssueFilterContext $context): ?array
    {
        try {
            $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($context->contents) ?? [];
            $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes);
        } catch (Error) {
            return null;
        }
        $matches = [];
        foreach ($nodes as $node) {
            $namespace = $node instanceof Node\Stmt\Namespace_ ? $node->name?->toString() : null;
            $scope = $node instanceof Node\Stmt\Namespace_ ? $node->stmts : [$node];
            foreach ((new NodeFinder)->findInstanceOf($scope, Node\Expr\FuncCall::class) as $call) {
                $name = $this->nativeName($context, $call, $namespace);
                if ($name === null || ! $this->nativeOutput($context, $name)) {
                    continue;
                }
                $argument = $this->outputArgument($call, self::OUTPUTS[$name]);
                if (! $argument?->value instanceof Node\Expr\Variable || ! is_string($argument->value->name)) {
                    continue;
                }
                $variable = $argument->value;
                $matches[$variable->getStartFilePos().':'.($variable->getEndFilePos() + 1)] = $name;
            }
        }

        return $matches;
    }

    private function nativeName(
        IssueFilterContext $context,
        Node\Expr\FuncCall $call,
        ?string $namespace,
    ): ?string {
        if (! $call->name instanceof Node\Name) {
            return null;
        }
        $name = strtolower($call->name->toString());
        if (! isset(self::OUTPUTS[$name])) {
            return null;
        }
        if (
            ! $call->name instanceof Node\Name\FullyQualified
            && $namespace !== null
            && $context->codebase->functionExists($namespace.'\\'.$name)
        ) {
            return null;
        }

        return $name;
    }

    private function nativeOutput(IssueFilterContext $context, string $name): bool
    {
        $metadata = $context->codebase->getFunction($name);
        $output = $metadata?->parameters[2] ?? null;

        return $metadata !== null
            && $metadata->flags->contains(MetadataFlags::BUILTIN)
            && strcasecmp($metadata->originalName, $name) === 0
            && count($metadata->parameters) >= 3
            && $output !== null
            && $output->name === '$'.self::OUTPUTS[$name]
            && $output->flags->contains(MetadataFlags::BY_REFERENCE);
    }

    private function outputArgument(Node\Expr\FuncCall $call, string $name): ?Node\Arg
    {
        foreach ($call->args as $index => $argument) {
            if (! $argument instanceof Node\Arg || $argument->unpack) {
                return null;
            }
            if ($argument->name?->toString() === $name || $index === 2 && $argument->name === null) {
                return $argument;
            }
        }

        return null;
    }
}
