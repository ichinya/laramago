<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Ichinya\Laramago\Analyzer\FacadeCallResolver;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Metadata\ClassLikeKind;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\CallableType;
use Mago\Sdk\Analyzer\Type\FunctionLikeKind;
use Mago\Sdk\Analyzer\Type\GenericParameterType;
use Mago\Sdk\Analyzer\Type\GenericParentKind;
use Mago\Sdk\Analyzer\Type\MixedType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\Visibility;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/** An independently declared formal/result template plus exact normal-return forwarding. */
final class ForwardedTransactionTemplateContract
{
    private const DB = 'Illuminate\\Support\\Facades\\DB';
    private const CONNECTION = 'Illuminate\\Database\\Connection';
    private const TRANSACTIONS = 'Illuminate\\Database\\Concerns\\ManagesTransactions';
    // Complete supported native bodies. No implementation is executed to discover a result.
    private const BODIES = [
        'Illuminate\\Support\\Facades\\Facade::getFacadeRoot' => 'return static::resolveFacadeInstance(static::getFacadeAccessor());',
        'Illuminate\\Support\\Facades\\Facade::resolveFacadeInstance' => 'if (isset(static::$resolvedInstance[$name])) { return static::$resolvedInstance[$name]; } if (static::$app) { if (static::$cached) { return static::$resolvedInstance[$name] = static::$app[$name]; } return static::$app[$name]; }',
        'Illuminate\\Support\\Facades\\Facade::__callStatic' => '$instance = static::getFacadeRoot(); if (! $instance) { throw new \\RuntimeException("A facade root has not been set."); } return $instance->$method(...$args);',
        'Illuminate\\Database\\DatabaseManager::__call' => 'return $this->connection()->$method(...$parameters);',
        'Illuminate\\Database\\DatabaseManager::connection' => '[$database, $type] = $this->parseConnectionName($name = \\Illuminate\\Support\\enum_value($name) ?: $this->getDefaultConnection()); if (! isset($this->connections[$name])) { $this->connections[$name] = $this->configure($this->makeConnection($database), $type); $this->dispatchConnectionEstablishedEvent($this->connections[$name]); } return $this->connections[$name];',
        'Illuminate\\Database\\Concerns\\ManagesTransactions::transaction' => 'for ($currentAttempt = 1; $currentAttempt <= $attempts; $currentAttempt++) { $this->beginTransaction(); try { $callbackResult = $callback($this); } catch (\\Throwable $e) { $this->handleTransactionException($e, $currentAttempt, $attempts); continue; } $levelBeingCommitted = $this->transactions; try { if ($this->transactions === 1) { $this->fireConnectionEvent("committing"); $this->getPdo()->commit(); } $this->transactions = max(0, $this->transactions - 1); } catch (\\Throwable $e) { $this->handleCommitTransactionException($e, $currentAttempt, $attempts); continue; } $this->transactionsManager?->commit($this->getName(), $levelBeingCommitted, $this->transactions); $this->fireConnectionEvent("committed"); return $callbackResult; }',
        'Illuminate\\Database\\Concerns\\ManagesTransactions::handleTransactionException' => 'if ($this->causedByConcurrencyError($e) && $this->transactions > 1) { $this->transactions--; $this->transactionsManager?->rollback($this->getName(), $this->transactions); throw new \\Illuminate\\Database\\DeadlockException($e->getMessage(), is_int($e->getCode()) ? $e->getCode() : 0, $e); } $this->rollBack(); if ($this->causedByConcurrencyError($e) && $currentAttempt < $maxAttempts) { return; } throw $e;',
        'Illuminate\\Database\\Concerns\\ManagesTransactions::handleCommitTransactionException' => '$this->transactions = max(0, $this->transactions - 1); if ($this->causedByConcurrencyError($e) && $currentAttempt < $maxAttempts) { $pdo = $this->getPdo(); if ($pdo->inTransaction()) { $pdo->rollBack(); } return; } if ($this->causedByLostConnection($e)) { $this->transactions = 0; } throw $e;',
    ];
    private PhpSource $source;
    private ?Codebase $activeCodebase = null;
    /** @var array<string, string> */
    private array $snapshots = [];

    public function __construct(private readonly string $root, private readonly ForwardedTransactionTemplateCalls $calls)
    {
        $this->source = new PhpSource($root);
    }

