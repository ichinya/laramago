<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\CodebaseScanContext;
use Mago\Sdk\Analyzer\CodebaseScanHook;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Reporting\AnnotationKind;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Reporting\ReportedIssue;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\NodeVisitor\NameResolver;

/** Source-bound Warning policy for ordinary argv normalization; native types remain unchanged. */
final class ArgvNormalizationAdvisories implements InitializationHook, CodebaseScanHook
{
    private array $files = [];
    private bool $started = false;
    private bool $complete = false;
    private bool $failed = false;
    private int $bytes = 0;
    public function initialize(InitializationContext $context): void { $this->reset(); }

    public function reset(): void { $this->files = []; $this->started = $this->complete = $this->failed = false; $this->bytes = 0; }

    public function getTargets(): array { return ['*']; }

    public function scan(CodebaseScanContext $context): void
    {
        if ($context->firstBatch) { $this->reset(); $this->started = true; }
        elseif (! $this->started || $this->complete) { $this->failed = true; }
        foreach ($context->files as $file) {
            if ($context->cancellation->isCancelled()) { $this->failed = true; break; }
            $this->bytes += strlen($file->contents);
            if (strlen($file->contents) > 2_000_000 || $this->bytes > 64_000_000 || count($this->files) > 100_000 || isset($this->files[$file->path])) { $this->failed = true; break; }
            try {
                $nodes = (new ParserFactory())->createForNewestSupportedVersion()->parse($file->contents) ?? [];
                $nodes = (new NodeTraverser(new NameResolver()))->traverse($nodes);
                $proofs = self::sourceProofs($nodes);
            } catch (\PhpParser\Error) { $this->failed = true; break; }
            $this->files[$file->path] = ['sha256' => hash('sha256', $file->contents), 'proofs' => $proofs];
        }
        $this->complete = $context->lastBatch && ! $this->failed;
    }

    public function facts(IssueFilterContext $context): array
    {
        $facts = ['arrayAdvisoryEligible' => false, 'reason' => 'no-current-source-certificate', 'sourceCertificate' => null, 'predicateContracts' => [],
            'sourceIndexGuards'=>['started'=>$this->started,'complete'=>$this->complete,'failed'=>$this->failed,
                'filePresent'=>isset($this->files[$context->file]),'contentsMatch'=>isset($this->files[$context->file]) && $this->files[$context->file]['sha256']===hash('sha256',$context->contents)]];
        if (! $this->started || ! $this->complete || $this->failed || $context->cancellation->isCancelled()
            || ! isset($this->files[$context->file]) || $this->files[$context->file]['sha256'] !== hash('sha256', $context->contents)) { return $facts; }
        $annotation = count($context->issue->annotations) === 1 ? $context->issue->annotations[0] : null;
        if ($annotation === null) { return $facts; }
        $key = $annotation->span->start.':'.$annotation->span->end;
        foreach ($this->files[$context->file]['proofs'] as $proof) {
            $site = $key === self::key($proof['arrayCall']) ? 'array-check'
                : (in_array($key, [self::key($proof['stringCall']), self::key($proof['stringReject'])], true) ? 'string-companion-observation-only' : null);
            if ($site === null) { continue; }
            $facts['sourceCertificate'] = self::certificate($proof) + ['selectedSite' => $site];
            foreach (['arrayCall' => 'is_array', 'stringCall' => 'is_string'] as $node => $builtin) {
                $facts['predicateContracts'][$builtin] = self::predicate($context, $proof[$node], $builtin);
            }
            $facts['reason'] = 'native-predicate-contract-unproved';
            if (! self::contract($facts['predicateContracts']['is_array'], 'is_array') || ! self::contract($facts['predicateContracts']['is_string'], 'is_string')) { return $facts; }
            $facts['reason'] = $site === 'array-check' ? 'native-array-advisory-envelope-mismatch' : 'string-companion-not-authorized-before-native-observation';
            if ($site !== 'array-check' || ! self::envelope($context->issue)) { return $facts; }
            $facts['arrayAdvisoryEligible'] = true;
            $facts['reason'] = 'exact-native-warning-and-source-normalization-policy';
            return $facts;
        }
        return $facts;
    }

