<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\FileAnalysisRequirement;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\NodeAnalysisHook;
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

/** Check required files only when Mago has resolved an absolute literal path. */
final class RequiredFileReferencesHook implements NodeAnalysisHook
{
    private ?string $sourceHash = null;
    /** @var array<string, Node\Expr\Include_> */
    private array $requires = [];
    /** @var array<string, Node\Expr\FuncCall> */
    private array $existenceGuards = [];

    public function getTargets(): array
    {
        return [NodeKind::RequireConstruct, NodeKind::RequireOnceConstruct];
    }

    public function getRequirements(): array
    {
        return [FileAnalysisRequirement::SourceText];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $require = $this->requiredExpression($context);
        if ($require === null) {
            return;
        }

        $key = $require->getStartFilePos() . ':' . ($require->getEndFilePos() + 1);
        $guard = $this->existenceGuards[$key] ?? null;
        if ($guard !== null && $this->nativeExistenceGuard($context, $guard)) {
            return;
        }

        $expression = $require->expr;
        $span = new Span($expression->getStartFilePos(), $expression->getEndFilePos() + 1);
        $path = $context->analysis->getExpressionType($span)?->getLiteralString();
        if ($path === null || !self::absoluteLocalPath($path)) {
            return;
        }

        // A failed stat cannot distinguish absence from an inaccessible entry.
        // Only a successful parent listing that lacks the name proves absence.
        if (@file_exists($path) || @is_link($path)) {
            return;
        }
        $entries = @scandir(dirname($path));
        if ($entries === false) {
            return;
        }
        $name = basename($path);
        foreach ($entries as $entry) {
            if (strcasecmp($entry, $name) === 0) {
                return;
            }
        }

        $context->report(
            Level::Warning,
            'laramago-missing-required-file',
            Issue::at(
                'Required file "' . $path . '" is absent from the analyzed filesystem snapshot.',
                new SourceLocation($context->source->path, $span),
            ),
        );
    }

    private static function absoluteLocalPath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '://')) {
            return false;
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            return str_starts_with($path, '/');
        }

        $normalized = str_replace('\\', '/', $path);

        return (
            preg_match('~^[A-Za-z]:/[^:]*$~', $normalized) === 1
            || preg_match('~^//(?![?.]/)[^/]+/[^/]+/[^:]*$~', $normalized) === 1
        );
    }

    private function nativeExistenceGuard(NodeAnalysisContext $context, Node\Expr\FuncCall $call): bool
    {
        if (!$call->name instanceof Node\Name) {
            return false;
        }
        $resolved = $call->name->getAttribute('resolvedName');
        $name = $resolved instanceof Node\Name ? $resolved->toString() : $call->name->toString();
        if (!in_array(strtolower($name), ['file_exists', 'is_file'], true)) {
            return false;
        }
        $namespaced = $call->name->getAttribute('namespacedName');
        if (
            $namespaced instanceof Node\Name
            && strcasecmp($namespaced->toString(), $name) !== 0
            && $context->codebase->getFunction($namespaced->toString()) !== null
        ) {
            return false;
        }
        $function = $context->codebase->getFunction($name);

        return (
            $function !== null
            && $function->flags->contains(MetadataFlags::BUILTIN)
            && !$function->flags->contains(MetadataFlags::USER_DEFINED)
        );
    }

    /** Only a direct positive guard with no intervening statements proves optional loading. */
    private function existenceGuard(Node\Stmt\If_ $if): ?Node\Expr\Include_
    {
        $call = $if->cond;
        $statement = $if->stmts[0] ?? null;
        if (
            !$call instanceof Node\Expr\FuncCall
            || count($call->args) !== 1
            || !$call->args[0] instanceof Node\Arg
            || $call->args[0]->byRef
            || $call->args[0]->unpack
            || $call->args[0]->name !== null
            || count($if->stmts) !== 1
            || !$statement instanceof Node\Stmt\Expression
            || !$statement->expr instanceof Node\Expr\Include_
        ) {
            return null;
        }
        $tested = $call->args[0]->value;
        if ($tested instanceof Node\Expr\Assign) {
            $tested = $tested->var;
        }
        $required = $statement->expr->expr;
        if (
            $tested instanceof Node\Expr\Variable
            && is_string($tested->name)
            && $required instanceof Node\Expr\Variable
            && $required->name === $tested->name
        ) {
            return $statement->expr;
        }
        if (
            $tested instanceof Node\Scalar\String_
            && $required instanceof Node\Scalar\String_
            && $required->value === $tested->value
        ) {
            return $statement->expr;
        }

        return null;
    }

    private function requiredExpression(NodeAnalysisContext $context): ?Node\Expr\Include_
    {
        $hash = hash('sha256', $context->source->path . "\0" . $context->source->contents);
        if ($hash !== $this->sourceHash) {
            $this->sourceHash = $hash;
            $this->requires = [];
            $this->existenceGuards = [];
            try {
                $nodes = (new ParserFactory())
                    ->createForNewestSupportedVersion()
                    ->parse($context->source->contents);
                $nodes = (new NodeTraverser(new NameResolver(null, ['replaceNodes' => false])))->traverse($nodes ?? []);
            } catch (\PhpParser\Error) {
                return null;
            }
            foreach ((new NodeFinder())->findInstanceOf($nodes ?? [], Node\Expr\Include_::class) as $include) {
                if (!in_array(
                    $include->type,
                    [Node\Expr\Include_::TYPE_REQUIRE, Node\Expr\Include_::TYPE_REQUIRE_ONCE],
                    true,
                )) {
                    continue;
                }
                $this->requires[$include->getStartFilePos() . ':' . ($include->getEndFilePos() + 1)] = $include;
            }
            foreach ((new NodeFinder())->findInstanceOf($nodes, Node\Stmt\If_::class) as $if) {
                $include = $this->existenceGuard($if);
                if ($include !== null) {
                    $this->existenceGuards[$include->getStartFilePos() . ':' . ($include->getEndFilePos() + 1)] =
                        $if->cond;
                }
            }
        }

        return $this->requires[$context->node->span->start . ':' . $context->node->span->end] ?? null;
    }
}
