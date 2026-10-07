<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use PhpParser\{Node, NodeFinder};
use Ichinya\Laramago\Analyzer\DefensiveBoundaryGuardSource as Source;

/**
 * A compact source recipe, not a native type or effective-container guarantee.
 * The importing catalogue must bind the caller and each physical library API,
 * retain root/alias/provider precedence, and still inspect every descendant.
 */
final class FactoryFakerLocalCacheReceiverSource
{
    private const APPLICATION = 'Illuminate\\Foundation\\Application';
    private const CONTAINER = 'Illuminate\\Container\\Container';
    private const CACHE = 'Illuminate\\Cache\\CacheManager';
    private const PROVIDER = 'Illuminate\\Cache\\CacheServiceProvider';

    public static function classify(Node\Expr\CallLike $selected, array $nodes): ?array
    {
        if (!$selected instanceof Node\Expr\MethodCall || !self::plain($selected)
            || !$selected->name instanceof Node\Identifier || strcasecmp($selected->name->name, 'extend') !== 0
            || count($selected->args) !== 2 || !$selected->var instanceof Node\Expr\Variable
            || !is_string($selected->var->name) || $selected->var->name === 'this') { return null; }
        $parents = self::parents($nodes);
        $closure = self::scope($selected, $parents);
        if (!$closure instanceof Node\Expr\Closure || $closure->static || $closure->byRef
            || $closure->params !== [] || count($closure->uses) !== 1 || $closure->attrGroups !== []
            || !$closure->returnType instanceof Node\Identifier || strtolower($closure->returnType->name) !== 'void'
            || count($closure->stmts) !== 2) { return null; }
        $capture = $closure->uses[0];
        if ($capture->byRef || !$capture->var instanceof Node\Expr\Variable || !is_string($capture->var->name)
            || $capture->var->name === 'this' || $capture->var->name === $selected->var->name) { return null; }
        $application = $capture->var->name;
        $originStatement = $closure->stmts[0]; $useStatement = $closure->stmts[1];
        if (!$originStatement instanceof Node\Stmt\Expression || !$originStatement->expr instanceof Node\Expr\Assign
            || !$useStatement instanceof Node\Stmt\Expression || $useStatement->expr !== $selected) { return null; }
        $origin = $originStatement->expr;
        if (!$origin->var instanceof Node\Expr\Variable || $origin->var->name !== $selected->var->name
            || !$origin->expr instanceof Node\Expr\MethodCall || !self::plain($origin->expr)
            || !$origin->expr->var instanceof Node\Expr\Variable || $origin->expr->var->name !== $application
            || !$origin->expr->name instanceof Node\Identifier || strcasecmp($origin->expr->name->name, 'make') !== 0
            || count($origin->expr->args) !== 1 || !self::classLiteral($origin->expr->args[0]->value, self::CACHE)) { return null; }
        $argument = $parents[spl_object_id($closure)] ?? null;
        $registration = $argument instanceof Node\Arg ? ($parents[spl_object_id($argument)] ?? null) : null;
        $registrationStatement = $registration instanceof Node ? ($parents[spl_object_id($registration)] ?? null) : null;
        if (!$argument instanceof Node\Arg || !$registration instanceof Node\Expr\MethodCall || !self::plain($registration)
            || count($registration->args) !== 1 || $registration->args[0] !== $argument
            || !$registration->var instanceof Node\Expr\Variable || $registration->var->name !== $application
            || !$registration->name instanceof Node\Identifier || strcasecmp($registration->name->name, 'booting') !== 0
            || !$registrationStatement instanceof Node\Stmt\Expression || $registrationStatement->expr !== $registration) { return null; }
        $caller = self::scope($registration, $parents);
        if (!$caller instanceof Node\Stmt\ClassMethod || $caller->isStatic() || $caller->byRef || $caller->params !== []
            || $caller->attrGroups !== [] || $caller->stmts === null || count($caller->stmts) < 3
            || !$caller->returnType instanceof Node\Name || strcasecmp($caller->returnType->toString(), self::APPLICATION) !== 0
            || $caller->stmts[2] !== $registrationStatement) { return null; }
        $class = $parents[spl_object_id($caller)] ?? null;
        if (!$class instanceof Node\Stmt\Class_ || $class->isAnonymous() || $class->namespacedName === null
            || $class->name === null || $class->attrGroups !== []) { return null; }
        $applicationStatement = $caller->stmts[0]; $guard = $caller->stmts[1];
        if (!$applicationStatement instanceof Node\Stmt\Expression || !$applicationStatement->expr instanceof Node\Expr\Assign
            || !$applicationStatement->expr->var instanceof Node\Expr\Variable
            || $applicationStatement->expr->var->name !== $application || !self::applicationGuard($guard, $application)) { return null; }
        $applicationOrigin = $applicationStatement->expr;
        // The initialization expression is opaque. It is read, never included,
        // required, evaluated, or treated as an Application producer by itself.
        // The next throwing instanceof guard supplies the nominal source fact.
        foreach ((new NodeFinder)->findInstanceOf([$applicationOrigin->expr], Node\Expr\Variable::class) as $variable) {
            if (!is_string($variable->name) || $variable->name === $application || $variable->name === $selected->var->name) { return null; }
        }
        foreach ([$class, $caller, $applicationStatement, $applicationOrigin, $guard, $registrationStatement,
            $registration, $argument, $closure, $capture, $originStatement, $origin, $origin->expr, $useStatement, $selected] as $node) {
            if (!self::noPriorityDocs($node)) { return null; }
        }
        $markerExposures = [];
        foreach ((new NodeFinder)->find([$caller], static fn(Node $node): bool => true) as $node) {
            if ($node->getStartFilePos() >= $applicationOrigin->getEndFilePos()
                && ($node instanceof Node\Expr\Eval_ || $node instanceof Node\Expr\FuncCall
                    && $node->name instanceof Node\Name
                    && in_array(strtolower($node->name->toString()), ['compact', 'extract', 'get_defined_vars'], true))) { return null; }
            if ($node instanceof Node\Arg && $node->value instanceof Node\Expr\Variable
                && in_array($node->value->name, [$application, $selected->var->name], true)) {
                $marker = self::markerExposure($node, $caller, $parents, $application);
                if ($marker === null) { return null; }
                $markerExposures[] = $marker;
            }
            // Dynamic calls in this new local receiver route are not silently
            // consumed by the old scanner's identifier-only registration loop.
            if ($node instanceof Node\Expr\MethodCall && !$node->name instanceof Node\Identifier
                && $node->var instanceof Node\Expr\Variable
                && in_array($node->var->name, [$application, $selected->var->name], true)) { return null; }
            if ($node instanceof Node\Expr\AssignRef || $node instanceof Node\Expr\ClosureUse && $node->byRef
                || $node instanceof Node\Arg && $node->byRef || $node instanceof Node\Stmt\Foreach_ && $node->byRef) {
                foreach ((new NodeFinder)->findInstanceOf([$node], Node\Expr\Variable::class) as $variable) {
                    if (!is_string($variable->name) || in_array($variable->name, [$application, $selected->var->name], true)) { return null; }
                }
            }
            if ($node instanceof Node\Expr\Assign && $node !== $applicationOrigin && $node !== $origin
                || $node instanceof Node\Expr\AssignOp || $node instanceof Node\Stmt\Unset_
                || $node instanceof Node\Stmt\Foreach_ || $node instanceof Node\Stmt\Catch_
                || $node instanceof Node\Expr\PreInc || $node instanceof Node\Expr\PreDec
                || $node instanceof Node\Expr\PostInc || $node instanceof Node\Expr\PostDec) {
                // The observed opaque initializer and exact manager producer
                // are the only assignments borrowing these selected values.
                // This rejects by-value aliases/storage as well as rebindings.
                foreach ((new NodeFinder)->findInstanceOf([$node], Node\Expr\Variable::class) as $variable) {
                    if (!is_string($variable->name) || in_array($variable->name, [$application, $selected->var->name], true)) { return null; }
                }
            }
        }
        foreach ((new NodeFinder)->find([$applicationStatement, $guard, $originStatement], static fn(Node $node): bool => true) as $node) {
            if (!self::noPriorityDocs($node)) { return null; }
        }
        // Before the selected use, the captured application and local manager
        // may occur only in the explicit origin/capture/receiver positions.
        $allowed = [$capture->var, $origin->var, $origin->expr->var, $selected->var];
        foreach ((new NodeFinder)->findInstanceOf([$closure], Node\Expr\Variable::class) as $variable) {
            if ($variable->getStartFilePos() >= $selected->args[0]->getStartFilePos()) { continue; }
            if (!is_string($variable->name) || in_array($variable->name, [$application, $selected->var->name], true)
                && !in_array($variable, $allowed, true)) { return null; }
        }
        return [
            'sourceOnly' => true, 'nativeAdmissionClaimed' => false, 'role' => 'cache-driver-extension',
            'recipe' => 'guarded-captured-application-local-cache-manager', 'descendantsConsumed' => false,
            'call' => Source::span($selected), 'receiverVariable' => $selected->var->name,
            'receiverOrigin' => Source::span($origin), 'make' => Source::span($origin->expr),
            'abstract' => self::CACHE, 'abstractSpan' => Source::span($origin->expr->args[0]->value),
            'applicationVariable' => $application, 'applicationOrigin' => Source::span($applicationOrigin),
            'applicationGuard' => Source::span($guard), 'capture' => Source::span($capture),
            'scope' => Source::span($closure), 'booting' => Source::span($registration),
            'callerClass' => $class->namespacedName->toString(), 'callerClassSpan' => Source::span($class),
            'callerClassNameSpan' => Source::span($class->name), 'callerClassStarts' => self::starts($class),
            'callerMethod' => $caller->name->name, 'callerMethodSpan' => Source::span($caller),
            'callerMethodNameSpan' => Source::span($caller->name), 'callerMethodStarts' => self::starts($caller),
            'callerReturnSpan' => Source::span($caller->returnType), 'callerReturnName' => self::APPLICATION,
            'callerVisibility' => $caller->isPrivate() ? 'Private' : ($caller->isProtected() ? 'Protected' : 'Public'),
            'callerClassParent' => $class->extends?->toString(), 'callerClassAbstract' => $class->isAbstract(),
            'callerClassFinal' => $class->isFinal(),
            'frameworkMarkerExposures' => $markerExposures,
            'requiredClasses' => [
                ['class' => self::APPLICATION, 'file' => 'vendor/laravel/framework/src/Illuminate/Foundation/Application.php'],
                ['class' => self::CONTAINER, 'file' => 'vendor/laravel/framework/src/Illuminate/Container/Container.php'],
                ['class' => self::CACHE, 'file' => 'vendor/laravel/framework/src/Illuminate/Cache/CacheManager.php'],
                ['class' => self::PROVIDER, 'file' => 'vendor/laravel/framework/src/Illuminate/Cache/CacheServiceProvider.php'],
            ],
            'requiredMethods' => self::methods(),
            'selectedClassAliasKeys' => [self::APPLICATION, self::CONTAINER, self::CACHE, self::PROVIDER, $class->namespacedName->toString()],
            'requiredRootKeys' => ['app', self::APPLICATION, 'Illuminate\\Contracts\\Foundation\\Application', self::CONTAINER,
                'Illuminate\\Contracts\\Container\\Container', 'cache', self::CACHE, 'Illuminate\\Contracts\\Cache\\Factory'],
        ];
    }

