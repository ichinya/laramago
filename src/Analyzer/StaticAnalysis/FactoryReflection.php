<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;

/** Resolves factory declarations as syntax, without loading application classes. */
final class FactoryReflection
{
    public const FACTORY = 'Illuminate\\Database\\Eloquent\\Factories\\Factory';
    public const HAS_FACTORY = 'Illuminate\\Database\\Eloquent\\Factories\\HasFactory';

    public function __construct(
        private readonly Codebase $codebase,
        private readonly PhpSource $source,
    ) {}

    public function method(string $class, string $name): ?FunctionLikeMetadata
    {
        return $this->codebase->getMethod($class, $name) ?? $this->codebase->getDeclaringMethod($class, $name);
    }

    public function inherits(string $class, string $parent): bool
    {
        return (
            strcasecmp($class, $parent) === 0
            || in_array(
                strtolower($parent),
                array_map(strtolower(...), $this->codebase->getClassAncestors($class)),
                true,
            )
        );
    }

    public function modelFactory(string $model): ?string
    {
        $method = $this->method($model, 'factory');
        if ($method === null || strcasecmp($method->identifier->class ?? '', self::HAS_FACTORY) !== 0) {
            return null;
        }
        $reflection = new ModelReflection($this->codebase, $this->source);
        $factory = $reflection->default($model, 'factory');
        if ($factory instanceof UnknownValue) {
            return null;
        }
        $newFactory = $this->method($model, 'newFactory');
        if ($newFactory !== null && strcasecmp($newFactory->identifier->class ?? '', self::HAS_FACTORY) !== 0) {
            // A native concrete return contract can describe a custom resolver.
            $type = $newFactory->returnType?->type ?? $newFactory->declaredReturnType?->type;
            $factory = null;
            foreach ($type?->atomicTypes ?? [] as $atom) {
                if (
                    $atom instanceof \Mago\Sdk\Analyzer\Type\NamedObjectType
                    && $this->inherits($atom->name, self::FACTORY)
                ) {
                    if ($factory !== null) {
                        return null;
                    }
                    $factory = $atom->name;
                } else {
                    return null;
                }
            }
            if ($factory === null) {
                return null;
            }
        }
        foreach ([$model, ...$this->codebase->getClassAncestors($model)] as $class) {
            foreach ($this->codebase->getClass($class)?->attributes ?? [] as $attribute) {
                if (strcasecmp($attribute->name, 'Illuminate\\Database\\Eloquent\\Attributes\\UseFactory') === 0) {
                    $factory ??= $attribute->arguments[0]->valueType?->getLiteralClassString();
                    if ($factory === null) {
                        return null;
                    }
                }
            }
            $factory ??= $this->binding($class, self::HAS_FACTORY);
        }
        if ($factory === null) {
            // Laravel's default App namespace and Database\\Factories convention.
            $relative = str_starts_with($model, 'App\\Models\\')
                ? substr($model, 11)
                : (str_starts_with($model, 'App\\') ? substr($model, 4) : null);
            $factory = $relative === null ? null : 'Database\\Factories\\'.$relative.'Factory';
        }

        return is_string($factory) && $this->codebase->classExists($factory) && $this->inherits($factory, self::FACTORY)
            ? $factory
            : null;
    }

    public function factoryModel(string $factory): ?string
    {
        $model = (new ModelReflection($this->codebase, $this->source))->default($factory, 'model');
        if ($model instanceof UnknownValue) {
            return null;
        }
        foreach ([$factory, ...$this->codebase->getClassAncestors($factory)] as $class) {
            $model ??= $this->binding($class, self::FACTORY);
        }
        if (
            $model === null
            && str_starts_with($factory, 'Database\\Factories\\')
            && str_ends_with($factory, 'Factory')
        ) {
            $relative = substr($factory, 19, -7);
            $candidate = 'App\\Models\\'.$relative;
            $model = $this->codebase->classExists($candidate) ? $candidate : 'App\\'.$relative;
        }

        return is_string($model)
        && $this->codebase->classExists($model)
        && $this->inherits($model, ModelReflection::MODEL)
            ? $model
            : null;
    }

