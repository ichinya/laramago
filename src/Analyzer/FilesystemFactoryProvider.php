<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ModelReflection;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use PhpParser\Node;
use PhpParser\NodeFinder;

/** Infer only direct native driver factories whose installed bodies construct the adapter. */
final class FilesystemFactoryProvider implements MethodReturnTypeProvider, InitializationHook
{
    private const MANAGER = 'Illuminate\\Filesystem\\FilesystemManager';

    private const FACTORIES = [
        'createlocaldriver' => 'Illuminate\\Filesystem\\LocalFilesystemAdapter',
        'createftpdriver' => 'Illuminate\\Filesystem\\FilesystemAdapter',
        'createsftpdriver' => 'Illuminate\\Filesystem\\FilesystemAdapter',
        'creates3driver' => 'Illuminate\\Filesystem\\AwsS3V3Adapter',
        'createreadthroughdriver' => 'Illuminate\\Filesystem\\ReadThroughFilesystem',
    ];

    private ?PhpSource $source = null;

    public function __construct(
        private readonly string $root = '.',
    ) {}

    public function initialize(InitializationContext $context): void
    {
        $this->source = null;
    }

    public function getTargets(): array
    {
        return array_map(
            static fn (string $method): MethodTarget => MethodTarget::exact(self::MANAGER, $method),
            array_keys(self::FACTORIES),
        );
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $receiver = $context->invocation->receiverType;
        $object = $receiver?->atomicTypes[0] ?? null;
        if (
            $receiver === null
            || count($receiver->atomicTypes) !== 1
            || ! $object instanceof NamedObjectType
            || strcasecmp($object->name, self::MANAGER) !== 0
        ) {
            return null;
        }
        $methodName = strtolower($context->invocation->name);
        $concrete = self::FACTORIES[$methodName] ?? null;
        $method = $context->codebase->getMethod(self::MANAGER, $methodName);
        $class = $context->codebase->getClass(self::MANAGER);
        if (
            $concrete === null
            || $method === null
            || $class === null
            || $class->hasIncompleteHierarchy()
            || strcasecmp($method->identifier->class ?? '', self::MANAGER) !== 0
            || $method->static
            || ! self::frameworkFile($method->location->file, 'Illuminate/Filesystem/FilesystemManager.php')
            || $context->codebase->getClass($concrete) === null
        ) {
            return null;
        }

        $result = Type::namedObject($concrete);
        $documented = $method->returnType?->type ?? $method->declaredReturnType?->type;
        $nativeContract = Type::namedObject(
            $methodName === 'creates3driver'
                ? 'Illuminate\\Contracts\\Filesystem\\Cloud'
                : 'Illuminate\\Contracts\\Filesystem\\Filesystem',
        );
        if (
            $documented === null
            || ! $context->types->isContainedBy($documented, $nativeContract)
            || ! $context->types->isContainedBy($nativeContract, $documented)
            || ! $context->types->isContainedBy($result, $documented)
            || $method->declaredReturnType !== null
            && ! $context->types->isContainedBy($result, $method->declaredReturnType->type)
        ) {
            return null;
        }

        $source = $this->source ??= new PhpSource($this->root);
        $reflection = new ModelReflection($context->codebase, $source);
        if (! self::nativeClassDoc($source, $class->location->file, self::MANAGER)) {
            return null;
        }
        $node = $reflection->methodNode($method);
        if ($node === null || ! self::returnsConstructedClass($node, $concrete, $methodName)) {
            return null;
        }
        if ($methodName === 'createlocaldriver' && ! self::localFluentMethods($context, $reflection, $source)) {
            return null;
        }

        return $result;
    }

    private static function returnsConstructedClass(Node\Stmt\ClassMethod $method, string $class, string $name): bool
    {
        $statements = $method->stmts ?? [];
        $last = array_pop($statements);
        if (! $last instanceof Node\Stmt\Return_ || $last->expr === null) {
            return false;
        }
        if ((new NodeFinder)->findFirstInstanceOf($statements, Node\Stmt\Return_::class) !== null) {
            return false;
        }
        if (self::hasYield($method->stmts ?? [])) {
            return false;
        }
        $expression = $last->expr;
        if ($name === 'createlocaldriver') {
            foreach (['shouldServeSignedUrls', 'diskName'] as $fluent) {
                if (
                    ! $expression instanceof Node\Expr\MethodCall
                    || $expression->isFirstClassCallable()
                    || ! $expression->name instanceof Node\Identifier
                    || strcasecmp($expression->name->toString(), $fluent) !== 0
                ) {
                    return false;
                }
                $expression = $expression->var;
            }
        }

        return (
            $expression instanceof Node\Expr\New_
            && $expression->class instanceof Node\Name
            && strcasecmp($expression->class->toString(), $class) === 0
        );
    }