    /** @param array<string, mixed> $proof */
    public function result(ReturnTypeProviderContext $context, array $proof): ?Type
    {
        $context->cancellation->throwIfCancelled();
        $scope = $proof['scope'];
        $codebase = $context->codebase;
        $this->activeCodebase = $codebase;
        $method = $proof['owner'] === null ? $codebase->getFunction($scope->namespacedName?->toString() ?? $scope->name->name)
            : ($codebase->getMethod($proof['owner'], $scope->name->name) ?? $codebase->getDeclaringMethod($proof['owner'], $scope->name->name));
        if ($method === null || ! $this->methodMatches($method, $scope, $proof['file'])
            || ($proof['owner'] !== null && (strcasecmp($method->identifier->class ?? '', $proof['owner']) !== 0 || ! $this->hierarchy($codebase, $proof['owner'])))) { return null; }
        $formal = $method->parameters[$proof['formal']] ?? null;
        $syntax = $scope->params[$proof['formal']] ?? null;
        $callable = $formal?->type?->type->atomicTypes[0] ?? null;
        $signature = $callable instanceof CallableType ? $callable->signature : null;
        $result = $signature?->returnType;
        $generic = $result?->atomicTypes[0] ?? null;
        if ($formal === null || $syntax === null || ! $syntax->type instanceof Node\Name || $syntax->type->toString() !== 'Closure'
            || $syntax->default !== null || $formal->flags->contains(MetadataFlags::BY_REFERENCE) || $formal->flags->contains(MetadataFlags::VARIADIC)
            || $formal->outType !== null || $formal->closureThisType !== null || $formal->defaultType !== null || ! $formal->type?->fromDocblock
            || count($formal->type->type->atomicTypes) !== 1 || ! $callable instanceof CallableType || $callable->alias !== null
            || $signature === null || ! $signature->closure || $signature->parameters !== [] || $signature->constraints !== []
            || count($result?->atomicTypes ?? []) !== 1 || ! $generic instanceof GenericParameterType || ($generic->intersections ?? []) !== []
            || ! $this->definedBy($generic, $method) || ! $method->returnType?->fromDocblock
            || ! $context->types->equals($method->returnType->type, $result)
            || $method->declaredReturnType !== null && ! $context->types->isContainedBy($result, $method->declaredReturnType->type)
            || ! $this->documents($context, $method, $scope, $proof['local'], $generic)
            || ! $this->wrapper($context, $proof) || ! $this->native($context)) { return null; }
        return $this->current() ? $result : null;
    }

    private function definedBy(GenericParameterType $generic, FunctionLikeMetadata $method): bool
    {
        $parent = $generic->definingEntity;
        return $parent->kind === GenericParentKind::FunctionLike && strcasecmp($parent->name, $method->identifier->class ?? '') === 0
            && strcasecmp($parent->member ?? '', $method->identifier->name) === 0;
    }

    private function documents(ReturnTypeProviderContext $context, FunctionLikeMetadata $method, Node $scope, string $local, GenericParameterType $generic): bool
    {
        $doc = $scope->getDocComment()?->getText() ?? '';
        if (preg_match('/@(?:phpstan|psalm)-(?:param|return|template)\b/i', $doc) === 1
            || preg_match('/@return\s+'.preg_quote($generic->name, '/').'\s*(?:\r?\n|\*\/)/', $doc) !== 1
            || preg_match('/@param\s+\\\\?Closure\s*\(\s*\)\s*:\s*'.preg_quote($generic->name, '/').'\s+\$'.preg_quote($local, '/').'\b/', $doc) !== 1
            || preg_match('/@template\s+'.preg_quote($generic->name, '/').'(?:\s+of\s+([^\r\n*]+))?\s*(?:\r?\n|\*\/)/', $doc, $match) !== 1) { return false; }
        $constraint = isset($match[1]) ? DiagnosticArrayTypes::parse(trim($match[1])) : Type::mixed();
        if ($constraint === null || ! $context->types->equals($constraint, $generic->constraint)) { return false; }
        $found = false;
        foreach ($method->templates as $template) {
            if ($template->name === $generic->name) {
                if ($found || $template->definingEntity != $generic->definingEntity || $template->default !== null
                    || ! $context->types->equals($template->constraint, $constraint)) { return false; }
                $found = true;
            }
        }
        return $found;
    }

