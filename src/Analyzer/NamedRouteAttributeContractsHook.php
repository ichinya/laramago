<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ContainerBindings;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ModelReflection;
use Ichinya\Laramago\Analyzer\StaticAnalysis\NamedRouteCatalog;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\NodeAnalysisHook;
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

/** Check Laravel's class-level FormRequest redirect attribute under a closed route catalog. */
final class NamedRouteAttributeContractsHook implements NodeAnalysisHook, InitializationHook
{
    private const ATTRIBUTE = 'Illuminate\Foundation\Http\Attributes\RedirectToRoute';
    private const FORM_REQUEST = 'Illuminate\Foundation\Http\FormRequest';
    private const REDIRECTOR = 'Illuminate\Routing\Redirector';
    private const URL_GENERATOR = 'Illuminate\Routing\UrlGenerator';

    private NamedRouteCatalog $catalog;
    private ContainerBindings $bindings;
    private PhpSource $source;
    private ?string $sourceHash = null;
    /** @var array<string, array{Node\Attribute, Node\Stmt\Class_}> */
    private array $references = [];

    public function __construct(
        private readonly string $root = '.',
    ) {
        $this->initializeState();
    }

    public function initialize(InitializationContext $context): void
    {
        $this->initializeState();
    }

    private function initializeState(): void
    {
        $this->catalog = new NamedRouteCatalog($this->root);
        $this->bindings = new ContainerBindings($this->root);
        $this->source = new PhpSource($this->root);
        $this->sourceHash = null;
        $this->references = [];
    }

    public function getTargets(): array
    {
        return [NodeKind::Attribute];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        if (! $this->catalog->enabled()) {
            return;
        }
        $reference = $this->reference($context);
        if ($reference === null) {
            return;
        }
        [$attribute, $request] = $reference;
        if (
            $request->extends?->toString() !== self::FORM_REQUEST
            || ! $this->unmodifiedRequest($request)
            || ! $this->nativeHandlers($context)
            || $this->bindings->configured('url')
            || $this->bindings->configured(self::URL_GENERATOR)
            || $this->bindings->configured('Illuminate\Contracts\Routing\UrlGenerator')
            || $this->bindings->configured('redirect')
            || $this->bindings->configured(self::REDIRECTOR)
            || $this->bindings->configured('Illuminate\Contracts\Routing\Redirector')
        ) {
            return;
        }
        $name = PhpSource::argument($attribute->args, 0, 'route');
        if (
            ! $name instanceof Node\Scalar\String_
            || $name->value === ''
            || $name->value === '0'
            || ! $this->catalog->missing($name->value)
        ) {
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

    /** @return array{Node\Attribute, Node\Stmt\Class_}|null */
    private function reference(NodeAnalysisContext $context): ?array
    {
        $hash = hash('sha256', $context->source->path."\0".$context->source->contents);
        if ($hash !== $this->sourceHash) {
            $this->sourceHash = $hash;
            $this->references = [];
            try {
                $nodes = (new ParserFactory)
                    ->createForNewestSupportedVersion()
                    ->parse($context->source->contents);
                $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes ?? []);
            } catch (\PhpParser\Error) {
                return null;
            }
            foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Stmt\Class_::class) as $class) {
                foreach ($class->attrGroups as $group) {
                    foreach ($group->attrs as $attribute) {
                        $this->references[$attribute->getStartFilePos().':'.($attribute->getEndFilePos() + 1)] = [
                            $attribute,
                            $class,
                        ];
                    }
                }
            }
        }
        $reference = $this->references[$context->node->span->start.':'.$context->node->span->end] ?? null;

