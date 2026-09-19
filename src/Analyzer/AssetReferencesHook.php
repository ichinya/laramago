<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ContainerBindings;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ContainerNativeContract;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PublicAssetCatalog;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
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
use PhpParser\PrettyPrinter\Standard;

/** Warn about absent literal local assets under an explicit complete catalog. */
final class AssetReferencesHook implements NodeAnalysisHook, InitializationHook
{
    private const URL = 'Illuminate\\Routing\\UrlGenerator';

    private PublicAssetCatalog $catalog;
    private PhpSource $source;
    private ContainerBindings $bindings;
    private ?string $sourceHash = null;
    /** @var array<string, Node\Expr\FuncCall|Node\Expr\MethodCall> */
    private array $calls = [];
    private ?bool $nativeUrl = null;
    private ?bool $nativeApp = null;
    /** @var array<string, bool> */
    private array $nativeHelpers = [];

    public function __construct(
        private readonly string $root = '.',
    ) {
        $this->reset();
    }

    public function initialize(InitializationContext $context): void
    {
        $this->reset();
    }

    private function reset(): void
    {
        $this->catalog = new PublicAssetCatalog($this->root);
        $this->source = new PhpSource($this->root);
        $this->bindings = new ContainerBindings($this->root);
        $this->sourceHash = null;
        $this->calls = [];
        $this->nativeUrl = null;
        $this->nativeApp = null;
        $this->nativeHelpers = [];
    }