    private static function methods(): array
    {
        $application = 'vendor/laravel/framework/src/Illuminate/Foundation/Application.php';
        $container = 'vendor/laravel/framework/src/Illuminate/Container/Container.php';
        return [
            ['queryClass' => self::APPLICATION, 'sourceClass' => self::APPLICATION, 'name' => 'make', 'file' => $application],
            ['queryClass' => self::APPLICATION, 'sourceClass' => self::APPLICATION, 'name' => 'resolve', 'file' => $application],
            ['queryClass' => self::APPLICATION, 'sourceClass' => self::APPLICATION, 'name' => 'loadDeferredProviderIfNeeded', 'file' => $application],
            ['queryClass' => self::APPLICATION, 'sourceClass' => self::APPLICATION, 'name' => 'booting', 'file' => $application],
            ['queryClass' => self::APPLICATION, 'sourceClass' => self::APPLICATION, 'name' => 'registerCoreContainerAliases', 'file' => $application],
            ['queryClass' => self::CONTAINER, 'sourceClass' => self::CONTAINER, 'name' => 'make', 'file' => $container],
            ['queryClass' => self::CONTAINER, 'sourceClass' => self::CONTAINER, 'name' => 'resolve', 'file' => $container],
            ['queryClass' => self::APPLICATION, 'sourceClass' => self::CONTAINER, 'name' => 'getAlias', 'file' => $container],
            ['queryClass' => self::CACHE, 'sourceClass' => self::CACHE, 'name' => 'extend', 'file' => 'vendor/laravel/framework/src/Illuminate/Cache/CacheManager.php'],
            ['queryClass' => self::PROVIDER, 'sourceClass' => self::PROVIDER, 'name' => 'register', 'file' => 'vendor/laravel/framework/src/Illuminate/Cache/CacheServiceProvider.php'],
        ];
    }
    private static function applicationGuard(Node $node, string $name): bool
    {
        if (!$node instanceof Node\Stmt\If_ || $node->else !== null || $node->elseifs !== [] || count($node->stmts) !== 1
            || !$node->cond instanceof Node\Expr\BooleanNot || !$node->cond->expr instanceof Node\Expr\Instanceof_
            || !$node->cond->expr->expr instanceof Node\Expr\Variable || $node->cond->expr->expr->name !== $name
            || !$node->cond->expr->class instanceof Node\Name || strcasecmp($node->cond->expr->class->toString(), self::APPLICATION) !== 0) { return false; }
        $statement = $node->stmts[0];
        return $statement instanceof Node\Stmt\Expression && $statement->expr instanceof Node\Expr\Throw_
            && $statement->expr->expr instanceof Node\Expr\New_ && $statement->expr->expr->class instanceof Node\Name
            && in_array(strtolower($statement->expr->expr->class->toString()), ['unexpectedvalueexception', 'runtimeexception'], true)
            && self::plain($statement->expr->expr) && count($statement->expr->expr->args) <= 1
            && (($statement->expr->expr->args[0]->value ?? null) === null || $statement->expr->expr->args[0]->value instanceof Node\Scalar\String_);
    }
    /** Only a recipe: the importing binder must prove the trait and override catalogue. */
    private static function markerExposure(Node\Arg $argument, Node\Stmt\ClassMethod $caller, array $parents, string $application): ?array
    {
        if ($argument->value->name !== $application) { return null; }
        $call = $parents[spl_object_id($argument)] ?? null;
        if (!$call instanceof Node\Expr\MethodCall || !self::plain($call) || count($call->args) !== 1
            || !$call->var instanceof Node\Expr\Variable || $call->var->name !== 'this'
            || !$call->name instanceof Node\Identifier) { return null; }
        $methods = ['markconfigcached' => ['markConfigCached', 'Illuminate\\Foundation\\Testing\\WithCachedConfig'],
            'markroutescached' => ['markRoutesCached', 'Illuminate\\Foundation\\Testing\\WithCachedRoutes']];
        $spec = $methods[strtolower($call->name->name)] ?? null; if ($spec === null) { return null; }
        $statement = $parents[spl_object_id($call)] ?? null; $capture = null;
        if ($spec[0] === 'markRoutesCached') {
            if (!$statement instanceof Node\Expr\ArrowFunction || $statement->expr !== $call
                || $statement->static || $statement->byRef || $statement->params !== []
                || $statement->attrGroups !== [] || $statement->returnType !== null) { return null; }
            $capture = Source::span($statement);
            $arrowArgument = $parents[spl_object_id($statement)] ?? null;
            $booting = $arrowArgument instanceof Node\Arg ? ($parents[spl_object_id($arrowArgument)] ?? null) : null;
            if (!$arrowArgument instanceof Node\Arg || !$booting instanceof Node\Expr\MethodCall || !self::plain($booting)
                || count($booting->args) !== 1 || !$booting->var instanceof Node\Expr\Variable || $booting->var->name !== $application
                || !$booting->name instanceof Node\Identifier || strcasecmp($booting->name->name, 'booting') !== 0) { return null; }
            $statement = $parents[spl_object_id($booting)] ?? null;
        }
        if (!$statement instanceof Node\Stmt\Expression) { return null; }
        $branch = $parents[spl_object_id($statement)] ?? null;
        if (!$branch instanceof Node\Stmt\If_ || !in_array($statement, $branch->stmts, true)
            || $branch->else !== null || $branch->elseifs !== [] || !self::existsOnTruePath($branch->cond, $spec[0])) { return null; }
        $scope = self::scope($branch, $parents); if ($scope !== $caller) { return null; }
        $traitGuard = self::traitOnTruePath($branch->cond, $spec[1]);
        if ($traitGuard === null || !self::traitIndexLifetime($caller, $branch, $traitGuard)) { return null; }
        foreach ([$argument, $call, $statement, $branch] as $node) { if (!self::noPriorityDocs($node)) { return null; } }
        return ['method' => $spec[0], 'trait' => $spec[1], 'call' => Source::span($call),
            'argument' => Source::span($argument), 'guard' => Source::span($branch->cond), 'capture' => $capture,
            'traitIndex' => $traitGuard,
            'nativeOwnerClaimed' => false, 'requiresCurrentTraitAndOverrideCertificate' => true];
    }
    private static function traitOnTruePath(Node\Expr $condition, string $trait): ?array
    {
        if ($condition instanceof Node\Expr\BinaryOp\BooleanAnd || $condition instanceof Node\Expr\BinaryOp\LogicalAnd) {
            $left = self::traitOnTruePath($condition->left, $trait); $right = self::traitOnTruePath($condition->right, $trait);
            return $left !== null && $right !== null ? null : ($left ?? $right);
        }
        if (!$condition instanceof Node\Expr\Isset_) { return null; }
        $found = [];
        foreach ($condition->vars as $value) {
            if ($value instanceof Node\Expr\ArrayDimFetch && $value->var instanceof Node\Expr\Variable
                && is_string($value->var->name) && $value->dim instanceof Node && self::classLiteral($value->dim, $trait)) {
                $found[] = ['variable' => $value->var->name, 'trait' => $trait, 'guardSpan' => Source::span($value)];
            }
        }
        return count($found) === 1 ? $found[0] : null;
    }
    private static function traitIndexLifetime(Node\Stmt\ClassMethod $caller, Node\Stmt\If_ $branch, array &$recipe): bool
    {
        $origins = [];
        foreach ($caller->stmts as $statement) {
            if ($statement->getEndFilePos() >= $branch->getStartFilePos()) { break; }
            if (!$statement instanceof Node\Stmt\Expression || !$statement->expr instanceof Node\Expr\Assign
                || !$statement->expr->var instanceof Node\Expr\Variable || $statement->expr->var->name !== $recipe['variable']) { continue; }
            $assignment = $statement->expr; $call = $assignment->expr;
            if (!$call instanceof Node\Expr\FuncCall || !self::plain($call) || !$call->name instanceof Node\Name
                || $call->name instanceof Node\Name\Relative || !($call->name->isUnqualified() || $call->name instanceof Node\Name\FullyQualified)
                || strcasecmp($call->name->toString(), 'class_uses_recursive') !== 0 || count($call->args) !== 1
                || !$call->args[0]->value instanceof Node\Expr\ClassConstFetch || !$call->args[0]->value->class instanceof Node\Name
                || strcasecmp($call->args[0]->value->class->toString(), 'static') !== 0
                || !$call->args[0]->value->name instanceof Node\Identifier || strcasecmp($call->args[0]->value->name->name, 'class') !== 0) { return false; }
            foreach ([$statement, $assignment, $call] as $node) { if (!self::noPriorityDocs($node)) { return false; } }
            $origins[] = $assignment;
        }
        if (count($origins) !== 1) { return false; } $origin = $origins[0];
        foreach ((new NodeFinder)->find([$caller], static fn(Node $node): bool => true) as $node) {
            if ($node === $origin) { continue; }
            if ($node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignRef || $node instanceof Node\Expr\AssignOp
                || $node instanceof Node\Stmt\Unset_ || $node instanceof Node\Stmt\Foreach_ || $node instanceof Node\Stmt\Catch_
                || $node instanceof Node\Expr\PreInc || $node instanceof Node\Expr\PreDec || $node instanceof Node\Expr\PostInc || $node instanceof Node\Expr\PostDec
                || $node instanceof Node\Expr\ClosureUse || $node instanceof Node\Arg) {
                foreach ((new NodeFinder)->findInstanceOf([$node], Node\Expr\Variable::class) as $variable) {
                    if (!is_string($variable->name) || $variable->name === $recipe['variable']) { return false; }
                }
            }
        }
        $recipe['originSpan'] = Source::span($origin); $recipe['functionSpan'] = Source::span($origin->expr);
        $recipe['sourceFunction'] = 'class_uses_recursive'; return true;
    }
    private static function existsOnTruePath(Node\Expr $condition, string $method): bool
    {
        if ($condition instanceof Node\Expr\BinaryOp\BooleanAnd || $condition instanceof Node\Expr\BinaryOp\LogicalAnd) {
            return self::existsOnTruePath($condition->left, $method) || self::existsOnTruePath($condition->right, $method);
        }
        return $condition instanceof Node\Expr\FuncCall && self::plain($condition)
            && $condition->name instanceof Node\Name && !$condition->name instanceof Node\Name\Relative
            && ($condition->name->isUnqualified() || $condition->name instanceof Node\Name\FullyQualified)
            && strcasecmp($condition->name->toString(), 'method_exists') === 0 && count($condition->args) === 2
            && $condition->args[0]->value instanceof Node\Expr\Variable && $condition->args[0]->value->name === 'this'
            && $condition->args[1]->value instanceof Node\Scalar\String_ && strcasecmp($condition->args[1]->value->value, $method) === 0;
    }
    private static function noPriorityDocs(Node $node): bool
    {
        foreach ($node->getComments() as $comment) {
            if ($comment instanceof \PhpParser\Comment\Doc
                && preg_match('/@(param|return|var|property(?:-read|-write)?|method|mixin|template|(?:phpstan|psalm)-)\b/', $comment->getText())) { return false; }
        }
        return true;
    }
    private static function classLiteral(Node $node, string $class): bool
    {
        return $node instanceof Node\Expr\ClassConstFetch && $node->class instanceof Node\Name
            && $node->name instanceof Node\Identifier && strcasecmp($node->name->name, 'class') === 0
            && strcasecmp($node->class->toString(), $class) === 0;
    }
    private static function plain(Node\Expr\CallLike $call): bool
    {
        return !$call->isFirstClassCallable() && !array_filter($call->args, static fn($arg): bool =>
            !$arg instanceof Node\Arg || $arg->name !== null || $arg->byRef || $arg->unpack);
    }
    private static function scope(Node $node, array $parents): Node
    {
        do { $node = $parents[spl_object_id($node)] ?? $node; } while (!$node instanceof Node\FunctionLike && isset($parents[spl_object_id($node)]));
        return $node;
    }
    private static function parents(array $nodes): array
    {
        $parents = [];
        foreach ((new NodeFinder)->find($nodes, static fn(Node $node): bool => true) as $node) {
            foreach ($node->getSubNodeNames() as $name) {
                $value = $node->$name;
                foreach (is_array($value) ? $value : [$value] as $child) { if ($child instanceof Node) { $parents[spl_object_id($child)] = $node; } }
            }
        }
        return $parents;
    }
    private static function starts(Node $node): array
    {
        $starts = [$node->getStartFilePos()]; foreach ($node->getComments() as $comment) { $starts[] = $comment->getStartFilePos(); } return $starts;
    }
}