    public static function envelope(ReportedIssue $issue): bool
    {
        if ($issue->level !== Level::Warning || $issue->code !== 'redundant-condition'
            || $issue->message !== 'This condition (type `true`) will always evaluate to true.'
            || $issue->notes !== ['Because this condition is always true, the code block it controls will always execute if this part of the code is reached.', 'The explicit condition might be redundant.']
            || $issue->help !== "Consider simplifying or removing the conditional check if the guarded code should always execute, or verify the expression's logic if a conditional check is truly needed."
            || $issue->link !== null || $issue->edits !== [] || count($issue->annotations) !== 1) { return false; }
        $primary = $issue->annotations[0];
        return $primary->kind === AnnotationKind::Primary && ($primary->file === null || $primary->file === '')
            && $primary->message === 'Expression of type `true` is always truthy';
    }

    private static function predicate(IssueFilterContext $context, Node\Expr\FuncCall $call, string $builtin): array
    {
        $name = $call->name;
        $resolved = $name->toString();
        if (! $name instanceof Node\Name\FullyQualified) {
            $qualified = $name->getAttribute('namespacedName');
            if ($qualified instanceof Node\Name && $context->codebase->getFunction($qualified->toString()) !== null) { $resolved = $qualified->toString(); }
        }
        $native = $context->codebase->getFunction($resolved);
        if ($native === null) { return ['resolvedName' => $resolved, 'missing' => true]; }
        $parameter = count($native->parameters) === 1 ? $native->parameters[0] : null;
        return ['resolvedName' => $resolved, 'kind' => $native->kind->name, 'identifierKind' => $native->identifier->kind->name,
            'identifierClass' => $native->identifier->class, 'identifierName' => $native->identifier->name,
            'builtin' => $native->flags->contains(MetadataFlags::BUILTIN), 'userDefined' => $native->flags->contains(MetadataFlags::USER_DEFINED),
            'flags' => $native->flags->bits, 'nativeLocation' => $native->location,
            'parameterCount' => count($native->parameters), 'parameterName' => $parameter?->name,
            'parameterByReference' => $parameter?->flags->contains(MetadataFlags::BY_REFERENCE),
            'parameterVariadic' => $parameter?->flags->contains(MetadataFlags::VARIADIC),
            'parameterDefault' => $parameter?->defaultType === null ? null : (string) $parameter->defaultType->type,
            'parameterOut' => $parameter?->outType === null ? null : (string) $parameter->outType->type,
            'parameterClosureThis' => $parameter?->closureThisType === null ? null : (string) $parameter->closureThisType->type,
            'parameterTypeMixed' => $parameter?->type !== null && $context->types->equals($parameter->type->type, Type::mixed()),
            'parameterPhysicalMixed' => $parameter?->declaredType !== null && !$parameter->declaredType->fromDocblock && !$parameter->declaredType->inferred && $context->types->equals($parameter->declaredType->type, Type::mixed()),
            'parameterEffective' => $parameter?->type === null ? null : (string) $parameter->type->type,
            'parameterDeclared' => $parameter?->declaredType === null ? null : (string) $parameter->declaredType->type,
            'returnBool' => $native->returnType !== null && $context->types->equals($native->returnType->type, Type::bool()),
            'returnCompatible' => self::booleanReturn($context, $native),
            'physicalReturnBool' => $native->declaredReturnType !== null && !$native->declaredReturnType->fromDocblock && !$native->declaredReturnType->inferred && $context->types->equals($native->declaredReturnType->type, Type::bool()),
            'effectiveBranchSummary' => self::branchSummary($native),
            'returnEffective' => $native->returnType === null ? null : (string) $native->returnType->type,
            'returnDeclared' => $native->declaredReturnType === null ? null : (string) $native->declaredReturnType->type,
            'templates' => $native->templates, 'globals' => $native->globalsAccessed, 'methodStatic' => $native->static];
    }

    private static function booleanReturn(IssueFilterContext $context, FunctionLikeMetadata $native): bool
    {
        $type=$native->returnType?->type;
        if($type===null){return false;}
        if($context->types->equals($type,Type::bool())){return true;}
        if(count($type->atomicTypes)!==1||!$type->atomicTypes[0] instanceof \Mago\Sdk\Analyzer\Type\ConditionalType){return false;}
        $conditional=$type->atomicTypes[0];
        return self::conditionalBranches(['thenTrue'=>$context->types->equals($conditional->then,Type::true()),
            'otherwiseFalse'=>$context->types->equals($conditional->otherwise,Type::false()),'nonnegated'=>!$conditional->negated]);
    }

    private static function conditionalBranches(array $branches): bool
    {
        return ($branches['thenTrue']??false)===true && ($branches['otherwiseFalse']??false)===true && ($branches['nonnegated']??false)===true;
    }

