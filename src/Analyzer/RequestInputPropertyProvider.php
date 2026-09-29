<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ModelReflection;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\PropertyTarget;
use Mago\Sdk\Analyzer\PropertyType;
use Mago\Sdk\Analyzer\PropertyTypeProvider;
use Mago\Sdk\Analyzer\PropertyTypeProviderContext;
use Mago\Sdk\Analyzer\Type\MixedType;
use PhpParser\Node;

/** Laravel request properties are raw input reads, not Eloquent attributes. */
final class RequestInputPropertyProvider implements PropertyTypeProvider, InitializationHook
{
    private const REQUEST = 'Illuminate\\Http\\Request';

    private ?PhpSource $source = null;

    public function __construct(private readonly string $root) {}

    public function initialize(InitializationContext $context): void
    {
        $this->source = null;
    }

    public function getTargets(): array
    {
        return [PropertyTarget::allProperties(self::REQUEST)];
    }

    public function getPropertyType(PropertyTypeProviderContext $context): ?PropertyType
    {
        $access = $context->access;
        $class = $access->class;
        $property = '$'.$access->property;
        if (
            $context->codebase->getDeclaringProperty($class, $property) !== null
            || $context->codebase->getDeclaringMagicProperty($class, $property) !== null
        ) {
            return null;
        }
        $method = $context->codebase->getDeclaringMethod($class, '__get');
        $type = $method?->returnType?->type;
        if (
            $method === null
            || strcasecmp($method->identifier->class ?? '', self::REQUEST) !== 0
            || ! str_ends_with(str_replace('\\', '/', $method->location->file ?? ''), '/laravel/framework/src/Illuminate/Http/Request.php')
            || $type === null
            || count($type->atomicTypes) !== 1
            || ! $type->atomicTypes[0] instanceof MixedType
        ) {
            return null;
        }
        $node = (new ModelReflection(
            $context->codebase,
            $this->source ??= new PhpSource($this->root),
        ))->methodNode($method);
        $statement = count($node?->stmts ?? []) === 1 ? $node->stmts[0] : null;
        $call = $statement instanceof Node\Stmt\Return_ ? $statement->expr : null;
        if (
            ! $call instanceof Node\Expr\StaticCall
            || ! $call->class instanceof Node\Name
            || strcasecmp($call->class->toString(), 'Illuminate\\Support\\Arr') !== 0
            || ! $call->name instanceof Node\Identifier
            || strcasecmp($call->name->toString(), 'get') !== 0
            || count($call->args) !== 3
        ) {
            return null;
        }
        $input = $call->args[0] instanceof Node\Arg ? $call->args[0]->value : null;
        $key = $call->args[1] instanceof Node\Arg ? $call->args[1]->value : null;
        $fallback = $call->args[2] instanceof Node\Arg ? $call->args[2]->value : null;
        if (
            ! self::thisCall($input, 'all')
            || $input->args !== []
            || ! $key instanceof Node\Expr\Variable
            || $key->name !== 'key'
            || ! $fallback instanceof Node\Expr\ArrowFunction
            || $fallback->params !== []
            || ! self::thisCall($fallback->expr, 'route')
            || count($fallback->expr->args) !== 1
            || ! ($fallback->expr->args[0] ?? null) instanceof Node\Arg
            || ! $fallback->expr->args[0]->value instanceof Node\Expr\Variable
            || $fallback->expr->args[0]->value->name !== 'key'
        ) {
            return null;
        }

        // Keep the native mixed type: validation is still required before using
        // raw input as a string, object or array. No writable contract is implied.
        return new PropertyType(readType: $type);
    }

    private static function thisCall(?Node $node, string $method): bool
    {
        return $node instanceof Node\Expr\MethodCall
            && $node->var instanceof Node\Expr\Variable
            && $node->var->name === 'this'
            && $node->name instanceof Node\Identifier
            && $node->name->toString() === $method;
    }
}
