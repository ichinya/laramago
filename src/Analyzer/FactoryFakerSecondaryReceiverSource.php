<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use PhpParser\{Node, NodeFinder};
use Ichinya\Laramago\Analyzer\DefensiveBoundaryGuardSource as Source;

/**
 * SOURCE-ONLY DRAFT: never executed or natively admitted in this review.
 * The result is a compact lexical recipe. Physical/current native checks and
 * catalogue registration priority remain the importing owner's responsibility.
 */
final class FactoryFakerSecondaryReceiverSource
{
    private const ONCE = 'Illuminate\\Support\\Once';
    private const INPUT = 'Symfony\\Component\\Console\\Input\\Input';
    private const ARGV = 'Symfony\\Component\\Console\\Input\\ArgvInput';
    private const DEFINITION = 'Symfony\\Component\\Console\\Input\\InputDefinition';

    public static function classify(Node\Expr\CallLike $call, array $nodes): ?array
    {
        if (!$call instanceof Node\Expr\MethodCall && !$call instanceof Node\Expr\StaticCall
            || !self::plain($call) || !$call->name instanceof Node\Identifier) { return null; }
        $name = strtolower($call->name->name);
        if ($call instanceof Node\Expr\StaticCall && $name === 'instance' && $call->args === []
            && $call->class instanceof Node\Name && strcasecmp($call->class->toString(), self::ONCE) === 0) {
            return [
                'sourceOnly' => true, 'nativeAdmission' => false, 'role' => 'once-process-instance',
                'call' => Source::span($call), 'receiverClass' => self::ONCE,
                'requiredClasses' => [['class' => self::ONCE, 'file' => 'vendor/laravel/framework/src/Illuminate/Support/Once.php']],
                'requiredMethods' => [
                    ['queryClass' => self::ONCE, 'sourceClass' => self::ONCE, 'name' => 'instance', 'file' => 'vendor/laravel/framework/src/Illuminate/Support/Once.php'],
                    ['queryClass' => self::ONCE, 'sourceClass' => self::ONCE, 'name' => '__construct', 'file' => 'vendor/laravel/framework/src/Illuminate/Support/Once.php'],
                ],
                'selectedClassAliasKeys' => [self::ONCE],
                'descendantsConsumed' => false,
            ];
        }
        if (!$call instanceof Node\Expr\MethodCall || $name !== 'bind' || count($call->args) !== 1
            || !$call->var instanceof Node\Expr\Variable || !is_string($call->var->name)
            || !$call->args[0]->value instanceof Node\Expr\New_
            || !$call->args[0]->value->class instanceof Node\Name
            || strcasecmp($call->args[0]->value->class->toString(), self::DEFINITION) !== 0
            || !self::plain($call->args[0]->value)) { return null; }

        $finder = new NodeFinder; $parents = [];
        foreach ($finder->find($nodes, static fn(Node $node): bool => true) as $node) {
            foreach ($node->getSubNodeNames() as $key) {
                $value = $node->$key;
                foreach (is_array($value) ? $value : [$value] as $child) {
                    if ($child instanceof Node) { $parents[spl_object_id($child)] = $node; }
                }
            }
        }
        $scope = $call;
        while (isset($parents[spl_object_id($scope)]) && !$scope instanceof Node\FunctionLike) {
            $scope = $parents[spl_object_id($scope)];
        }
        // Deliberately matches the actual immediate closure / first try body.
        if (!$scope instanceof Node\Expr\Closure || $scope->static || $scope->byRef
            || $scope->params !== [] || $scope->uses !== []) { return null; }
        $statement = $parents[spl_object_id($call)] ?? null;
        $try = $statement instanceof Node\Stmt\Expression ? ($parents[spl_object_id($statement)] ?? null) : null;
        if (!$statement instanceof Node\Stmt\Expression || $statement->expr !== $call
            || !$try instanceof Node\Stmt\TryCatch || ($try->stmts[0] ?? null) !== $statement) { return null; }
        $tryIndex = array_search($try, $scope->stmts, true);
        if (!is_int($tryIndex) || $tryIndex === 0) { return null; }
        $originStatement = $scope->stmts[$tryIndex - 1];
        $origin = $originStatement instanceof Node\Stmt\Expression ? $originStatement->expr : null;
        if (!$origin instanceof Node\Expr\Assign || !$origin->var instanceof Node\Expr\Variable
            || $origin->var->name !== $call->var->name || !$origin->expr instanceof Node\Expr\New_
            || !$origin->expr->class instanceof Node\Name || strcasecmp($origin->expr->class->toString(), self::ARGV) !== 0
            || $origin->expr->args !== [] || !self::plain($origin->expr)) { return null; }
        $limit = $call->getEndFilePos();
        foreach ($finder->find([$scope], static fn(Node $node): bool => true) as $node) {
            if ($node->getStartFilePos() > $limit) { continue; }
            if ($node instanceof Node\Stmt\Global_ || $node instanceof Node\Stmt\Static_
                || $node instanceof Node\Expr\AssignRef || $node instanceof Node\Expr\Eval_
                || $node instanceof Node\Expr\Include_ || $node instanceof Node\Stmt\Unset_
                || $node instanceof Node\Expr\ClosureUse && $node->byRef
                || $node instanceof Node\Stmt\Foreach_ && $node->byRef) { return null; }
            if ($node instanceof Node\Expr\Variable) {
                if (!is_string($node->name)) { return null; }
                if ($node->name === $call->var->name && $node !== $origin->var && $node !== $call->var) { return null; }
            }
        }
        return [
            'sourceOnly' => true, 'nativeAdmission' => false, 'role' => 'console-input-definition-binding',
            'call' => Source::span($call), 'receiverClass' => self::ARGV, 'variable' => $call->var->name,
            'origin' => Source::span($origin), 'originNew' => Source::span($origin->expr),
            'scope' => Source::span($scope), 'firstTry' => Source::span($try),
            'argumentClass' => self::DEFINITION, 'argument' => Source::span($call->args[0]->value),
            'requiredClasses' => [
                ['class' => self::ARGV, 'file' => 'vendor/symfony/console/Input/ArgvInput.php'],
                ['class' => self::INPUT, 'file' => 'vendor/symfony/console/Input/Input.php'],
                ['class' => self::DEFINITION, 'file' => 'vendor/symfony/console/Input/InputDefinition.php'],
            ],
            'requiredMethods' => [
                ['queryClass' => self::ARGV, 'sourceClass' => self::ARGV, 'name' => '__construct', 'file' => 'vendor/symfony/console/Input/ArgvInput.php'],
                ['queryClass' => self::INPUT, 'sourceClass' => self::INPUT, 'name' => '__construct', 'file' => 'vendor/symfony/console/Input/Input.php'],
                ['queryClass' => self::ARGV, 'sourceClass' => self::INPUT, 'name' => 'bind', 'file' => 'vendor/symfony/console/Input/Input.php'],
                ['queryClass' => self::ARGV, 'sourceClass' => self::ARGV, 'name' => 'parse', 'file' => 'vendor/symfony/console/Input/ArgvInput.php'],
                ['queryClass' => self::ARGV, 'sourceClass' => self::ARGV, 'name' => 'parseToken', 'file' => 'vendor/symfony/console/Input/ArgvInput.php'],
                ['queryClass' => self::DEFINITION, 'sourceClass' => self::DEFINITION, 'name' => '__construct', 'file' => 'vendor/symfony/console/Input/InputDefinition.php'],
            ],
            'selectedClassAliasKeys' => [self::ARGV, self::INPUT, self::DEFINITION],
            'descendantsConsumed' => false,
        ];
    }

    private static function plain(Node\Expr\CallLike $call): bool
    {
        return !$call->isFirstClassCallable() && !array_filter($call->args, static fn($arg): bool =>
            !$arg instanceof Node\Arg || $arg->name !== null || $arg->byRef || $arg->unpack);
    }
}
