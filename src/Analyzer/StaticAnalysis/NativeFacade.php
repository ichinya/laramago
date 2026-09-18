<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use PhpParser\Node;
use PhpParser\NodeFinder;

/** Proves a call uses an unmodified Laravel facade accessor and native root method. */
final class NativeFacade
{
    public const BASE = 'Illuminate\\Support\\Facades\\Facade';

    private readonly PhpSource $source;

    public function __construct(string $root)
    {
        $this->source = new PhpSource($root);
    }

    public function dispatches(
        Codebase $codebase,
        ?Type $receiver,
        string $facade,
        string $accessor,
        string $root,
        string $method,
    ): bool {
        $object = $receiver?->atomicTypes[0] ?? null;
        if (
            $receiver === null
            || count($receiver->atomicTypes) !== 1
            || ! $object instanceof NamedObjectType
            || strcasecmp($object->name, $facade) !== 0
        ) {
            return false;
        }

        return $this->dispatchesClass($codebase, $facade, $accessor, $root, $method);
    }

    public function dispatchesClass(
        Codebase $codebase,
        string $facade,
        string $accessor,
        string $root,
        string $method,
    ): bool {
        $class = $codebase->getClassLike($facade);
        if ($class === null || $class->hasIncompleteHierarchy()) {
            return false;
        }
        $reflection = new ModelReflection($codebase, $this->source);
        if (! $this->usesMagicDispatch($codebase, $facade, $method)) {
            return false;
        }
        $facadeAccessor = $codebase->getDeclaringMethod($facade, 'getFacadeAccessor');
        $dispatcher = $codebase->getDeclaringMethod($facade, '__callStatic');
        $rootGetter = $codebase->getDeclaringMethod($facade, 'getFacadeRoot');
        $resolver = $codebase->getDeclaringMethod($facade, 'resolveFacadeInstance');
        if (
            $facadeAccessor === null
            || $dispatcher === null
            || $rootGetter === null
            || $resolver === null
            || strcasecmp($facadeAccessor->identifier->class ?? '', $facade) !== 0
            || strcasecmp($dispatcher->identifier->class ?? '', self::BASE) !== 0
            || strcasecmp($rootGetter->identifier->class ?? '', self::BASE) !== 0
            || strcasecmp($resolver->identifier->class ?? '', self::BASE) !== 0
            || ! $dispatcher->static
            || ! $rootGetter->static
            || ! $resolver->static
            || ! self::frameworkFile($dispatcher->location->file, 'Illuminate/Support/Facades/Facade.php')
            || ! self::frameworkFile($rootGetter->location->file, 'Illuminate/Support/Facades/Facade.php')
            || ! self::frameworkFile($resolver->location->file, 'Illuminate/Support/Facades/Facade.php')
        ) {
            return false;
        }
        $value = PhpSource::value($reflection->returnExpression($facadeAccessor), $facade, $facade);
        if ($value !== $accessor) {
            return false;
        }
        $target = $codebase->getMethod($root, $method) ?? $codebase->getDeclaringMethod($root, $method);

        return (
            $target !== null
            && strcasecmp($target->identifier->class ?? '', $root) === 0
            && ! $target->static
            && self::frameworkFile(
                $facadeAccessor->location->file,
                'Illuminate/Support/Facades/'.self::short($facade).'.php',
            )
            && self::frameworkFile($target->location->file, str_replace('\\', '/', $root).'.php')
        );
    }

    private function usesMagicDispatch(Codebase $codebase, string $facade, string $method): bool
    {
        // Pseudo-method metadata can hide real declarations on any ancestor.
        $visited = [];
        $foundBase = false;
        $class = $facade;
        while ($class !== null) {
            $key = strtolower($class);
            if (isset($visited[$key])) {
                return false;
            }
            $visited[$key] = true;
            $metadata = $codebase->getClassLike($class);
            $file = $metadata?->location->file;
            if ($metadata === null || $metadata->hasIncompleteHierarchy() || $file === null) {
                return false;
            }
            $nodes = $this->source->read(str_starts_with($file, '//?/') ? substr($file, 4) : $file);
            $node = (new NodeFinder)->findFirst(
                $nodes ?? [],
                static fn (Node $node): bool => (
                    $node instanceof Node\Stmt\Class_
                    && strcasecmp($node->namespacedName?->toString() ?? '', $class) === 0
                ),
            );
            if (
                ! $node instanceof Node\Stmt\Class_
                || $node->getMethod($method) !== null
                || $node->getTraitUses() !== []
                || strcasecmp($node->extends?->toString() ?? '', $metadata->directParentClass ?? '') !== 0
            ) {
                // Trait adaptations and unavailable source cannot prove magic forwarding.
                return false;
            }
            $foundBase = $foundBase || strcasecmp($class, self::BASE) === 0;
            $class = $metadata->directParentClass;
        }

        return $foundBase;
    }

    private static function frameworkFile(?string $path, string $suffix): bool
    {
        $path = str_replace('\\', '/', $path ?? '');

        return str_ends_with($path, '/laravel/framework/src/'.$suffix);
    }

    private static function short(string $class): string
    {
        return substr($class, (int) strrpos('\\'.$class, '\\'));
    }
}