    /** @param array<string, mixed> $proof */
    private function wrapper(ReturnTypeProviderContext $context, array $proof): bool
    {
        $call = $context->invocation;
        $callback = $call->getArgument(0, 'callback');
        if ($callback === null || $callback->span->start !== $proof['callbackArgument']->getStartFilePos()
            || $callback->span->end !== $proof['callbackArgument']->getEndFilePos() + 1 || $callback->type === null
            || count($callback->type->atomicTypes) !== 1 || ! ($atom = $callback->type->atomicTypes[0]) instanceof CallableType) { return false; }
        $id = $atom->alias ?? $atom->signature?->source;
        $metadata = $id?->kind === FunctionLikeKind::Closure ? $context->codebase->getFunctionLike($id) : null;
        if ($metadata === null || ! $this->methodMatches($metadata, $proof['callback'], $proof['file'])
            || $metadata->parameters !== [] || $metadata->declaredReturnType !== null || $metadata->returnType?->fromDocblock) { return false; }
        // Only an inferred unknown result is supplemented. Stronger effective facts remain authoritative.
        $returns = $metadata->returnType?->type->atomicTypes ?? [];
        if ($returns !== [] && (count($returns) !== 1 || ! $returns[0] instanceof MixedType)) { return false; }
        $seen = [];
        foreach ($call->arguments as $index => $argument) {
            $name = $argument->name ?? ($index === 0 ? 'callback' : 'attempts');
            if ($argument->unpacked || $argument->placeholder || ! in_array($name, ['callback', 'attempts'], true) || isset($seen[$name])) { return false; }
            $seen[$name] = true;
        }
        $attempts = $call->getArgument(1, 'attempts');
        if ($attempts !== null) {
            $literal = $attempts->type?->getLiteralInt();
            if ($literal === null || $literal < 1) { return false; }
        }
        return true;
    }

    private function native(ReturnTypeProviderContext $context): bool
    {
        $codebase = $context->codebase;
        if (! $this->hierarchy($codebase, self::DB) || ! $this->hierarchy($codebase, 'Illuminate\\Database\\DatabaseManager')) { return false; }
        if (! $this->bindingSnapshot()) { return false; }
        if ($this->calls->hasConnectionExtensions() && ! $this->connectionBoundary($context)) { return false; }
        $bindings = new ContainerBindings($this->root);
        foreach (['db', 'db.connection', 'Illuminate\\Database\\ConnectionInterface'] as $key) { if ($bindings->configured($key)) { return false; } }
        if (strcasecmp((new FacadeCallResolver($this->root))->rootClass($codebase, $context->invocation->receiverType) ?? '', 'Illuminate\\Database\\DatabaseManager') !== 0) { return false; }
        $application = $codebase->getDeclaringMethod('Illuminate\\Foundation\\Application', 'registerCoreContainerAliases');
        $accessor = $codebase->getDeclaringMethod(self::DB, 'getFacadeAccessor');
        foreach ([$application, $accessor] as $dependency) {
            if ($dependency === null || $dependency->location->file === null || ! $this->retain($dependency->location->file)) { return false; }
            $syntax = (new ModelReflection($codebase, $this->source))->methodNode($dependency);
            if ($syntax === null || ! $this->methodMatches($dependency, $syntax, $dependency->location->file)) { return false; }
        }
        $facade = $codebase->getMethod(self::DB, 'transaction');
        $reflection = new ModelReflection($codebase, $this->source);
        if ($facade === null || $reflection->methodNode($facade) !== null || count($facade->returnType?->type->atomicTypes ?? []) !== 1
            || ! ($facade->returnType->type->atomicTypes[0] ?? null) instanceof MixedType
            || ! $this->retain($facade->location->file ?? '') || ! $this->nativeFacadeDocument($facade)) { return false; }
        foreach (self::BODIES as $selector => $body) {
            [$owner, $name] = explode('::', $selector);
            $method = $codebase->getDeclaringMethod($owner === self::TRANSACTIONS ? self::CONNECTION : $owner, $name);
            $file = $owner === self::TRANSACTIONS ? '/Database/Concerns/ManagesTransactions.php'
                : ($owner === 'Illuminate\\Database\\DatabaseManager' ? '/Database/DatabaseManager.php' : '/Support/Facades/Facade.php');
            if ($method === null || strcasecmp($method->identifier->class ?? '', $owner) !== 0
                || ! str_ends_with(strtolower($this->calls->path($method->location->file ?? '')), strtolower('/laravel/framework/src/Illuminate'.$file))
                || ! ($this->nativeBody($codebase, $method, $body)
                    || $owner === 'Illuminate\\Database\\DatabaseManager' && $name === '__call'
                        && $this->nativeBody($codebase, $method, 'if (static::hasMacro($method)) { return $this->macroCall($method, $parameters); } return $this->connection()->$method(...$parameters);')
                        && $this->defaultMacroDispatch($context))) { return false; }
        }
        $standard = $codebase->getDeclaringMethod(self::CONNECTION, 'transaction');
        if ($standard === null || ! $this->nativeTransactionTemplate($context, $standard)) { return false; }
        foreach (array_unique([self::CONNECTION, ...$codebase->getClassDescendants(self::CONNECTION)]) as $class) {
            if (! $this->hierarchy($codebase, $class)) { return false; }
            foreach (['transaction', 'handleTransactionException', 'handleCommitTransactionException'] as $name) {
                $method = $codebase->getMethod($class, $name) ?? $codebase->getDeclaringMethod($class, $name);
                if ($method === null) { return false; }
                if (strcasecmp($method->identifier->class ?? '', self::TRANSACTIONS) === 0) { continue; }
                if ($name !== 'transaction' || ! $this->sqlServerTransaction($context, $method)) { return false; }
            }
        }
        return true;
    }

