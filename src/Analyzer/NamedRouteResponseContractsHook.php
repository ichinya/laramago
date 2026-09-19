<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ContainerBindings;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ModelReflection;
use Ichinya\Laramago\Analyzer\StaticAnalysis\NamedRouteCatalog;
use Ichinya\Laramago\Analyzer\StaticAnalysis\NativeFacade;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\NodeAnalysisHook;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;
use Mago\Sdk\Syntax\NodeKind;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** Literal route names forwarded through Laravel's response factory. */
final class NamedRouteResponseContractsHook implements NodeAnalysisHook
{
    private const FACTORY = 'Illuminate\\Routing\\ResponseFactory';
    private const CONTRACT = 'Illuminate\\Contracts\\Routing\\ResponseFactory';
    private const FACADE = 'Illuminate\\Support\\Facades\\Response';

    private readonly NamedRouteCatalog $catalog;
    private readonly ContainerBindings $bindings;
    private readonly PhpSource $source;
    private readonly NativeFacade $facade;
    private ?string $sourceHash = null;
    /** @var array<string, Node\Expr\MethodCall|Node\Expr\StaticCall> */
    private array $calls = [];

    public function __construct(string $root = '.')
    {
        $this->catalog = new NamedRouteCatalog($root);
        $this->bindings = new ContainerBindings($root);
        $this->source = new PhpSource($root);
        $this->facade = new NativeFacade($root);
    }

    public function getTargets(): array
    {
        return [NodeKind::MethodCall, NodeKind::StaticMethodCall];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::ReceiverType, FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        if (! $this->catalog->enabled()) {
            return;
        }
        $call = $this->call($context);
        if (
            $call === null
            || $call->isFirstClassCallable()
            || ! $call->name instanceof Node\Identifier
            || strtolower($call->name->name) !== 'redirecttoroute'
            || $this->customService()
            || ! $this->nativeFactory($context)
        ) {
            return;
        }
        if ($call instanceof Node\Expr\StaticCall) {
            if (
                ! $call->class instanceof Node\Name
                || strcasecmp($call->class->toString(), self::FACADE) !== 0
                || ! $this->facade->dispatchesClass(
                    $context->codebase,
                    self::FACADE,
                    self::CONTRACT,
                    self::FACTORY,
                    'redirectToRoute',
                )
            ) {
                return;
            }
        } else {
            $atoms = $context->receiverType?->atomicTypes ?? [];
            $receiver = $atoms[0] ?? null;
            if (
                count($atoms) !== 1
                || ! $receiver instanceof NamedObjectType
                || $receiver->name !== self::FACTORY
                && ($receiver->name !== self::CONTRACT
                || ! $this->nativeResponseHelper($context, $call->var))
            ) {
                return;
            }
        }
        $parameters = ['route', 'parameters', 'status', 'headers'];
        $arguments = [];
        $named = false;
        foreach ($call->getArgs() as $offset => $argument) {
            $parameter = $argument->name?->toString() ?? $parameters[$offset] ?? null;
            if (
                $argument->unpack
                || $parameter === null
                || ! in_array($parameter, $parameters, true)
                || isset($arguments[$parameter])
                || $named
                && $argument->name === null
            ) {
                return;
            }
            $named = $argument->name !== null;
            $arguments[$parameter] = $argument->value;
        }
        $name = $arguments['route'] ?? null;
        if (! $name instanceof Node\Scalar\String_ || ! $this->catalog->missing($name->value)) {
            return;
        }
        $context->report(
            Level::Warning,
            'laramago-missing-named-route',
            Issue::at(
                'Named route "'.$name->value.'" is absent from the explicitly complete named-routes catalog.',
                new SourceLocation(
                    $context->source->path,
                    new Span($name->getStartFilePos(), $name->getEndFilePos() + 1),
                ),
            ),
        );
    }

    private function customService(): bool
    {
        foreach ([
            self::CONTRACT,
            self::FACTORY,
            'redirect',
            'Illuminate\\Routing\\Redirector',
            'url',
            'Illuminate\\Routing\\UrlGenerator',
            'Illuminate\\Contracts\\Routing\\UrlGenerator',
        ] as $service) {
            if ($this->bindings->configured($service)) {
                return true;
            }
        }

        return false;
    }

