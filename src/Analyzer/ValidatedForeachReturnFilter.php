<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\DiagnosticArrayTypes;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Reporting\AnnotationKind;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** Verify direct array returns after exhaustive, side-effect-free scalar validation. */
final class ValidatedForeachReturnFilter implements IssueFilterHook, InitializationHook
{
    private const SPECIAL_VARIABLES = ['this', 'GLOBALS', '_GET', '_POST', '_COOKIE', '_REQUEST', '_SERVER', '_ENV', '_FILES', '_SESSION'];

    /** @var array<string, array<Node>|null> */
    private array $cache = [];

    public function initialize(InitializationContext $context): void
    {
        $this->cache = [];
    }

    public function getCodes(): array
    {
        return ['less-specific-nested-return-statement'];
    }

    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        if ($context->issue->code !== 'less-specific-nested-return-statement'
            || strlen($context->contents) > 1024 * 1024 || strlen($context->issue->message) > 16384
            || ! preg_match('/^Returned type `([^`]+)` is less specific than the declared return type `([^`]+)` for function `([^`]+)` due to nested \'mixed\'\.$/D', $context->issue->message, $match)) {
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
        $expected = DiagnosticArrayTypes::parse($match[2]);
        if ($primary === null || $expected === null) {
            return IssueFilterDecision::Keep;
        }
        foreach ($this->declarations($this->nodes($context->file, $context->contents) ?? []) as [$name, $declaration]) {
            if (strcasecmp($name, $match[3]) !== 0 || $declaration->byRef) {
                continue;
            }
            [$owner, $member] = str_contains($name, '::') ? explode('::', $name, 2) : [null, $name];
            $metadata = $owner === null ? $context->codebase->getFunction($member)
                : $context->codebase->getDeclaringMethod($owner, $member);
            if ($metadata === null || $metadata->templates !== [] || $metadata->returnType === null
                || $metadata->flags->contains(MetadataFlags::BY_REFERENCE)
                || strcasecmp($metadata->identifier->class ?? '', $owner ?? '') !== 0
                || strcasecmp($metadata->identifier->name, $member) !== 0
                || self::path($metadata->location->file ?? '') !== self::path($context->file)
                || $metadata->nameLocation?->span->start !== $declaration->name->getStartFilePos()
                || $metadata->nameLocation?->span->end !== $declaration->name->getEndFilePos() + 1
                || $metadata->location->span->start > $declaration->getStartFilePos()
                || $metadata->location->span->end !== $declaration->getEndFilePos() + 1
                || count($metadata->parameters) !== count($declaration->params)
                || ! DiagnosticArrayTypes::same($metadata->returnType->type, $expected, $context->types)) {
                return IssueFilterDecision::Keep;
            }
            $statements = $declaration->stmts ?? [];
            $return = array_pop($statements);
            $loop = array_pop($statements);
            if (! $return instanceof Node\Stmt\Return_ || ! $return->expr instanceof Node\Expr\Variable
                || ! is_string($return->expr->name)
                || $return->expr->getStartFilePos() !== $primary->span->start
                || $return->expr->getEndFilePos() + 1 !== $primary->span->end
                || ! $loop instanceof Node\Stmt\Foreach_ || $loop->byRef || $loop->keyVar !== null
                || ! self::variable($loop->expr, $return->expr->name)
                || ! $loop->valueVar instanceof Node\Expr\Variable || ! is_string($loop->valueVar->name)
                || $loop->valueVar->name === $return->expr->name || count($loop->stmts) !== 1) {
                return IssueFilterDecision::Keep;
            }
            $root = $return->expr->name;
            $element = $loop->valueVar->name;
            if (in_array($root, self::SPECIAL_VARIABLES, true) || in_array($element, self::SPECIAL_VARIABLES, true)) {
                return IssueFilterDecision::Keep;
            }
            $input = null;
            foreach ($declaration->params as $index => $parameter) {
                $declared = $metadata->parameters[$index] ?? null;
                if ($parameter->byRef || $parameter->variadic || ! $parameter->var instanceof Node\Expr\Variable
                    || ! is_string($parameter->var->name) || $parameter->var->name === $element
                    || $declared === null || $declared->name !== '$'.$parameter->var->name
                    || $declared->nameLocation->span->start !== $parameter->var->getStartFilePos()
                    || $declared->nameLocation->span->end !== $parameter->var->getEndFilePos() + 1
                    || $declared->flags->contains(MetadataFlags::BY_REFERENCE)
                    || $declared->flags->contains(MetadataFlags::VARIADIC)
                    || $declared->outType !== null || $declared->closureThisType !== null) {
                    return IssueFilterDecision::Keep;
                }
                if ($parameter->var->name === $root) {
                    $input = $declared->type?->type;
                }
            }
            // Only a by-value parameter is supported: local aliases and captures need another proof.
            if ($input === null) {
                return IssueFilterDecision::Keep;
            }
            $arrayGuard = $listGuard = false;
            foreach ($statements as $statement) {
                $checks = $this->checks($statement, $root, $context);
                if ($checks === null || array_diff($checks, ['is_array', 'array_is_list']) !== []) {
                    return IssueFilterDecision::Keep;
                }
                $arrayGuard = $arrayGuard || in_array('is_array', $checks, true);
                $listGuard = $listGuard || in_array('array_is_list', $checks, true);
            }
            $checks = $this->checks($loop->stmts[0], $element, $context);
            $value = $checks === null || count($checks) !== 1 ? null : match ($checks[0]) {
                'is_string' => Type::string(),
                'is_int' => Type::int(),
                'is_float' => Type::float(),
                'is_bool' => Type::bool(),
                default => null,
            };
            if ($value === null) {
                return IssueFilterDecision::Keep;
            }
            $atom = count($input->atomicTypes) === 1 ? $input->atomicTypes[0] : null;
            if ($listGuard) {
                $proven = Type::list($value);
            } elseif ($arrayGuard) {
                $proven = Type::array(Type::union(Type::int(), Type::string()), $value);
            } elseif ($atom instanceof ListType && $atom->knownElements === null) {
                $proven = Type::fromAtomic(new ListType($value, null, $atom->knownCount, $atom->nonEmpty));
            } elseif ($atom instanceof KeyedArrayType && $atom->knownItems === null && $atom->keyType !== null) {
                $proven = Type::fromAtomic(new KeyedArrayType(null, $atom->keyType, $value, $atom->nonEmpty));
            } else {
                return IssueFilterDecision::Keep;
            }
            return $context->types->isContainedBy($proven, $expected)
                && ($metadata->declaredReturnType === null || $context->types->isContainedBy($proven, $metadata->declaredReturnType->type))
                ? IssueFilterDecision::Remove : IssueFilterDecision::Keep;
        }
        return IssueFilterDecision::Keep;
    }