    private static function localFluentMethods(
        ReturnTypeProviderContext $context,
        ModelReflection $reflection,
        PhpSource $source,
    ): bool {
        $class = $context->codebase->getClass('Illuminate\\Filesystem\\LocalFilesystemAdapter');
        if (
            $class === null
            || $class->hasIncompleteHierarchy()
            || ! self::nativeClassDoc($source, $class->location->file, 'Illuminate\\Filesystem\\LocalFilesystemAdapter')
        ) {
            return false;
        }
        foreach (['diskName', 'shouldServeSignedUrls'] as $name) {
            $method = $context->codebase->getMethod('Illuminate\\Filesystem\\LocalFilesystemAdapter', $name);
            if (
                $method === null
                || strcasecmp($method->identifier->class ?? '', 'Illuminate\\Filesystem\\LocalFilesystemAdapter') !== 0
                || ! self::frameworkFile($method->location->file, 'Illuminate/Filesystem/LocalFilesystemAdapter.php')
            ) {
                return false;
            }
            $node = $reflection->methodNode($method);
            if ($node === null) {
                return false;
            }
            $doc = $node->getDocComment()?->getText();
            $returnTags = [];
            preg_match_all('/@(?:psalm-|phpstan-)?return\s+([^\s*]+)/i', $doc ?? '', $returnTags);
            $statements = $node->stmts ?? [];
            $last = array_pop($statements);
            if (
                ! $last instanceof Node\Stmt\Return_
                || $node->returnType !== null
                || ($returnTags[1] ?? []) !== ['$this']
                || ! $last->expr instanceof Node\Expr\Variable
                || $last->expr->name !== 'this'
                || (new NodeFinder)->findFirstInstanceOf($statements, Node\Stmt\Return_::class) !== null
                || self::hasYield($node->stmts ?? [])
            ) {
                return false;
            }
        }

        return true;
    }

    /** @param array<array-key, Node> $nodes */
    private static function hasYield(array $nodes): bool
    {
        return (
            (new NodeFinder)->findFirst(
                $nodes,
                static fn (Node $node): bool => (
                    $node instanceof Node\Expr\Yield_
                    || $node instanceof Node\Expr\YieldFrom
                ),
            ) !== null
        );
    }

    private static function nativeClassDoc(PhpSource $source, ?string $file, string $name): bool
    {
        if ($file === null || ! self::frameworkFile($file, str_replace('\\', '/', $name).'.php')) {
            return false;
        }
        $class = (new NodeFinder)->findFirst(
            $source->read($file) ?? [],
            static fn (Node $node): bool => (
                $node instanceof Node\Stmt\Class_
                && strcasecmp($node->namespacedName?->toString() ?? '', $name) === 0
            ),
        );

        if (! $class instanceof Node\Stmt\Class_) {
            return false;
        }
        $doc = $class->getDocComment()?->getText() ?? '';
        if (
            preg_match('/@(?:psalm-|phpstan-)?method\b/i', $doc) === 1
            || preg_match('/@(?:psalm|phpstan)-mixin\b/i', $doc) === 1
        ) {
            return false;
        }
        $matches = [];
        preg_match_all('/@mixin\s+([^\s*]+)/', $doc, $matches);
        $mixins = $matches[1] ?? [];
        sort($mixins);
        if ($name === self::MANAGER) {
            return $mixins === [
                '\\Illuminate\\Contracts\\Filesystem\\Filesystem',
                '\\Illuminate\\Filesystem\\FilesystemAdapter',
            ];
        }

        return $mixins === [];
    }

    private static function frameworkFile(?string $path, string $suffix): bool
    {
        return str_ends_with(str_replace('\\', '/', $path ?? ''), '/laravel/framework/src/'.$suffix);
    }
}
