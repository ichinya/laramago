<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\Assertion\SimpleAssertion;
use Mago\Sdk\Analyzer\Assertion\SimpleAssertionKind;
use Mago\Sdk\Analyzer\AssertionProviderContext;
use Mago\Sdk\Analyzer\FunctionAssertionProvider;
use Mago\Sdk\Analyzer\FunctionTarget;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\InvocationAssertions;
use PhpParser\Node;
use PhpParser\NodeFinder;

/** The surviving branch of Laravel's conditional abort helpers. */
final class AbortConditionProvider implements FunctionAssertionProvider, InitializationHook
{
    private ?PhpSource $source = null;

    public function __construct(private readonly string $root) {}

    public function initialize(InitializationContext $context): void
    {
        $this->source = null;
    }

    public function getTargets(): array
    {
        return [FunctionTarget::exact('abort_if'), FunctionTarget::exact('abort_unless')];
    }

    public function getAssertions(AssertionProviderContext $context): ?InvocationAssertions
    {
        $call = $context->invocation;
        $name = strtolower($call->name);
        if (! in_array($name, ['abort_if', 'abort_unless'], true)
            || $call->getArgument(0, 'boolean') === null || $call->getArgument(1, 'code') === null
            || count($call->arguments) > 4) {
            return null;
        }
        foreach ($call->arguments as $argument) {
            if ($argument->unpacked || $argument->placeholder) {
                return null;
            }
        }
        $function = $context->codebase->getFunction($name);
        $abort = $context->codebase->getFunction('abort');
        $file = $function?->location->file;
        if ($file === null
            || ! str_ends_with(str_replace('\\', '/', $file), '/laravel/framework/src/Illuminate/Foundation/helpers.php')
            || $abort?->location->file !== $file || (string) $abort?->returnType?->type !== 'never') {
            return null;
        }
        $node = (new NodeFinder)->findFirst(
            ($this->source ??= new PhpSource($this->root))->read($file) ?? [],
            static fn (Node $node): bool => $node instanceof Node\Stmt\Function_
                && strcasecmp($node->name->toString(), $name) === 0,
        );
        $statements = $node instanceof Node\Stmt\Function_ ? $node->stmts : [];
        if (! $node instanceof Node\Stmt\Function_ || count($node->params) !== 4) {
            return null;
        }
        foreach (['boolean', 'code', 'message', 'headers'] as $position => $parameter) {
            $declaration = $node->params[$position];
            if ($declaration->byRef || $declaration->variadic
                || ! $declaration->var instanceof Node\Expr\Variable || $declaration->var->name !== $parameter) {
                return null;
            }
        }
        $if = count($statements) === 1 ? $statements[0] : null;
        if (! $if instanceof Node\Stmt\If_ || $if->else !== null || $if->elseifs !== [] || count($if->stmts) !== 1) {
            return null;
        }
        $condition = $if->cond;
        if ($name === 'abort_unless') {
            if (! $condition instanceof Node\Expr\BooleanNot) {
                return null;
            }
            $condition = $condition->expr;
        }
        $forward = $if->stmts[0] instanceof Node\Stmt\Expression ? $if->stmts[0]->expr : null;
        if (! $condition instanceof Node\Expr\Variable || $condition->name !== 'boolean'
            || ! $forward instanceof Node\Expr\FuncCall || ! $forward->name instanceof Node\Name
            || strcasecmp($forward->name->toString(), 'abort') !== 0 || count($forward->args) !== 3) {
            return null;
        }
        foreach (['code', 'message', 'headers'] as $position => $parameter) {
            $argument = $forward->args[$position];
            if (! $argument instanceof Node\Arg || $argument->unpack || $argument->name !== null
                || ! $argument->value instanceof Node\Expr\Variable || $argument->value->name !== $parameter) {
                return null;
            }
        }

        return new InvocationAssertions(assertions: [
            '$boolean' => [new SimpleAssertion($name === 'abort_if' ? SimpleAssertionKind::Falsy : SimpleAssertionKind::Truthy)],
        ]);
    }
}
