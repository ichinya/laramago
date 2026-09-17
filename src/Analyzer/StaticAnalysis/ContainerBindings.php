<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use PhpParser\Node;

/** Syntax-only container bindings from explicitly opted-in application catalogs. */
final class ContainerBindings
{
    /** @var array<string, string> */
    private array $bindings = [];
    /** @var array<string, string> */
    private array $aliases = [];
    /** @var array<string, true> */
    private array $blocked = [];
    private bool $unknown = false;

    public function __construct(string $root)
    {
        $source = new PhpSource($root);
        $json = @file_get_contents($root.'/composer.json');
        /** @var mixed $composer */
        $composer = $json === false ? null : json_decode($json, true);
        /** @var mixed $extra */
        $extra = is_array($composer) ? $composer['extra'] ?? null : null;
        /** @var mixed $laramago */
        $laramago = is_array($extra) ? $extra['laramago'] ?? null : null;
        if (! is_array($laramago) || ! array_key_exists('binding-files', $laramago)) {
            return;
        }
        /** @var mixed $files */
        $files = $laramago['binding-files'];
        if (! is_array($files) || ! array_is_list($files)) {
            $this->unknown = true;

            return;
        }
        $paths = array_filter($files, is_string(...));
        if (count($paths) !== count($files)) {
            $this->unknown = true;
        }
        foreach ($paths as $file) {
            if (
                ! preg_match('~^(?:app|bootstrap)/[a-zA-Z0-9_./-]+\.php$~', $file)
                || in_array('..', explode('/', $file), true)
            ) {
                $this->unknown = true;
                continue;
            }
            $nodes = $source->read($file);
            if ($nodes === null) {
                $this->unknown = true;
                continue;
            }
            foreach ($nodes as $node) {
                $this->scan($node, true);
            }
        }
    }

    public function configured(string $abstract): bool
    {
        return (
            $this->unknown
            || isset($this->bindings[$abstract])
            || isset($this->aliases[$abstract])
            || isset($this->blocked[$abstract])
        );
    }

    public function concrete(string $abstract, Codebase $codebase): ?string
    {
        if ($this->unknown) {
            return null;
        }
        $requested = $abstract;
        $contracts = [];
        $seen = [];
        while (true) {
            if (isset($seen[$abstract]) || isset($this->blocked[$abstract])) {
                return null;
            }
            $seen[$abstract] = true;
            if ($codebase->classOrInterfaceExists($abstract)) {
                $contracts[] = $abstract;
            }
            if (isset($this->aliases[$abstract])) {
                $abstract = $this->aliases[$abstract];
                continue;
            }
            if (isset($this->bindings[$abstract])) {
                $concrete = $this->bindings[$abstract];
                if ($concrete === $abstract) {
                    break;
                }
                $abstract = $concrete;
                continue;
            }
            break;
        }
        $class = $codebase->getClass($abstract);
        if (
            $class === null
            || $class->flags->contains(MetadataFlags::ABSTRACT)
            || $class->hasIncompleteHierarchy()
            || $class->templates !== []
        ) {
            return null;
        }
        $ancestors = array_map(strtolower(...), [
            $class->name,
            ...$class->parentClasses,
            ...$class->parentInterfaces,
        ]);
        foreach ($contracts as $contract) {
            if (! in_array(strtolower($contract), $ancestors, true)) {
                return null;
            }
        }

        // Plain aliases are permitted, but an unconfigured plain string is unknown.
        return $requested === $abstract && ! $this->configured($requested) && ! $codebase->classExists($requested)
            ? null
            : $class->name;
    }

    private function scan(Node $node, bool $direct): void
    {
        if ($node instanceof Node\Expr\MethodCall && $this->containerReceiver($node->var)) {
            $name = $node->name instanceof Node\Identifier ? strtolower($node->name->toString()) : null;
            if (in_array($name, ['bind', 'singleton', 'alias'], true)) {
                $this->registration($node, $name, $direct);
            } elseif (
                $name === null
                || in_array(
                    $name,
                    [
                        'bindif',
                        'singletonif',
                        'scoped',
                        'scopedif',
                        'instance',
                        'extend',
                        'when',
                        'rebinding',
                        'resolving',
                        'beforeresolving',
                        'afterresolving',
                    ],
                    true,
                )
            ) {
                $this->unknown = true;
            }
        } elseif ($node instanceof Node\Expr\MethodCall && self::unprovenAppReceiver($node->var)) {
            // A namespaced app() may resolve to a local function. Without runtime
            // name fallback, no registration in this catalog is safe to assume.
            $this->unknown = true;
        }
        $properties = get_object_vars($node);
        foreach ($node->getSubNodeNames() as $key) {
            /** @var mixed $value */
            $value = $properties[$key] ?? null;
            $children = is_array($value) ? $value : [$value];
            foreach (array_filter($children, static fn (mixed $child): bool => $child instanceof Node) as $child) {
                $allowed = $direct && ($node instanceof Node\Stmt\Namespace_ || $node instanceof Node\Stmt\Expression);
                $this->scan($child, $allowed);
            }
        }
    }

