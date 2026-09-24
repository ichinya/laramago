<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
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

/** Check independently asserted final calls, never infer effective route or container state. */
final class ControllerCallContractsHook implements NodeAnalysisHook, InitializationHook
{
    /** @var array<string, array<string, list<string|array{class: string}>>> */
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
        $policy = is_array($composer) ? $composer['extra']['laramago']['controller-call-contracts'] ?? null : null;
        if (
            ! is_array($policy)
            || ($policy['final-positional-call-asserted'] ?? null) !== true
            || array_diff(array_keys($policy), ['final-positional-call-asserted', 'entries']) !== []
            || ! is_array($policy['entries'] ?? null)
            || ! array_is_list($policy['entries'])
            || count($policy['entries']) > 256
        ) {
            return;
        }
        $contracts = [];
        /** @var mixed $entry */
        foreach ($policy['entries'] as $entry) {
            if (
                ! is_array($entry)
                || array_diff(array_keys($entry), ['controller', 'method', 'arguments']) !== []
                || ! is_string($entry['controller'] ?? null)
                || ! self::className($entry['controller'])
                || ! is_string($entry['method'] ?? null)
                || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $entry['method']) !== 1
                || ! is_array($entry['arguments'] ?? null)
                || ! array_is_list($entry['arguments'])
                || count($entry['arguments']) > 64
            ) {
                return;
            }
            $owner = strtolower(ltrim($entry['controller'], '\\'));
            $method = strtolower($entry['method']);
            if (isset($contracts[$owner][$method])) {
                return;
            }
            $arguments = [];
            /** @var mixed $argument */
            foreach ($entry['arguments'] as $argument) {
                if (
                    is_string($argument)
                    && in_array($argument, ['string', 'int', 'float', 'bool', 'array', 'null'], true)
                ) {
                    $arguments[] = $argument;
                } elseif (
                    is_array($argument)
                    && array_keys($argument) === ['class']
                    && is_string($argument['class'])
                    && self::className($argument['class'])
                ) {
                    $arguments[] = ['class' => ltrim($argument['class'], '\\')];
                } else {
                    return;
                }
            }
            $contracts[$owner][$method] = $arguments;
        }
        $this->contracts = $contracts;
    }

    private static function className(string $name): bool
    {
        return preg_match('~^\\\\?[a-zA-Z_][a-zA-Z0-9_]*(?:\\\\[a-zA-Z_][a-zA-Z0-9_]*)*$~D', $name) === 1;
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
        if ($class === null || $class->namespacedName === null || $class->isAbstract()) {
            return;
        }
        $owner = $class->namespacedName->toString();
        $metadata = $context->codebase->getClass($owner);
        if (
            $metadata === null
            || $metadata->hasIncompleteHierarchy()
            || str_replace('\\', '/', $metadata->location->file ?? '') !== str_replace(
                '\\',
                '/',
                $context->source->path,
            )
        ) {
            return;
        }
        $declarations = 0;
        foreach ($this->classes as $candidate) {
            if (strcasecmp($candidate->namespacedName?->toString() ?? '', $owner) === 0) {
                $declarations++;
            }
        }
        if ($declarations !== 1) {
            return;
        }
        foreach ($this->contracts[strtolower($owner)] ?? [] as $name => $arguments) {
            $method = $class->getMethod($name);
            if (
                $method === null
                || ! $method->isPublic()
                || $method->isStatic()
                || $method->isAbstract()
                || str_starts_with($name, '__')
                && $name !== '__invoke'
            ) {
                continue;
            }
            $methods = 0;
            foreach ($class->getMethods() as $candidate) {
                if (strcasecmp($candidate->name->toString(), $name) === 0) {
                    $methods++;
                }
            }
            if ($methods !== 1) {
                continue;
            }
            foreach ($method->params as $parameter) {
                if ($parameter->byRef) {
                    continue 2;
                }
            }
            $required = 0;
            $position = 0;
            foreach ($method->params as $parameter) {
                $position++;
                // A required parameter after a default still makes earlier slots mandatory.
                if ($parameter->default === null && ! $parameter->variadic) {
                    $required = $position;
                }
            }
            if (count($arguments) < $required) {
                $this->report(
                    $context,
                    $method->name,
                    'laramago-missing-controller-arguments',
                    $owner
                    .'::'
                    .$method->name->toString()
                    .' requires '
                    .$required
                    .' positional arguments; '
                    .count($arguments)
                    .' asserted.',
                );
            }
            foreach ($arguments as $index => $argument) {
                $parameter = $method->params[$index] ?? null;
                $last = $method->params === [] ? null : $method->params[count($method->params) - 1];
                $parameter ??= $last?->variadic === true ? $last : null;
                if ($parameter === null || $parameter->type === null) {
                    continue;
                }
                // Legacy implicit nullable native parameters still accept null.
                if (
                    $argument === 'null'
                    && $parameter->default instanceof Node\Expr\ConstFetch
                    && strtolower($parameter->default->name->toString()) === 'null'
                ) {
                    continue;
                }
                if ($this->rejects($context, $parameter->type, $argument)) {
                    $actual = is_string($argument) ? $argument : $argument['class'];
                    $this->report(
                        $context,
                        $parameter,
                        'laramago-incompatible-controller-argument',
                        $owner
                        .'::'
                        .$method->name->toString()
                        .' cannot accept asserted '
                        .$actual
                        .' at argument '
                        .($index + 1)
                        .'.',
                    );
                }
            }
        }
    }

    /**
     * Prove rejection only; false also includes unknown and weak scalar coercion.
     * @param string|array{class: string} $actual
     */
    private function rejects(
        NodeAnalysisContext $context,
        Node\Identifier|Node\Name|Node\ComplexType $expected,
        string|array $actual,
    ): bool {
        if ($expected instanceof Node\NullableType) {
            return $actual !== 'null' && $this->rejects($context, $expected->type, $actual);
        }
        if ($expected instanceof Node\UnionType) {
            foreach ($expected->types as $member) {
                if (! $this->rejects($context, $member, $actual)) {
                    return false;
                }
            }

            return $expected->types !== [];
        }
        if ($expected instanceof Node\IntersectionType) {
            return false;
        }
        if ($expected instanceof Node\Name) {
            if ($expected->isSpecialClassName()) {
                return false;
            }
            $target = $context->codebase->getClassLike($expected->toString());
            if ($target === null || $target->hasIncompleteHierarchy()) {
                return false;
            }
            if (is_string($actual)) {
                return true;
            }
            $concrete = $context->codebase->getClass($actual['class']);

            return (
                $concrete !== null
                && ! $concrete->hasIncompleteHierarchy()
                && ! $concrete->flags->contains(MetadataFlags::ABSTRACT)
                && ! $context->types->isContainedBy(
                    Type::namedObject($actual['class']),
                    Type::namedObject($expected->toString()),
                )
            );
        }
        if (! $expected instanceof Node\Identifier) {
            return false;
        }
        $name = strtolower($expected->toString());
        if (in_array($name, ['mixed', 'callable'], true)) {
            return false;
        }
        if ($actual === 'null') {
            return $name !== 'null';
        }
        if ($name === 'null') {
            return true;
        }
        if ($name === 'array') {
            return $actual !== 'array';
        }
        if ($name === 'object') {
            return is_string($actual);
        }
        if ($name === 'iterable') {
            return is_string($actual) && $actual !== 'array';
        }

        // Array-to-scalar is impossible; scalar coercion and Stringable objects defer.
        return $actual === 'array' && in_array($name, ['string', 'int', 'float', 'bool', 'true', 'false'], true);
    }

    private function report(NodeAnalysisContext $context, Node $node, string $code, string $message): void
    {
        $context->report(Level::Warning, $code, Issue::at(
            $message.' This check relies on the explicit final positional controller-call contract.',
            new SourceLocation($context->source->path, new Span($node->getStartFilePos(), $node->getEndFilePos() + 1)),
        ));
    }
}
