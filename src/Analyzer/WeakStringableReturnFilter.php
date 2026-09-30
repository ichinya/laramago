<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\DiagnosticArrayTypes;
use Ichinya\Laramago\Analyzer\StaticAnalysis\DiagnosticReturnTypes;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Analyzer\Metadata\ClassLikeKind;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\ArrayItem;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListElement;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\Reporting\AnnotationKind;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** PHPStan-compatible Stringable return contracts in files without strict_types. */
final class WeakStringableReturnFilter implements IssueFilterHook, InitializationHook
{
    /** @var array<string, array<string, string>> */
    private array $returns = [];

    public function initialize(InitializationContext $context): void
    {
        $this->returns = [];
    }

    public function getCodes(): array
    {
        return ['invalid-return-statement'];
    }

    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        if ($context->issue->code !== 'invalid-return-statement' || strlen($context->contents) > 1024 * 1024
            || strlen($context->issue->message) > 16384
            || preg_match('/^Invalid return type for function `([^`]+)`: expected `([^`]+)`, but found `([^`]+)`\.$/D', $context->issue->message, $match) !== 1) {
            return IssueFilterDecision::Keep;
        }
        $primary = null;
        foreach ($context->issue->annotations as $annotation) {
            if ($annotation->kind === AnnotationKind::Primary) {
                if ($primary !== null || $annotation->file !== null && $annotation->file !== '') {
                    return IssueFilterDecision::Keep;
                }
                $primary = $annotation;
            }
        }
        if ($primary === null) {
            return IssueFilterDecision::Keep;
        }
        $key = hash('sha256', $context->file."\0".$context->contents);
        if (! isset($this->returns[$key])) {
            if (count($this->returns) >= 16) {
                unset($this->returns[array_key_first($this->returns)]);
            }
            $this->returns[$key] = $this->returnSites($context->contents);
        }
        $owner = $this->returns[$key][$primary->span->start.':'.$primary->span->end] ?? null;
        if ($owner === null || strcasecmp($owner, $match[1]) !== 0) {
            return IssueFilterDecision::Keep;
        }
        if (str_contains($owner, '::')) {
            [$class, $method] = explode('::', $owner, 2);
            $metadata = $context->codebase->getMethod($class, $method)
                ?? $context->codebase->getDeclaringMethod($class, $method);
        } else {
            $metadata = $context->codebase->getFunction($owner);
        }
        $declared = $metadata?->returnType?->type;
        $expected = DiagnosticReturnTypes::parse($match[2]);
        $found = DiagnosticReturnTypes::parse($match[3], true);
        if ($declared === null || $expected === null || $found === null || $metadata->templates !== []
            || $metadata->flags->contains(MetadataFlags::BY_REFERENCE)) {
            return IssueFilterDecision::Keep;
        }
        $declared = DiagnosticArrayTypes::resolveAliases($declared, $context->codebase);
        if ($declared === null || ! DiagnosticArrayTypes::same($expected, $declared, $context->types)) {
            return IssueFilterDecision::Keep;
        }
        $changed = false;
        $converted = $this->convert($found, $context->codebase, $changed);
        if (! $changed || ! $context->types->isContainedBy($converted, $declared)
            || $metadata->declaredReturnType !== null
                && ! $context->types->isContainedBy($converted, $metadata->declaredReturnType->type)) {
            return IssueFilterDecision::Keep;
        }
        return IssueFilterDecision::Remove;
    }

    private function convert(Type $type, Codebase $codebase, bool &$changed): Type
    {
        $atoms = [];
        foreach ($type->atomicTypes as $atom) {
            if ($atom instanceof NamedObjectType && $this->stringable($atom->name, $codebase)) {
                array_push($atoms, ...Type::string()->atomicTypes);
                $changed = true;
            } elseif ($atom instanceof KeyedArrayType) {
                $items = $atom->knownItems === null ? null : [];
                foreach ($atom->knownItems ?? [] as $item) {
                    $items[] = new ArrayItem($item->key, $item->optional, $this->convert($item->type, $codebase, $changed));
                }
                $atoms[] = new KeyedArrayType($items, $atom->keyType,
                    $atom->valueType === null ? null : $this->convert($atom->valueType, $codebase, $changed), $atom->nonEmpty);
            } elseif ($atom instanceof ListType) {
                $items = $atom->knownElements === null ? null : [];
                foreach ($atom->knownElements ?? [] as $item) {
                    $items[] = new ListElement($item->index, $item->optional, $this->convert($item->type, $codebase, $changed));
                }
                $atoms[] = new ListType($this->convert($atom->elementType, $codebase, $changed), $items, $atom->knownCount, $atom->nonEmpty);
            } else {
                $atoms[] = $atom;
            }
        }
        return Type::fromAtomics(...$atoms)->withFlags($type->flags);
    }

    private function stringable(string $name, Codebase $codebase): bool
    {
        $class = $codebase->getClass($name);
        $method = $codebase->getDeclaringMethod($name, '__toString');
        return $class !== null && $class->kind === ClassLikeKind::Class_ && ! $class->hasIncompleteHierarchy()
            && ! $class->flags->contains(MetadataFlags::ABSTRACT) && $class->templates === []
            && $method !== null && $method->visibility === Visibility::Public && ! $method->static
            && $method->parameters === [] && $method->templates === []
            && ! $method->flags->contains(MetadataFlags::BY_REFERENCE)
            && $method->returnType?->type->__toString() === 'string'
            && ($method->declaredReturnType === null || $method->declaredReturnType->type->__toString() === 'string');
    }

    /** @return array<string, string> */
    private function returnSites(string $contents): array
    {
        try {
            $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($contents) ?? [];
            foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Stmt\Declare_::class) as $declare) {
                foreach ($declare->declares as $item) {
                    if (strtolower($item->key->toString()) === 'strict_types'
                        && $item->value instanceof Node\Scalar\Int_ && $item->value->value === 1) {
                        return [];
                    }
                }
            }
            $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes);
        } catch (Error) {
            return [];
        }
        $sites = [];
        $visit = static function (mixed $value, ?string $class, ?string $owner) use (&$visit, &$sites): void {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $visit($item, $class, $owner);
                }
            } elseif ($value instanceof Node) {
                if ($value instanceof Node\Stmt\ClassLike) {
                    $class = isset($value->namespacedName) ? $value->namespacedName->toString() : null;
                    $owner = null;
                }
                if ($value instanceof Node\FunctionLike) {
                    $owner = match (true) {
                        $value instanceof Node\Stmt\Function_ => $value->namespacedName->toString(),
                        $value instanceof Node\Stmt\ClassMethod && $class !== null => $class.'::'.$value->name->toString(),
                        default => null,
                    };
                    if ($value->returnsByRef()) {
                        $owner = null;
                    }
                }
                if ($value instanceof Node\Stmt\Return_ && $value->expr !== null && $owner !== null) {
                    $sites[$value->expr->getStartFilePos().':'.($value->expr->getEndFilePos() + 1)] = $owner;
                }
                foreach ($value->getSubNodeNames() as $name) {
                    $visit($value->$name, $class, $owner);
                }
            }
        };
        $visit($nodes, null, null);
        return $sites;
    }
}
