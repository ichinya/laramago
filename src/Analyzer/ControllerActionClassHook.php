<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ContainerBindings;
use Ichinya\Laramago\Analyzer\StaticAnalysis\NativeFacade;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\MethodCallAnalysisHook;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;

/** Diagnose missing classes and provably missing or inaccessible methods in literal controller route actions. */
final class ControllerActionClassHook implements MethodCallAnalysisHook
{
    private const ROUTER = 'Illuminate\\Routing\\Router';
    private const FACADE = 'Illuminate\\Support\\Facades\\Route';
    private const DISPATCHER = 'Illuminate\\Routing\\Contracts\\ControllerDispatcher';

    private readonly NativeFacade $facade;
    private readonly ContainerBindings $bindings;

    private ?string $sourceHash = null;

    /** @var array<string, Node\Expr\MethodCall|Node\Expr\StaticCall> */
    private array $calls = [];

    public function __construct(string $projectRoot = '.')
    {
        $this->facade = new NativeFacade($projectRoot);
        $this->bindings = new ContainerBindings($projectRoot);
    }

    public function getTargets(): array
    {
        $targets = [];
        foreach (['get', 'post', 'put', 'patch', 'delete', 'options', 'any', 'match', 'addRoute'] as $method) {
            $targets[] = MethodTarget::exact(self::ROUTER, $method);
            $targets[] = MethodTarget::exact(self::FACADE, $method);
        }

        return $targets;
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::ReceiverType, FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $call = $this->call($context);
        if ($call === null || ! $call->name instanceof Node\Identifier || $this->insideRouteGroup($call)) {
            return;
        }
        $method = strtolower($call->name->name);
        if (! $this->isNativeCall($context, $call, $method)) {
            return;
        }
        $position = in_array($method, ['match', 'addroute'], true) ? 2 : 1;
        $action = null;
        foreach ($call->getArgs() as $offset => $argument) {
            if ($argument->unpack) {
                return;
            }
            if (
                $argument->name === null
                && $offset === $position
                || $argument->name !== null
                && $argument->name->name === 'action'
            ) {
                $action = $argument->value;
            }
        }
        if (! $action instanceof Node\Expr) {
            return;
        }
        $controller = $this->controller($action);
        if ($controller === null) {
            return;
        }
        $location = $controller['location'];
        $customBinding =
            $this->bindings->configured($controller['name']) || $this->bindings->configured(self::DISPATCHER);
        if (! $controller['invokable'] && $customBinding) {
            return;
        }
        // Relative string actions may receive Laravel's legacy route namespace.
        // Only a leading slash proves that the literal names this exact class.
        if (! $context->codebase->classExists($controller['name'])) {
            if ($controller['reportMissingClass']) {
                $context->report(
                    Level::Warning,
                    'laramago-missing-controller-class',
                    Issue::at(
                        'Controller class '.$controller['name'].' referenced by route action does not exist.',
                        new SourceLocation(
                            $context->source->path,
                            new Span($location->getStartFilePos(), $location->getEndFilePos() + 1),
                        ),
                    ),
                );
            }

            return;
        }
        $method = null;
        if ($controller['invokable']) {
            $resolution = $this->nativeInvokableMethod($context, $controller['name']);
            if (! $resolution['complete']) {
                return;
            }
            $method = $resolution['method'];
        } else {
            if (! $this->hasStandardControllerDispatch($context, $controller['name'])) {
                return;
            }
            $method = $context->codebase->getMethod(
                $controller['name'],
                $controller['method'],
            ) ?? $context->codebase->getDeclaringMethod($controller['name'], $controller['method']);
        }
        if ($method === null) {
            if (
                $controller['invokable']
                || ! $context->codebase->methodExists($controller['name'], $controller['method'])
            ) {
                $context->report(
                    Level::Warning,
                    'laramago-missing-controller-method',
                    Issue::at(
                        'Controller method '
                        .$controller['name']
                        .'::'
                        .$controller['method']
                        .' referenced by route action does not exist.',
                        new SourceLocation(
                            $context->source->path,
                            new Span($location->getStartFilePos(), $location->getEndFilePos() + 1),
                        ),
                    ),
                );
            }

            return;
        }
        // PHP itself requires __invoke to be public. Native Mago reports an invalid declaration
        // at its source, so a route-level visibility warning would only duplicate that diagnostic.
        if ($controller['invokable']) {
            return;
        }
        if ($method->visibility === null || $method->visibility === Visibility::Public) {
            return;
        }
        if ($this->isActionAccessible($context, $controller['name'], $method)) {
            return;
        }
        $context->report(
            Level::Warning,
            'laramago-inaccessible-controller-method',
            Issue::at(
                'Controller method '
                .$controller['name']
                .'::'
                .$controller['method']
                .' referenced by route action is not accessible to Laravel controller dispatch.',
                new SourceLocation(
                    $context->source->path,
                    new Span($location->getStartFilePos(), $location->getEndFilePos() + 1),
                ),
            ),
        );
    }