    /** @return list<string>|null */
    private function checks(Node\Stmt $statement, string $variable, IssueFilterContext $context): ?array
    {
        if (! $statement instanceof Node\Stmt\If_ || $statement->else !== null || $statement->elseifs !== []
            || count($statement->stmts) !== 1 || ! $statement->stmts[0] instanceof Node\Stmt\Expression
            || ! $statement->stmts[0]->expr instanceof Node\Expr\Throw_) {
            return null;
        }
        return $this->condition($statement->cond, $variable, $context);
    }

    /** @return list<string>|null */
    private function condition(Node\Expr $expression, string $variable, IssueFilterContext $context, int $depth = 0): ?array
    {
        if ($depth > 8) {
            return null;
        }
        if ($expression instanceof Node\Expr\BinaryOp\BooleanOr) {
            $left = $this->condition($expression->left, $variable, $context, $depth + 1);
            $right = $this->condition($expression->right, $variable, $context, $depth + 1);
            return $left === null || $right === null ? null : [...$left, ...$right];
        }
        if (! $expression instanceof Node\Expr\BooleanNot || ! $expression->expr instanceof Node\Expr\FuncCall) {
            return null;
        }
        $call = $expression->expr;
        if (! $call->name instanceof Node\Name || $call->isFirstClassCallable() || count($call->args) !== 1
            || ! $call->args[0] instanceof Node\Arg || $call->args[0]->name !== null || $call->args[0]->unpack
            || $call->args[0]->byRef || ! self::variable($call->args[0]->value, $variable)) {
            return null;
        }
        $name = strtolower($call->name->toString());
        if (! in_array($name, ['is_array', 'array_is_list', 'is_string', 'is_int', 'is_float', 'is_bool'], true)) {
            return null;
        }
        if (! $call->name instanceof Node\Name\FullyQualified) {
            $resolved = $call->name->getAttribute('namespacedName');
            if ($resolved instanceof Node\Name && strcasecmp($resolved->toString(), $name) !== 0
                && $context->codebase->functionExists($resolved->toString())) {
                return null;
            }
        }
        $metadata = $context->codebase->getFunction($name);
        return $metadata !== null && $metadata->flags->contains(MetadataFlags::BUILTIN) ? [$name] : null;
    }

    private static function variable(Node\Expr $node, string $name): bool
    {
        return $node instanceof Node\Expr\Variable && $node->name === $name;
    }

    private static function path(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (preg_match('~^//\\?/[A-Za-z]:/~', $path) === 1) {
            $path = substr($path, 4);
        }
        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }

    /** @param array<Node> $nodes
     * @return \Generator<array{string, Node\Stmt\Function_|Node\Stmt\ClassMethod}>
     */
    private function declarations(array $nodes, ?string $class = null): \Generator
    {
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Function_) {
                if (isset($node->namespacedName)) {
                    yield [$node->namespacedName->toString(), $node];
                }
                continue;
            }
            if ($node instanceof Node\Stmt\ClassMethod) {
                if ($class !== null) {
                    yield [$class.'::'.$node->name->toString(), $node];
                }
                continue;
            }
            if ($node instanceof Node\Stmt\Namespace_) {
                yield from $this->declarations($node->stmts);
            } elseif ($node instanceof Node\Stmt\Class_ && isset($node->namespacedName)) {
                yield from $this->declarations($node->stmts, $node->namespacedName->toString());
            }
        }
    }

    /** @return array<Node>|null */
    private function nodes(string $file, string $contents): ?array
    {
        $key = hash('sha256', $file."\0".$contents);
        if (! array_key_exists($key, $this->cache)) {
            if (count($this->cache) >= 16) {
                unset($this->cache[array_key_first($this->cache)]);
            }
            try {
                $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($contents) ?? [];
                $this->cache[$key] = (new NodeTraverser(new NameResolver))->traverse($nodes);
            } catch (Error) {
                $this->cache[$key] = null;
            }
        }
        return $this->cache[$key];
    }
}
