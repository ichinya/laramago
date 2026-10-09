<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\{Argument, FunctionReturnTypeProvider, FunctionTarget, ReturnTypeProviderContext, Type};
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type\{ArrayKeyKind, CallableType, FunctionLikeKind, KeyedArrayType, ListType};
use PhpParser\{Node, NodeFinder, NodeTraverser, ParserFactory};
use PhpParser\NodeVisitor\NameResolver;

/** Preserve string element and key contracts after a physically bound, pure predicate. */
final class StringPredicateArrayProvider implements FunctionReturnTypeProvider
{
    public function __construct(private readonly string $root) {}
    public function getTargets(): array { return [FunctionTarget::exact('array_filter')]; }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $call = $context->invocation;
        if (count($call->arguments) < 2 || count($call->arguments) > 3) { return null; }
        foreach ($call->arguments as $argument) {
            if ($argument->placeholder || $argument->unpacked || $argument->name !== null && ! in_array($argument->name, ['array', 'callback', 'mode'], true)) { return null; }
        }
        $mode = $call->getArgument(2, 'mode');
        if ($mode !== null && $mode->type?->getLiteralInt() !== 0) { return null; }
        $array = $call->getArgument(0, 'array')?->type;
        $callback = $call->getArgument(1, 'callback');
        if ($array === null || $callback === null || ! $this->builtin($context, 'array_filter')) { return null; }
        $literal = $callback->type?->getLiteralString();
        if ($literal === 'is_string') {
            if (! $this->builtin($context, 'is_string')) { return null; }
        } elseif (! $this->predicate($context, $callback)) { return null; }
        $keys = [];
        foreach ($array->atomicTypes as $atomic) {
            if ($atomic instanceof ListType) { $keys[] = Type::int(); }
            elseif ($atomic instanceof KeyedArrayType) {
                if ($atomic->keyType !== null) { $keys[] = $atomic->keyType; }
                foreach ($atomic->knownItems ?? [] as $item) {
                    $keys[] = match ($item->key->kind) {
                        ArrayKeyKind::Integer => Type::int(), ArrayKeyKind::String => Type::string(),
                        default => Type::union(Type::int(), Type::string()),
                    };
                }
            } else { return null; }
        }
        $key = array_shift($keys) ?? Type::union(Type::int(), Type::string());
        foreach ($keys as $next) { $key = Type::union($key, $next); }
        return Type::array($key, Type::string());
    }

    private function predicate(ReturnTypeProviderContext $context, Argument $argument): bool
    {
        $type = $argument->type;
        $atomic = $type?->atomicTypes[0] ?? null;
        if (! $atomic instanceof CallableType || count($type->atomicTypes) !== 1
            || $atomic->signature?->source?->kind !== FunctionLikeKind::Closure) { return false; }
        $native = $context->codebase->getFunctionLike($atomic->signature->source);
        $path = str_replace('\\', '/', $native?->location->file ?? '');
        if (str_starts_with($path, '//?/')) { $path = substr($path, 4); }
        if ($native === null || $path === '') { return false; }
        $bytes = @file_get_contents((new PhpSource($this->root))->path($path));
        if ($bytes === false) { return false; }
        $span = $native->location->span;
        if ($span->end > strlen($bytes) || trim(str_replace("\r\n", "\n", substr($bytes, $span->start, $span->length())))
            !== trim(str_replace("\r\n", "\n", $argument->expression))) { return false; }
        try {
            $nodes = (new NodeTraverser(new NameResolver))->traverse((new ParserFactory)->createForNewestSupportedVersion()->parse($bytes) ?? []);
        } catch (\PhpParser\Error) { return false; }
        $arrow = (new NodeFinder)->findFirst($nodes, static fn (Node $node): bool => $node instanceof Node\Expr\ArrowFunction
            && $node->getStartFilePos() === $native->location->span->start && $node->getEndFilePos() + 1 === $native->location->span->end);
        if (! $arrow instanceof Node\Expr\ArrowFunction || $arrow->byRef || count($arrow->params) !== 1
            || $arrow->params[0]->byRef || $arrow->params[0]->variadic || ! $arrow->params[0]->var instanceof Node\Expr\Variable
            || $arrow->params[0]->type !== null && (! $arrow->params[0]->type instanceof Node\Identifier
                || strtolower($arrow->params[0]->type->name) !== 'mixed')
            || ! is_string($arrow->params[0]->var->name)) { return false; }
        $value = $arrow->params[0]->var->name;
        $expr = $arrow->expr;
        $guard = $expr instanceof Node\Expr\BinaryOp\BooleanAnd ? $expr->left : $expr;
        if (! $this->functionCall($context, $guard, 'is_string', $value)) { return false; }
        if ($expr === $guard) { return true; }
        // This bounded conjunction has no writes, extra callbacks or captured state.
        $right = $expr->right;
        if ($right instanceof Node\Expr\BinaryOp\Identical && $right->right instanceof Node\Scalar\Int_
            && $right->right->value === 1 && $right->left instanceof Node\Expr\FuncCall) {
            $regex = $right->left;
            return $regex->name instanceof Node\Name && $this->resolvedBuiltin($context, $regex->name, 'preg_match')
                && count($regex->args) === 2 && $regex->args[0] instanceof Node\Arg && $regex->args[1] instanceof Node\Arg
                && ! $regex->args[0]->unpack && ! $regex->args[1]->unpack && $regex->args[0]->name === null && $regex->args[1]->name === null
                && $regex->args[0]->value instanceof Node\Scalar\String_ && $regex->args[1]->value instanceof Node\Expr\Variable
                && $regex->args[1]->value->name === $value;
        }
        return $right instanceof Node\Expr\BinaryOp\NotIdentical && $right->right instanceof Node\Scalar\String_
            && $right->right->value === '' && $this->functionCall($context, $right->left, 'trim', $value);
    }

    private function functionCall(ReturnTypeProviderContext $context, Node $node, string $name, string $value): bool
    {
        if (! $node instanceof Node\Expr\FuncCall || ! $node->name instanceof Node\Name
            || strtolower($node->name->toString()) !== $name || count($node->args) !== 1 || ! $node->args[0] instanceof Node\Arg
            || $node->args[0]->unpack || $node->args[0]->name !== null || ! $node->args[0]->value instanceof Node\Expr\Variable
            || $node->args[0]->value->name !== $value) { return false; }
        return $this->resolvedBuiltin($context, $node->name, $name);
    }

    private function resolvedBuiltin(ReturnTypeProviderContext $context, Node\Name $node, string $name): bool
    {
        if (strtolower($node->toString()) !== $name) { return false; }
        $namespaced = $node->getAttribute('namespacedName');
        if ($namespaced instanceof Node\Name && strcasecmp($namespaced->toString(), $name) !== 0
            && $context->codebase->getFunction($namespaced->toString()) !== null) { return false; }
        return $this->builtin($context, $name);
    }

    private function builtin(ReturnTypeProviderContext $context, string $name): bool
    {
        $method = $context->codebase->getFunction($name);
        return $method !== null && $method->flags->contains(MetadataFlags::BUILTIN) && ! $method->flags->contains(MetadataFlags::USER_DEFINED);
    }
}
