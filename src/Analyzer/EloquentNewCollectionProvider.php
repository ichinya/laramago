<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ModelReflection;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\InvocationKind;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\GenericParameterType;
use Mago\Sdk\Analyzer\Type\GenericParentKind;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\Analyzer\Type\Variance;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;

/** Specialize the native empty model collection without changing populated arrays. */
final class EloquentNewCollectionProvider implements MethodReturnTypeProvider, InitializationHook
{
    private const MODEL = 'Illuminate\\Database\\Eloquent\\Model';
    private const TRAIT = 'Illuminate\\Database\\Eloquent\\HasCollection';

    private ?PhpSource $source = null;

    public function __construct(private readonly string $root = '.') {}

    public function initialize(InitializationContext $context): void
    {
        $this->source = null;
    }

    public function getTargets(): array
    {
        return [MethodTarget::exact(self::MODEL, 'newCollection'), MethodTarget::exact(self::TRAIT, 'newCollection')];
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $call = $context->invocation;
        $model = $call->receiverType?->atomicTypes[0] ?? null;
        if ($call->kind !== InvocationKind::InstanceMethod
            || strcasecmp($call->name, 'newCollection') !== 0
            || count($call->receiverType?->atomicTypes ?? []) !== 1
            || ! $model instanceof NamedObjectType || ($model->intersections ?? []) !== []
            || ($model->parameters ?? []) !== [] || count($call->arguments) > 1) {
            return null;
        }
        if ($call->arguments !== []) {
            $models = $call->getArgument(0, 'models');
            if ($models === null || $models->unpacked || $models->placeholder
                || preg_match('/^\s*(?:\[\s*\]|array\s*\(\s*\))\s*$/iD', $models->expression) !== 1) {
                return null;
            }
        }
        $class = $context->codebase->getClass($model->name);
        if ($class === null || $class->hasIncompleteHierarchy()
            || ! in_array(strtolower(self::MODEL), array_map(strtolower(...), [$class->name, ...$class->parentClasses]), true)) {
            return null;
        }
        foreach ([$class->name, ...$class->parentClasses] as $ancestor) {
            $metadata = $context->codebase->getClass($ancestor);
            if ($metadata === null || $metadata->hasIncompleteHierarchy()
                || array_filter([...$metadata->pseudoMethods, ...$metadata->staticPseudoMethods],
                    static fn (string $name): bool => strcasecmp($name, 'newCollection') === 0) !== []) {
                return null;
            }
        }
        $reflection = new ModelReflection($context->codebase, $this->source ??= new PhpSource($this->root));
        if (! $this->nativeMapping($context->codebase, [$class->name, ...$class->parentClasses])) {
            return null;
        }
        foreach (['newCollection', 'resolveCollectionFromAttribute'] as $name) {
            $method = $reflection->method($model->name, $name);
            if ($method === null || strcasecmp($method->identifier->class ?? '', self::TRAIT) !== 0
                || $method->static || $method->abstract || $method->visibility !== Visibility::Public
                || $method->declaredReturnType !== null || $method->templates !== []
                || $method->flags->contains(MetadataFlags::BY_REFERENCE)
                || ! str_ends_with('/'.ltrim(str_replace('\\', '/', $method->location->file ?? ''), '/'),
                    '/laravel/framework/src/Illuminate/Database/Eloquent/HasCollection.php')) {
                return null;
            }
            $node = $reflection->methodNode($method);
            if ($node === null || ! $node->isPublic() || $node->isStatic() || $node->byRef
                || $node->returnType !== null || $node->stmts === null
                || (new Standard)->prettyPrint($node->stmts) !== str_replace("\r\n", "\n", $this->body($name))) {
                return null;
            }
            if ($name === 'newCollection') {
                $return = $method->returnType?->type;
                $template = $return?->atomicTypes[0] ?? null;
                $parameter = $node->params[0] ?? null;
                if (count($node->params) !== 1 || count($method->parameters) !== 1
                    || count($return?->atomicTypes ?? []) !== 1 || ! $template instanceof GenericParameterType
                    || $template->name !== 'TCollection' || ! ($method->returnType?->fromDocblock ?? false)
                    || $template->definingEntity->kind !== GenericParentKind::ClassLike
                    || strcasecmp($template->definingEntity->name, self::TRAIT) !== 0
                    || ! $parameter?->var instanceof Node\Expr\Variable || $parameter->var->name !== 'models'
                    || ! $parameter->type instanceof Node\Identifier || $parameter->type->name !== 'array'
                    || $parameter->byRef || $parameter->variadic
                    || ! $parameter->default instanceof Node\Expr\Array_ || $parameter->default->items !== []) {
                    return null;
                }
            } elseif ($node->params !== [] || $method->parameters !== []) {
                return null;
            }
        }
        $cache = $context->codebase->getDeclaringProperty($model->name, '$resolvedCollectionClasses');
        if ($cache === null || ! str_ends_with('/'.ltrim(str_replace('\\', '/', $cache->nameLocation?->file ?? ''), '/'),
                '/laravel/framework/src/Illuminate/Database/Eloquent/HasCollection.php')
            || ! $cache->flags->contains(MetadataFlags::STATIC)
            || $cache->readVisibility !== Visibility::Protected || $cache->writeVisibility !== Visibility::Protected
            || $cache->hooks !== []
            || $reflection->default($model->name, 'resolvedCollectionClasses') !== []) {
            return null;
        }

        // Custom factories were rejected above. The resolver keeps CollectedBy and
        // collectionClass contracts; custom collections are never rewritten as base collections.
        return (new EloquentCollectionType)->resolve($context->codebase, Type::fromAtomic($model));
    }

