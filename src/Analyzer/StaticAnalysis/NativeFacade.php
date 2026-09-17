<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;

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
        $declared = $codebase->getMethod($facade, $method) ?? $codebase->getDeclaringMethod($facade, $method);
        if (
            $declared !== null
            && strcasecmp($declared->identifier->class ?? '', $facade) === 0
            && $reflection->methodNode($declared) !== null
        ) {
            // A real static method takes priority over magic facade forwarding.
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
