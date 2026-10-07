<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Analyzer\Metadata\ClassLikeKind;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Reporting\AnnotationKind;
use Mago\Sdk\Reporting\Level;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

/**
 * Advisory policy: native Mago's equality diagnostic remains the equality evidence.
 * Source and frozen metadata certify its scope; no annotation or type is rewritten.
 */
final class RedundantLocalObjectDocblockFilter implements IssueFilterHook, InitializationHook
{
    private const MAX_FILE_BYTES = 1024 * 1024;
    private const MAX_CACHE_BYTES = 8 * 1024 * 1024;
    private const MAX_CACHE_ENTRIES = 32;
    private const MAX_NODES = 20000;

    /** @var array<string, array{sites: list<array<string, mixed>>, bytes: int}> */
    private array $cache = [];
    private int $cacheBytes = 0;

    public function initialize(InitializationContext $context): void { $this->cache = []; $this->cacheBytes = 0; }
    public function getCodes(): array { return ['redundant-docblock-type']; }

    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        $issue = $context->issue;
        if ($context->cancellation->isCancelled() || strlen($context->contents) > self::MAX_FILE_BYTES
            || $issue->code !== 'redundant-docblock-type' || $issue->level !== Level::Warning
            || count($issue->annotations) !== 2 || $issue->edits !== [] || $issue->link !== null
            || $issue->notes !== ['The type defined in this docblock is identical to the inferred type of the variable.']
            || $issue->help !== 'You can remove this redundant `@var` docblock tag.'
            || preg_match('/^Redundant docblock type for variable `(\$[A-Za-z_][A-Za-z0-9_]*)`\.$/D', $issue->message, $message) !== 1) {
            return IssueFilterDecision::Keep;
        }
        [$primary, $secondary] = $issue->annotations;
        if ($primary->kind !== AnnotationKind::Primary || $secondary->kind !== AnnotationKind::Secondary
            || $primary->file !== null && $primary->file !== '' || $secondary->file !== null && $secondary->file !== ''
            || $primary->span->end <= $primary->span->start || $primary->span->end > strlen($context->contents)
            || $secondary->span->end <= $secondary->span->start || $secondary->span->end > strlen($context->contents)
            || $primary->span->end > $secondary->span->start
            || preg_match('/^This docblock asserts the type should be `([A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)`, which is identical to the inferred type\.$/D', $primary->message ?? '', $type) !== 1
            || $secondary->message !== 'The variable `'.$message[1].'` type is known to be `'.$type[1].'` here.') {
            return IssueFilterDecision::Keep;
        }
        foreach ($this->sites($context->file, $context->contents) as $site) {
            if ($context->cancellation->isCancelled()) { return IssueFilterDecision::Keep; }
            if ($site['variable'] !== $message[1] || strcasecmp($site['type'], $type[1]) !== 0
                || $primary->span->start !== $site['typeStart'] || $primary->span->end !== $site['typeEnd']
                || $secondary->span->start !== $site['variableStart'] || $secondary->span->end !== $site['variableEnd']) { continue; }
            $object = $context->codebase->getClass($site['type']);
            if ($object === null || $object->kind !== ClassLikeKind::Class_ || $object->hasIncompleteHierarchy()
                || $object->templates !== [] || strcasecmp($object->name, $site['type']) !== 0) { continue; }
            $caller = $site['class'] === null ? $context->codebase->getFunction($site['function'])
                : $context->codebase->getMethod($site['class'], $site['function']);
            if ($caller === null || $caller->abstract || $caller->templates !== []
                || $caller->flags->contains(MetadataFlags::BY_REFERENCE)
                || strcasecmp($caller->identifier->name, $site['function']) !== 0
                || strcasecmp($caller->identifier->class ?? '', $site['class'] ?? '') !== 0
                || self::path($caller->location->file) !== self::path($context->file)
                || $caller->location->span->start !== $site['scopeStart'] || $caller->location->span->end !== $site['scopeEnd']
                || self::path($caller->nameLocation?->file ?? '') !== self::path($context->file)
                || $caller->nameLocation?->span->start !== $site['nameStart'] || $caller->nameLocation?->span->end !== $site['nameEnd']) { continue; }
            if ($site['class'] !== null) {
                $owner = $context->codebase->getClass($site['class']);
                if ($owner === null || $owner->kind !== ClassLikeKind::Class_ || $owner->hasIncompleteHierarchy()
                    || $owner->templates !== [] || $owner->typeAliases !== []
                    || strcasecmp($owner->name, $site['class']) !== 0
                    || self::path($owner->location->file) !== self::path($context->file)
                    || $owner->location->span->start !== $site['classStart'] || $owner->location->span->end !== $site['classEnd']) { continue; }
            }
            return IssueFilterDecision::Remove;
        }
        return IssueFilterDecision::Keep;
    }

    private static function path(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (str_starts_with($path, '//?/')) { $path = substr($path, 4); }
        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }

    /** @return list<array<string, mixed>> */
    private function sites(string $file, string $contents): array
    {
        $key = hash('sha256', $file."\0".$contents);
        if (isset($this->cache[$key])) {
            $entry = $this->cache[$key]; unset($this->cache[$key]); $this->cache[$key] = $entry;
            return $entry['sites'];
        }
        try { $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($contents) ?? []; }
        catch (Error) { return []; }
        $resolver = new NameResolver;
        $visitor = new class($contents, $resolver, self::MAX_NODES) extends NodeVisitorAbstract {
            private int $count = 0;
            public bool $failed = false;
            /** @var list<Node\Stmt\ClassLike> */
            private array $classes = [];
            /** @var list<array<string, mixed>> */
            private array $scopes = [];
            /** @var list<array<string, mixed>> */
            public array $sites = [];

            public function __construct(private readonly string $contents, private readonly NameResolver $resolver, private readonly int $maximum) {}

            public function enterNode(Node $node): ?int
            {
                if (++$this->count > $this->maximum) { $this->failed = true; return NodeTraverser::STOP_TRAVERSAL; }
                if ($node instanceof Node\Stmt\ClassLike) { $this->classes[] = $node; }
                if ($node instanceof Node\FunctionLike) {
                    $class = $this->classes === [] ? null : $this->classes[count($this->classes) - 1];
                    $named = $node instanceof Node\Stmt\Function_
                        || $node instanceof Node\Stmt\ClassMethod && $class instanceof Node\Stmt\Class_ && $class->name !== null;
                    $direct = [];
                    foreach ($node->getStmts() ?? [] as $statement) { $direct[spl_object_id($statement)] = true; }
                    $forbidden = [];
                    foreach ($node->getParams() as $parameter) {
                        if (is_string($parameter->var->name)) { $forbidden[$parameter->var->name] = true; }
                    }
                    $this->scopes[] = ['named' => $named && ! $node->byRef, 'node' => $node,
                        'class' => $node instanceof Node\Stmt\ClassMethod ? $class : null,
                        'direct' => $direct, 'forbidden' => $forbidden, 'uncertain' => false, 'sites' => []];
                }
                if ($this->scopes === []) { return null; }
                $index = count($this->scopes) - 1;
                if ($node instanceof Node\Expr\Variable && ! is_string($node->name)
                    || $node instanceof Node\Expr\AssignRef || $node instanceof Node\Expr\Eval_
                    || $node instanceof Node\Arg && $node->byRef) { $this->scopes[$index]['uncertain'] = true; }
                if ($node instanceof Node\Stmt\Global_) {
                    foreach ($node->vars as $var) { $this->forbid($index, $var); }
                } elseif ($node instanceof Node\Stmt\Static_) {
                    foreach ($node->vars as $var) { $this->forbid($index, $var->var); }
                } elseif ($node instanceof Node\Stmt\Foreach_ && $node->byRef) {
                    $this->forbid($index, $node->valueVar);
                }
                if ($node instanceof Node\Expr\Closure) {
                    foreach ($node->uses as $use) {
                        if ($use->byRef && $index > 0) { $this->forbid($index - 1, $use->var); }
                    }
                }
                if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name
                    && in_array(strtolower($node->name->getLast()), ['extract', 'parse_str'], true)) {
                    $this->scopes[$index]['uncertain'] = true;
                }
                if (($node instanceof Node\Stmt\Expression || $node instanceof Node\Stmt\Foreach_) && isset($this->scopes[$index]['direct'][spl_object_id($node)])) {
                    $this->candidate($index, $node);
                }
                return null;
            }

            public function leaveNode(Node $node): ?int
            {
                if ($node instanceof Node\FunctionLike) {
                    $scope = array_pop($this->scopes);
                    if ($scope['named'] && ! $scope['uncertain']) {
                        foreach ($scope['sites'] as $site) {
                            if (! isset($scope['forbidden'][substr($site['variable'], 1)])) { $this->sites[] = $site; }
                        }
                    }
                }
                if ($node instanceof Node\Stmt\ClassLike) { array_pop($this->classes); }
                return null;
            }

            private function forbid(int $index, Node\Expr $var): void
            {
                if ($var instanceof Node\Expr\Variable && is_string($var->name)) { $this->scopes[$index]['forbidden'][$var->name] = true; }
                else { $this->scopes[$index]['uncertain'] = true; }
            }

            private function candidate(int $index, Node\Stmt\Expression|Node\Stmt\Foreach_ $statement): void
            {
                if (! $this->scopes[$index]['named']) { return; }
                if ($statement instanceof Node\Stmt\Foreach_) {
                    $var = ForeachHeaderObjectDocblockProof::value($statement, $this->scopes[$index]['node'], $this->contents);
                    if ($var === null) { return; }
                } else {
                    if (! $statement->expr instanceof Node\Expr\Assign || ! $statement->expr->var instanceof Node\Expr\Variable
                        || ! is_string($statement->expr->var->name)) { return; }
                    $var = $statement->expr->var;
                }
                if (in_array($var->name, ['this', 'GLOBALS', '_ENV', '_SERVER', '_GET', '_POST', '_FILES', '_COOKIE', '_SESSION', '_REQUEST'], true)) { return; }
                $doc = $statement->getDocComment();
                if ($doc === null || count(array_filter($statement->getComments(), static fn ($comment): bool => $comment instanceof \PhpParser\Comment\Doc)) !== 1
                    || preg_match('/\A\/\*\*[ \t\r\n*]*@var[ \t]+(\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)[ \t]+(\$[A-Za-z_][A-Za-z0-9_]*)[ \t\r\n*]*\*\/\z/D', $doc->getText(), $tag, PREG_OFFSET_CAPTURE) !== 1
                    || $tag[2][0] !== '$'.$var->name || in_array(strtolower($tag[1][0]), ['self', 'static', 'parent', 'object', 'mixed'], true)) { return; }
                $token = $tag[1][0];
                $name = str_starts_with($token, '\\') ? new Node\Name\FullyQualified(substr($token, 1)) : new Node\Name($token);
                $type = $this->resolver->getNameContext()->getResolvedClassName($name)->toString();
                $scope = $this->scopes[$index]; $function = $scope['node']; $class = $scope['class'];
                $typeStart = $doc->getStartFilePos() + $tag[1][1]; $typeEnd = $typeStart + strlen($token);
                $variableStart = $var->getStartFilePos(); $variableEnd = $var->getEndFilePos() + 1;
                if ($typeStart < 0 || $typeEnd > strlen($this->contents) || $variableStart < 0 || $variableEnd > strlen($this->contents)
                    || substr($this->contents, $typeStart, $typeEnd - $typeStart) !== $token
                    || substr($this->contents, $variableStart, $variableEnd - $variableStart) !== $tag[2][0]) { return; }
                $this->scopes[$index]['sites'][] = ['type' => $type, 'typeStart' => $typeStart, 'typeEnd' => $typeEnd,
                    'variable' => $tag[2][0], 'variableStart' => $variableStart, 'variableEnd' => $variableEnd,
                    'class' => $class?->namespacedName?->toString(),
                    'classStart' => $class?->getStartFilePos(), 'classEnd' => $class === null ? null : $class->getEndFilePos() + 1,
                    'function' => $function instanceof Node\Stmt\Function_ ? $function->namespacedName->toString() : $function->name->toString(),
                    'scopeStart' => $function->getStartFilePos(), 'scopeEnd' => $function->getEndFilePos() + 1,
                    'nameStart' => $function->name->getStartFilePos(), 'nameEnd' => $function->name->getEndFilePos() + 1];
            }
        };
        try { (new NodeTraverser($resolver, $visitor))->traverse($nodes); }
        catch (Error) { return []; }
        $sites = $visitor->failed ? [] : $visitor->sites;
        $entry = ['sites' => $sites, 'bytes' => strlen($contents) + count($sites) * 1024];
        while ($this->cache !== [] && (count($this->cache) >= self::MAX_CACHE_ENTRIES || $this->cacheBytes + $entry['bytes'] > self::MAX_CACHE_BYTES)) {
            $oldest = array_key_first($this->cache); $this->cacheBytes -= $this->cache[$oldest]['bytes']; unset($this->cache[$oldest]);
        }
        if ($entry['bytes'] <= self::MAX_CACHE_BYTES) { $this->cache[$key] = $entry; $this->cacheBytes += $entry['bytes']; }
        return $sites;
    }
}