    /** @param list<string> $ancestors */
    private function nativeMapping(Codebase $codebase, array $ancestors): bool
    {
        $trait = $codebase->getClassLike(self::TRAIT);
        $template = $trait?->templates[0] ?? null;
        $traitNode = $this->classNode($codebase, self::TRAIT);
        $doc = $traitNode?->getDocComment()?->getText() ?? '';
        if ($trait === null || count($trait->templates) !== 1 || $template?->name !== 'TCollection'
            || $template->default !== null || $template->variance !== Variance::Invariant
            || preg_match_all('/@(?:(?:phpstan|psalm)-)?template(?:-covariant|-contravariant)?\b/', $doc) !== 1
            || preg_match('/@template\s+TCollection\s+of\s+\\\\Illuminate\\\\Database\\\\Eloquent\\\\Collection[\t ]*(?:\r?\n|\*\/)/', $doc) !== 1) {
            return false;
        }
        foreach ($ancestors as $ancestor) {
            $node = $this->classNode($codebase, $ancestor);
            if ($node === null) {
                return false;
            }
            $native = strcasecmp($ancestor, self::MODEL) === 0;
            $found = false;
            foreach ($node->getTraitUses() as $use) {
                foreach ($use->traits as $name) {
                    if (strcasecmp($name->toString(), self::TRAIT) === 0) {
                        if (! $native || $found || $use->adaptations !== []
                            || preg_replace('/\s+/', '', $use->getDocComment()?->getText() ?? '')
                                !== '/**@useHasCollection<\\Illuminate\\Database\\Eloquent\\Collection<array-key,static&self>>*/') {
                            return false;
                        }
                        $found = true;
                    } elseif (! $native && $this->customTraitUsesCollection($codebase, $name->toString(), []) !== false) {
                        return false;
                    }
                }
            }
            if ($native && (! $found || ! str_ends_with('/'.ltrim(str_replace('\\', '/',
                    $codebase->getClass(self::MODEL)?->location->file ?? ''), '/'),
                    '/laravel/framework/src/Illuminate/Database/Eloquent/Model.php'))) {
                return false;
            }
        }
        return true;
    }

    /** @param list<string> $seen */
    private function customTraitUsesCollection(Codebase $codebase, string $name, array $seen): ?bool
    {
        if (count($seen) >= 32 || in_array(strtolower($name), $seen, true)) {
            return null;
        }
        if (strcasecmp($name, self::TRAIT) === 0) {
            return true;
        }
        $node = $this->classNode($codebase, $name);
        if (! $node instanceof Node\Stmt\Trait_) {
            return null;
        }
        $seen[] = strtolower($name);
        foreach ($node->getTraitUses() as $use) {
            foreach ($use->traits as $trait) {
                $result = $this->customTraitUsesCollection($codebase, $trait->toString(), $seen);
                if ($result !== false) {
                    return $result;
                }
            }
        }
        return false;
    }

    private function classNode(Codebase $codebase, string $name): ?Node\Stmt\ClassLike
    {
        $metadata = $codebase->getClassLike($name);
        $file = $metadata?->location->file;
        if ($metadata === null || $metadata->hasIncompleteHierarchy() || $file === null || str_starts_with($file, '@')) {
            return null;
        }
        $normalized = str_replace('\\', '/', $file);
        if (DIRECTORY_SEPARATOR === '\\' && preg_match('~^//\?/[A-Za-z]:/~', $normalized) === 1) {
            $file = substr($normalized, 4);
        }
        $nodes = (new NodeFinder)->find($this->source?->read($file) ?? [], static fn (Node $node): bool =>
            $node instanceof Node\Stmt\ClassLike && $node->namespacedName !== null
                && strcasecmp($node->namespacedName->toString(), $name) === 0);
        return count($nodes) === 1 ? $nodes[0] : null;
    }

    private function body(string $method): string
    {
        return $method === 'newCollection' ? <<<'PHP'
static::$resolvedCollectionClasses[static::class] ??= $this->resolveCollectionFromAttribute() ?? static::$collectionClass;
$collection = new static::$resolvedCollectionClasses[static::class]($models);
if (\Illuminate\Database\Eloquent\Model::isAutomaticallyEagerLoadingRelationships()) {
    $collection->withRelationshipAutoloading();
}
return $collection;
PHP : <<<'PHP'
$reflection = new \ReflectionClass(static::class);
do {
    $attributes = $reflection->getAttributes(\Illuminate\Database\Eloquent\Attributes\CollectedBy::class);
    if (isset($attributes[0], $attributes[0]->getArguments()[0])) {
        return $attributes[0]->getArguments()[0];
    }
} while ($reflection = $reflection->getParentClass());
return null;
PHP;
    }
}
