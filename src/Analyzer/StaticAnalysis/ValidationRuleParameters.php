<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;
use PhpParser\ParserFactory;

/** Minimum parameter counts proven by unconditional native validator calls. */
final class ValidationRuleParameters
{
    /** Complete method ASTs from Laravel framework 7c75fbf, excluding whitespace and comments. */
    private const PARSER_METHODS = [
        'parseStringRule' => 'caa2f703daf83f5610e8c768624a8e782ac19266929bef17e0012018d070dfdf',
        'parseParameters' => '4dd2bf3422571596006eb6bf157f7681a95d75f6e6b93b21395bba20a97e6b7b',
        'ruleIsRegex' => '4ef26232bff0cb9fd7a7eaf28914bf5b1e2fff4382657ac855926e7f4299a558',
    ];
    private const PARAMETER_COUNTER = '095ae0543a3b2b7ef42b146f08d5cfe212b6db79b9dd791662435ab5908781eb';

    /** @var array<string, int> Native method name => required count. */
    private array $minimums = [];

    public function __construct(
        private readonly BuiltinValidationRuleCatalog $catalog,
    ) {
        $directory = $catalog->sourcePath();
        if ($directory === null) {
            return;
        }
        if (! self::nativeCsvParser($directory.'/ValidationRuleParser.php')) {
            return;
        }
        $source = @file_get_contents($directory.'/Concerns/ValidatesAttributes.php');
        if ($source === false || strlen($source) > 1048576) {
            return;
        }
        try {
            $nodes = (new ParserFactory)
                ->createForNewestSupportedVersion()
                ->parse($source);
        } catch (\PhpParser\Error) {
            return;
        }
        $trait = null;
        foreach ($nodes ?? [] as $node) {
            if (
                ! $node instanceof Node\Stmt\Namespace_
                || $node->name?->toString() !== 'Illuminate\\Validation\\Concerns'
            ) {
                continue;
            }
            foreach ($node->stmts as $statement) {
                if ($statement instanceof Node\Stmt\Trait_ && $statement->name?->toString() === 'ValidatesAttributes') {
                    if ($trait !== null) {
                        return;
                    }
                    $trait = $statement;
                }
            }
        }
        if ($trait === null || ! self::nativeCounter($trait)) {
            return;
        }
        foreach ($trait->getMethods() as $method) {
            if (count($method->params) < 3 || ! self::plainParameter($method->params[2], 'parameters')) {
                continue;
            }
            $first = $method->stmts[0] ?? null;
            if (! $first instanceof Node\Stmt\Expression || ! $first->expr instanceof Node\Expr\MethodCall) {
                continue;
            }
            $call = $first->expr;
            $countArgument = $call->args[0] ?? null;
            $parametersArgument = $call->args[1] ?? null;
            $ruleArgument = $call->args[2] ?? null;
            if (
                ! $call->var instanceof Node\Expr\Variable
                || $call->var->name !== 'this'
                || ! $call->name instanceof Node\Identifier
                || $call->name->toString() !== 'requireParameterCount'
                || count($call->args) !== 3
                || ! $countArgument instanceof Node\Arg
                || ! $parametersArgument instanceof Node\Arg
                || ! $ruleArgument instanceof Node\Arg
                || ! self::positionalArgument($countArgument)
                || ! self::positionalArgument($parametersArgument)
                || ! self::positionalArgument($ruleArgument)
                || ! $countArgument->value instanceof Node\Scalar\Int_
                || ! $parametersArgument->value instanceof Node\Expr\Variable
                || $parametersArgument->value->name !== 'parameters'
                || ! $ruleArgument->value instanceof Node\Scalar\String_
            ) {
                continue;
            }
            $name = $ruleArgument->value->value;
            if ($catalog->methodFor($name) !== $method->name->toString()) {
                continue;
            }
            $this->minimums[$method->name->toString()] = $countArgument->value->value;
        }
        $validator = self::nativeValidator($directory.'/Validator.php');
        if ($validator === null || $validator->getMethod('requireParameterCount') !== null) {
            $this->minimums = [];

            return;
        }
        foreach (array_keys($this->minimums) as $method) {
            if ($validator->getMethod($method) !== null) {
                unset($this->minimums[$method]);
            }
        }
    }

    /**
     * A null result means no proven missing-parameter contract.
     *
     * @return array{required: int, provided: int}|null
     */
    public function missing(string $rule): ?array
    {
        $parts = explode(':', $rule, 2);
        $name = $parts[0];
        $parameter = $parts[1] ?? null;
        if (in_array(strtolower(str_replace('-', '_', trim($name))), ['regex', 'notregex', 'not_regex'], true)) {
            return null;
        }
        $method = $this->catalog->methodFor(trim($name));
        if ($method === null || ! isset($this->minimums[$method])) {
            return null;
        }
        // This mirrors ValidationRuleParser::parseParameters for non-regex rules.
        $provided = $parameter === null ? 0 : count(str_getcsv($parameter, escape: '\\'));
        $required = $this->minimums[$method];

        return $provided < $required ? ['required' => $required, 'provided' => $provided] : null;
    }

