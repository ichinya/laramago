<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ModelReflection;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\CallableSignatureOverride;
use Mago\Sdk\Analyzer\CallableSignatureProviderContext;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\EffectiveCallableSignature;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\Invocation;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\CallableParameter;
use Mago\Sdk\Analyzer\Type\CallableSignature;
use Mago\Sdk\Analyzer\Type\CallableType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\Visibility;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard;

/** Eloquent chunk callbacks receive the model's hydrated collection. */
final class EloquentChunkCallbackProvider implements CallableSignatureOverride, MethodReturnTypeProvider, InitializationHook
{
    private const MODEL = 'Illuminate\\Database\\Eloquent\\Model';
    private const BUILDER = 'Illuminate\\Database\\Eloquent\\Builder';
    private const TRAIT = 'Illuminate\\Database\\Concerns\\BuildsQueries';
    private const METHODS = ['chunk', 'chunkbyid', 'chunkbyiddesc', 'orderedchunkbyid'];

    // Laravel 13.31 normalized, comment-free bodies. Unknown implementations defer.
    private const BODIES = [
        'chunk' => 'e478551b9cfadf6ac32af2ffabbeb748d9db1d8a95bc949443cbef14afb6bfab',
        'chunkbyid' => '876803540cb0ffe94c3392825ea1152209f9890baa5b13fa5ea22e01895a2b58',
        'chunkbyiddesc' => '3affa2d0f400b0883ffb9b4a4d711221859d1ff79296b6b0b384667d9904e4a4',
        'orderedchunkbyid' => '18c57c5f83dba163a72568bd074640c5a32dd6e8aea5fdc8573dc1d58788fa93',
        'get' => 'eb5237d06208e8cd6bfc98b3209a63b217d75c5059009d1d753ec1cfaff210b1',
    ];

    private PhpSource $source;
    private readonly EloquentModelDispatch $models;
    private readonly EloquentCollectionType $collections;
    /** @var array<string, bool> */
    private array $native = [];

    public function __construct(private readonly string $projectRoot = '.')
    {
        $this->source = new PhpSource($projectRoot);
        $this->models = new EloquentModelDispatch;
        $this->collections = new EloquentCollectionType;
    }

    public function initialize(InitializationContext $context): void
    {
        $this->source = new PhpSource($this->projectRoot);
        $this->native = [];
    }

    public function getTargets(): array
    {
        $targets = [];
        foreach ([self::MODEL, self::BUILDER, self::TRAIT] as $class) {
            foreach (self::METHODS as $method) {
                $targets[] = MethodTarget::exact($class, $method);
            }
        }

        return $targets;
    }

    public function getCallableSignature(CallableSignatureProviderContext $context): ?EffectiveCallableSignature
    {
        $model = $this->model($context->codebase, $context->invocation);
        $collection = $model === null ? null : $this->collections->resolve($context->codebase, $model);
        $native = $collection === null ? null : $this->models->signature(
            $context->codebase,
            $context->invocation->name,
            self::BUILDER,
        );
        $callback = $native?->parameters[1]->type;
        $atom = $callback?->atomicTypes[0] ?? null;
        $signature = $atom instanceof CallableType ? $atom->signature : null;
        $parameter = $signature?->parameters[0] ?? null;
        $input = $parameter?->type->atomicTypes[0] ?? null;
        if (
            $native === null || count($callback?->atomicTypes ?? []) !== 1
            || $signature === null || count($signature->parameters) !== 2
            || ! $input instanceof NamedObjectType
            || strcasecmp($input->name, 'Illuminate\\Support\\Collection') !== 0
            || count($parameter->type->atomicTypes) !== 1
        ) {
            return null;
        }
        $callbackParameters = $signature->parameters;
        $callbackParameters[0] = $this->parameter($parameter, $collection);
        $parameters = $native->parameters;
        $parameters[1] = $this->parameter($parameters[1], Type::fromAtomic(new CallableType(
            new CallableSignature(
                $signature->pure,
                $signature->closure,
                $callbackParameters,
                $signature->returnType,
                $signature->source,
                $signature->constraints,
            ),
            null,
        )));

        return new EffectiveCallableSignature($parameters, $native->allowsNamedArguments, $native->displayName);
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        return $this->model($context->codebase, $context->invocation) === null ? null : Type::bool();
    }