    /** The physically declared normal-return template remains authoritative for each standard driver. */
    private function nativeTransactionTemplate(ReturnTypeProviderContext $context, FunctionLikeMetadata $standard): bool
    {
        $codebase = $context->codebase;
        $generic = $standard?->returnType?->type->atomicTypes[0] ?? null;
        $callback = $standard?->parameters[0]->type?->type->atomicTypes[0] ?? null;
        if ($standard === null || ! $generic instanceof GenericParameterType || ! $this->definedBy($generic, $standard)
            || count($standard->returnType->type->atomicTypes) !== 1 || ! $standard->returnType->fromDocblock
            || count($standard->parameters[0]->type?->type->atomicTypes ?? []) !== 1
            || ! $callback instanceof CallableType || $callback->alias !== null || ! $callback->signature?->closure || count($callback->signature->parameters) !== 1
            || ! $context->types->equals($callback->signature->returnType ?? Type::mixed(), $standard->returnType->type)
            || count($standard->parameters) !== 2 || $standard->parameters[0]->name !== '$callback' || $standard->parameters[1]->name !== '$attempts') { return false; }
        $parameter = $callback->signature->parameters[0];
        $input = $parameter->type?->atomicTypes[0] ?? null;
        $syntax = (new ModelReflection($codebase, $this->source))->methodNode($standard);
        $matchingTemplates = array_values(array_filter($standard->templates, static fn ($template): bool => $template->name === $generic->name));
        if (count($matchingTemplates) !== 1 || $matchingTemplates[0]->default !== null
            || $matchingTemplates[0]->definingEntity != $generic->definingEntity
            || ! $context->types->equals($matchingTemplates[0]->constraint, $generic->constraint)
            || preg_match('/@template\s+'.preg_quote($generic->name, '/').'(?:\s+of\s+mixed)?\s*(?:\r?\n|\*\/)/', $syntax?->getDocComment()?->getText() ?? '') !== 1
            || preg_match('/@return\s+'.preg_quote($generic->name, '/').'\s*(?:\r?\n|\*\/)/', $syntax?->getDocComment()?->getText() ?? '') !== 1) { return false; }
        if (! $input instanceof NamedObjectType || count($parameter->type?->atomicTypes ?? []) !== 1 || ! $input->static
            || $parameter->byReference || $parameter->variadic || $parameter->hasDefault
            || $parameter->closureThisType !== null || $callback->signature->constraints !== []
            || ($input->parameters ?? []) !== [] || ($input->intersections ?? []) !== [] || $input->isThis || $input->remappedParameters
            || ! $standard->parameters[0]->type?->fromDocblock || $standard->parameters[0]->outType !== null
            || $standard->parameters[0]->closureThisType !== null || $standard->parameters[0]->defaultType !== null
            || ! $context->types->equals($standard->parameters[1]->type?->type ?? Type::mixed(), Type::int())
            || $standard->parameters[1]->defaultType?->type->getLiteralInt() !== 1
            || ! $syntax?->params[1]->default instanceof Node\Scalar\Int_ || $syntax->params[1]->default->value !== 1
            || preg_match('~@param\s+\(?\\\\Closure\s*\(\s*static\s*\)\s*:\s*'.preg_quote($generic->name, '~').'\s*\)?\s+\$callback\b~', $syntax?->getDocComment()?->getText() ?? '') !== 1
            || preg_match('/@(?:phpstan|psalm)-(?:param|return|template)\b/i', $syntax?->getDocComment()?->getText() ?? '') === 1) { return false; }
        foreach ($standard->parameters as $formal) {
            if ($formal->flags->contains(MetadataFlags::BY_REFERENCE) || $formal->flags->contains(MetadataFlags::VARIADIC)) { return false; }
        }
        return true;
    }