    /** Mutable subclasses cannot retain a count refinement after an ignored method call. */
    public function hasStateMutation(string $factory): bool
    {
        foreach ([$factory, ...$this->codebase->getClassAncestors($factory)] as $class) {
            if (strcasecmp($class, self::FACTORY) === 0) {
                continue;
            }
            $metadata = $this->codebase->getClassLike($class);
            foreach ($metadata?->methods ?? [] as $name) {
                $method = $this->method($class, $name);
                if ($method === null || strcasecmp($method->identifier->class ?? '', self::FACTORY) === 0) {
                    continue;
                }
                $node = (new ModelReflection($this->codebase, $this->source))->methodNode($method);
                if ($node === null) {
                    return true;
                }
                $mutation = (new NodeFinder)->findFirst($node->stmts ?? [], static function (Node $node): bool {
                    if (
                        ! (
                            $node instanceof Node\Expr\Assign
                            || $node instanceof Node\Expr\AssignRef
                            || $node instanceof Node\Expr\AssignOp
                            || $node instanceof Node\Expr\PreInc
                            || $node instanceof Node\Expr\PostInc
                            || $node instanceof Node\Expr\PreDec
                            || $node instanceof Node\Expr\PostDec
                        )
                    ) {
                        return false;
                    }
                    $target = $node->var;
                    while ($target instanceof Node\Expr\ArrayDimFetch) {
                        $target = $target->var;
                    }

                    return (
                        $target instanceof Node\Expr\PropertyFetch
                        && $target->var instanceof Node\Expr\Variable
                        && $target->var->name === 'this'
                        && (
                            ! $target->name instanceof Node\Identifier
                            || in_array(strtolower($target->name->toString()), ['count', 'model'], true)
                        )
                    );
                });
                if ($mutation !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Read a single named generic binding, using PHP's namespace and import rules. */
    private function binding(string $class, string $base): ?string
    {
        $metadata = $this->codebase->getClass($class);
        $file = $metadata?->location->file;
        if ($file === null || str_starts_with($file, '@')) {
            return null;
        }
        $resolver = new NameResolver;
        $visitor = new class($resolver, $class, $base) extends NodeVisitorAbstract {
            public ?string $binding = null;
            private bool $inside = false;

            public function __construct(
                private NameResolver $resolver,
                private string $class,
                private string $base,
            ) {}

            public function enterNode(Node $node): null
            {
                if ($node instanceof Node\Stmt\ClassLike) {
                    $this->inside = strcasecmp($node->namespacedName?->toString() ?? '', $this->class) === 0;
                }
                if (
                    ! $this->inside
                    || ! ($node instanceof Node\Stmt\ClassLike
                    || $node instanceof Node\Stmt\TraitUse)
                ) {
                    return null;
                }
                $matches = [];
                preg_match_all(
                    '/@(?:phpstan-|psalm-)?(?:extends|use|template-extends|template-use)\s+([\\\\\w]+)\s*<\s*([\\\\\w]+)\s*>/',
                    $node->getDocComment()?->getText() ?? '',
                    $matches,
                    PREG_SET_ORDER,
                );
                foreach ($matches as $match) {
                    $context = $this->resolver->getNameContext();
                    $parent = str_starts_with($match[1], '\\')
                        ? new Node\Name\FullyQualified(substr($match[1], 1))
                        : new Node\Name($match[1]);
                    $argument = str_starts_with($match[2], '\\')
                        ? new Node\Name\FullyQualified(substr($match[2], 1))
                        : new Node\Name($match[2]);
                    if (strcasecmp($context->getResolvedClassName($parent)->toString(), $this->base) === 0) {
                        $this->binding = $context->getResolvedClassName($argument)->toString();
                    }
                }

                return null;
            }
        };
        (new NodeTraverser($resolver, $visitor))->traverse($this->source->read($file) ?? []);

        return $visitor->binding;
    }
}