    private function nativeFactory(NodeAnalysisContext $context): bool
    {
        $method = $context->codebase->getDeclaringMethod(self::FACTORY, 'redirectToRoute');
        $redirect = $context->codebase->getDeclaringMethod('Illuminate\\Routing\\Redirector', 'route');
        $url = $context->codebase->getDeclaringMethod('Illuminate\\Routing\\UrlGenerator', 'route');
        if (
            $method === null
            || $redirect === null
            || $url === null
            || strcasecmp($method->identifier->class ?? '', self::FACTORY) !== 0
            || strcasecmp($redirect->identifier->class ?? '', 'Illuminate\\Routing\\Redirector') !== 0
            || strcasecmp($url->identifier->class ?? '', 'Illuminate\\Routing\\UrlGenerator') !== 0
            || $method->static
            || $redirect->static
            || $url->static
            || ! self::frameworkFile($method->location->file, 'Illuminate/Routing/ResponseFactory.php')
            || ! self::frameworkFile($redirect->location->file, 'Illuminate/Routing/Redirector.php')
            || ! self::frameworkFile($url->location->file, 'Illuminate/Routing/UrlGenerator.php')
            || array_map(static fn ($parameter): string => $parameter->name, $method->parameters) !== [
                '$route',
                '$parameters',
                '$status',
                '$headers',
            ]
        ) {
            return false;
        }
        foreach ([$method, $redirect, $url] as $nativeMethod) {
            if (
                $nativeMethod->abstract
                || $nativeMethod->visibility !== Visibility::Public
                || $nativeMethod->flags->contains(MetadataFlags::BY_REFERENCE)
            ) {
                return false;
            }
        }
        if (! $this->nativeUrlRoute($context, $url)) {
            return false;
        }
        $node = (new ModelReflection($context->codebase, $this->source))->methodNode($method);
        if (
            $node === null
            || $node->byRef
            || count($node->params) !== 4
            || count($node->stmts ?? []) !== 1
        ) {
            return false;
        }
        $return = $node->stmts[0] ?? null;
        if (! $return instanceof Node\Stmt\Return_ || ! $return->expr instanceof Node\Expr\MethodCall) {
            return false;
        }
        $forward = $return->expr;
        if (
            ! $forward->name instanceof Node\Identifier
            || $forward->name->toString() !== 'route'
            || ! $forward->var instanceof Node\Expr\PropertyFetch
            || ! $forward->var->var instanceof Node\Expr\Variable
            || $forward->var->var->name !== 'this'
            || ! $forward->var->name instanceof Node\Identifier
            || $forward->var->name->name !== 'redirector'
            || count($forward->args) !== 4
        ) {
            return false;
        }
        foreach (['route', 'parameters', 'status', 'headers'] as $offset => $name) {
            $parameter = $node->params[$offset];
            $argument = $forward->args[$offset];
            if (
                $parameter->byRef
                || $parameter->variadic
                || ! $parameter->var instanceof Node\Expr\Variable
                || $parameter->var->name !== $name
                || ! $argument instanceof Node\Arg
                || $argument->unpack
                || $argument->name !== null
                || ! $argument->value instanceof Node\Expr\Variable
                || $argument->value->name !== $name
            ) {
                return false;
            }
        }

        $constructor = $context->codebase->getDeclaringMethod(self::FACTORY, '__construct');
        $constructorNode = $constructor !== null
            ? (new ModelReflection($context->codebase, $this->source))->methodNode($constructor)
            : null;
        $assign = $constructorNode?->stmts[1] ?? null;
        $redirectParameter = $constructorNode?->params[1] ?? null;
        if (
            $constructor === null
            || strcasecmp($constructor->identifier->class ?? '', self::FACTORY) !== 0
            || ! self::frameworkFile($constructor->location->file, 'Illuminate/Routing/ResponseFactory.php')
            || count($constructorNode?->params ?? []) !== 2
            || ! $redirectParameter instanceof Node\Param
            || ! $redirectParameter->var instanceof Node\Expr\Variable
            || $redirectParameter->var->name !== 'redirector'
            || ! $redirectParameter->type instanceof Node\Name
            || strcasecmp($redirectParameter->type->toString(), 'Illuminate\\Routing\\Redirector') !== 0
            || count($constructorNode->stmts ?? []) !== 2
            || ! $assign instanceof Node\Stmt\Expression
            || ! $assign->expr instanceof Node\Expr\Assign
            || ! $assign->expr->var instanceof Node\Expr\PropertyFetch
            || ! $assign->expr->var->var instanceof Node\Expr\Variable
            || $assign->expr->var->var->name !== 'this'
            || ! $assign->expr->var->name instanceof Node\Identifier
            || $assign->expr->var->name->name !== 'redirector'
            || ! $assign->expr->expr instanceof Node\Expr\Variable
            || $assign->expr->expr->name !== 'redirector'
        ) {
            return false;
        }
        $redirectNode = (new ModelReflection($context->codebase, $this->source))->methodNode($redirect);
        $redirectReturn = $redirectNode?->stmts[0] ?? null;
        $to = $redirectReturn instanceof Node\Stmt\Return_ ? $redirectReturn->expr : null;
        $urlCall = $to instanceof Node\Expr\MethodCall ? $to->args[0] ?? null : null;
        if (
            $redirectNode === null
            || count($redirectNode->stmts ?? []) !== 1
            || count($redirectNode->params) !== 4
            || ! $to instanceof Node\Expr\MethodCall
            || ! $to->name instanceof Node\Identifier
            || $to->name->name !== 'to'
            || ! $to->var instanceof Node\Expr\Variable
            || $to->var->name !== 'this'
            || count($to->args) !== 3
            || ! $urlCall instanceof Node\Arg
            || ! $urlCall->value instanceof Node\Expr\MethodCall
            || ! $urlCall->value->name instanceof Node\Identifier
            || $urlCall->value->name->name !== 'route'
            || ! $urlCall->value->var instanceof Node\Expr\PropertyFetch
            || ! $urlCall->value->var->var instanceof Node\Expr\Variable
            || $urlCall->value->var->var->name !== 'this'
            || ! $urlCall->value->var->name instanceof Node\Identifier
            || $urlCall->value->var->name->name !== 'generator'
            || count($urlCall->value->args) !== 2
        ) {
            return false;
        }
        foreach (['route', 'parameters', 'status', 'headers'] as $offset => $name) {
            $parameter = $redirectNode->params[$offset];
            if (
                $parameter->byRef
                || $parameter->variadic
                || ! $parameter->var instanceof Node\Expr\Variable
                || $parameter->var->name !== $name
            ) {
                return false;
            }
        }
        foreach (['route', 'parameters'] as $offset => $name) {
            $argument = $urlCall->value->args[$offset];
            if (
                ! $argument instanceof Node\Arg
                || $argument->unpack
                || $argument->name !== null
                || ! $argument->value instanceof Node\Expr\Variable
                || $argument->value->name !== $name
            ) {
                return false;
            }
        }
        foreach (['status', 'headers'] as $offset => $name) {
            $argument = $to->args[$offset + 1];
            if (
                ! $argument instanceof Node\Arg
                || $argument->unpack
                || $argument->name !== null
                || ! $argument->value instanceof Node\Expr\Variable
                || $argument->value->name !== $name
            ) {
                return false;
            }
        }
        $redirectConstructor = $context->codebase->getDeclaringMethod('Illuminate\\Routing\\Redirector', '__construct');
        $redirectConstructorNode = $redirectConstructor !== null
            ? (new ModelReflection($context->codebase, $this->source))->methodNode($redirectConstructor)
            : null;
        $generator = $redirectConstructorNode?->stmts[0] ?? null;
        $generatorParameter = $redirectConstructorNode?->params[0] ?? null;
        if (
            $redirectConstructor === null
            || strcasecmp($redirectConstructor->identifier->class ?? '', 'Illuminate\\Routing\\Redirector') !== 0
            || ! self::frameworkFile($redirectConstructor->location->file, 'Illuminate/Routing/Redirector.php')
            || count($redirectConstructorNode?->params ?? []) !== 1
            || ! $generatorParameter instanceof Node\Param
            || ! $generatorParameter->type instanceof Node\Name
            || strcasecmp($generatorParameter->type->toString(), 'Illuminate\\Routing\\UrlGenerator') !== 0
            || count($redirectConstructorNode->stmts ?? []) !== 1
            || ! $generator instanceof Node\Stmt\Expression
            || ! $generator->expr instanceof Node\Expr\Assign
            || ! $generator->expr->var instanceof Node\Expr\PropertyFetch
            || ! $generator->expr->var->var instanceof Node\Expr\Variable
            || $generator->expr->var->var->name !== 'this'
            || ! $generator->expr->var->name instanceof Node\Identifier
            || $generator->expr->var->name->name !== 'generator'
            || ! $generator->expr->expr instanceof Node\Expr\Variable
            || $generator->expr->expr->name !== 'generator'
        ) {
            return false;
        }

        return true;
    }

