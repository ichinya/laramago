<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ContainerBindings;
use Ichinya\Laramago\Analyzer\StaticAnalysis\MixManifestCatalog;
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

/** Warn only for missing keys with an explicitly asserted native, stable Mix lookup. */
final class MixManifestReferencesHook implements NodeAnalysisHook, InitializationHook
{
    private const MIX = 'Illuminate\\Foundation\\Mix';
    // Laravel framework 7c75fbf Mix.php: significant tokens, including PHPDoc.
    private const NATIVE_MIX_HASH = 'dc82e2595a13f26902e4675ede598a0234d6dd700f4b070ec3ac6fccd9e990b1';

    private MixManifestCatalog $catalog;
    private ContainerBindings $bindings;
    private PhpSource $source;
    /** @var array<string, true> */
    private array $asserted = [];
    private ?string $sourceHash = null;
    /** @var array<string, Node\Expr\FuncCall> */
    private array $calls = [];
    private ?bool $native = null;

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
        $this->catalog = new MixManifestCatalog($this->root);
        $this->bindings = new ContainerBindings($this->root);
        $this->source = new PhpSource($this->root);
        $this->asserted = [];
        $this->sourceHash = null;
        $this->calls = [];
        $this->native = null;
        $this->loadAssertions($this->root);
    }

    public function getTargets(): array
    {
        return [NodeKind::FunctionCall];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        if ($this->asserted === []) {
            return;
        }
        $call = $this->call($context);
        if ($call === null || $call->isFirstClassCallable() || ! $this->isNativeCall($call, $context)) {
            return;
        }
        [$path, $directory] = $this->literalArguments($call);
        if (! $path instanceof Node\Scalar\String_ || ! $directory instanceof Node\Scalar\String_) {
            return;
        }
        $name = ltrim($directory->value, '/');
        if (
            ! isset($this->asserted[$name])
            || $this->catalog->status($directory->value) !== 'complete'
            || $this->catalog->hotFileState($directory->value) !== 'absent'
            || $this->catalog->contains($path->value, $directory->value) !== false
        ) {
            return;
        }
        $context->report(
            Level::Warning,
            'laramago-missing-mix-manifest-key',
            Issue::at(
                'Mix path "'
                .$path->value
                .'" is absent from the explicitly asserted native manifest for directory "'
                .$directory->value
                .'".',
                new SourceLocation(
                    $context->source->path,
                    new Span($path->getStartFilePos(), $path->getEndFilePos() + 1),
                ),
            ),
        );
    }

    private function loadAssertions(string $root): void
    {
        $text = @file_get_contents(rtrim($root, '/\\').'/composer.json');
        /** @var mixed $json */
        $json = $text === false ? null : json_decode($text, true);
        /** @var mixed $extra */
        $extra = is_array($json) ? $json['extra'] ?? null : null;
        /** @var mixed $options */
        $options = is_array($extra) ? $extra['laramago'] ?? null : null;
        /** @var mixed $catalogs */
        $catalogs = is_array($options) ? $options['reference-catalogs'] ?? null : null;
        /** @var mixed $mix */
        $mix = is_array($catalogs) ? $catalogs['mix-manifests'] ?? null : null;
        /** @var mixed $files */
        $files = is_array($mix) ? $mix['files'] ?? null : null;
        if (! is_array($files) || ! array_is_list($files)) {
            return;
        }
        /** @var mixed $entry */
        foreach ($files as $entry) {
            if (
                ! is_array($entry)
                || ! is_string($entry['directory'] ?? null)
                || ! is_string($entry['path'] ?? null)
                || ($entry['native-runtime'] ?? null) !== true
                || ($entry['effective-public-path'] ?? null) !== true
                || ($entry['hot-file-absent'] ?? null) !== true
                || ($entry['manifest-stable'] ?? null) !== true
                || $this->catalog->manifestPath($entry['directory']) !== $entry['path']
            ) {
                continue;
            }
            $this->asserted[ltrim($entry['directory'], '/')] = true;
        }
    }

    /** @return array{Node\Expr|null, Node\Expr|null} */
    public function literalArguments(Node\Expr\FuncCall $call): array
    {
        if (count($call->args) < 1 || count($call->args) > 2) {
            return [null, null];
        }
        $seen = [];
        $path = null;
        $directory = new Node\Scalar\String_('');
        $pos = 0;
        $named = false;
        foreach ($call->args as $arg) {
            if (! $arg instanceof Node\Arg || $arg->unpack || $arg->byRef) {
                return [null, null];
            }
            if ($arg->name === null) {
                if ($named) {
                    return [null, null];
                }
                $name = $pos++ === 0 ? 'path' : 'manifestDirectory';
            } else {
                $named = true;
                $name = $arg->name->toString();
            }
            if (! in_array($name, ['path', 'manifestDirectory'], true) || isset($seen[$name])) {
                return [null, null];
            }
            $seen[$name] = true;
            if ($name === 'path') {
                $path = $arg->value;
            } else {
                $directory = $arg->value;
            }
        }

        return [$path, $directory];
    }

    /** Native helper and installed Mix dispatch, independently of manifest assertions. */
    public function isNativeCall(Node\Expr\FuncCall $call, NodeAnalysisContext $context): bool
    {
        if (! $call->name instanceof Node\Name) {
            return false;
        }
        $name = $call->name->toString();
        /** @var mixed $namespaced */
        $namespaced = $call->name->getAttribute('namespacedName');
        if ($namespaced instanceof Node\Name && $context->codebase->getFunction($namespaced->toString()) !== null) {
            $name = $namespaced->toString();
        }
        if (strtolower($name) !== 'mix') {
            return false;
        }
        if ($this->native !== null) {
            return $this->native;
        }
        if ($this->bindings->configured(self::MIX)) {
            return $this->native = false;
        }
        $function = $context->codebase->getFunction('mix');
        $app = $context->codebase->getFunction('app');
        $file = $function?->location->file;
        $method = $context->codebase->getDeclaringMethod(self::MIX, '__invoke');
        $mixFile = $method?->location->file;
        if (
            $file === null
            || $mixFile === null
            || $app === null
            || $app->location->file !== $file
            || array_map(static fn ($parameter): string => $parameter->name, $app->parameters) !== [
                '$abstract',
                '$parameters',
            ]
            || ! str_ends_with(
                str_replace('\\', '/', $file),
                '/laravel/framework/src/Illuminate/Foundation/helpers.php',
            )
            || ! str_ends_with(str_replace('\\', '/', $mixFile), '/laravel/framework/src/Illuminate/Foundation/Mix.php')
            || array_map(static fn ($parameter): string => $parameter->name, $function->parameters) !== [
                '$path',
                '$manifestDirectory',
            ]
            || $method->identifier->class !== self::MIX
            || $method->static
            || $method->visibility !== Visibility::Public
            || array_map(static fn ($parameter): string => $parameter->name, $method->parameters) !== [
                '$path',
                '$manifestDirectory',
            ]
            || $this->shadowedMixFunctions($context)
            || ! $this->nativeMixFile($mixFile)
        ) {
            return $this->native = false;
        }
        $nodes = $this->source->read(str_starts_with($file, '//?/') ? substr($file, 4) : $file);
        if ($nodes === null || ! $this->nativeApp($nodes)) {
            return $this->native = false;
        }
        $helper = (new NodeFinder)->findFirst(
            $nodes,
            static fn (Node $node): bool => (
                $node instanceof Node\Stmt\Function_
                && strtolower($node->name->toString()) === 'mix'
            ),
        );
        if (
            ! $helper instanceof Node\Stmt\Function_
            || $helper->byRef
            || count($helper->params) !== 2
            || ! $helper->params[0]->var instanceof Node\Expr\Variable
            || $helper->params[0]->var->name !== 'path'
            || $helper->params[0]->default !== null
            || ! $helper->params[1]->var instanceof Node\Expr\Variable
            || $helper->params[1]->var->name !== 'manifestDirectory'
            || ! $helper->params[1]->default instanceof Node\Scalar\String_
            || $helper->params[1]->default->value !== ''
            || $helper->params[0]->byRef
            || $helper->params[1]->byRef
            || $helper->params[0]->variadic
            || $helper->params[1]->variadic
            || preg_match('/@\S*return\b/i', $helper->getDocComment()?->getText() ?? '') === 1
            || ! $helper->returnType instanceof Node\UnionType
            || count($helper->returnType->types) !== 2
            || ! $helper->returnType->types[0] instanceof Node\Name
            || strcasecmp($helper->returnType->types[0]->toString(), 'Illuminate\\Support\\HtmlString') !== 0
            || ! $helper->returnType->types[1] instanceof Node\Identifier
            || strtolower($helper->returnType->types[1]->toString()) !== 'string'
            || count($helper->stmts) !== 1
            || ! $helper->stmts[0] instanceof Node\Stmt\Return_
            || ! $helper->stmts[0]->expr instanceof Node\Expr\FuncCall
        ) {
            return $this->native = false;
        }
        $dispatch = $helper->stmts[0]->expr;
        if (
            ! $dispatch->name instanceof Node\Expr\FuncCall
            || ! $dispatch->name->name instanceof Node\Name
            || strtolower($dispatch->name->name->toString()) !== 'app'
            || count($dispatch->name->args) !== 1
            || ! $dispatch->name->args[0] instanceof Node\Arg
            || $dispatch->name->args[0]->byRef
            || $dispatch->name->args[0]->unpack
            || $dispatch->name->args[0]->name !== null
            || ! $dispatch->name->args[0]->value instanceof Node\Expr\ClassConstFetch
            || ! $dispatch->name->args[0]->value->class instanceof Node\Name
            || strcasecmp($dispatch->name->args[0]->value->class->toString(), self::MIX) !== 0
            || ! $dispatch->name->args[0]->value->name instanceof Node\Identifier
            || strtolower($dispatch->name->args[0]->value->name->toString()) !== 'class'
            || count($dispatch->args) !== 1
            || ! $dispatch->args[0] instanceof Node\Arg
            || $dispatch->args[0]->byRef
            || ! $dispatch->args[0]->unpack
            || $dispatch->args[0]->name !== null
            || ! $dispatch->args[0]->value instanceof Node\Expr\FuncCall
            || ! $dispatch->args[0]->value->name instanceof Node\Name
            || strtolower($dispatch->args[0]->value->name->toString()) !== 'func_get_args'
            || $dispatch->args[0]->value->args !== []
        ) {
            return $this->native = false;
        }

        return $this->native = true;
    }

    private function shadowedMixFunctions(NodeAnalysisContext $context): bool
    {
        foreach ([
            'str_starts_with',
            'is_file',
            'public_path',
            'rtrim',
            'file_get_contents',
            'app',
            'json_decode',
            'report',
        ] as $function) {
            if ($context->codebase->getFunction('Illuminate\\Foundation\\'.$function) !== null) {
                return true;
            }
        }

        return false;
    }

    /** @param array<array-key, Node> $nodes */
    private function nativeApp(array $nodes): bool
    {
        $app = (new NodeFinder)->findFirst(
            $nodes,
            static fn (Node $node): bool => (
                $node instanceof Node\Stmt\Function_
                && strtolower($node->name->toString()) === 'app'
            ),
        );
        if (
            ! $app instanceof Node\Stmt\Function_
            || $app->byRef
            || $app->returnType !== null
            || count($app->params) !== 2
            || ! $app->params[0]->var instanceof Node\Expr\Variable
            || $app->params[0]->var->name !== 'abstract'
            || ! $app->params[0]->default instanceof Node\Expr\ConstFetch
            || strtolower($app->params[0]->default->name->toString()) !== 'null'
            || $app->params[0]->type !== null
            || $app->params[0]->byRef
            || $app->params[0]->variadic
            || ! $app->params[1]->var instanceof Node\Expr\Variable
            || $app->params[1]->var->name !== 'parameters'
            || ! $app->params[1]->default instanceof Node\Expr\Array_
            || $app->params[1]->default->items !== []
            || ! $app->params[1]->type instanceof Node\Identifier
            || strtolower($app->params[1]->type->toString()) !== 'array'
            || $app->params[1]->byRef
            || $app->params[1]->variadic
            || count($app->stmts) !== 2
            || ! $app->stmts[0] instanceof Node\Stmt\If_
            || $app->stmts[0]->elseifs !== []
            || $app->stmts[0]->else !== null
            || ! $app->stmts[0]->cond instanceof Node\Expr\FuncCall
            || ! $app->stmts[0]->cond->name instanceof Node\Name
            || strtolower($app->stmts[0]->cond->name->toString()) !== 'is_null'
            || count($app->stmts[0]->cond->args) !== 1
            || ! $this->variable($app->stmts[0]->cond->args[0], 'abstract')
            || count($app->stmts[0]->stmts) !== 1
            || ! $app->stmts[0]->stmts[0] instanceof Node\Stmt\Return_
            || ! $this->containerInstance($app->stmts[0]->stmts[0]->expr)
            || ! $app->stmts[1] instanceof Node\Stmt\Return_
            || ! $app->stmts[1]->expr instanceof Node\Expr\MethodCall
        ) {
            return false;
        }
        $make = $app->stmts[1]->expr;

        return (
            $this->containerInstance($make->var)
            && $make->name instanceof Node\Identifier
            && strtolower($make->name->toString()) === 'make'
            && count($make->args) === 2
            && $this->variable($make->args[0], 'abstract')
            && $this->variable($make->args[1], 'parameters')
        );
    }

    private function containerInstance(?Node $node): bool
    {
        return (
            $node instanceof Node\Expr\StaticCall
            && $node->class instanceof Node\Name
            && strcasecmp($node->class->toString(), 'Illuminate\\Container\\Container') === 0
            && $node->name instanceof Node\Identifier
            && strtolower($node->name->toString()) === 'getinstance'
            && $node->args === []
        );
    }

    private function variable(Node $argument, string $name): bool
    {
        return (
            $argument instanceof Node\Arg
            && ! $argument->unpack
            && ! $argument->byRef
            && $argument->name === null
            && $argument->value instanceof Node\Expr\Variable
            && $argument->value->name === $name
        );
    }

    private function nativeMixFile(string $path): bool
    {
        $contents = @file_get_contents(str_starts_with($path, '//?/') ? substr($path, 4) : $path);
        if ($contents === false) {
            return false;
        }
        $significant = '';
        foreach (token_get_all($contents) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT], true)) {
                    continue;
                }
                $significant .= in_array($token[0], [T_DOC_COMMENT, T_OPEN_TAG], true)
                    ? str_replace(["\r\n", "\r"], "\n", $token[1])
                    : $token[1];
            } else {
                $significant .= $token;
            }
        }

        return hash('sha256', $significant) === self::NATIVE_MIX_HASH;
    }

    private function call(NodeAnalysisContext $context): ?Node\Expr\FuncCall
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
            foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Expr\FuncCall::class) as $found) {
                $this->calls[$found->getStartFilePos().':'.($found->getEndFilePos() + 1)] = $found;
            }
        }

        return $this->calls[$context->node->span->start.':'.$context->node->span->end] ?? null;
    }
}