    private static function nativeCsvParser(string $path): bool
    {
        $source = @file_get_contents($path);
        if ($source === false || strlen($source) > 1048576) {
            return false;
        }
        try {
            $nodes = (new ParserFactory)
                ->createForNewestSupportedVersion()
                ->parse($source);
        } catch (\PhpParser\Error) {
            return false;
        }
        foreach ($nodes ?? [] as $node) {
            if (! $node instanceof Node\Stmt\Namespace_ || $node->name?->toString() !== 'Illuminate\\Validation') {
                continue;
            }
            foreach ($node->stmts as $statement) {
                if (
                    ! $statement instanceof Node\Stmt\Class_
                    || $statement->name?->toString() !== 'ValidationRuleParser'
                ) {
                    continue;
                }
                foreach (self::PARSER_METHODS as $name => $fingerprint) {
                    $method = $statement->getMethod($name);
                    if ($method === null) {
                        return false;
                    }
                    try {
                        if (NativeValidationMethodContract::fingerprint($method) !== $fingerprint) {
                            return false;
                        }
                    } catch (\JsonException) {
                        return false;
                    }
                }

                return true;
            }
        }

        return false;
    }

    private static function nativeCounter(Node\Stmt\Trait_ $trait): bool
    {
        $method = $trait->getMethod('requireParameterCount');
        if ($method === null) {
            return false;
        }
        try {
            if (NativeValidationMethodContract::fingerprint($method) !== self::PARAMETER_COUNTER) {
                return false;
            }
        } catch (\JsonException) {
            return false;
        }
        $first = $method->stmts[0] ?? null;
        if (
            count($method->params) !== 3
            || count($method->stmts ?? []) !== 1
            || ! self::plainParameter($method->params[0], 'count')
            || ! self::plainParameter($method->params[1], 'parameters')
            || ! self::plainParameter($method->params[2], 'rule')
            || ! $first instanceof Node\Stmt\If_
            || count($first->stmts) !== 1
            || $first->else !== null
            || $first->elseifs !== []
        ) {
            return false;
        }
        $condition = $first->cond;
        $throw = $first->stmts[0];
        $countArgument =
            $condition instanceof Node\Expr\BinaryOp\Smaller && $condition->left instanceof Node\Expr\FuncCall
                ? $condition->left->args[0] ?? null
                : null;

        return (
            $condition instanceof Node\Expr\BinaryOp\Smaller
            && $condition->left instanceof Node\Expr\FuncCall
            && $condition->left->name instanceof Node\Name
            && $condition->left->name->toString() === 'count'
            && count($condition->left->args) === 1
            && $countArgument instanceof Node\Arg
            && $countArgument->value instanceof Node\Expr\Variable
            && $countArgument->value->name === 'parameters'
            && $condition->right instanceof Node\Expr\Variable
            && $condition->right->name === 'count'
            && $throw instanceof Node\Stmt\Expression
            && $throw->expr instanceof Node\Expr\Throw_
            && $throw->expr->expr instanceof Node\Expr\New_
            && $throw->expr->expr->class instanceof Node\Name
            && $throw->expr->expr->class->toString() === 'InvalidArgumentException'
        );
    }

    private static function nativeValidator(string $path): ?Node\Stmt\Class_
    {
        $source = @file_get_contents($path);
        if ($source === false || strlen($source) > 1048576) {
            return null;
        }
        try {
            $nodes = (new ParserFactory)
                ->createForNewestSupportedVersion()
                ->parse($source);
        } catch (\PhpParser\Error) {
            return null;
        }
        $validator = null;
        foreach ($nodes ?? [] as $node) {
            if (! $node instanceof Node\Stmt\Namespace_ || $node->name?->toString() !== 'Illuminate\\Validation') {
                continue;
            }
            foreach ($node->stmts as $statement) {
                if (! $statement instanceof Node\Stmt\Class_ || $statement->name?->toString() !== 'Validator') {
                    continue;
                }
                if ($validator !== null) {
                    return null;
                }
                $validator = $statement;
            }
        }

        return $validator;
    }

    private static function plainParameter(Node\Param $parameter, string $name): bool
    {
        return (
            $parameter->var instanceof Node\Expr\Variable
            && $parameter->var->name === $name
            && $parameter->default === null
            && ! $parameter->byRef
            && ! $parameter->variadic
        );
    }

    private static function positionalArgument(Node\Arg $argument): bool
    {
        return $argument->name === null && ! $argument->unpack && ! $argument->byRef;
    }
}