    /**
     * @return Node\Expr\MethodCall|Node\Expr\StaticCall|null
     */
    private function call(NodeAnalysisContext $context): Node\Expr\MethodCall|Node\Expr\StaticCall|null
    {
        $sourceHash = hash('sha256', $context->source->contents);
        if ($sourceHash !== $this->sourceHash) {
            $this->sourceHash = $sourceHash;
            $this->calls = [];
            try {
                $nodes = (new ParserFactory)
                    ->createForNewestSupportedVersion()
                    ->parse($context->source->contents);
                $nodes = (new NodeTraverser(new NameResolver, new ParentConnectingVisitor))->traverse($nodes ?? []);
            } catch (\PhpParser\Error) {
                return null;
            }
            foreach ((new NodeFinder)->find(
                $nodes,
                static fn (Node $node): bool => (
                    $node instanceof Node\Expr\MethodCall
                    || $node instanceof Node\Expr\StaticCall
                ),
            ) as $node) {
                if ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall) {
                    $this->calls[$node->getStartFilePos().':'.($node->getEndFilePos() + 1)] = $node;
                }
            }
        }
        $call = $this->calls[$context->node->span->start.':'.$context->node->span->end] ?? null;
        if (
            ! $call instanceof Node\Expr\MethodCall
            && ! $call instanceof Node\Expr\StaticCall
            || ! $call->name instanceof Node\Identifier
            || $call->isFirstClassCallable()
        ) {
            return null;
        }