    private function nativeUrlRoute(
        NodeAnalysisContext $context,
        \Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata $method,
    ): bool {
        $node = (new ModelReflection($context->codebase, $this->source))->methodNode($method);
        $statements = $node?->stmts ?? [];
        if (
            $node === null
            || count($node->params) !== 3
            || count($statements) !== 4
            || ! $statements[0] instanceof Node\Stmt\If_
            || ! $statements[1] instanceof Node\Stmt\If_
            || ! $statements[2] instanceof Node\Stmt\If_
            || ! $statements[3] instanceof Node\Stmt\Expression
            || ! $statements[3]->expr instanceof Node\Expr\Throw_
            || ! $statements[3]->expr->expr instanceof Node\Expr\New_
            || ! $statements[3]->expr->expr->class instanceof Node\Name
            || strcasecmp(
                $statements[3]->expr->expr->class->toString(),
                'Symfony\\Component\\Routing\\Exception\\RouteNotFoundException',
            ) !== 0
        ) {
            return false;
        }
        $lookup = $statements[1];
        $condition = $lookup->cond;
        $isNull = $condition instanceof Node\Expr\BooleanNot ? $condition->expr : null;
        $assignment = $isNull instanceof Node\Expr\FuncCall ? $isNull->args[0] ?? null : null;
        $route = $assignment instanceof Node\Arg ? $assignment->value : null;
        $byName = $route instanceof Node\Expr\Assign ? $route->expr : null;
        $returned = $lookup->stmts[0] ?? null;
        $toRoute = $returned instanceof Node\Stmt\Return_ ? $returned->expr : null;
        if (
            ! $isNull instanceof Node\Expr\FuncCall
            || ! $isNull->name instanceof Node\Name
            || strtolower($isNull->name->toString()) !== 'is_null'
            || count($isNull->args) !== 1
            || ! $route instanceof Node\Expr\Assign
            || ! $route->var instanceof Node\Expr\Variable
            || $route->var->name !== 'route'
            || ! $byName instanceof Node\Expr\MethodCall
            || ! $byName->name instanceof Node\Identifier
            || $byName->name->name !== 'getByName'
            || ! $byName->var instanceof Node\Expr\PropertyFetch
            || ! $byName->var->var instanceof Node\Expr\Variable
            || $byName->var->var->name !== 'this'
            || ! $byName->var->name instanceof Node\Identifier
            || $byName->var->name->name !== 'routes'
            || count($byName->args) !== 1
            || ! self::variableArgument($byName->args[0], 'name')
            || count($lookup->stmts) !== 1
            || ! $toRoute instanceof Node\Expr\MethodCall
            || ! $toRoute->var instanceof Node\Expr\Variable
            || $toRoute->var->name !== 'this'
            || ! $toRoute->name instanceof Node\Identifier
            || $toRoute->name->name !== 'toRoute'
            || count($toRoute->args) !== 3
        ) {
            return false;
        }
        foreach (['route', 'parameters', 'absolute'] as $offset => $name) {
            if (! self::variableArgument($toRoute->args[$offset], $name)) {
                return false;
            }
        }
        $fallback = $statements[2];
        $fallbackReturn = $fallback->stmts[0] ?? null;
        $fallbackCondition = $fallback->cond;
        $resolver =
            $fallbackCondition instanceof Node\Expr\BinaryOp\BooleanAnd
            && $fallbackCondition->left instanceof Node\Expr\BooleanNot
                ? $fallbackCondition->left->expr
                : null;
        $resolverArgument = $resolver instanceof Node\Expr\FuncCall ? $resolver->args[0] ?? null : null;
        $resolverProperty = $resolverArgument instanceof Node\Arg ? $resolverArgument->value : null;
        $urlNullCheck =
            $fallbackCondition instanceof Node\Expr\BinaryOp\BooleanAnd
            && $fallbackCondition->right instanceof Node\Expr\BooleanNot
                ? $fallbackCondition->right->expr
                : null;
        $urlArgument = $urlNullCheck instanceof Node\Expr\FuncCall ? $urlNullCheck->args[0] ?? null : null;
        $urlAssignment = $urlArgument instanceof Node\Arg ? $urlArgument->value : null;
        $resolve = $urlAssignment instanceof Node\Expr\Assign ? $urlAssignment->expr : null;

        return (
            count($fallback->stmts) === 1
            && $resolver instanceof Node\Expr\FuncCall
            && $resolver->name instanceof Node\Name
            && strtolower($resolver->name->toString()) === 'is_null'
            && count($resolver->args) === 1
            && $resolverProperty instanceof Node\Expr\PropertyFetch
            && $resolverProperty->var instanceof Node\Expr\Variable
            && $resolverProperty->var->name === 'this'
            && $resolverProperty->name instanceof Node\Identifier
            && $resolverProperty->name->name === 'missingNamedRouteResolver'
            && $urlNullCheck instanceof Node\Expr\FuncCall
            && $urlNullCheck->name instanceof Node\Name
            && strtolower($urlNullCheck->name->toString()) === 'is_null'
            && count($urlNullCheck->args) === 1
            && $urlAssignment instanceof Node\Expr\Assign
            && $urlAssignment->var instanceof Node\Expr\Variable
            && $urlAssignment->var->name === 'url'
            && $resolve instanceof Node\Expr\FuncCall
            && $resolve->name instanceof Node\Name
            && strtolower($resolve->name->toString()) === 'call_user_func'
            && count($resolve->args) === 4
            && $resolve->args[0] instanceof Node\Arg
            && $resolve->args[0]->value instanceof Node\Expr\PropertyFetch
            && $resolve->args[0]->value->name instanceof Node\Identifier
            && $resolve->args[0]->value->name->name === 'missingNamedRouteResolver'
            && self::variableArgument($resolve->args[1], 'name')
            && self::variableArgument($resolve->args[2], 'parameters')
            && self::variableArgument($resolve->args[3], 'absolute')
            && $fallbackReturn instanceof Node\Stmt\Return_
            && $fallbackReturn->expr instanceof Node\Expr\Variable
            && $fallbackReturn->expr->name === 'url'
        );
    }

