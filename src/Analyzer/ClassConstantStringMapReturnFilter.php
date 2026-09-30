<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\DiagnosticArrayTypes;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\TypeComparator;
use Mago\Sdk\Analyzer\Type\ArrayItem;
use Mago\Sdk\Analyzer\Type\ArrayKey;
use Mago\Sdk\Analyzer\Type\ArrayKeyKind;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\Reporting\AnnotationKind;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** Recover literal string keys lost in directly returned class constant maps. */
final class ClassConstantStringMapReturnFilter implements IssueFilterHook, InitializationHook
{
    /** @var array<string, array<Node>|null> */
    private array $cache = [];

    public function initialize(InitializationContext $context): void
    {
        $this->cache = [];
    }

    public function getCodes(): array
    {
        return ['less-specific-return-statement'];
    }

    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        if ($context->issue->code !== 'less-specific-return-statement'
            || strlen($context->contents) > 1024 * 1024 || strlen($context->issue->message) > 16384
            || ! preg_match('/^Returned type `[^`]+` is less specific than the declared return type `array<string, string>` for function `([^`]+)::([^`]+)`\.$/D', $context->issue->message, $match)) {
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
        $class = $context->codebase->getClass($match[1]);
        $method = $context->codebase->getDeclaringMethod($match[1], $match[2]);
        $expected = Type::array(Type::string(), Type::string());
        if ($primary === null || $class === null || $class->hasIncompleteHierarchy()
            || $method === null || strcasecmp($method->identifier->class ?? '', $match[1]) !== 0
            || $method->templates !== [] || $method->flags->contains(MetadataFlags::BY_REFERENCE)
            || $method->returnType === null
            || ! DiagnosticArrayTypes::same($method->returnType->type, $expected, $context->types)) {
            return IssueFilterDecision::Keep;
        }
        $nodes = $this->nodes($context->file, $context->contents);
        foreach ((new NodeFinder)->findInstanceOf($nodes ?? [], Node\Stmt\Class_::class) as $node) {
            if (! isset($node->namespacedName) || strcasecmp($node->namespacedName->toString(), $match[1]) !== 0) {
                continue;
            }
            $declaration = $node->getMethod($match[2]);
            $statement = count($declaration?->stmts ?? []) === 1 ? $declaration->stmts[0] : null;
            $read = $statement instanceof Node\Stmt\Return_ ? $statement->expr : null;
            if ($declaration === null || $declaration->byRef
                || ! $read instanceof Node\Expr\ClassConstFetch || ! $read->class instanceof Node\Name
                || strtolower($read->class->toString()) !== 'self' || ! $read->name instanceof Node\Identifier
                || $read->getStartFilePos() !== $primary->span->start
                || $read->getEndFilePos() + 1 !== $primary->span->end) {
                return IssueFilterDecision::Keep;
            }
            foreach ($node->getConstants() as $group) {
                foreach ($group->consts as $constant) {
                    if ($constant->name->toString() !== $read->name->toString()) {
                        continue;
                    }
                    $metadata = $context->codebase->getClassConstant($match[1], $constant->name->toString());
                    $actual = $this->map($constant->value, $match[1], $context->codebase, $context->types);
                    if ($metadata === null || $actual === null
                        || ! $context->types->isContainedBy($actual, $expected)
                        || $metadata->declaredType !== null && ! $context->types->isContainedBy($actual, $metadata->declaredType->type)
                        || $metadata->type?->fromDocblock && ! $context->types->isContainedBy($actual, $metadata->type->type)
                        || $method->declaredReturnType !== null && ! $context->types->isContainedBy($actual, $method->declaredReturnType->type)) {
                        return IssueFilterDecision::Keep;
                    }
                    return IssueFilterDecision::Remove;
                }
            }
        }
        return IssueFilterDecision::Keep;
    }

    private function map(Node\Expr $value, string $owner, Codebase $codebase, TypeComparator $types): ?Type
    {
        if (! $value instanceof Node\Expr\Array_ || $value->items === [] || count($value->items) > 128) {
            return null;
        }
        $items = [];
        foreach ($value->items as $item) {
            if ($item === null || $item->key === null || $item->unpack || $item->byRef
                || ! $item->value instanceof Node\Scalar\String_) {
                return null;
            }
            $key = $this->key($item->key, $owner, $codebase, $types);
            // PHP normalizes canonical numeric string keys to integers.
            if ($key === null || ! is_string(array_key_first([$key => true])) || isset($items[$key])) {
                return null;
            }
            $items[$key] = new ArrayItem(new ArrayKey(ArrayKeyKind::String, $key), false, Type::literalString($item->value->value));
        }
        return Type::fromAtomic(new KeyedArrayType(array_values($items), null, null, true));
    }

    private function key(Node\Expr $key, string $owner, Codebase $codebase, TypeComparator $types): ?string
    {
        if ($key instanceof Node\Scalar\String_) {
            return $key->value;
        }
        if (! $key instanceof Node\Expr\ClassConstFetch || ! $key->class instanceof Node\Name
            || ! $key->name instanceof Node\Identifier) {
            return null;
        }
        $class = $key->class->toString();
        if (strtolower($class) === 'self') {
            $class = $owner;
        } elseif (! $key->class instanceof Node\Name\FullyQualified) {
            return null;
        }
        $declaration = $codebase->getClass($class);
        if ($declaration === null || $declaration->hasIncompleteHierarchy()) {
            return null;
        }
        $constant = $codebase->getClassConstant($class, $key->name->toString());
        $literal = $constant?->inferredType?->getLiteralString();
        if ($constant === null || $literal === null
            || strcasecmp($class, $owner) !== 0 && $constant->visibility !== Visibility::Public
            || $constant->declaredType !== null && ! $types->isContainedBy(Type::literalString($literal), $constant->declaredType->type)
            || $constant->type?->fromDocblock && ! $types->isContainedBy(Type::literalString($literal), $constant->type->type)) {
            return null;
        }
        return $literal;
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