        return $call;
    }

    /** @param Node\Expr\MethodCall|Node\Expr\StaticCall $call */
    private function isNativeCall(NodeAnalysisContext $context, Node $call, string $method): bool
    {
        if ($call instanceof Node\Expr\StaticCall) {
            return (
                $call->class instanceof Node\Name
                && strcasecmp($call->class->toString(), self::FACADE) === 0
                && $this->facade->dispatchesClass($context->codebase, self::FACADE, 'router', self::ROUTER, $method)
            );
        }
        $atoms = $context->receiverType?->atomicTypes ?? [];
        if (
            count($atoms) !== 1
            || ! $atoms[0] instanceof NamedObjectType
            || strcasecmp($atoms[0]->name, self::ROUTER) !== 0
        ) {
            return false;
        }
        $owner = $context->codebase->getDeclaringMethod(self::ROUTER, $method);

        return (
            $owner !== null
            && strcasecmp($owner->identifier->class ?? '', self::ROUTER) === 0
            && self::frameworkFile($owner->location->file, 'Illuminate/Routing/Router.php')
        );
    }

    /** @param Node\Expr\MethodCall|Node\Expr\StaticCall $call */
    private function insideRouteGroup(Node $call): bool
    {
        $node = $call;
        while (true) {
            /** @var mixed $parentAttribute */
            $parentAttribute = $node->getAttribute('parent');
            if (! $parentAttribute instanceof Node) {
                break;
            }
            $parent = $parentAttribute;
            if ($parent instanceof Node\Expr\Closure || $parent instanceof Node\Expr\ArrowFunction) {
                /** @var mixed $argumentAttribute */
                $argumentAttribute = $parent->getAttribute('parent');
                $argument = $argumentAttribute instanceof Node\Arg ? $argumentAttribute : null;
                /** @var mixed $groupAttribute */
                $groupAttribute = $argument?->getAttribute('parent');
                $group = $groupAttribute instanceof Node ? $groupAttribute : null;
                if (
                    ($group instanceof Node\Expr\MethodCall
                    || $group instanceof Node\Expr\StaticCall)
                    && $group->name instanceof Node\Identifier
                    && strtolower($group->name->name) === 'group'
                ) {
                    return true;
                }
            }
            $node = $parent;
        }

        return false;
    }

    /**
     * @return array{
     *     name: non-empty-string,
     *     method: non-empty-string,
     *     invokable: bool,
     *     reportMissingClass: bool,
     *     location: Node\Expr
     * }|null
     */
    private function controller(Node\Expr $action): ?array
    {
        if ($action instanceof Node\Scalar\String_) {
            return $this->stringController($action);
        }
        // `Controller::class` evaluates without a leading slash. Router may prepend a namespace
        // from an externally established route group, which lexical source context cannot prove absent.
        if (! $action instanceof Node\Expr\Array_ || count($action->items) !== 2) {
            return null;
        }
        [$classItem, $methodItem] = $action->items;
        if (
            $classItem->key !== null
            || $methodItem->key !== null
            || $classItem->unpack
            || $methodItem->unpack
            || ! $classItem->value instanceof Node\Expr\ClassConstFetch
            || ! $classItem->value->class instanceof Node\Name
            || ! $classItem->value->name instanceof Node\Identifier
            || strcasecmp($classItem->value->name->name, 'class') !== 0
            || ! $methodItem->value instanceof Node\Scalar\String_
        ) {
            return null;
        }
        $class = $classItem->value->class;
        if ($class->isSpecialClassName()) {
            return null;
        }
        $name = $class->toString();
        $method = $methodItem->value->value;
        if ($method === '' || preg_match('/\s/', $name.$method)) {
            return null;
        }

        // Native Mago diagnoses missing classes at `Controller::class`; avoid a duplicate extension issue.
        return [
            'name' => $name,
            'method' => $method,
            'invokable' => false,
            'reportMissingClass' => false,
            'location' => $methodItem->value,
        ];
    }

    /**
     * @return array{
     *     name: non-empty-string,
     *     method: non-empty-string,
     *     invokable: bool,
     *     reportMissingClass: bool,
     *     location: Node\Expr
     * }|null
     */
    private function stringController(Node\Scalar\String_ $action): ?array
    {
        $separatorCount = substr_count($action->value, '@');
        if ($separatorCount === 0) {
            $class = $this->absoluteStringClass($action->value);

            return (
                $class === null
                    ? null
                    : [
                        'name' => $class,
                        'method' => '__invoke',
                        'invokable' => true,
                        'reportMissingClass' => true,
                        'location' => $action,
                    ]
            );
        }
        if ($separatorCount !== 1) {
            return null;
        }
        [$class, $method] = explode('@', $action->value, 2);
        if ($class === '' || $method === '' || preg_match('/\s/', $class.$method)) {
            return null;
        }
        $absolute = str_starts_with($class, '\\');
        $class = ltrim($class, '\\');
        if ($class === '' || preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*$/D', $class) !== 1) {
            return null;
        }

        return (
            $absolute
                ? [
                    'name' => $class,
                    'method' => $method,
                    'invokable' => false,
                    'reportMissingClass' => true,
                    'location' => $action,
                ]
                : null
        );
    }

    /** @return non-empty-string|null */
    private function absoluteStringClass(string $value): ?string
    {
        if (! str_starts_with($value, '\\')) {
            return null;
        }
        $class = ltrim($value, '\\');

        return $class !== '' && preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*$/D', $class) === 1
            ? $class
            : null;
    }

    private function hasStandardControllerDispatch(NodeAnalysisContext $context, string $controller): bool
    {
        $metadata = $context->codebase->getClass($controller);
        if ($metadata === null || $metadata->hasIncompleteHierarchy()) {
            return false;
        }
        $callAction = $context->codebase->getDeclaringMethod($controller, 'callAction');
        if ($callAction !== null && ! self::isStandardControllerMethod($callAction, 'callAction')) {
            return false;
        }
        $magicCall = $context->codebase->getDeclaringMethod($controller, '__call');

        return $magicCall === null || self::isStandardControllerMethod($magicCall, '__call');
    }

    /** @return array{complete: bool, method: FunctionLikeMetadata|null} */
    private function nativeInvokableMethod(NodeAnalysisContext $context, string $controller): array
    {
        $class = $context->codebase->getClass($controller);
        if ($class === null || $class->hasIncompleteHierarchy()) {
            return ['complete' => false, 'method' => null];
        }
        $inherited = false;
        $traitAliasUncertainty = false;
        while (true) {
            foreach ($class->methods as $name) {
                if (strcasecmp($name, '__invoke') !== 0) {
                    continue;
                }
                $method = $context->codebase->getMethod(
                    $class->name,
                    '__invoke',
                ) ?? $context->codebase->getDeclaringMethod($class->name, '__invoke');
                if ($method === null) {
                    return ['complete' => false, 'method' => null];
                }
                if (
                    (self::containsMethod($class->pseudoMethods, '__invoke')
                    || self::containsMethod($class->staticPseudoMethods, '__invoke'))
                    && $method->flags->contains(MetadataFlags::MAGIC_METHOD)
                ) {
                    continue;
                }

                // PHP's method_exists() does not expose a parent's private method on the child class.
                return [
                    'complete' => ! $inherited || $method->visibility !== Visibility::Private,
                    'method' => ! $inherited || $method->visibility !== Visibility::Private ? $method : null,
                ];
            }
            foreach ($class->usedTraits as $trait) {
                $resolution = $this->traitInvokableMethod($context, $trait, []);
                if (! $resolution['complete']) {
                    return $resolution;
                }
                if ($resolution['method'] !== null) {
                    return $resolution;
                }
            }
            // Mago's stable metadata does not expose trait alias adaptations. A trait without
            // a declared __invoke may still add one via `handle as __invoke`, so absence is unknown.
            if ($class->usedTraits !== []) {
                $traitAliasUncertainty = true;
            }
            if ($class->directParentClass === null) {
                return ['complete' => ! $traitAliasUncertainty, 'method' => null];
            }
            $class = $context->codebase->getClass($class->directParentClass);
            if ($class === null || $class->hasIncompleteHierarchy()) {
                return ['complete' => false, 'method' => null];
            }
            $inherited = true;
        }
    }

    /**
     * @param list<string> $visited
     * @return array{complete: bool, method: FunctionLikeMetadata|null}
     */
    private function traitInvokableMethod(NodeAnalysisContext $context, string $name, array $visited): array
    {
        $key = strtolower($name);
        if (in_array($key, $visited, true)) {
            return ['complete' => false, 'method' => null];
        }
        $visited[] = $key;
        $trait = $context->codebase->getClassLike($name);
        if ($trait === null || $trait->hasIncompleteHierarchy()) {
            return ['complete' => false, 'method' => null];
        }
        if (self::containsMethod($trait->methods, '__invoke')) {
            $method = $context->codebase->getMethod(
                $trait->name,
                '__invoke',
            ) ?? $context->codebase->getDeclaringMethod($trait->name, '__invoke');
            if (
                $method !== null
                && (self::containsMethod($trait->pseudoMethods, '__invoke')
                || self::containsMethod($trait->staticPseudoMethods, '__invoke'))
                && $method->flags->contains(MetadataFlags::MAGIC_METHOD)
            ) {
                $method = null;
            }

            if ($method !== null) {
                return ['complete' => true, 'method' => $method];
            }
        }
        foreach ($trait->usedTraits as $used) {
            $resolution = $this->traitInvokableMethod($context, $used, $visited);
            if (! $resolution['complete'] || $resolution['method'] !== null) {
                return $resolution;
            }
        }

        return ['complete' => true, 'method' => null];
    }

    /** @param list<string> $methods */
    private static function containsMethod(array $methods, string $name): bool
    {
        foreach ($methods as $method) {
            if (strcasecmp($method, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    private function isActionAccessible(
        NodeAnalysisContext $context,
        string $controller,
        FunctionLikeMetadata $method,
    ): bool {
        if ($method->visibility === Visibility::Public) {
            return true;
        }
        $callAction = $context->codebase->getDeclaringMethod($controller, 'callAction');
        if (! self::isStandardControllerMethod($callAction, 'callAction')) {
            return false;
        }

        return (
            $method->visibility === Visibility::Protected
            || $method->visibility === Visibility::Private
            && strcasecmp($method->identifier->class ?? '', $callAction->identifier->class ?? '') === 0
        );
    }

    private static function isStandardControllerMethod(?FunctionLikeMetadata $method, string $name): bool
    {
        return (
            $method !== null
            && strcasecmp($method->identifier->class ?? '', 'Illuminate\\Routing\\Controller') === 0
            && strcasecmp($method->identifier->name, $name) === 0
            && self::frameworkFile($method->location->file, 'Illuminate/Routing/Controller.php')
        );
    }

    private static function frameworkFile(?string $path, string $suffix): bool
    {
        return str_ends_with(str_replace('\\', '/', $path ?? ''), '/laravel/framework/src/'.$suffix);
    }
}
