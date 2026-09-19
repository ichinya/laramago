<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\MethodCallAnalysisHook;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/** Direct native self-alias registration fails before mutating container state. */
final class ContainerSelfAliasHook implements MethodCallAnalysisHook
{
    private const CONTAINER = 'Illuminate\\Container\\Container';

    public function __construct(
        private readonly string $root = '.',
    ) {}

    public function getTargets(): array
    {
        return [MethodTarget::exact(self::CONTAINER, 'alias')];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        try {
            $nodes = (new ParserFactory)
                ->createForNewestSupportedVersion()
                ->parse($context->source->contents);
            $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes ?? []);
        } catch (\PhpParser\Error) {
            return;
        }
        $call = (new NodeFinder)->findFirst(
            $nodes,
            static fn (Node $node): bool => (
                $node instanceof Node\Expr\MethodCall
                && $node->getStartFilePos() === $context->node->span->start
                && ($node->getEndFilePos() + 1) === $context->node->span->end
            ),
        );
        if (
            ! $call instanceof Node\Expr\MethodCall
            || ! $call->name instanceof Node\Identifier
            || strcasecmp($call->name->name, 'alias') !== 0
            || $call->isFirstClassCallable()
            || count($call->args) !== 2
            || ! $call->var instanceof Node\Expr\New_
            || ! $call->var->class instanceof Node\Name
            || strcasecmp($call->var->class->toString(), self::CONTAINER) !== 0
            || $call->var->args !== []
        ) {
            return;
        }
        $arguments = [];
        $named = false;
        foreach ($call->args as $position => $argument) {
            if (
                ! $argument instanceof Node\Arg
                || $argument->unpack
                || $argument->byRef
                || ! $argument->value instanceof Node\Scalar\String_
            ) {
                return;
            }
            if ($argument->name === null) {
                if ($named) {
                    return;
                }
                $name = $position === 0 ? 'abstract' : 'alias';
            } else {
                $named = true;
                $name = $argument->name->name;
            }
            if (! in_array($name, ['abstract', 'alias'], true) || isset($arguments[$name])) {
                return;
            }
            $arguments[$name] = $argument->value;
        }
        if (
            $arguments['abstract']->value !== $arguments['alias']->value
            || ! $this->native($context)
        ) {
            return;
        }
        $alias = $arguments['alias'];
        $context->report(Level::Warning, 'laramago-container-self-alias', Issue::at(
            'The native container throws LogicException when an alias equals its abstract key.',
            new SourceLocation(
                $context->source->path,
                new Span($alias->getStartFilePos(), $alias->getEndFilePos() + 1),
            ),
        ));
    }

    private function native(NodeAnalysisContext $context): bool
    {
        $class = $context->codebase->getClass(self::CONTAINER);
        $method = $context->codebase->getDeclaringMethod(self::CONTAINER, 'alias');
        $path = $method?->location->file;
        if (
            $class === null
            || $class->hasIncompleteHierarchy()
            || $class->directParentClass !== null
            || $class->pseudoMethods !== []
            || $class->staticPseudoMethods !== []
            || $class->mixins !== []
            || $context->codebase->getDeclaringMethod(self::CONTAINER, '__construct') !== null
            || $method?->identifier->class !== self::CONTAINER
            || $path === null
            || ! str_ends_with(
                str_replace('\\', '/', $path),
                '/laravel/framework/src/Illuminate/Container/Container.php',
            )
        ) {
            return false;
        }
        $nodes = (new PhpSource($this->root))->read($path);
        if ($nodes === null) {
            return false;
        }
        $declaration = (new NodeFinder)->findFirst(
            $nodes,
            static fn (Node $node): bool => (
                $node instanceof Node\Stmt\Class_
                && $node->namespacedName?->toString() === self::CONTAINER
            ),
        );
        if (
            ! $declaration instanceof Node\Stmt\Class_
            || $declaration->isAbstract()
            || $declaration->getDocComment() !== null
        ) {
            return false;
        }
        $aliases = array_values(array_filter(
            $declaration->stmts,
            static fn (Node $node): bool => (
                $node instanceof Node\Stmt\ClassMethod
                && strcasecmp($node->name->name, 'alias') === 0
            ),
        ));
        if (count($aliases) !== 1) {
            return false;
        }
        // Audited Laravel 7c75fbf alias body, signature, visibility and PHPDoc.
        $text = (new Standard)->prettyPrint($aliases);
        $normalized = '';
        foreach (token_get_all('<?php '.$text) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT], true)) {
                    continue;
                }
                $normalized .= str_replace(["\r\n", "\r"], "\n", $token[1]);
            } else {
                $normalized .= $token;
            }
        }

        return hash('sha256', $normalized) === '9b4bd37b6526296533246c21bb22ce5939da4e84c4aa431ea476c5e6312a6c49';
    }
}