    public function getTargets(): array
    {
        return [NodeKind::FunctionCall, NodeKind::MethodCall];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::SourceText, FileAnalysisRequirement::ReceiverType];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        // A null catalog also covers disabled, malformed and incomplete scans.
        if ($this->catalog->assets() === null) {
            return;
        }
        $call = $this->call($context);
        if ($call === null || $call->isFirstClassCallable() || ! $this->nativeUrl($context)) {
            return;
        }
        if ($call instanceof Node\Expr\FuncCall) {
            $helper = $this->helperName($context, $call);
            if (
                ! in_array($helper, ['asset', 'secure_asset'], true)
                || ! $this->nativeHelper($context, $helper)
                || ! $this->nativeApp($context)
                || $helper === 'secure_asset'
                && ! $this->nativeHelper($context, 'asset')
                || $this->customUrlService()
            ) {
                return;
            }
            $parameters = $helper === 'asset' ? ['path', 'secure'] : ['path'];
        } else {
            $receiver = $context->receiverType?->atomicTypes ?? [];
            if (
                ! $call->name instanceof Node\Identifier
                || strtolower($call->name->name) !== 'asset'
                || count($receiver) !== 1
                || ! $receiver[0] instanceof NamedObjectType
                || strcasecmp($receiver[0]->name, self::URL) !== 0
            ) {
                return;
            }
            $parameters = ['path', 'secure'];
        }
        $path = $this->pathArgument($call->getArgs(), $parameters);
        if (! $path instanceof Node\Scalar\String_ || $this->catalog->contains($path->value) !== false) {
            return;
        }
        $context->report(
            Level::Warning,
            'laramago-missing-public-asset',
            Issue::at(
                'Literal public asset "'
                .$path->value
                .'" is absent from the explicitly complete expected public-assets catalog.',
                new SourceLocation(
                    $context->source->path,
                    new Span($path->getStartFilePos(), $path->getEndFilePos() + 1),
                ),
            )->withNote(
                'The URL generator still returns a URL; this warning does not assert a runtime exception or HTTP response.',
            ),
        );
    }

    /** @param array<array-key, Node\Arg|Node\VariadicPlaceholder> $args
     *  @param list<string> $parameters
     */
    private function pathArgument(array $args, array $parameters): ?Node\Expr
    {
        if ($args === [] || count($args) > count($parameters)) {
            return null;
        }
        $seen = [];
        $named = false;
        foreach ($args as $offset => $arg) {
            if (! $arg instanceof Node\Arg || $arg->unpack || $arg->byRef) {
                return null;
            }
            $parameter = $arg->name?->toString() ?? $parameters[$offset] ?? null;
            if (
                $parameter === null
                || ! in_array($parameter, $parameters, true)
                || isset($seen[$parameter])
                || $named
                && $arg->name === null
            ) {
                return null;
            }
            $named = $arg->name !== null;
            $seen[$parameter] = $arg->value;
        }

        return $seen['path'] ?? null;
    }

    private function helperName(NodeAnalysisContext $context, Node\Expr\FuncCall $call): string
    {
        if (! $call->name instanceof Node\Name) {
            return '';
        }
        $name = $call->name->toString();
        /** @var mixed $namespaced */
        $namespaced = $call->name->getAttribute('namespacedName');
        if ($namespaced instanceof Node\Name && $context->codebase->getFunction($namespaced->toString()) !== null) {
            $name = $namespaced->toString();
        }

        return strtolower(ltrim($name, '\\'));
    }

    private function customUrlService(): bool
    {
        foreach (['url', self::URL, 'Illuminate\\Contracts\\Routing\\UrlGenerator'] as $service) {
            if ($this->bindings->configured($service)) {
                return true;
            }
        }

        return false;
    }

    private function nativeApp(NodeAnalysisContext $context): bool
    {
        if ($this->nativeApp !== null) {
            return $this->nativeApp;
        }
        $function = $context->codebase->getFunction('app');
        $file = str_replace('\\', '/', $function?->location->file ?? '');
        if (
            $function === null
            || ! str_ends_with($file, '/laravel/framework/src/Illuminate/Foundation/helpers.php')
            || ! ContainerNativeContract::helper($function, 'app')
            || $function->flags->contains(MetadataFlags::BY_REFERENCE)
            || array_map(static fn ($parameter): string => $parameter->name, $function->parameters) !== [
                '$abstract',
                '$parameters',
            ]
        ) {
            return $this->nativeApp = false;
        }
        $node = (new NodeFinder)->findFirst(
            $this->source->read(str_starts_with($file, '//?/') ? substr($file, 4) : $file) ?? [],
            static fn (Node $node): bool => $node instanceof Node\Stmt\Function_ && $node->name->name === 'app',
        );
        if (! $node instanceof Node\Stmt\Function_ || $node->byRef) {
            return $this->nativeApp = false;
        }
        $expected = (new ParserFactory)
            ->createForNewestSupportedVersion()
            ->parse(
                '<?php function app($abstract = null, array $parameters = []) { '
                .'if (is_null($abstract)) { return \\Illuminate\\Container\\Container::getInstance(); } '
                .'return \\Illuminate\\Container\\Container::getInstance()->make($abstract, $parameters); }',
            ) ?? [];
        $expected = (new NodeTraverser(new NameResolver))->traverse($expected);
        $copy = clone $node;
        $copy->setAttribute('comments', []);
        $printer = new Standard;

        return $this->nativeApp = $printer->prettyPrint([$copy]) === $printer->prettyPrint($expected);
    }

    private function nativeHelper(NodeAnalysisContext $context, string $helper): bool
    {
        if (array_key_exists($helper, $this->nativeHelpers)) {
            return $this->nativeHelpers[$helper];
        }
        $function = $context->codebase->getFunction($helper);
        $file = str_replace('\\', '/', $function?->location->file ?? '');
        $parameters = $helper === 'asset' ? ['$path', '$secure'] : ['$path'];
        if (
            $function === null
            || ! str_ends_with($file, '/laravel/framework/src/Illuminate/Foundation/helpers.php')
            || $function->flags->contains(MetadataFlags::BY_REFERENCE)
            || array_map(static fn ($parameter): string => $parameter->name, $function->parameters) !== $parameters
        ) {
            return $this->nativeHelpers[$helper] = false;
        }
        $node = (new NodeFinder)->findFirst(
            $this->source->read(str_starts_with($file, '//?/') ? substr($file, 4) : $file) ?? [],
            static fn (Node $node): bool => (
                $node instanceof Node\Stmt\Function_
                && strtolower($node->name->name) === $helper
            ),
        );
        if (
            ! $node instanceof Node\Stmt\Function_
            || $node->byRef
            || count($node->params) !== count($parameters)
            || count($node->stmts) !== 1
            || ! $node->stmts[0] instanceof Node\Stmt\Return_
            || ! $node->returnType instanceof Node\Identifier
            || strtolower($node->returnType->name) !== 'string'
        ) {
            return $this->nativeHelpers[$helper] = false;
        }
        $doc = $node->getDocComment()?->getText() ?? '';
        if (
            preg_match('/@param\s+string\s+\$path\b/', $doc) !== 1
            || $helper === 'asset'
            && preg_match('/@param\s+bool\|null\s+\$secure\b/', $doc) !== 1
            || preg_match_all('/@[A-Za-z-]+/', $doc) !== count($parameters)
        ) {
            return $this->nativeHelpers[$helper] = false;
        }
        foreach ($parameters as $offset => $parameter) {
            if (
                $node->params[$offset]->byRef
                || $node->params[$offset]->variadic
                || $function->parameters[$offset]->flags->contains(MetadataFlags::BY_REFERENCE)
                || ! $node->params[$offset]->var instanceof Node\Expr\Variable
                || ! is_string($node->params[$offset]->var->name)
                || '$'.$node->params[$offset]->var->name !== $parameter
                || ($node->params[$offset]->default !== null) !== ($parameter === '$secure')
                || $parameter === '$secure'
                && (! $node->params[$offset]->default instanceof Node\Expr\ConstFetch
                || strtolower($node->params[$offset]->default->name->toString()) !== 'null')
            ) {
                return $this->nativeHelpers[$helper] = false;
            }
        }
        $forward = $node->stmts[0]->expr;
        if (
            ! $forward instanceof Node\Expr\MethodCall
            && ! $forward instanceof Node\Expr\FuncCall
            || ! $forward->name instanceof Node\Identifier
            && ! $forward->name instanceof Node\Name
            || strtolower($forward->name->toString()) !== 'asset'
            || count($forward->args) !== 2
            || $forward->isFirstClassCallable()
        ) {
            return $this->nativeHelpers[$helper] = false;
        }
        if ($helper === 'asset') {
            $dispatch = $forward instanceof Node\Expr\MethodCall ? $forward->var : null;
            if (
                ! $dispatch instanceof Node\Expr\FuncCall
                || ! $dispatch->name instanceof Node\Name
                || strtolower(ltrim($dispatch->name->toString(), '\\')) !== 'app'
                || $dispatch->isFirstClassCallable()
                || count($dispatch->args) !== 1
                || ! $dispatch->args[0] instanceof Node\Arg
                || $dispatch->args[0]->byRef
                || $dispatch->args[0]->unpack
                || $dispatch->args[0]->name !== null
                || ! $dispatch->args[0]->value instanceof Node\Scalar\String_
                || $dispatch->args[0]->value->value !== 'url'
            ) {
                return $this->nativeHelpers[$helper] = false;
            }
        } elseif (! $forward instanceof Node\Expr\FuncCall) {
            return $this->nativeHelpers[$helper] = false;
        }
        foreach ($forward->args as $offset => $argument) {
            if (! $argument instanceof Node\Arg || $argument->unpack || $argument->byRef || $argument->name !== null) {
                return $this->nativeHelpers[$helper] = false;
            }
            if (
                $offset === 0
                && (! $argument->value instanceof Node\Expr\Variable
                || $argument->value->name !== 'path')
            ) {
                return $this->nativeHelpers[$helper] = false;
            }
            if (
                $offset === 1
                && (
                    $helper === 'asset'
                        ? ! $argument->value instanceof Node\Expr\Variable
                        || $argument->value->name !== 'secure'
                        : ! $argument->value instanceof Node\Expr\ConstFetch
                        || strtolower($argument->value->name->toString()) !== 'true'
                )
            ) {
                return $this->nativeHelpers[$helper] = false;
            }
        }

        return $this->nativeHelpers[$helper] = true;
    }

    private function nativeUrl(NodeAnalysisContext $context): bool
    {
        if ($this->nativeUrl !== null) {
            return $this->nativeUrl;
        }
        $method = $context->codebase->getDeclaringMethod(self::URL, 'asset');
        $class = $context->codebase->getClassLike(self::URL);
        $file = str_replace('\\', '/', $method?->location->file ?? '');
        if (
            $method === null
            || $class === null
            || strcasecmp($method->identifier->class ?? '', self::URL) !== 0
            || $method->static
            || $method->abstract
            || $method->flags->contains(MetadataFlags::BY_REFERENCE)
            || $method->visibility !== Visibility::Public
            || ! str_ends_with($file, '/laravel/framework/src/Illuminate/Routing/UrlGenerator.php')
            || array_map(static fn ($parameter): string => $parameter->name, $method->parameters) !== [
                '$path',
                '$secure',
            ]
        ) {
            return $this->nativeUrl = false;
        }
        $declaration = (new NodeFinder)->findFirst(
            $this->source->read(str_starts_with($file, '//?/') ? substr($file, 4) : $file) ?? [],
            static fn (Node $node): bool => (
                $node instanceof Node\Stmt\Class_
                && strcasecmp($node->namespacedName?->toString() ?? '', self::URL) === 0
            ),
        );
        // A class-level @method may override Mago's contract for the real declaration.
        if (! $declaration instanceof Node\Stmt\Class_ || $declaration->getDocComment() !== null) {
            return $this->nativeUrl = false;
        }
        $node = $declaration->getMethod('asset');
        if ($node === null || $node->byRef || $node->isStatic() || ! $node->isPublic()) {
            return $this->nativeUrl = false;
        }
        if (count($node->params) !== 2) {
            return $this->nativeUrl = false;
        }
        foreach ($node->params as $offset => $parameter) {
            if (
                $parameter->byRef
                || $parameter->variadic
                || $method->parameters[$offset]->flags->contains(MetadataFlags::BY_REFERENCE)
                || ($parameter->default !== null) !== ($offset === 1)
                || $offset === 1
                && (! $parameter->default instanceof Node\Expr\ConstFetch
                || strtolower($parameter->default->name->toString()) !== 'null')
            ) {
                return $this->nativeUrl = false;
            }
        }
        $statements = $node->stmts;
        if (
            $statements === null
            || count($node->params) !== 2
            || count($statements) !== 3
            || ! $statements[0] instanceof Node\Stmt\If_
            || count($statements[0]->stmts) !== 1
            || ! $statements[0]->stmts[0] instanceof Node\Stmt\Return_
            || ! $statements[0]->stmts[0]->expr instanceof Node\Expr\Variable
            || $statements[0]->stmts[0]->expr->name !== 'path'
            || ! $statements[1] instanceof Node\Stmt\Expression
            || ! $statements[2] instanceof Node\Stmt\Return_
        ) {
            return $this->nativeUrl = false;
        }
        // The audited Laravel asset body generates URLs and returns valid URLs as-is.
        $body = (new Standard)->prettyPrint($statements);
        $body = str_replace('\\Illuminate\\Support\\Str::finish', 'Str::finish', $body);
        $expected = <<<'PHP'
            if ($this->isValidUrl($path)) {
                return $path;
            }
            $root = $this->assetRoot ?: $this->formatRoot($this->formatScheme($secure));
            return Str::finish($this->removeIndex($root), '/') . trim($path, '/');
            PHP;
        if ($body !== str_replace("\r\n", "\n", $expected)) {
            return $this->nativeUrl = false;
        }
        $doc = $node->getDocComment()?->getText() ?? '';
        if (
            preg_match('/@param\s+string\s+\$path\b/', $doc) !== 1
            || preg_match('/@param\s+bool\|null\s+\$secure\b/', $doc) !== 1
            || preg_match('/@return\s+string\b/', $doc) !== 1
            || preg_match_all('/@[A-Za-z-]+/', $doc) !== 3
        ) {
            return $this->nativeUrl = false;
        }

        return $this->nativeUrl = true;
    }

    private function call(NodeAnalysisContext $context): Node\Expr\FuncCall|Node\Expr\MethodCall|null
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
                    $node instanceof Node\Expr\FuncCall
                    || $node instanceof Node\Expr\MethodCall
                ),
            ) as $call) {
                if ($call instanceof Node\Expr\FuncCall || $call instanceof Node\Expr\MethodCall) {
                    $this->calls[$call->getStartFilePos().':'.($call->getEndFilePos() + 1)] = $call;
                }
            }
        }

        return $this->calls[$context->node->span->start.':'.$context->node->span->end] ?? null;
    }
}
