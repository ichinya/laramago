<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\NativeFacade;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\MethodCallAnalysisHook;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
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

/** Diagnose missing classes in literal Controller@method route actions. */
final class ControllerActionClassHook implements MethodCallAnalysisHook
{
    private const ROUTER = 'Illuminate\\Routing\\Router';
    private const FACADE = 'Illuminate\\Support\\Facades\\Route';

    private readonly NativeFacade $facade;

    private ?string $sourceHash = null;

    /** @var array<string, Node\Expr\MethodCall|Node\Expr\StaticCall> */
    private array $calls = [];

    public function __construct(string $projectRoot = '.')
    {
        $this->facade = new NativeFacade($projectRoot);
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
        if ($call === null || !$call->name instanceof Node\Identifier || $this->insideRouteGroup($call)) {
            return;
        }
        $method = strtolower($call->name->name);
        if (!$this->isNativeCall($context, $call, $method)) {
            return;
        }
        $position = in_array($method, ['match', 'addroute'], true) ? 2 : 1;
        $action = null;
        foreach ($call->getArgs() as $offset => $argument) {
            if ($argument->unpack) {
                return;
            }
            if (
                $argument->name === null && $offset === $position
                || $argument->name !== null && $argument->name->name === 'action'
            ) {
                $action = $argument->value;
            }
        }
        if (!$action instanceof Node\Scalar\String_) {
            return;
        }
        $controller = $this->controller($action->value);
        if ($controller === null || !$controller['absolute'] || $context->codebase->classExists($controller['name'])) {
            return;
        }
        // Relative string actions may receive Laravel's legacy route namespace.
        // Only a leading slash proves that the literal names this exact class.
        $context->report(
            Level::Warning,
            'laramago-missing-controller-class',
            Issue::at(
                'Controller class ' . $controller['name'] . ' referenced by route action does not exist.',
                new SourceLocation(
                    $context->source->path,
                    new Span($action->getStartFilePos(), $action->getEndFilePos() + 1),
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
                $nodes = (new ParserFactory())
                    ->createForNewestSupportedVersion()
                    ->parse($context->source->contents);
                $nodes = (new NodeTraverser(new NameResolver(), new ParentConnectingVisitor()))->traverse($nodes ?? []);
            } catch (\PhpParser\Error) {
                return null;
            }
            foreach ((new NodeFinder())->find(
                $nodes,
                static fn(Node $node): bool => (
                    $node instanceof Node\Expr\MethodCall
                    || $node instanceof Node\Expr\StaticCall
                ),
            ) as $node) {
                if ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall) {
                    $this->calls[$node->getStartFilePos() . ':' . ($node->getEndFilePos() + 1)] = $node;
                }
            }
        }
        $call = $this->calls[$context->node->span->start . ':' . $context->node->span->end] ?? null;
        if (
            !$call instanceof Node\Expr\MethodCall && !$call instanceof Node\Expr\StaticCall
            || !$call->name instanceof Node\Identifier
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
            || !$atoms[0] instanceof NamedObjectType
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
            if (!$parentAttribute instanceof Node) {
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
                    ($group instanceof Node\Expr\MethodCall || $group instanceof Node\Expr\StaticCall)
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

    /** @return array{name: non-empty-string, absolute: bool}|null */
    private function controller(string $action): ?array
    {
        if (substr_count($action, '@') !== 1) {
            return null;
        }
        [$class, $method] = explode('@', $action, 2);
        if ($class === '' || $method === '' || preg_match('/\s/', $class . $method)) {
            return null;
        }
        $absolute = str_starts_with($class, '\\');
        $class = ltrim($class, '\\');
        if ($class === '' || preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*$/D', $class) !== 1) {
            return null;
        }

        return ['name' => $class, 'absolute' => $absolute];
    }

    private static function frameworkFile(?string $path, string $suffix): bool
    {
        return str_ends_with(str_replace('\\', '/', $path ?? ''), '/laravel/framework/src/' . $suffix);
    }
}
