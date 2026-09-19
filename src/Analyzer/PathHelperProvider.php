<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\FunctionReturnTypeProvider;
use Mago\Sdk\Analyzer\FunctionTarget;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use PhpParser\Node;
use PhpParser\NodeFinder;

/** Refines native path helpers only under explicit effective-root assertions. */
final class PathHelperProvider implements FunctionReturnTypeProvider, InitializationHook
{
    private const METHODS = [
        'base_path' => 'basePath',
        'app_path' => 'path',
        'config_path' => 'configPath',
        'database_path' => 'databasePath',
        'public_path' => 'publicPath',
        'resource_path' => 'resourcePath',
        'storage_path' => 'storagePath',
        'lang_path' => 'langPath',
    ];

    /** @var array<string, string>|null */
    private ?array $bases = null;
    /** @var array<string, bool> */
    private array $native = [];
    private ?PhpSource $source = null;

    public function __construct(
        private readonly string $root,
    ) {}

    public function initialize(InitializationContext $context): void
    {
        $this->bases = null;
        $this->native = [];
        $this->source = null;
    }

    public function getTargets(): array
    {
        return array_map(static fn (string $name): FunctionTarget => FunctionTarget::exact(
            $name,
        ), array_keys(self::METHODS));
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $name = strtolower($context->invocation->name);
        $base = $this->bases()[$name] ?? null;
        if ($base === null || ! $this->native($name, $context)) {
            return null;
        }
        $arguments = $context->invocation->arguments;
        if (count($arguments) > 1) {
            return null;
        }
        $argument = $context->invocation->getArgument(0, 'path');
        if ($arguments !== [] && $argument === null) {
            return null;
        }
        if ($argument !== null && ($argument->unpacked || $argument->placeholder)) {
            return null;
        }
        $path = $argument?->type?->getLiteralString() ?? ($argument === null ? '' : null);
        if ($path === null) {
            return null;
        }

        // Illuminate\Filesystem\join_paths omits empty paths, but retains "0".
        return Type::literalString($base.($path === '' ? '' : DIRECTORY_SEPARATOR.ltrim($path, DIRECTORY_SEPARATOR)));
    }

    /** @return array<string, string> */
    private function bases(): array
    {
        if ($this->bases !== null) {
            return $this->bases;
        }
        $this->bases = [];
        $json = @file_get_contents($this->root.'/composer.json');
        /** @var mixed $composer */
        $composer = $json === false ? null : json_decode($json, true);
        /** @var mixed $extra */
        $extra = is_array($composer) ? $composer['extra'] ?? null : null;
        /** @var mixed $laramago */
        $laramago = is_array($extra) ? $extra['laramago'] ?? null : null;
        /** @var mixed $bases */
        $bases = is_array($laramago) ? $laramago['path-helper-bases'] ?? null : null;
        if (! is_array($bases) || array_is_list($bases)) {
            return $this->bases;
        }
        foreach (array_keys($bases) as $name) {
            if (! is_string($bases[$name])) {
                continue;
            }
            $base = $bases[$name];
            if (
                ! is_string($name)
                || ! isset(self::METHODS[$name])
                || str_contains($base, "\0")
                || ! preg_match('~^(?:/|[A-Za-z]:[/\\\\]|\\\\\\\\[^\\\\]+\\\\[^\\\\]+)~', $base)
            ) {
                continue;
            }
            $this->bases[$name] = $base;
        }

        return $this->bases;
    }

    private function native(string $name, ReturnTypeProviderContext $context): bool
    {
        if (isset($this->native[$name])) {
            return $this->native[$name];
        }
        $function = $context->codebase->getFunction($name);
        $file = $function?->location->file;
        if (
            $file === null
            || ! str_ends_with(
                str_replace('\\', '/', $file),
                '/laravel/framework/src/Illuminate/Foundation/helpers.php',
            )
            || count($function->parameters) !== 1
            || $function->parameters[0]->name !== '$path'
            || $function->flags->contains(MetadataFlags::BY_REFERENCE)
            || $function->parameters[0]->flags->contains(MetadataFlags::BY_REFERENCE)
            || $function->parameters[0]->flags->contains(MetadataFlags::VARIADIC)
            || (string) $function->declaredReturnType?->type !== 'string'
        ) {
            return $this->native[$name] = false;
        }
        $this->source ??= new PhpSource($this->root);
        $nodes = $this->source->read(str_starts_with($file, '//?/') ? substr($file, 4) : $file);
        $node = (new NodeFinder)->findFirst(
            $nodes ?? [],
            static fn (Node $candidate): bool => (
                $candidate instanceof Node\Stmt\Function_
                && $candidate->name->toLowerString() === $name
            ),
        );
        if (
            ! $node instanceof Node\Stmt\Function_
            || $node->byRef
            || count($node->params) !== 1
            || $node->params[0]->byRef
            || $node->params[0]->variadic
            || ! $node->params[0]->default instanceof Node\Scalar\String_
            || $node->params[0]->default->value !== ''
            || count($node->stmts) !== 1
            || ! $node->stmts[0] instanceof Node\Stmt\Return_
            || ! $node->stmts[0]->expr instanceof Node\Expr\MethodCall
            || $node->stmts[0]->expr->isFirstClassCallable()
            || ! $node->stmts[0]->expr->var instanceof Node\Expr\FuncCall
            || $node->stmts[0]->expr->var->isFirstClassCallable()
            || ! $node->stmts[0]->expr->var->name instanceof Node\Name
            || strtolower($node->stmts[0]->expr->var->name->toString()) !== 'app'
            || $node->stmts[0]->expr->var->args !== []
            || ! $node->stmts[0]->expr->name instanceof Node\Identifier
            || $node->stmts[0]->expr->name->toString() !== self::METHODS[$name]
            || count($node->stmts[0]->expr->args) !== 1
            || ! $node->stmts[0]->expr->args[0] instanceof Node\Arg
            || $node->stmts[0]->expr->args[0]->byRef
            || $node->stmts[0]->expr->args[0]->unpack
            || $node->stmts[0]->expr->args[0]->name !== null
            || ! $node->stmts[0]->expr->args[0]->value instanceof Node\Expr\Variable
            || $node->stmts[0]->expr->args[0]->value->name !== 'path'
            || preg_match('/@(?:return|phpstan-return|psalm-return)\b/i', $node->getDocComment()?->getText() ?? '')
        ) {
            return $this->native[$name] = false;
        }

        return $this->native[$name] = true;
    }
}