    private static function branchSummary(FunctionLikeMetadata $native): ?array
    {
        $type=$native->returnType?->type;
        $conditional=count($type?->atomicTypes??[])===1?$type->atomicTypes[0]:null;
        if(!$conditional instanceof \Mago\Sdk\Analyzer\Type\ConditionalType){return null;}
        return ['subject'=>(string)$conditional->subject,'target'=>(string)$conditional->target,'then'=>(string)$conditional->then,'otherwise'=>(string)$conditional->otherwise,'negated'=>$conditional->negated];
    }

    public static function contract(array $facts, string $builtin): bool
    {
        return ! ($facts['missing'] ?? false) && $facts['kind'] === 'Function_' && $facts['identifierKind'] === 'Function_'
            && $facts['identifierClass'] === null && strtolower($facts['identifierName']) === $builtin
            && $facts['builtin'] === true && $facts['userDefined'] === false && $facts['parameterCount'] === 1
            && $facts['parameterByReference'] === false && $facts['parameterVariadic'] === false
            && $facts['parameterDefault'] === null && $facts['parameterOut'] === null && $facts['parameterClosureThis'] === null
            && $facts['parameterTypeMixed'] === true && $facts['parameterPhysicalMixed'] === true && $facts['returnCompatible'] === true && $facts['physicalReturnBool'] === true
            && $facts['templates'] === [] && $facts['globals'] === [] && $facts['methodStatic'] === false;
    }