    private function parameter(CallableParameter $parameter, Type $type): CallableParameter
    {
        return new CallableParameter(
            $parameter->name, $type, $parameter->closureThisType,
            $parameter->byReference, $parameter->variadic, $parameter->hasDefault,
        );
    }

    private function model(Codebase $codebase, Invocation $call): ?Type
    {
        $name = strtolower($call->name);
        $receiver = $call->receiverType->atomicTypes[0] ?? null;
        $callback = $call->getArgument(1, 'callback');
        if (
            ! in_array($name, self::METHODS, true)
            || count($call->receiverType?->atomicTypes ?? []) !== 1
            || ! $receiver instanceof NamedObjectType
            || $callback === null
            || preg_match('/^(?:static\s+)?(?:function\s*\(|fn\s*\()/', ltrim($callback->expression)) !== 1
        ) {
            return null;
        }
        foreach ($call->arguments as $argument) {
            if ($argument->unpacked || $argument->placeholder) {
                return null;
            }
        }
        $model = strcasecmp($receiver->name, self::BUILDER) === 0
            ? ($receiver->parameters[0] ?? null)
            : $this->models->modelType($codebase, $call);
        $atom = $model->atomicTypes[0] ?? null;
        if (
            count($model?->atomicTypes ?? []) !== 1 || ! $atom instanceof NamedObjectType
            || ! $this->models->supportsModel($codebase, $atom->name, $name)
        ) {
            return null;
        }
        foreach (['newInstance', 'newFromBuilder', 'hydrate'] as $method) {
            if ($this->models->overrides($codebase, $atom->name, $method)) {
                return null;
            }
        }
        foreach (array_unique([$name, $name === 'chunk' ? 'chunk' : 'orderedchunkbyid', 'get']) as $method) {
            if (! $this->nativeMethod($codebase, $method)) {
                return null;
            }
        }

        return $model;
    }

    private function nativeMethod(Codebase $codebase, string $name): bool
    {
        if (array_key_exists($name, $this->native)) {
            return $this->native[$name];
        }
        $method = $codebase->getMethod(self::BUILDER, $name) ?? $codebase->getDeclaringMethod(self::BUILDER, $name);
        $owner = $name === 'get' ? self::BUILDER : self::TRAIT;
        $path = $name === 'get' ? '/Eloquent/Builder.php' : '/Concerns/BuildsQueries.php';
        if (
            $method === null || strcasecmp($method->identifier->class ?? '', $owner) !== 0
            || $method->static || $method->visibility !== Visibility::Public
            || count($method->parameters) !== match ($name) {
                'get' => 1,
                'chunk' => 2,
                'orderedchunkbyid' => 5,
                default => 4,
            }
            || $method->parameters[0]->name !== ($name === 'get' ? '$columns' : '$count')
            || $name !== 'get' && ($method->parameters[1]->name !== '$callback'
                || (string) ($method->returnType?->type) !== 'bool')
            || ! str_ends_with(str_replace('\\', '/', $this->source->path($method->location->file ?? '')),
                '/laravel/framework/src/Illuminate/Database'.$path)
        ) {
            return $this->native[$name] = false;
        }
        $node = (new ModelReflection($codebase, $this->source))->methodNode($method);
        if ($node === null) {
            return $this->native[$name] = false;
        }
        $documentation = $node->getDocComment()?->getText() ?? '';
        $expected = $name === 'get'
            ? '~@return\s+\\\\Illuminate\\\\Database\\\\Eloquent\\\\Collection<int,\s*TModel>~'
            : '~@param\s+callable\(\\\\Illuminate\\\\Support\\\\Collection<int,\s*TValue>,\s*int\):\s*mixed\s+\$callback~';
        if (preg_match($expected, $documentation) !== 1) {
            return $this->native[$name] = false;
        }
        $clean = new NodeTraverser(new class extends NodeVisitorAbstract {
            public function enterNode(Node $node): null
            {
                $node->setAttribute('comments', []);

                return null;
            }
        });
        $statements = $clean->traverse($node->stmts ?? []);

        return $this->native[$name] = hash('sha256', (new Standard)->prettyPrint($statements)) === self::BODIES[$name];
    }
}