    /** Both unchanged SQL Server paths return the callback result on normal completion. */
    private function sqlServerTransaction(ReturnTypeProviderContext $context, FunctionLikeMetadata $method): bool
    {
        $owner = 'Illuminate\Database\SqlServerConnection';
        if (strcasecmp($method->identifier->class ?? '', $owner) !== 0
            || ! str_ends_with(strtolower($this->calls->path($method->location->file ?? '')), '/laravel/framework/src/illuminate/database/sqlserverconnection.php')) { return false; }
        $body = 'for ($a = 1; $a <= $attempts; $a++) { if ($this->getDriverName() === "sqlsrv") { return parent::transaction($callback, $attempts); } $this->getPdo()->exec("BEGIN TRAN"); try { $result = $callback($this); $this->getPdo()->exec("COMMIT TRAN"); } catch (\Throwable $e) { $this->getPdo()->exec("ROLLBACK TRAN"); throw $e; } return $result; }';

        return $this->nativeBody($context->codebase, $method, $body)
            && $this->nativeTransactionTemplate($context, $method);
    }
    /** A visible driver extension still crosses the unchanged physical Connection boundary. */
    private function connectionBoundary(ReturnTypeProviderContext $context): bool
    {
        $owner = 'Illuminate\\Database\\DatabaseManager';
        $codebase = $context->codebase;
        $configure = $codebase->getDeclaringMethod($owner, 'configure');
        $extend = $codebase->getDeclaringMethod($owner, 'extend');
        $formal = $configure?->parameters[0] ?? null;
        $atom = $formal?->declaredType?->type->atomicTypes[0] ?? null;
        if ($configure === null || $extend === null || strcasecmp($configure->identifier->class ?? '', $owner) !== 0
            || strcasecmp($extend->identifier->class ?? '', $owner) !== 0 || count($configure->parameters) !== 2
            || $formal === null || $formal->name !== '$connection' || $formal->declaredType?->fromDocblock
            || count($formal->declaredType?->type->atomicTypes ?? []) !== 1 || ! $atom instanceof NamedObjectType
            || strcasecmp($atom->name, self::CONNECTION) !== 0 || $atom->static || $atom->isThis
            || ($atom->parameters ?? []) !== [] || ($atom->intersections ?? []) !== []
            || $formal->flags->contains(MetadataFlags::BY_REFERENCE) || $formal->flags->contains(MetadataFlags::VARIADIC)
            || $formal->defaultType !== null || $formal->outType !== null || $formal->closureThisType !== null
            || $formal->type === null || ! $context->types->equals($formal->type->type, $formal->declaredType->type)) { return false; }
        if (! $this->nativeBody($codebase, $extend, '$this->extensions[$name] = $resolver;')) { return false; }
        // Both profiles return the same physically checked connection on normal completion.
        return $this->nativeBody($codebase, $configure, 'return $connection;')
            || $this->nativeBody($codebase, $configure, '$connection = $this->setPdoForType($connection, $type)->setReadWriteType($type); if ($this->app->bound("events")) { $connection->setEventDispatcher($this->app["events"]); } if ($this->app->bound("db.transactions")) { $connection->setTransactionManager($this->app["db.transactions"]); } $connection->setReconnector($this->reconnector); return $connection;');
    }

    private function bindingSnapshot(): bool
    {
        $file = $this->root.'/composer.json';
        if (! $this->retain($file)) { return false; }
        $bytes = @file_get_contents($file);
        $composer = $bytes === false ? null : json_decode($bytes, true);
        if (! is_array($composer)) { return false; }
        $extra = $composer['extra'] ?? [];
        $settings = is_array($extra) ? $extra['laramago'] ?? [] : null;
        if (! is_array($settings)) { return false; }
        $files = $settings['binding-files'] ?? [];
        if (! is_array($files) || ! array_is_list($files)) { return false; }
        foreach ($files as $path) {
            if (! is_string($path) || ! preg_match('~^(?:app|bootstrap)/[a-zA-Z0-9_./-]+\\.php$~', $path)
                || in_array('..', explode('/', $path), true) || ! $this->retain($path)) { return false; }
        }
        return true;
    }

    private function nativeFacadeDocument(FunctionLikeMetadata $method): bool
    {
        $location = $method->returnType?->location;
        if ($location?->file === null || ! $this->retain($location->file)) { return false; }
        $bytes = @file_get_contents($this->calls->path($location->file));
        return $bytes !== false && $location->span->start >= 0 && $location->span->end <= strlen($bytes)
            && trim(substr($bytes, $location->span->start, $location->span->length())) === 'mixed';
    }

