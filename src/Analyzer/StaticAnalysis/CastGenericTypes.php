<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\ArrayItem;
use Mago\Sdk\Analyzer\Type\GenericParameterType;
use Mago\Sdk\Analyzer\Type\GenericParentKind;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListElement;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use PhpParser\NameContext;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;

/** Substitutes class templates only along verified, explicitly annotated ancestry. */
final class CastGenericTypes
{
    public function __construct(
        private readonly Codebase $codebase,
        private readonly PhpSource $source,
        private readonly string $class,
    ) {}

    public function substitute(Type $type): Type
    {
        $parts = [];
        foreach ($type->atomicTypes as $atom) {
            if ($atom instanceof GenericParameterType) {
                $bindings = $atom->definingEntity->kind === GenericParentKind::ClassLike
                    ? $this->bindings($this->class, $atom->definingEntity->name)
                    : null;
                $parts[] = ($atom->intersections ?? []) === []
                    ? $bindings[$atom->name] ?? Type::fromAtomic($atom)
                    : Type::fromAtomic($atom);
                continue;
            }
            if ($atom instanceof NamedObjectType && ($atom->intersections ?? []) === []) {
                $atom = new NamedObjectType(
                    $atom->name,
                    $atom->parameters === null ? null : array_map($this->substitute(...), $atom->parameters),
                    $atom->variances,
                    $atom->static,
                    $atom->isThis,
                    $atom->intersections,
                    $atom->remappedParameters,
                );
            } elseif ($atom instanceof ListType) {
                $atom = new ListType(
                    $this->substitute($atom->elementType),
                    $atom->knownElements === null
                        ? null
                        : array_map(
                            fn (ListElement $item): ListElement => new ListElement(
                                $item->index,
                                $item->optional,
                                $this->substitute($item->type),
                            ),
                            $atom->knownElements,
                        ),
                    $atom->knownCount,
                    $atom->nonEmpty,
                );
            } elseif ($atom instanceof KeyedArrayType) {
                $atom = new KeyedArrayType(
                    $atom->knownItems === null
                        ? null
                        : array_map(
                            fn (ArrayItem $item): ArrayItem => new ArrayItem(
                                $item->key,
                                $item->optional,
                                $this->substitute($item->type),
                            ),
                            $atom->knownItems,
                        ),
                    $atom->keyType === null ? null : $this->substitute($atom->keyType),
                    $atom->valueType === null ? null : $this->substitute($atom->valueType),
                    $atom->nonEmpty,
                );
            }
            $parts[] = Type::fromAtomic($atom);
        }
        $first = array_shift($parts);

        return $first === null
            ? $type
            : array_reduce($parts, static fn (Type $a, Type $b): Type => Type::union($a, $b), $first);
    }

    /**
     * @param array<string, Type> $arguments
     * @return array<string, Type>|null
     */
    private function bindings(string $class, string $target, array $arguments = [], int $depth = 0): ?array
    {
        if ($depth > 16) {
            return null;
        }
        if (strcasecmp($class, $target) === 0) {
            return $arguments;
        }
        $metadata = $this->codebase->getClassLike($class);
        if ($metadata === null || $metadata->hasIncompleteHierarchy()) {
            return null;
        }
        $parents = $metadata->directParentInterfaces;
        if ($metadata->directParentClass !== null) {
            $parents[] = $metadata->directParentClass;
        }
        $annotations = $this->annotations($class);
        $result = null;
        foreach ($parents as $parent) {
            if (
                strcasecmp($parent, $target) !== 0
                && ! in_array(
                    strtolower($target),
                    array_map(strtolower(...), $this->codebase->getClassAncestors($parent)),
                    true,
                )
            ) {
                continue;
            }
            $bindings = [];
            $parentMetadata = $this->codebase->getClassLike($parent);
            $annotation = $annotations[strtolower($parent)] ?? null;
            if ($parentMetadata === null) {
                return null;
            }
            if ($parentMetadata->templates !== []) {
                if ($annotation === null || count($annotation['arguments']) !== count($parentMetadata->templates)) {
                    return null;
                }
                foreach ($parentMetadata->templates as $index => $template) {
                    $unbound = array_keys($metadata->typeAliases);
                    foreach ($metadata->templates as $localTemplate) {
                        if (! isset($arguments[$localTemplate->name])) {
                            $unbound[] = $localTemplate->name;
                        }
                    }
                    $binding = (new CastTypeExpression(
                        $this->codebase,
                        $annotation['context'],
                        $arguments,
                        $unbound,
                    ))->parse($annotation['arguments'][$index]);
                    if ($binding === null) {
                        return null;
                    }
                    $bindings[$template->name] = $binding;
                }
            }
            $found = $this->bindings($parent, $target, $bindings, $depth + 1);
            // Missing bindings or multiple paths to a template owner stay unresolved.
            if ($found === null || $result !== null) {
                return null;
            }
            $result = $found;
        }

        return $result;
    }

    /** @return array<string, array{arguments: list<string>, context: NameContext}> */
    private function annotations(string $class): array
    {
        $file = $this->codebase->getClassLike($class)?->location->file;
        if ($file === null || str_starts_with($file, '@')) {
            return [];
        }
        $resolver = new NameResolver;
        $visitor = new class($class, $resolver) extends NodeVisitorAbstract {
            /** @var array<string, array{arguments: list<string>, context: NameContext}> */
            public array $annotations = [];

            public function __construct(
                private string $class,
                private NameResolver $resolver,
            ) {}

            public function enterNode(Node $node): null
            {
                if (
                    ! $node instanceof Node\Stmt\ClassLike
                    || strcasecmp($node->namespacedName?->toString() ?? '', $this->class) !== 0
                ) {
                    return null;
                }
                $matches = [];
                preg_match_all(
                    '/@(?:phpstan-|psalm-)?(?:template-)?(?:extends|implements)\s+([\\\\\w]+)\s*<(?<arguments>(?:[^<>]|<(?&arguments)>)*)>/',
                    preg_replace('/^\s*\* ?/m', '', $node->getDocComment()?->getText() ?? '') ?? '',
                    $matches,
                    PREG_SET_ORDER,
                );
                foreach ($matches as $match) {
                    $context = clone $this->resolver->getNameContext();
                    $name = str_starts_with($match[1], '\\')
                        ? new Node\Name\FullyQualified(substr($match[1], 1))
                        : new Node\Name($match[1]);
                    $resolved = strtolower($context->getResolvedClassName($name)->toString());
                    if (isset($this->annotations[$resolved])) {
                        $this->annotations[$resolved]['arguments'] = [];
                        continue;
                    }
                    $this->annotations[$resolved] = [
                        'arguments' => CastTypeExpression::split($match['arguments'], ',') ?? [],
                        'context' => $context,
                    ];
                }

                return null;
            }
        };
        (new NodeTraverser($resolver, $visitor))->traverse($this->source->read($file) ?? []);

        return $visitor->annotations;
    }
}