        return $reference !== null && strcasecmp($reference[0]->name->toString(), self::ATTRIBUTE) === 0
            ? $reference
            : null;
    }

    private function unmodifiedRequest(Node\Stmt\Class_ $request): bool
    {
        $redirectAttributes = 0;
        foreach ($request->stmts as $statement) {
            if ($statement instanceof Node\Stmt\TraitUse || $statement instanceof Node\Stmt\Property) {
                return false;
            }
            if (
                $statement instanceof Node\Stmt\ClassMethod
                && in_array(
                    strtolower($statement->name->toString()),
                    [
                        'getvalidatorinstance',
                        'configurefromattributes',
                        'failedvalidation',
                        'getredirecturl',
                        'nearestclasswithattribute',
                        'setredirector',
                    ],
                    true,
                )
            ) {
                return false;
            }
        }
        foreach ($request->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (strcasecmp($attribute->name->toString(), self::ATTRIBUTE) === 0) {
                    $redirectAttributes++;
                    if (
                        $redirectAttributes > 1
                        || count($attribute->args) !== 1
                        || $attribute->args[0]->unpack
                        || $attribute->args[0]->name !== null
                        && $attribute->args[0]->name->toString() !== 'route'
                    ) {
                        return false;
                    }
                }
                if (
                    strcasecmp($attribute->name->toString(), 'Illuminate\Foundation\Http\Attributes\RedirectTo') === 0
                ) {
                    return false;
                }
            }
        }

        return true;
    }

    private function nativeHandlers(NodeAnalysisContext $context): bool
    {
        $attributeClass = $context->codebase->getClass(self::ATTRIBUTE);
        $requestClass = $context->codebase->getClass(self::FORM_REQUEST);
        $constructor = $context->codebase->getMethod(self::ATTRIBUTE, '__construct');
        if (
            $attributeClass === null
            || $attributeClass->hasIncompleteHierarchy()
            || $requestClass === null
            || $requestClass->hasIncompleteHierarchy()
            || $constructor === null
            || $constructor->identifier->class !== self::ATTRIBUTE
            || ! $constructor->constructor
            || $constructor->static
            || $constructor->visibility !== Visibility::Public
            || ! self::frameworkFile($constructor->location->file, 'Foundation/Http/Attributes/RedirectToRoute.php')
            || ! $this->classOnlyAttribute($constructor->location->file)
        ) {
            return false;
        }
        $reflection = new ModelReflection($context->codebase, $this->source);
        $constructorNode = $reflection->methodNode($constructor);
        $route = $constructorNode?->params[0] ?? null;
        if (
            $constructorNode === null
            || count($constructorNode->params) !== 1
            || $constructorNode->stmts !== []
            || ! $route instanceof Node\Param
            || ! $route->isPromoted()
            || ! $route->isPublic()
            || $route->byRef
            || $route->variadic
            || $route->default !== null
            || ! $route->type instanceof Node\Identifier
            || strtolower($route->type->toString()) !== 'string'
            || ! $route->var instanceof Node\Expr\Variable
            || $route->var->name !== 'route'
        ) {
            return false;
        }
        /** @var array<string, Node\Stmt\ClassMethod> $methods */
        $methods = [];
        foreach ([
            'getValidatorInstance',
            'configureFromAttributes',
            'failedValidation',
            'getRedirectUrl',
            'setRedirector',
        ] as $name) {
            $method = $context->codebase->getDeclaringMethod(self::FORM_REQUEST, $name);
            $node = $method !== null ? $reflection->methodNode($method) : null;
            if (
                $method === null
                || $method->identifier->class !== self::FORM_REQUEST
                || $method->static
                || $method->visibility !== ($name === 'setRedirector' ? Visibility::Public : Visibility::Protected)
                || ! self::frameworkFile($method->location->file, 'Foundation/Http/FormRequest.php')
                || $node === null
            ) {
                return false;
            }
            $methods[$name] = $node;
        }
        $urlRoute = $context->codebase->getDeclaringMethod(self::URL_GENERATOR, 'route');
        if (
            $urlRoute === null
            || $urlRoute->identifier->class !== self::URL_GENERATOR
            || $urlRoute->static
            || $urlRoute->visibility !== Visibility::Public
            || ! self::frameworkFile($urlRoute->location->file, 'Routing/UrlGenerator.php')
            || ! $this->nativeUrlRoute($reflection->methodNode($urlRoute))
        ) {
            return false;
        }

        return (
            self::topLevelCallsThis($methods['getValidatorInstance'], 'configureFromAttributes')
            && self::copiesRouteAttribute($methods['configureFromAttributes'])
            && self::throwsThis($methods['failedValidation'], 'getRedirectUrl')
            && self::forwardsRoute($methods['getRedirectUrl'])
            && self::setsRedirector($methods['setRedirector'])
            && $this->nativeRedirector($context, $reflection)
        );
    }

    private function nativeUrlRoute(?Node\Stmt\ClassMethod $method): bool
    {
        $statements = $method?->stmts ?? [];
        if (
            $method === null
            || count($method->params) !== 3
            || count($statements) !== 4
            || ! $statements[0] instanceof Node\Stmt\If_
            || ! $statements[1] instanceof Node\Stmt\If_
            || ! $statements[2] instanceof Node\Stmt\If_
            || ! $statements[3] instanceof Node\Stmt\Expression
            || ! $statements[3]->expr instanceof Node\Expr\Throw_
            || ! $statements[3]->expr->expr instanceof Node\Expr\New_
            || ! $statements[3]->expr->expr->class instanceof Node\Name
            || $statements[3]->expr->expr->class->toString()
                !== 'Symfony\Component\Routing\Exception\RouteNotFoundException'
        ) {
            return false;
        }
        $lookup = $statements[1];
        $notNull = $lookup->cond instanceof Node\Expr\BooleanNot ? $lookup->cond->expr : null;
        $assignment = $notNull instanceof Node\Expr\FuncCall ? PhpSource::argument($notNull->args, 0, '') : null;
        $byName = $assignment instanceof Node\Expr\Assign ? $assignment->expr : null;
        $return = $lookup->stmts[0] ?? null;
        $toRoute = $return instanceof Node\Stmt\Return_ ? $return->expr : null;
        if (
            ! self::function($notNull, 'is_null')
            || ! $assignment instanceof Node\Expr\Assign
            || ! self::variable($assignment->var, 'route')
            || ! $byName instanceof Node\Expr\MethodCall
            || ! self::thisProperty($byName->var, 'routes')
            || ! $byName->name instanceof Node\Identifier
            || $byName->name->toString() !== 'getByName'
            || ! self::variable(PhpSource::argument($byName->args, 0, ''), 'name')
            || count($lookup->stmts) !== 1
            || ! $toRoute instanceof Node\Expr\MethodCall
            || ! self::variable($toRoute->var, 'this')
            || ! $toRoute->name instanceof Node\Identifier
            || $toRoute->name->toString() !== 'toRoute'
            || count($toRoute->args) !== 3
            || ! self::variable(PhpSource::argument($toRoute->args, 0, ''), 'route')
            || ! self::variable(PhpSource::argument($toRoute->args, 1, ''), 'parameters')
            || ! self::variable(PhpSource::argument($toRoute->args, 2, ''), 'absolute')
        ) {
            return false;
        }
        $fallback = $statements[2];
        $condition = $fallback->cond;
        $resolver =
            $condition instanceof Node\Expr\BinaryOp\BooleanAnd && $condition->left instanceof Node\Expr\BooleanNot
                ? $condition->left->expr
                : null;
        $resolved =
            $condition instanceof Node\Expr\BinaryOp\BooleanAnd && $condition->right instanceof Node\Expr\BooleanNot
                ? $condition->right->expr
                : null;
        $resolveAssignment = $resolved instanceof Node\Expr\FuncCall
            ? PhpSource::argument($resolved->args, 0, '')
            : null;
        $call = $resolveAssignment instanceof Node\Expr\Assign ? $resolveAssignment->expr : null;
        $fallbackReturn = $fallback->stmts[0] ?? null;

        return (
            $resolver instanceof Node\Expr\FuncCall
            && self::function($resolver, 'is_null')
            && self::thisProperty(PhpSource::argument($resolver->args, 0, ''), 'missingNamedRouteResolver')
            && self::function($resolved, 'is_null')
            && $resolveAssignment instanceof Node\Expr\Assign
            && self::variable($resolveAssignment->var, 'url')
            && $call instanceof Node\Expr\FuncCall
            && self::function($call, 'call_user_func')
            && count($call->args) === 4
            && self::thisProperty(PhpSource::argument($call->args, 0, ''), 'missingNamedRouteResolver')
            && self::variable(PhpSource::argument($call->args, 1, ''), 'name')
            && self::variable(PhpSource::argument($call->args, 2, ''), 'parameters')
            && self::variable(PhpSource::argument($call->args, 3, ''), 'absolute')
            && count($fallback->stmts) === 1
            && $fallbackReturn instanceof Node\Stmt\Return_
            && self::variable($fallbackReturn->expr, 'url')
        );
    }

    private static function function(?Node $node, string $name): bool
    {
        return (
            $node instanceof Node\Expr\FuncCall
            && $node->name instanceof Node\Name
            && strtolower($node->name->toString()) === $name
        );
    }

    private function nativeRedirector(NodeAnalysisContext $context, ModelReflection $reflection): bool
    {
        $constructor = $context->codebase->getDeclaringMethod(self::REDIRECTOR, '__construct');
        $getter = $context->codebase->getDeclaringMethod(self::REDIRECTOR, 'getUrlGenerator');
        if (
            $constructor === null
            || $getter === null
            || $constructor->identifier->class !== self::REDIRECTOR
            || $getter->identifier->class !== self::REDIRECTOR
            || $constructor->static
            || $getter->static
            || $constructor->visibility !== Visibility::Public
            || $getter->visibility !== Visibility::Public
            || ! self::frameworkFile($constructor->location->file, 'Routing/Redirector.php')
            || ! self::frameworkFile($getter->location->file, 'Routing/Redirector.php')
        ) {
            return false;
        }
        $constructorNode = $reflection->methodNode($constructor);
        $getterNode = $reflection->methodNode($getter);
        $parameter = $constructorNode?->params[0] ?? null;
        $assignment = $constructorNode?->stmts[0] ?? null;
        $return = $getterNode?->stmts[0] ?? null;
        if (
            $constructorNode === null
            || count($constructorNode->params) !== 1
            || count($constructorNode->stmts ?? []) !== 1
            || ! $parameter instanceof Node\Param
            || ! $parameter->type instanceof Node\Name
            || $parameter->type->toString() !== self::URL_GENERATOR
            || ! $parameter->var instanceof Node\Expr\Variable
            || $parameter->var->name !== 'generator'
            || ! $assignment instanceof Node\Stmt\Expression
            || ! $assignment->expr instanceof Node\Expr\Assign
            || ! self::thisProperty($assignment->expr->var, 'generator')
            || ! self::variable($assignment->expr->expr, 'generator')
            || $getterNode === null
            || count($getterNode->stmts ?? []) !== 1
            || ! $return instanceof Node\Stmt\Return_
            || ! self::thisProperty($return->expr, 'generator')
        ) {
            return false;
        }

        return true;
    }

    private function classOnlyAttribute(?string $path): bool
    {
        if ($path === null) {
            return false;
        }
        foreach ((new NodeFinder)->findInstanceOf(
            $this->source->read($path) ?? [],
            Node\Stmt\Class_::class,
        ) as $class) {
            if ($class->name?->toString() !== 'RedirectToRoute') {
                continue;
            }
            foreach ($class->attrGroups as $group) {
                foreach ($group->attrs as $attribute) {
                    $target = PhpSource::argument($attribute->args, 0, 'flags');
                    if (
                        $attribute->name->toString() === 'Attribute'
                        && $target instanceof Node\Expr\ClassConstFetch
                        && $target->class instanceof Node\Name
                        && $target->class->toString() === 'Attribute'
                        && $target->name instanceof Node\Identifier
                        && $target->name->toString() === 'TARGET_CLASS'
                    ) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private static function topLevelCallsThis(Node\Stmt\ClassMethod $method, string $name): bool
    {
        foreach ($method->stmts ?? [] as $statement) {
            $call = $statement instanceof Node\Stmt\Expression ? $statement->expr : null;
            if (
                $call instanceof Node\Expr\MethodCall
                && $call->var instanceof Node\Expr\Variable
                && $call->var->name === 'this'
                && $call->name instanceof Node\Identifier
                && strcasecmp($call->name->toString(), $name) === 0
            ) {
                return true;
            }
        }

        return false;
    }

    private static function throwsThis(Node\Stmt\ClassMethod $method, string $name): bool
    {
        foreach ($method->stmts ?? [] as $statement) {
            $throw = $statement instanceof Node\Stmt\Expression ? $statement->expr : null;
            if (! $throw instanceof Node\Expr\Throw_) {
                continue;
            }
            foreach ((new NodeFinder)->findInstanceOf([$throw], Node\Expr\MethodCall::class) as $call) {
                if (
                    $call->var instanceof Node\Expr\Variable
                    && $call->var->name === 'this'
                    && $call->name instanceof Node\Identifier
                    && strcasecmp($call->name->toString(), $name) === 0
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function copiesRouteAttribute(Node\Stmt\ClassMethod $method): bool
    {
        $read = false;
        foreach ((new NodeFinder)->findInstanceOf($method->stmts ?? [], Node\Expr\Assign::class) as $assign) {
            if (
                $assign->var instanceof Node\Expr\Variable
                && $assign->var->name === 'redirectToRoute'
                && $assign->expr instanceof Node\Expr\MethodCall
                && $assign->expr->name instanceof Node\Identifier
                && $assign->expr->name->toString() === 'getAttributes'
                && ($argument = PhpSource::argument($assign->expr->args, 0, 'name'))
                    instanceof Node\Expr\ClassConstFetch
                && $argument->class instanceof Node\Name
                && strcasecmp($argument->class->toString(), self::ATTRIBUTE) === 0
                && $argument->name instanceof Node\Identifier
                && strtolower($argument->name->toString()) === 'class'
            ) {
                $read = true;
            }
        }
        if (! $read) {
            return false;
        }
        foreach ((new NodeFinder)->findInstanceOf($method->stmts ?? [], Node\Stmt\If_::class) as $branch) {
            if (
                ! $branch->cond instanceof Node\Expr\BinaryOp\NotIdentical
                || ! self::variable($branch->cond->left, 'redirectToRoute')
                || ! $branch->cond->right instanceof Node\Expr\Array_
                || $branch->cond->right->items !== []
            ) {
                continue;
            }
            foreach ((new NodeFinder)->findInstanceOf($branch->stmts, Node\Expr\Assign::class) as $assign) {
                if (
                    self::thisProperty($assign->var, 'redirectRoute')
                    && $assign->expr instanceof Node\Expr\PropertyFetch
                    && $assign->expr->name instanceof Node\Identifier
                    && $assign->expr->name->toString() === 'route'
                    && $assign->expr->var instanceof Node\Expr\MethodCall
                    && $assign->expr->var->name instanceof Node\Identifier
                    && $assign->expr->var->name->toString() === 'newInstance'
                    && $assign->expr->var->var instanceof Node\Expr\ArrayDimFetch
                    && $assign->expr->var->var->var instanceof Node\Expr\Variable
                    && $assign->expr->var->var->var->name === 'redirectToRoute'
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function forwardsRoute(Node\Stmt\ClassMethod $method): bool
    {
        $generator = false;
        foreach ($method->stmts ?? [] as $statement) {
            $assign = $statement instanceof Node\Stmt\Expression ? $statement->expr : null;
            if (
                $assign instanceof Node\Expr\Assign
                && self::variable($assign->var, 'url')
                && $assign->expr instanceof Node\Expr\MethodCall
                && $assign->expr->name instanceof Node\Identifier
                && $assign->expr->name->toString() === 'getUrlGenerator'
                && self::thisProperty($assign->expr->var, 'redirector')
            ) {
                $generator = true;
            }
        }
        if (! $generator) {
            return false;
        }
        foreach ($method->stmts ?? [] as $statement) {
            $match = $statement instanceof Node\Stmt\Return_ ? $statement->expr : null;
            if (! $match instanceof Node\Expr\Match_) {
                continue;
            }
            foreach ($match->arms as $arm) {
                $condition = $arm->conds[0] ?? null;
                $call = $arm->body;
                if (
                    count($arm->conds ?? []) === 1
                    && $condition instanceof Node\Expr\BooleanNot
                    && $condition->expr instanceof Node\Expr\Empty_
                    && self::thisProperty($condition->expr->expr, 'redirectRoute')
                    && $call instanceof Node\Expr\MethodCall
                    && self::variable($call->var, 'url')
                    && $call->name instanceof Node\Identifier
                    && $call->name->toString() === 'route'
                    && self::thisProperty(PhpSource::argument($call->args, 0, 'name'), 'redirectRoute')
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function setsRedirector(Node\Stmt\ClassMethod $method): bool
    {
        $parameter = $method->params[0] ?? null;
        $statement = $method->stmts[0] ?? null;

        return (
            count($method->params) === 1
            && $parameter instanceof Node\Param
            && $parameter->type instanceof Node\Name
            && $parameter->type->toString() === self::REDIRECTOR
            && self::variable($parameter->var, 'redirector')
            && $statement instanceof Node\Stmt\Expression
            && $statement->expr instanceof Node\Expr\Assign
            && self::thisProperty($statement->expr->var, 'redirector')
            && self::variable($statement->expr->expr, 'redirector')
        );
    }

    private static function variable(?Node $node, string $name): bool
    {
        return $node instanceof Node\Expr\Variable && $node->name === $name;
    }

    private static function thisProperty(?Node $node, string $name): bool
    {
        return (
            $node instanceof Node\Expr\PropertyFetch
            && $node->var instanceof Node\Expr\Variable
            && $node->var->name === 'this'
            && $node->name instanceof Node\Identifier
            && $node->name->toString() === $name
        );
    }

    private static function frameworkFile(?string $path, string $suffix): bool
    {
        return str_ends_with(str_replace('\\', '/', $path ?? ''), '/laravel/framework/src/Illuminate/'.$suffix);
    }
}