    public static function sourceProofs(array $nodes): array
    {
        $proofs = [];
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Namespace_) { $proofs = [...$proofs, ...self::container($node->stmts, 'namespace')]; }
        }
        return [...self::container($nodes, 'root'), ...$proofs];
    }

    private static function container(array $nodes, string $container): array
    {
        $proofs = [];
        foreach ($nodes as $index => $node) {
            if ($node instanceof Node\Stmt\TryCatch && $node->finally === null) {
                $proofs = [...$proofs, ...self::straightLine($node->stmts, $container.'-try', array_slice($nodes, 0, $index))];
            }
        }
        return [...self::straightLine($nodes, $container, []), ...$proofs];
    }

    private static function straightLine(array $nodes, string $container, array $outerPrefix): array
    {
        $proofs = [];
        foreach ($nodes as $index => $node) {
            $binding = $node instanceof Node\Stmt\Expression ? $node->expr : null;
            if (! $binding instanceof Node\Expr\Assign || ! self::variable($binding->var)
                || ! $binding->expr instanceof Node\Expr\BinaryOp\Coalesce || ! self::emptyArray($binding->expr->right)
                || ! self::argv($binding->expr->left)) { continue; }
            $raw = $binding->var->name;
            $listStatement = $nodes[$index + 1] ?? null;
            $listBinding = $listStatement instanceof Node\Stmt\Expression ? $listStatement->expr : null;
            $guard = $nodes[$index + 2] ?? null;
            if (! $listBinding instanceof Node\Expr\Assign || ! self::variable($listBinding->var) || ! self::emptyArray($listBinding->expr)
                || $listBinding->var->name === $raw || ! $guard instanceof Node\Stmt\If_ || $guard->else !== null || $guard->elseifs !== []
                || count($guard->stmts) !== 1 || ! self::predicateCall($guard->cond, $raw)) { continue; }
            $loop = $guard->stmts[0];
            if (! $loop instanceof Node\Stmt\Foreach_ || ! self::variable($loop->expr, $raw) || $loop->keyVar !== null || $loop->byRef
                || ! self::variable($loop->valueVar) || count($loop->stmts) !== 2) { continue; }
            $element = $loop->valueVar->name; $list = $listBinding->var->name;
            if (count(array_unique([$raw, $element, $list])) !== 3) { continue; }
            $reject = $loop->stmts[0]; $appendStatement = $loop->stmts[1];
            if (! $reject instanceof Node\Stmt\If_ || $reject->else !== null || $reject->elseifs !== [] || count($reject->stmts) !== 1
                || ! $reject->cond instanceof Node\Expr\BooleanNot || ! self::predicateCall($reject->cond->expr, $element)
                || ! self::terminates($reject->stmts[0])) { continue; }
            $append = $appendStatement instanceof Node\Stmt\Expression ? $appendStatement->expr : null;
            if (! $append instanceof Node\Expr\Assign || ! $append->var instanceof Node\Expr\ArrayDimFetch || $append->var->dim !== null
                || ! self::variable($append->var->var, $list) || ! self::variable($append->expr, $element)) { continue; }
            $prefix = [...$outerPrefix, ...array_slice($nodes, 0, $index)];
            if (! self::prefixSafe($prefix, [$raw, $list, $element]) || ! self::segmentSafe([$node, $listStatement, $guard])) { continue; }
            $proofs[] = ['container' => $container, 'binding' => $binding, 'listBinding' => $listBinding, 'guard' => $guard, 'loop' => $loop,
                'arrayCall' => $guard->cond, 'stringCall' => $reject->cond->expr, 'stringReject' => $reject->cond, 'append' => $append,
                'raw' => $raw, 'list' => $list, 'element' => $element];
        }
        return $proofs;
    }

    private static function prefixSafe(array $prefix, array $locals): bool
    {
        return (new NodeFinder())->findFirst($prefix, static function (Node $node) use ($locals): bool {
            if ($node instanceof Node\Expr\Variable && is_string($node->name) && in_array($node->name, $locals, true)) { return true; }
            if ($node instanceof Node\Expr\AssignRef || $node instanceof Node\Stmt\Global_ || $node instanceof Node\Stmt\Static_ || $node instanceof Node\Expr\Eval_
                || $node instanceof Node\Expr\Variable && ! is_string($node->name) || $node instanceof Node\Arg && $node->byRef) { return true; }
            if ($node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignOp || $node instanceof Node\Expr\PreInc || $node instanceof Node\Expr\PreDec || $node instanceof Node\Expr\PostInc || $node instanceof Node\Expr\PostDec) {
                return (new NodeFinder())->findFirst([$node->var], static fn (Node $value): bool => $value instanceof Node\Expr\Variable && $value->name === '_SERVER') !== null;
            }
            if ($node instanceof Node\Stmt\Unset_) { return (new NodeFinder())->findFirst($node->vars, static fn (Node $value): bool => $value instanceof Node\Expr\Variable && $value->name === '_SERVER') !== null; }
            if ($node instanceof Node\Expr\FuncCall) {
                return ! $node->name instanceof Node\Name || in_array(strtolower($node->name->toString()), ['extract', 'parse_str'], true);
            }
            return false;
        }) === null;
    }

    private static function segmentSafe(array $nodes): bool
    {
        return (new NodeFinder())->findFirst($nodes, static fn (Node $node): bool => $node instanceof Node\Expr\AssignRef || $node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction
            || $node instanceof Node\Arg && ($node->byRef || $node->unpack || $node->name !== null)
            || $node instanceof Node\Expr\Variable && ! is_string($node->name)
            || array_filter($node->getComments(), static fn ($comment): bool => preg_match('/@(?:phpstan-|psalm-)?var\b/', $comment->getText()) === 1) !== []) === null;
    }

    private static function terminates(Node $node): bool
    {
        if (! $node instanceof Node\Stmt\Expression) { return false; }
        if ($node->expr instanceof Node\Expr\Exit_) { return $node->expr->expr === null || $node->expr->expr instanceof Node\Scalar\Int_ || $node->expr->expr instanceof Node\Scalar\String_; }
        return $node->expr instanceof Node\Expr\Throw_ && $node->expr->expr instanceof Node\Expr\New_
            && $node->expr->expr->class instanceof Node\Name && count($node->expr->expr->args) <= 1
            && ($node->expr->expr->args === [] || $node->expr->expr->args[0]->value instanceof Node\Scalar\String_);
    }

    private static function predicateCall(Node $node, string $local): bool
    {
        return $node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name && ! $node->isFirstClassCallable()
            && count($node->args) === 1 && self::variable($node->args[0]->value, $local);
    }

    private static function argv(Node $node): bool { return $node instanceof Node\Expr\ArrayDimFetch && $node->var instanceof Node\Expr\Variable && $node->var->name === '_SERVER' && $node->dim instanceof Node\Scalar\String_ && $node->dim->value === 'argv'; }

    private static function emptyArray(Node $node): bool { return $node instanceof Node\Expr\Array_ && $node->items === []; }

    private static function variable(Node $node, ?string $name = null): bool { return $node instanceof Node\Expr\Variable && is_string($node->name) && ! in_array($node->name, ['this','GLOBALS','_SERVER','_GET','_POST','_REQUEST','_ENV','argv','argc'], true) && ($name === null || $node->name === $name); }

    private static function key(Node $node): string { return $node->getStartFilePos().':'.($node->getEndFilePos() + 1); }

    private static function certificate(array $proof): array
    {
        $result = array_intersect_key($proof, array_flip(['container','raw','list','element']));
        foreach (['binding','listBinding','guard','loop','arrayCall','stringCall','stringReject','append'] as $key) { $result[$key.'Span'] = self::key($proof[$key]); }
        return $result;
    }
}