    private function nativeBody(Codebase $codebase, FunctionLikeMetadata $metadata, string $expected): bool
    {
        $file = $metadata->location->file;
        if ($file === null || ! $this->retain($file)) { return false; }
        $node = (new ModelReflection($codebase, $this->source))->methodNode($metadata);
        if ($node === null || ! $this->methodMatches($metadata, $node, $file)) { return false; }
        $expected = (new ParserFactory)->createForNewestSupportedVersion()->parse('<?php '.$expected) ?? [];
        $expected = (new NodeTraverser(new NameResolver))->traverse($expected);
        $functions = [];
        foreach ((new NodeFinder)->findInstanceOf($this->source->read($this->calls->path($file)) ?? [], Node\Stmt\Function_::class) as $function) {
            $functions[strtolower($function->namespacedName?->toString() ?? $function->name->name)] = true;
        }
        $actual = self::body($node->stmts ?? [], $codebase, $functions);
        return $actual !== null && $actual === self::body($expected, $codebase);
    }

    /** @param array<Node> $statements @param array<string, true> $functions */
    private static function body(array $statements, Codebase $codebase, array $functions = []): ?string
    {
        $clone = unserialize(serialize($statements), ['allowed_classes' => true]);
        $normalizer = new class($codebase, $functions) extends NodeVisitorAbstract {
            public bool $valid = true;
            public function __construct(private readonly Codebase $codebase, private readonly array $functions) {}
            public function enterNode(Node $node): null
            {
                $node->setAttribute('comments', []);
                if ($node instanceof Node\Scalar\String_) { $node->setAttribute('kind', Node\Scalar\String_::KIND_SINGLE_QUOTED); }
                if ($node instanceof Node\Expr\ConstFetch && in_array(strtolower($node->name->toString()), ['null', 'true', 'false'], true)) {
                    $node->name = new Node\Name(strtolower($node->name->toString()));
                }
                if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
                    $fallback = $node->name->getAttribute('namespacedName');
                    if ($fallback instanceof Node\Name && (isset($this->functions[strtolower($fallback->toString())])
                        || $this->codebase->getFunction($fallback->toString()) !== null)) { $this->valid = false; }
                    // Both builtin and named framework helper calls retain their real global binding.
                    $function = $this->codebase->getFunction($node->name->toString());
                    if ($function === null || isset($this->functions[strtolower($node->name->toString())])) { $this->valid = false; }
                    $node->name = new Node\Name\FullyQualified(strtolower($node->name->toString()));
                }
                return null;
            }
        };
        $clone = (new NodeTraverser($normalizer))->traverse($clone);
        return $normalizer->valid ? (new Standard)->prettyPrint($clone) : null;
    }

    private function hierarchy(Codebase $codebase, string $class): bool
    {
        $this->activeCodebase = $codebase;
        foreach (array_unique([$class, ...$codebase->getClassAncestors($class)]) as $name) {
            $metadata = $codebase->getClassLike($name);
            if ($metadata === null || strcasecmp($metadata->name, $name) !== 0 || $metadata->hasIncompleteHierarchy()) { return false; }
            if ($metadata->flags->contains(MetadataFlags::BUILTIN)) { continue; }
            if ($metadata->location->file === null || ! $this->retain($metadata->location->file)) { return false; }
            $found = false;
            $nodes = $this->source->read($this->calls->path($metadata->location->file)) ?? [];
            $anonymous = [];
            foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Expr\New_::class) as $construction) {
                if ($construction->class instanceof Node\Stmt\Class_ && $construction->class->isAnonymous()
                    && $construction->getStartFilePos() === $metadata->location->span->start
                    && $construction->getEndFilePos() + 1 === $metadata->location->span->end) {
                    $anonymous[] = $construction->class;
                }
            }
            foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Stmt\ClassLike::class) as $node) {
                if ($node instanceof Node\Stmt\Class_ && $node->isAnonymous()) {
                    // Mago spans the entire anonymous construction, including its new token.
                    if ($metadata->nameLocation !== null || count($anonymous) !== 1 || $anonymous[0] !== $node) { continue; }
                } elseif ($node->namespacedName === null || strcasecmp($node->namespacedName->toString(), $metadata->name) !== 0) { continue; }
                if ($found) { return false; }
                $kind = $node instanceof Node\Stmt\Class_ ? ClassLikeKind::Class_ : ($node instanceof Node\Stmt\Trait_ ? ClassLikeKind::Trait : ClassLikeKind::Interface);
                if ($kind !== $metadata->kind || $metadata->location->span->end !== $node->getEndFilePos() + 1
                    || $node->name !== null && ($metadata->nameLocation?->span->start !== $node->name->getStartFilePos()
                        || $metadata->nameLocation?->span->end !== $node->name->getEndFilePos() + 1)) { return false; }
                if ($node instanceof Node\Stmt\Class_ && (strcasecmp($node->extends?->toString() ?? '', $metadata->directParentClass ?? '') !== 0
                    || $node->isAbstract() !== $metadata->flags->contains(MetadataFlags::ABSTRACT)
                    || $node->isFinal() !== $metadata->flags->contains(MetadataFlags::FINAL))) { return false; }
                foreach ($node->stmts as $statement) {
                    if (! $statement instanceof Node\Stmt\TraitUse) { continue; }
                    if ($statement->adaptations !== [] && ! $this->managerMacroAlias($codebase, $name, $statement)) { return false; }
                    foreach ($statement->traits as $trait) { if (! in_array(strtolower($trait->toString()), array_map(strtolower(...), $metadata->usedTraits), true)) { return false; } }
                }
                foreach ($node->getMethods() as $syntax) {
                    $bound = $codebase->getMethod($name, $syntax->name->name) ?? $codebase->getDeclaringMethod($name, $syntax->name->name);
                    if ($bound === null || strcasecmp($bound->identifier->class ?? '', $name) !== 0 || ! $this->methodMatches($bound, $syntax, $metadata->location->file)) { return false; }
                }
                $found = true;
            }
            if (! $found) { return false; }
        }
        return true;
    }

    /** The native manager aliases Macroable::__call without changing its declaration. */
    private function managerMacroAlias(Codebase $codebase, string $owner, Node\Stmt\TraitUse $use): bool
    {
        $trait = 'Illuminate\\Support\\Traits\\Macroable';
        if (strcasecmp($owner, 'Illuminate\\Database\\DatabaseManager') !== 0 || count($use->traits) !== 1
            || strcasecmp($use->traits[0]->toString(), $trait) !== 0 || count($use->adaptations) !== 1) { return false; }
        $alias = $use->adaptations[0];
        if (! $alias instanceof Node\Stmt\TraitUseAdaptation\Alias || $alias->newModifier !== null
            || $alias->trait !== null && strcasecmp($alias->trait->toString(), $trait) !== 0
            || $alias->method->name !== '__call' || $alias->newName?->name !== 'macroCall') { return false; }
        $original = $codebase->getDeclaringMethod($trait, '__call');
        $bound = $codebase->getDeclaringMethod($owner, 'macroCall');
        $syntax = $original === null ? null : (new ModelReflection($codebase, $this->source))->methodNode($original);
        return $original !== null && $bound !== null && $original == $bound && $syntax !== null
            && strcasecmp($original->identifier->class ?? '', $trait) === 0 && $original->identifier->name === '__call'
            && str_ends_with(strtolower($this->calls->path($original->location->file ?? '')), '/laravel/framework/src/illuminate/macroable/traits/macroable.php')
            && $this->methodMatches($original, $syntax, $original->location->file);
    }

    /** A native empty macro table and unchanged dispatch under the explicit offline default policy. */
    private function defaultMacroDispatch(ReturnTypeProviderContext $context): bool
    {
        $manager = 'Illuminate\\Database\\DatabaseManager';$trait = 'Illuminate\\Support\\Traits\\Macroable';$codebase = $context->codebase;
        if (! $this->calls->defaultMacroDispatch() || ! $this->hierarchy($codebase, $trait)) { return false; }
        $method = $codebase->getDeclaringMethod($manager, 'hasMacro');
        if ($method === null || strcasecmp($method->identifier->class ?? '', $trait) !== 0 || $method->identifier->name !== 'hasMacro'
            || ! $method->static || count($method->parameters) !== 1 || $method->parameters[0]->name !== '$name'
            || ! $method->parameters[0]->type?->fromDocblock || ! $method->returnType?->fromDocblock
            || ! $context->types->equals($method->parameters[0]->type->type, Type::string())
            || ! $context->types->equals($method->returnType->type, Type::bool())
            || ! $this->nativeBody($codebase, $method, 'return isset(static::$macros[$name]);')) { return false; }
        $property = $codebase->getDeclaringProperty($manager, '$macros');
        $propertyFile = $property?->location?->file ?? $property?->nameLocation?->file;
        if ($property === null || $propertyFile === null || ! $this->retain($propertyFile)
            || $property != $codebase->getDeclaringProperty($trait, '$macros')
            || ! $property->flags->contains(MetadataFlags::STATIC) || ! $property->flags->contains(MetadataFlags::HAS_DEFAULT)
            || $property->hooks !== [] || $property->readVisibility !== Visibility::Protected || $property->writeVisibility !== Visibility::Protected
            || $property->declaredType !== null || (new ModelReflection($codebase, $this->source))->default($manager, 'macros') !== []) { return false; }
        $found = false;
        foreach ((new NodeFinder)->findInstanceOf($this->source->read($this->calls->path($propertyFile)) ?? [], Node\Stmt\Trait_::class) as $node) {
            if ($node->namespacedName?->toString() !== $trait) { continue; }
            foreach ($node->getProperties() as $declaration) { foreach ($declaration->props as $field) {
                if ($field->name->name !== 'macros') { continue; }
                if ($found || ! $declaration->isStatic() || ! $declaration->isProtected() || $declaration->type !== null
                    || ! $field->default instanceof Node\Expr\Array_ || $field->default->items !== []
                    || $property->nameLocation?->span->start !== $field->name->getStartFilePos()
                    || $property->nameLocation?->span->end !== $field->name->getEndFilePos()+1) { return false; }
                $found = true;
            } }
        }
        if (! $found || ! $this->macroCatalog($codebase)) { return false; }
        return $this->current();
    }

    private function macroCatalog(Codebase $codebase): bool
    {
        $file = $this->root.'/composer.json';
        if (! $this->retain($file)) { return false; }
        $settings = json_decode(file_get_contents($file), true)['extra']['laramago'] ?? [];
        if (! is_array($settings)) { return false; }
        $files = $settings['macro-files'] ?? [];
        $providers = $settings['macro-service-providers'] ?? [];
        if (! is_array($files) || ! array_is_list($files) || ! is_array($providers) || $providers !== [] && array_is_list($providers)) { return false; }
        foreach (array_merge($files, array_values($providers)) as $path) {
            if (! is_string($path) || ! preg_match('~^(?:app|bootstrap)/[a-zA-Z0-9_./-]+\.php$~', $path)
                || in_array('..', explode('/', $path), true) || ! $this->retain($path)) { return false; }
        }
        $catalog = new MacroIndex($this->root);
        if ($catalog->hasUnknownRegistrations()) { return false; }
        foreach ([$manager = 'Illuminate\\Database\\DatabaseManager', self::DB, self::CONNECTION,
            ...$codebase->getClassDescendants($manager), ...$codebase->getClassDescendants(self::CONNECTION)] as $class) {
            if ($catalog->mayRegister($class, 'transaction')) { return false; }
        }
        return true;
    }

    private function methodMatches(FunctionLikeMetadata $metadata, Node $node, string $file): bool
    {
        if ($this->calls->path($metadata->location->file ?? '') !== $this->calls->path($file) || ! $this->retain($file)
            || $metadata->location->span->end !== $node->getEndFilePos() + 1 || count($metadata->parameters) !== count($node->params)
            || $metadata->flags->contains(MetadataFlags::BY_REFERENCE) !== $node->byRef) { return false; }
        if ($node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Stmt\Function_) {
            if ($metadata->nameLocation?->span->start !== $node->name->getStartFilePos()
                || $metadata->nameLocation?->span->end !== $node->name->getEndFilePos() + 1) { return false; }
        }
        if ($node instanceof Node\Expr\Closure && ($metadata->kind->name !== 'Closure'
            || $metadata->identifier->kind !== FunctionLikeKind::Closure || $metadata->nameLocation !== null
            || $metadata->location->span->start !== $node->getStartFilePos()
            // Mago's closure metadata leaves the method-only static field false for both source forms.
            || $metadata->static)) { return false; }
        $implicitAbstract = $node instanceof Node\Stmt\ClassMethod && $node->stmts === null
            && $metadata->identifier->class !== null && $this->activeCodebase?->getClassLike($metadata->identifier->class)?->kind === ClassLikeKind::Interface;
        if ($node instanceof Node\Stmt\ClassMethod && ($metadata->static !== $node->isStatic() || $metadata->abstract !== ($node->isAbstract() || $implicitAbstract)
            || $metadata->visibility !== ($node->isPrivate() ? Visibility::Private : ($node->isProtected() ? Visibility::Protected : Visibility::Public)))) { return false; }
        foreach ($node->params as $index => $parameter) {
            $bound = $metadata->parameters[$index];
            if (! $parameter->var instanceof Node\Expr\Variable || $bound->name !== '$'.$parameter->var->name
                || $bound->flags->contains(MetadataFlags::BY_REFERENCE) !== $parameter->byRef
                || $bound->flags->contains(MetadataFlags::VARIADIC) !== $parameter->variadic) { return false; }
        }
        return true;
    }

    private function retain(string $file): bool
    {
        $file = $this->calls->path($file);
        $hash = @hash_file('sha256', $file);
        if ($hash === false || ! $this->calls->declarationCurrent($file)) { return false; }
        if (isset($this->snapshots[$file])) { return $this->snapshots[$file] === $hash; }
        $this->snapshots[$file] = $hash;
        return true;
    }
    private function current(): bool
    {
        foreach ($this->snapshots as $file => $hash) { if ($hash !== @hash_file('sha256', $file)) { return false; } }
        return true;
    }
}
