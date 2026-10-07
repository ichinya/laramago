<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Analyzer\Metadata\ClassLikeKind;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\Reporting\AnnotationKind;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/** PHPStan-compatible direct coalesce probe advisory, with no property/type/existence guarantee. */
final class CoalesceModelPropertyIssueFilter implements IssueFilterHook
{
    public array $stages = [];
    public function __construct(private readonly string $root, private readonly string $model = 'Illuminate\\Database\\Eloquent\\Model') {}
    public function getCodes(): array { return ['non-documented-property']; }
    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        $this->stages = []; $issue = $context->issue;
        if ($context->cancellation->isCancelled() || $issue->level !== Level::Warning || $issue->code !== 'non-documented-property'
            || count($issue->annotations) !== 2 || $issue->edits !== [] || $issue->link !== null
            || preg_match('/^Ambiguous property access: \$([A-Za-z_][A-Za-z0-9_]*) on class `([^`]+)`\.$/D', $issue->message, $match) !== 1
            || $match[2] !== $this->model
            || $issue->notes !== ['While this read from might be handled by `__get()`, Mago cannot determine its type without a corresponding `@property` docblock tag.']
            || $issue->help !== 'To enable type checking, add a `@property`, `@property-read`, or `@property-write` tag to the docblock of the `'.$this->model.'` class. For example: `/** @property string $'.$match[1].' */`') { return IssueFilterDecision::Keep; }
        [$primary, $secondary] = $issue->annotations;
        if ($primary->kind !== AnnotationKind::Primary || $secondary->kind !== AnnotationKind::Secondary || $primary->file !== null || $secondary->file !== null
            || $primary->message !== 'This property is not explicitly defined' || $secondary->message !== 'On an object of type `'.$this->model.'`'
            || $primary->span->end > strlen($context->contents) || $primary->span->end <= $primary->span->start
            || substr($context->contents, $primary->span->start, $primary->span->length()) !== $match[1]) { return IssueFilterDecision::Keep; }
        $file = $this->source($context->file, $context->contents); $site = null;
        foreach ($file === null ? [] : (new NodeFinder)->findInstanceOf($file['nodes'], Node\Expr\BinaryOp\Coalesce::class) as $coalesce) {
            $fetch = $coalesce->left;
            if (! $fetch instanceof Node\Expr\PropertyFetch || ! $fetch->name instanceof Node\Identifier || $fetch->name->name !== $match[1]
                || [$fetch->name->getStartFilePos(), $fetch->name->getEndFilePos() + 1] !== [$primary->span->start, $primary->span->end]
                || [$fetch->var->getStartFilePos(), $fetch->var->getEndFilePos() + 1] !== [$secondary->span->start, $secondary->span->end]) { continue; }
            $receiver = $fetch->var;
            while ($receiver instanceof Node\Expr\PropertyFetch && $receiver->name instanceof Node\Identifier) { $receiver = $receiver->var; }
            if (! $receiver instanceof Node\Expr\Variable || ! is_string($receiver->name) || in_array($receiver->name, ['GLOBALS', '_SERVER', '_ENV'], true)) { continue; }
            $site = $fetch;
        }
        $this->stages['currentDirectReadOnlyCoalesceProbe'] = $site !== null;
        if ($site === null) { return IssueFilterDecision::Keep; }
        $metadata = $context->codebase->getClass($this->model);
        $source = $metadata === null ? null : $this->source($metadata->location->file);
        $syntax = $source === null ? null : $this->classNode($source['nodes'], $this->model);
        $interface = $context->codebase->getInterface('ArrayAccess');
        $implements = $syntax === null ? [] : array_map(static fn (Node\Name $name): string => strtolower($name->toString()), $syntax->implements);
        $nativeInterfaces = array_map('strtolower', $metadata?->directParentInterfaces ?? []); sort($implements); sort($nativeInterfaces);
        $checks = ['currentAbstractModel' => $metadata !== null && $metadata->kind === ClassLikeKind::Class_ && ! $metadata->hasIncompleteHierarchy()
                && strcasecmp($metadata->name, $this->model) === 0 && $metadata->flags->contains(MetadataFlags::ABSTRACT)
                && ! $metadata->flags->contains(MetadataFlags::BUILTIN) && ! $metadata->flags->contains(MetadataFlags::FINAL)
                && $metadata->templates === [] && $metadata->mixins === [] && $metadata->typeAliases === [],
            'currentSourceModel' => $syntax !== null && $syntax->isAbstract() && ! $syntax->isFinal() && ! $syntax->isReadonly()
                && strcasecmp($syntax->extends?->toString() ?? '', $metadata?->directParentClass ?? '') === 0,
            'nativeSourceModelLocations' => $metadata !== null && $syntax !== null && $source !== null
                && $this->located($metadata->location, $syntax, $source) && $this->located($metadata->nameLocation, $syntax->name, $source),
            'nativeSourceArrayAccessRole' => in_array('arrayaccess', $implements, true) && $implements === $nativeInterfaces
                && $interface?->kind === ClassLikeKind::Interface && strcasecmp($interface->name, 'ArrayAccess') === 0
                && ! $interface->hasIncompleteHierarchy() && $interface->flags->contains(MetadataFlags::BUILTIN),
            'noKnownBasePhysicalProperty' => $context->codebase->getDeclaringProperty($this->model, '$'.$match[1]) === null,
            'noKnownBaseMagicProperty' => $context->codebase->getDeclaringMagicProperty($this->model, '$'.$match[1]) === null];
        $this->stages['model'] = $checks;
        if (in_array(false, $checks, true)) { return IssueFilterDecision::Keep; }
        foreach (['__get' => ['getAttribute', Type::mixed(), 'mixed'], '__isset' => ['offsetExists', Type::bool(), 'bool']] as $name => [$delegate, $return, $token]) {
            $direct = $context->codebase->getMethod($this->model, $name); $declaring = $context->codebase->getDeclaringMethod($this->model, $name);
            $method = $syntax->getMethod($name); $param = $method?->params[0] ?? null; $nativeParam = $direct?->parameters[0] ?? null;
            $doc = $method?->getDocComment();
            $okay = $direct !== null && $direct == $declaring && $direct->identifier->class !== null && strcasecmp($direct->identifier->class, $this->model) === 0
                && strcasecmp($direct->identifier->name, $name) === 0 && ! $direct->abstract && ! $direct->static && ! $direct->constructor
                && $direct->kind === \Mago\Sdk\Analyzer\Metadata\FunctionLikeKind::Method
                && $direct->identifier->kind === \Mago\Sdk\Analyzer\Type\FunctionLikeKind::Method
                && $direct->visibility === Visibility::Public && $direct->templates === [] && $direct->whereConstraints === []
                && ! $direct->flags->contains(MetadataFlags::BY_REFERENCE) && ! $direct->flags->contains(MetadataFlags::BUILTIN)
                && count($direct->parameters) === 1 && $direct->declaredReturnType === null && $method !== null
                && $method->isPublic() && ! $method->isStatic() && ! $method->isAbstract() && ! $method->byRef && $method->returnType === null
                && $direct->originalName === $method->name->name && $direct->final === $method->isFinal() && $direct->hasDocblock
                && $this->located($direct->location, $method, $source) && $this->located($direct->nameLocation, $method->name, $source)
                && count($method->params) === 1 && $param->var instanceof Node\Expr\Variable && $param->var->name === 'key'
                && $param->type === null && ! $param->byRef && ! $param->variadic && $param->default === null
                && $nativeParam !== null && $nativeParam->name === '$key' && $nativeParam->declaredType === null && $nativeParam->outType === null
                && $nativeParam->closureThisType === null
                && ! $nativeParam->flags->contains(MetadataFlags::BY_REFERENCE) && ! $nativeParam->flags->contains(MetadataFlags::VARIADIC)
                && ! $nativeParam->flags->contains(MetadataFlags::HAS_DEFAULT)
                && $nativeParam->defaultType === null && $nativeParam->type !== null && $nativeParam->type->fromDocblock && ! $nativeParam->type->inferred
                && $context->types->equals($nativeParam->type->type, Type::string()) && $doc !== null
                && $this->docType($nativeParam->type->location, $doc, $source, 'string')
                && $this->located($nativeParam->location, $param, $source) && $this->located($nativeParam->nameLocation, $param->var, $source)
                && $direct->returnType !== null && $direct->returnType->fromDocblock && ! $direct->returnType->inferred
                && $context->types->equals($direct->returnType->type, $return) && $this->docType($direct->returnType->location, $doc, $source, $token)
                && (new Standard)->prettyPrint($method->stmts ?? []) === 'return $this->'.$delegate.'($key);';
            $this->stages[$name] = $okay;
            if (! $okay) { return IssueFilterDecision::Keep; }
        }
        return IssueFilterDecision::Remove;
    }
    private function source(?string $file, ?string $analyzed = null): ?array
    {
        if ($file === null) { return null; } $path = $this->path($file); $contents = @file_get_contents($path);
        if ($contents === false || strlen($contents) > 1024 * 1024 || $analyzed !== null && $contents !== $analyzed) { return null; }
        try { $nodes = (new NodeTraverser(new NameResolver))->traverse((new ParserFactory)->createForNewestSupportedVersion()->parse($contents) ?? []); }
        catch (\PhpParser\Error) { return null; }
        return ['path' => $path, 'contents' => $contents, 'hash' => hash('sha256', $contents), 'nodes' => $nodes];
    }
    private function classNode(array $nodes, string $name): ?Node\Stmt\Class_
    {
        $matches = (new NodeFinder)->find($nodes, static fn (Node $node): bool => $node instanceof Node\Stmt\Class_ && strcasecmp($node->namespacedName?->toString() ?? '', $name) === 0);
        return count($matches) === 1 ? $matches[0] : null;
    }
    private function located(?SourceLocation $location, Node $node, array $file): bool
    {
        return $location?->file !== null && $this->path($location->file) === $file['path']
            && [$location->span->start, $location->span->end] === [$node->getStartFilePos(), $node->getEndFilePos() + 1]
            && $file['hash'] === @hash_file('sha256', $file['path']);
    }
    private function docType(?SourceLocation $location, \PhpParser\Comment\Doc $doc, array $file, string $token): bool
    {
        return $location?->file !== null && $this->path($location->file) === $file['path'] && $location->span->start >= $doc->getStartFilePos()
            && $location->span->end <= $doc->getEndFilePos() + 1 && substr($file['contents'], $location->span->start, $location->span->length()) === $token;
    }
    private function path(string $path): string
    {
        $path = str_replace('\\', '/', $path); if (str_starts_with($path, '//?/')) { $path = substr($path, 4); }
        if (! str_starts_with($path, '/') && preg_match('~^[A-Za-z]:/~', $path) !== 1) { $path = $this->root.'/'.$path; }
        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }
}
