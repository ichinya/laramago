<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;

/** Locates rule expressions in known Laravel declarations without evaluating them. */
final class ValidationRuleDeclarations
{
    /**
     * The caller must prove the resolved native method or facade dispatch. A
     * matching class name alone does not establish that macros or bindings
     * have not replaced the call.
     */
    public static function callRules(
        Node\Expr\MethodCall|Node\Expr\StaticCall $call,
        string $declaringClass,
    ): ?Node\Expr {
        if (! $call->name instanceof Node\Identifier || $call->isFirstClassCallable()) {
            return null;
        }
        $seenNames = [];
        $named = false;
        foreach ($call->getArgs() as $argument) {
            if ($argument->unpack || $argument->byRef) {
                return null;
            }
            $name = $argument->name?->toString();
            if ($name === null && $named) {
                return null;
            }
            if ($name !== null) {
                if (isset($seenNames[$name])) {
                    return null;
                }
                $seenNames[$name] = true;
                $named = true;
            }
        }

        $methodName = $call->name->toString();
        $method = strtolower($methodName);
        $class = strtolower(ltrim($declaringClass, '\\'));
        [$position, $name] = match (true) {
            $call instanceof Node\Expr\MethodCall && $class === 'illuminate\\validation\\factory',
            $call instanceof Node\Expr\StaticCall && $class === 'illuminate\\support\\facades\\validator',
                => match ($method) {
                'make', 'validate' => [1, 'rules'],
                default => [null, null],
            },
            $call instanceof Node\Expr\MethodCall && $class === 'illuminate\\contracts\\validation\\factory' => $method
                === 'make'
                    ? [1, 'rules']
                    : [null, null],
            $call instanceof Node\Expr\MethodCall && $class === 'illuminate\\validation\\validator' => match ($method) {
                'sometimes' => [1, 'rules'],
                'setrules', 'addrules' => [0, 'rules'],
                default => [null, null],
            },
            $class === 'illuminate\\http\\request' && $call instanceof Node\Expr\MethodCall => match ($methodName) {
                'validate' => [0, 'rules'],
                'validateWithBag' => [1, 'rules'],
                default => [null, null],
            },
            $call instanceof Node\Expr\MethodCall && $class === 'illuminate\\foundation\\validation\\validatesrequests'
                => match ($method) {
                'validate' => [1, 'rules'],
                'validatewithbag' => [2, 'rules'],
                default => [null, null],
            },
            default => [null, null],
        };

        if ($position === null || $name === null) {
            return null;
        }
        if (
            isset($seenNames[$name])
            && isset($call->getArgs()[$position])
            && $call->getArgs()[$position]->name === null
        ) {
            return null;
        }

        return PhpSource::argument($call->getArgs(), $position, $name);
    }

    /**
     * The caller must prove this method belongs to a FormRequest or Livewire
     * Form and is the effective rules method. Only an unconditional literal
     * return is exposed; other control flow remains unknown.
     */
    public static function rulesMethod(Node\Stmt\ClassMethod $method): ?Node\Expr\Array_
    {
        $statements = $method->stmts;
        if (
            strcasecmp($method->name->toString(), 'rules') !== 0
            || $method->isStatic()
            || $statements === null
            || count($statements) !== 1
            || ! $statements[0] instanceof Node\Stmt\Return_
            || ! $statements[0]->expr instanceof Node\Expr\Array_
        ) {
            return null;
        }

        return $statements[0]->expr;
    }
}
