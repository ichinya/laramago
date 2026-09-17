<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\EffectiveCallableSignature;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\CallableParameter;
use PhpParser\Node;

/** Syntax-only registrations from an explicitly opted-in runtime macro catalog. */
final class MacroIndex
{
    /** @var array<string, array<string, array{EffectiveCallableSignature, Type}|null>> */
    public array $contracts = [];
    /** @var array<string, true> */
    public array $blocked = [];
    public bool $unknown = false;

    public function __construct(string $root)
    {
        $source = new PhpSource($root);
        $json = @file_get_contents($root.'/composer.json');
        /** @var mixed $composer */
        $composer = $json === false ? null : json_decode($json, true);
        /** @var mixed $files */
        $files = is_array($composer) ? $composer['extra']['laramago']['macro-files'] ?? [] : [];
        if (! is_array($files) || ! array_is_list($files)) {
            return;
        }
        $paths = array_filter($files, is_string(...));
        if (count($paths) !== count($files)) {
            $this->unknown = true;
        }
        foreach ($paths as $file) {
            // Catalogs stay inside the application and never recursively scan files.
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

    private function scan(Node $node, bool $direct): void
    {
        if ($node instanceof Node\Expr\StaticCall) {
            $name = $node->name instanceof Node\Identifier ? strtolower($node->name->toString()) : null;
            if ($name === null || in_array($name, ['macro', 'mixin', 'flushmacros'], true)) {
                if (! $node->class instanceof Node\Name || $node->class->isSpecialClassName()) {
                    $this->unknown = true;
                } else {
                    $class = strtolower($node->class->toString());
                    $key = PhpSource::argument($node->args, 0, 'name');
                    if ($name !== 'macro' || ! $key instanceof Node\Scalar\String_ || $key->value === '') {
                        $this->blocked[$class] = true;
                    } else {
                        $method = $key->value;
                        $closure = PhpSource::argument($node->args, 1, 'macro');
                        $duplicate = array_key_exists($method, $this->contracts[$class] ?? []);
                        $this->contracts[$class][$method] = $direct && ! $duplicate ? $this->contract($closure) : null;
                    }
                }
            }
        }
        $properties = get_object_vars($node);
        foreach ($node->getSubNodeNames() as $key) {
            /** @var mixed $value */
            $value = $properties[$key] ?? null;
            $children = is_array($value) ? $value : [$value];
            foreach (array_filter($children, static fn (mixed $child): bool => $child instanceof Node) as $child) {
                // Only unconditional top-level expressions are accepted. Provider methods
                // and arbitrary closures are deliberately not assumed to execute.
                $allowed = $direct && ($node instanceof Node\Stmt\Namespace_ || $node instanceof Node\Stmt\Expression);
                $this->scan($child, $allowed);
            }
        }
    }

    /** @return array{EffectiveCallableSignature, Type}|null */
    private function contract(?Node $node): ?array
    {
        if (! $node instanceof Node\Expr\Closure && ! $node instanceof Node\Expr\ArrowFunction) {
            return null;
        }
        $return = self::type($node->returnType);
        if ($return === null || $node->byRef || $node->static) {
            return null;
        }
        $parameters = [];
        foreach ($node->params as $parameter) {
            $type = self::type($parameter->type);
            if (
                $type === null
                || ! $parameter->var instanceof Node\Expr\Variable
                || ! is_string($parameter->var->name)
            ) {
                return null;
            }
            if (
                $parameter->default instanceof Node\Expr\ConstFetch
                && strtolower($parameter->default->name->toString()) === 'null'
            ) {
                $type = Type::union($type, Type::null());
            }
            $parameters[] = new CallableParameter(
                name: '$'.$parameter->var->name,
                type: $type,
                byReference: $parameter->byRef,
                variadic: $parameter->variadic,
                hasDefault: $parameter->default !== null,
            );
        }

        return [new EffectiveCallableSignature($parameters), $return];
    }

    private static function type(?Node $node): ?Type
    {
        if ($node instanceof Node\NullableType) {
            $type = self::type($node->type);

            return $type === null ? null : Type::union($type, Type::null());
        }
        if ($node instanceof Node\UnionType) {
            $result = null;
            foreach ($node->types as $part) {
                $type = self::type($part);
                if ($type === null) {
                    return null;
                }
                $result = $result === null ? $type : Type::union($result, $type);
            }

            return $result;
        }
        if ($node instanceof Node\Name && ! $node->isSpecialClassName()) {
            return Type::namedObject($node->toString());
        }

        return (
            $node instanceof Node\Identifier
                ? match (strtolower($node->toString())) {
                    'int' => Type::int(),
                    'float' => Type::float(),
                    'string' => Type::string(),
                    'bool' => Type::bool(),
                    'null' => Type::null(),
                    'void' => Type::void(),
                    'never' => Type::never(),
                    'mixed' => Type::mixed(),
                    'object' => Type::object(),
                    'array' => Type::array(Type::union(Type::int(), Type::string()), Type::mixed()),
                    default => null,
                } : null
        );
    }
}