    private function registration(Node\Expr\MethodCall $call, string $method, bool $direct): void
    {
        if (! self::validArguments($call, $method)) {
            $this->unknown = true;

            return;
        }
        $abstractNode = PhpSource::argument($call->args, 0, 'abstract');
        $abstract = self::name($abstractNode);
        if ($abstract === null) {
            $this->unknown = true;

            return;
        }
        if ($method === 'alias') {
            $alias = self::name(PhpSource::argument($call->args, 1, 'alias'));
            if ($alias === null) {
                $this->unknown = true;

                return;
            }
            if (! $direct) {
                unset($this->aliases[$alias]);
                $this->blocked[$alias] = true;

                return;
            }
            $this->put($this->aliases, $alias, $abstract);

            return;
        }
        if (! $direct) {
            $this->blocked[$abstract] = true;

            return;
        }
        $concreteNode = PhpSource::argument($call->args, 1, 'concrete');
        $concrete = $concreteNode === null ? self::className($abstractNode) : self::className($concreteNode);
        if ($concrete === null) {
            $this->blocked[$abstract] = true;

            return;
        }
        $this->put($this->bindings, $abstract, $concrete);
    }

    /** @param array<string, string> $values */
    private function put(array &$values, string $key, string $value): void
    {
        if (
            isset($this->blocked[$key])
            || array_key_exists($key, $this->bindings)
            || array_key_exists($key, $this->aliases)
        ) {
            unset($this->bindings[$key], $this->aliases[$key]);
            $this->blocked[$key] = true;

            return;
        }
        $values[$key] = $value;
    }

    private function containerReceiver(Node\Expr $receiver): bool
    {
        if (
            $receiver instanceof Node\Expr\FuncCall
            && $receiver->name instanceof Node\Name
            && $receiver->name->isFullyQualified()
            && strtolower($receiver->name->toString()) === 'app'
            && $receiver->args === []
        ) {
            return true;
        }

        return (
            $receiver instanceof Node\Expr\StaticCall
            && $receiver->class instanceof Node\Name
            && strcasecmp($receiver->class->toString(), 'Illuminate\\Container\\Container') === 0
            && $receiver->name instanceof Node\Identifier
            && strtolower($receiver->name->toString()) === 'getinstance'
            && $receiver->args === []
        );
    }

    private static function unprovenAppReceiver(Node\Expr $receiver): bool
    {
        return (
            $receiver instanceof Node\Expr\FuncCall
            && $receiver->name instanceof Node\Name
            && ! $receiver->name->isFullyQualified()
            && strtolower($receiver->name->toString()) === 'app'
        );
    }

    private static function name(?Node $node): ?string
    {
        $value = PhpSource::value($node);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function className(?Node $node): ?string
    {
        if (
            ! $node instanceof Node\Expr\ClassConstFetch
            || ! $node->class instanceof Node\Name
            || ! $node->name instanceof Node\Identifier
            || strtolower($node->name->toString()) !== 'class'
        ) {
            return null;
        }

        return $node->class->toString();
    }

    private static function validArguments(Node\Expr\MethodCall $call, string $method): bool
    {
        $names = match ($method) {
            'alias' => ['abstract', 'alias'],
            'singleton' => ['abstract', 'concrete'],
            default => ['abstract', 'concrete', 'shared'],
        };
        $maximum = $method === 'bind' ? 3 : 2;
        if (count($call->args) > $maximum) {
            return false;
        }
        foreach ($call->args as $argument) {
            if (
                ! $argument instanceof Node\Arg
                || $argument->unpack
                || $argument->name !== null
                && ! in_array(strtolower($argument->name->toString()), $names, true)
            ) {
                return false;
            }
        }

        return true;
    }
}