    private static function variableArgument(Node\Arg|Node\VariadicPlaceholder $argument, string $name): bool
    {
        return (
            $argument instanceof Node\Arg
            && ! $argument->unpack
            && $argument->name === null
            && $argument->value instanceof Node\Expr\Variable
            && $argument->value->name === $name
        );
    }

    private function nativeResponseHelper(NodeAnalysisContext $context, Node\Expr $expression): bool
    {
        if (
            ! $expression instanceof Node\Expr\FuncCall
            || ! $expression->name instanceof Node\Name
            || $expression->args !== []
            || $expression->isFirstClassCallable()
        ) {
            return false;
        }
        $name = $expression->name->toString();
        /** @var mixed $fallback */
        $fallback = $expression->name->getAttribute('namespacedName');
        if (
            $fallback instanceof Node\Name
            && $context->codebase->getFunction($fallback->toString()) !== null
            || strcasecmp($name, 'response') !== 0
        ) {
            return false;
        }
        $function = $context->codebase->getFunction('response');
        $app = $context->codebase->getFunction('app');
        if (
            $function === null
            || $app === null
            || ! self::frameworkFile($function->location->file, 'Illuminate/Foundation/helpers.php')
            || ! self::frameworkFile($app->location->file, 'Illuminate/Foundation/helpers.php')
            || array_map(static fn ($parameter): string => $parameter->name, $function->parameters) !== [
                '$content',
                '$status',
                '$headers',
            ]
            || array_map(static fn ($parameter): string => $parameter->name, $app->parameters) !== [
                '$abstract',
                '$parameters',
            ]
        ) {
            return false;
        }
        $file = $function->location->file ?? '';
        $node = (new NodeFinder)->findFirst(
            $this->source->read(str_starts_with($file, '//?/') ? substr($file, 4) : $file) ?? [],
            static fn (Node $node): bool => (
                $node instanceof Node\Stmt\Function_
                && strtolower($node->name->toString()) === 'response'
            ),
        );
        if (! $node instanceof Node\Stmt\Function_ || count($node->stmts ?? []) !== 3) {
            return false;
        }
        [$factory, $branch, $make] = $node->stmts;

        return (
            $factory instanceof Node\Stmt\Expression
            && $factory->expr instanceof Node\Expr\Assign
            && $factory->expr->var instanceof Node\Expr\Variable
            && $factory->expr->var->name === 'factory'
            && $factory->expr->expr instanceof Node\Expr\FuncCall
            && $factory->expr->expr->name instanceof Node\Name
            && strtolower($factory->expr->expr->name->toString()) === 'app'
            && PhpSource::argument($factory->expr->expr->args, 0, 'abstract') instanceof Node\Expr\ClassConstFetch
            && PhpSource::value(PhpSource::argument($factory->expr->expr->args, 0, 'abstract')) === self::CONTRACT
            && $branch instanceof Node\Stmt\If_
            && $branch->cond instanceof Node\Expr\BinaryOp\Identical
            && $branch->cond->left instanceof Node\Expr\FuncCall
            && $branch->cond->left->name instanceof Node\Name
            && strtolower($branch->cond->left->name->toString()) === 'func_num_args'
            && PhpSource::value($branch->cond->right) === 0
            && count($branch->stmts) === 1
            && $branch->stmts[0] instanceof Node\Stmt\Return_
            && $branch->stmts[0]->expr instanceof Node\Expr\Variable
            && $branch->stmts[0]->expr->name === 'factory'
            && $make instanceof Node\Stmt\Return_
            && $make->expr instanceof Node\Expr\MethodCall
            && $make->expr->name instanceof Node\Identifier
            && $make->expr->name->name === 'make'
        );
    }

    private function call(NodeAnalysisContext $context): Node\Expr\MethodCall|Node\Expr\StaticCall|null
    {
        $hash = hash('sha256', $context->source->path."\0".$context->source->contents);
        if ($hash !== $this->sourceHash) {
            $this->sourceHash = $hash;
            $this->calls = [];
            try {
                $nodes = (new ParserFactory)
                    ->createForNewestSupportedVersion()
                    ->parse($context->source->contents);
                $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes ?? []);
            } catch (\PhpParser\Error) {
                return null;
            }
            foreach ((new NodeFinder)->find(
                $nodes,
                static fn (Node $node): bool => (
                    $node instanceof Node\Expr\MethodCall
                    || $node instanceof Node\Expr\StaticCall
                ),
            ) as $call) {
                if ($call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\StaticCall) {
                    $this->calls[$call->getStartFilePos().':'.($call->getEndFilePos() + 1)] = $call;
                }
            }
        }

        return $this->calls[$context->node->span->start.':'.$context->node->span->end] ?? null;
    }

    private static function frameworkFile(?string $file, string $suffix): bool
    {
        return str_ends_with(str_replace('\\', '/', $file ?? ''), '/laravel/framework/src/'.$suffix);
    }
}
