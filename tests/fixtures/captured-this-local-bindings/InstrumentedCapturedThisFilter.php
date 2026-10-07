<?php

declare (strict_types=1);
namespace Example\LocalAppCapture;
use Ichinya\Laramago\Analyzer\CapturedThisStorageContract;

use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Reporting\AnnotationKind;
use Mago\Sdk\Reporting\Level;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
/** PHPStan's HasMethod fact for one captured-this arrow in the immediate true branch. */
final class CapturedThisMethodExistsFilter implements IssueFilterHook
{
    public function __construct(private readonly string $root)
    {
    }
    public function getCodes(): array
    {
        return ['non-existent-method'];
    }
    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        return $this->inspect($context)['remove'] ? IssueFilterDecision::Remove : IssueFilterDecision::Keep;
    }
    public function inspect(IssueFilterContext $context): array
    {
        $issue = $context->issue;
        if ($context->cancellation->isCancelled() || $issue->level !== Level::Error || $issue->code !== 'non-existent-method' || $issue->notes !== [] || $issue->edits !== [] || $issue->link !== null || count($issue->annotations) !== 2 || strlen($context->contents) > 1024 * 1024 || preg_match('/^Method `([a-zA-Z_][a-zA-Z0-9_]*)` does not exist on type `([a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*)`\.$/D', $issue->message, $match) !== 1 || $issue->help !== 'Ensure the `' . $match[1] . '` method is defined in the `' . $match[2] . '` class-like.') {
            return ['remove'=>false,'sourceReturnLine'=>__LINE__,'storageProof'=>$transportProof??null,'caller'=>isset($native)?['class'=>$native->identifier->class,'method'=>$native->originalName]:null];
        }
        [$primary, $secondary] = $issue->annotations;
        if ($primary->kind !== AnnotationKind::Primary || $secondary->kind !== AnnotationKind::Secondary || $primary->file !== null || $secondary->file !== null || $primary->message !== 'This method selection is invalid' || $secondary->message !== 'This expression has type `' . $match[2] . '`' || $primary->span->start < 0 || $primary->span->end > strlen($context->contents) || $secondary->span->start < 0 || $secondary->span->end > strlen($context->contents) || substr($context->contents, $secondary->span->start, $secondary->span->end - $secondary->span->start) !== '$this') {
            return ['remove'=>false,'sourceReturnLine'=>__LINE__,'storageProof'=>$transportProof??null,'caller'=>isset($native)?['class'=>$native->identifier->class,'method'=>$native->originalName]:null];
        }
        $class = $context->codebase->getClass($match[2]);
        if ($class === null || $class->hasIncompleteHierarchy() || $class->flags->contains(MetadataFlags::FINAL) || $class->templates !== [] || $class->mixins !== [] || $class->pseudoMethods !== [] || $context->codebase->methodExists($class->name, $match[1])) {
            return ['remove'=>false,'sourceReturnLine'=>__LINE__,'storageProof'=>$transportProof??null,'caller'=>isset($native)?['class'=>$native->identifier->class,'method'=>$native->originalName]:null];
        }
        try {
            $nodes = (new NodeTraverser(new NameResolver()))->traverse((new ParserFactory())->createForNewestSupportedVersion()->parse($context->contents) ?? []);
        } catch (\PhpParser\Error) {
            return ['remove'=>false,'sourceReturnLine'=>__LINE__,'storageProof'=>$transportProof??null,'caller'=>isset($native)?['class'=>$native->identifier->class,'method'=>$native->originalName]:null];
        }
        $finder = new NodeFinder();
        foreach ($finder->findInstanceOf($nodes, Node\Stmt\Class_::class) as $declaration) {
            if (strcasecmp($declaration->namespacedName?->toString() ?? '', $class->name) !== 0 || $declaration->isFinal() || $declaration->isAnonymous() || !$this->sameFile($context->file, $class->location->file) || $class->location->span->start > $declaration->getStartFilePos() || $class->location->span->end <= $declaration->getEndFilePos()) {
                continue;
            }
            foreach ($declaration->getMethods() as $scope) {
                if ($scope->getStartFilePos() > $primary->span->start || $scope->getEndFilePos() < $primary->span->end) {
                    continue;
                }
                $native = $context->codebase->getDeclaringMethod($class->name, $scope->name->name);
                if ($scope->isStatic() || $scope->byRef || $scope->attrGroups !== [] || $native === null || strcasecmp($native->identifier->class ?? '', $class->name) !== 0 || $native->static || $native->flags->contains(MetadataFlags::BY_REFERENCE) || !$this->sameFile($context->file, $native->location->file) || $native->location->span->start > $scope->getStartFilePos() || $native->location->span->end <= $scope->getEndFilePos()) {
                    continue;
                }
                foreach ($finder->findInstanceOf($scope->stmts ?? [], Node\Stmt\If_::class) as $if) {
                    if ($if->getStartFilePos() > $primary->span->start || $if->getEndFilePos() < $primary->span->end) {
                        continue;
                    }
                    $condition = $if->cond;
                    if ($condition instanceof Node\Expr\BinaryOp\BooleanAnd) {
                        if (!$condition->left instanceof Node\Expr\Isset_) {
                            continue;
                        }
                        $condition = $condition->right;
                    }
                    if (!self::guard($condition, $match[1]) || !self::nativeFunction($context, $condition, $class->name)) {
                        continue;
                    }
                    if (count($if->stmts) !== 1 || !$if->stmts[0] instanceof Node\Stmt\Expression) {
                        continue;
                    }
                    $registration = $if->stmts[0]->expr;
                    if (!$registration instanceof Node\Expr\MethodCall || !$registration->name instanceof Node\Identifier || !$registration->var instanceof Node\Expr\Variable || !is_string($registration->var->name) || $registration->var->name === 'this' || count($registration->args) !== 1 || !$registration->args[0] instanceof Node\Arg || $registration->args[0]->name !== null || $registration->args[0]->byRef || $registration->args[0]->unpack) {
                        continue;
                    }
                    $transportProof = CapturedThisStorageContract::prove($context, $this->root, $scope, $native, $registration);
                    if (!$transportProof['admitted']) {
                        continue;
                    }
                    $arrow = $registration->args[0]->value;
                    if (!$arrow instanceof Node\Expr\ArrowFunction || $arrow->static || $arrow->byRef || $arrow->params !== [] || $arrow->attrGroups !== [] || $arrow->returnType !== null || !$arrow->expr instanceof Node\Expr\MethodCall) {
                        continue;
                    }
                    $call = $arrow->expr;
                    if (!$call->var instanceof Node\Expr\Variable || $call->var->name !== 'this' || !$call->name instanceof Node\Identifier || strcasecmp($call->name->name, $match[1]) !== 0 || $call->name->getStartFilePos() !== $primary->span->start || $call->name->getEndFilePos() + 1 !== $primary->span->end || $call->var->getStartFilePos() !== $secondary->span->start || $call->var->getEndFilePos() + 1 !== $secondary->span->end) {
                        continue;
                    }
                    foreach ($call->args as $argument) {
                        if (!$argument instanceof Node\Arg || $argument->byRef || $argument->unpack || $argument->name !== null) {
                            return ['remove'=>false,'sourceReturnLine'=>__LINE__,'storageProof'=>$transportProof??null,'caller'=>isset($native)?['class'=>$native->identifier->class,'method'=>$native->originalName]:null];
                        }
                    }
                    // Exclude closures nested in another scope: their $this may be bound elsewhere.
                    foreach ($finder->findInstanceOf($scope->stmts ?? [], Node\FunctionLike::class) as $nested) {
                        if ($nested !== $arrow && $nested->getStartFilePos() < $call->getStartFilePos() && $nested->getEndFilePos() >= $call->getEndFilePos()) {
                            return ['remove'=>false,'sourceReturnLine'=>__LINE__,'storageProof'=>$transportProof??null,'caller'=>isset($native)?['class'=>$native->identifier->class,'method'=>$native->originalName]:null];
                        }
                    }
                    return ['remove'=>true,'sourceReturnLine'=>__LINE__,'storageProof'=>$transportProof??null,'caller'=>isset($native)?['class'=>$native->identifier->class,'method'=>$native->originalName]:null];
                }
            }
        }
        return ['remove'=>false,'sourceReturnLine'=>__LINE__,'storageProof'=>$transportProof??null,'caller'=>isset($native)?['class'=>$native->identifier->class,'method'=>$native->originalName]:null];
    }
    private static function guard(Node\Expr $node, string $method): bool
    {
        return $node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name && strcasecmp($node->name->toString(), 'method_exists') === 0 && count($node->args) === 2 && $node->args[0] instanceof Node\Arg && $node->args[1] instanceof Node\Arg && $node->args[0]->name === null && $node->args[1]->name === null && !$node->args[0]->unpack && !$node->args[1]->unpack && !$node->args[0]->byRef && !$node->args[1]->byRef && $node->args[0]->value instanceof Node\Expr\Variable && $node->args[0]->value->name === 'this' && $node->args[1]->value instanceof Node\Scalar\String_ && strcasecmp($node->args[1]->value->value, $method) === 0;
    }
    private static function nativeFunction(IssueFilterContext $context, Node\Expr\FuncCall $guard, string $class): bool
    {
        $function = $context->codebase->getFunction('method_exists');
        if ($function === null || !$function->flags->contains(MetadataFlags::BUILTIN) || $function->flags->contains(MetadataFlags::BY_REFERENCE)) {
            return false;
        }
        $original = $guard->name->getAttribute('originalName');
        if (!$guard->name->isFullyQualified() || $original instanceof Node\Name && !$original->isFullyQualified()) {
            $separator = strrpos($class, '\\');
            $namespace = $separator === false ? '' : substr($class, 0, $separator);
            if ($namespace !== '' && $context->codebase->getFunction($namespace . '\method_exists') !== null) {
                return false;
            }
        }
        return true;
    }
    private function sameFile(string $left, ?string $right): bool
    {
        if ($right === null) {
            return false;
        }
        $normalize = function (string $path): string {
            $path = str_replace('\\', '/', $path);
            if (str_starts_with($path, '//?/')) {
                $path = substr($path, 4);
            }
            if (!str_starts_with($path, '/') && preg_match('~^[A-Za-z]:/~', $path) !== 1) {
                $path = str_replace('\\', '/', $this->root) . '/' . $path;
            }
            return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
        };
        return $normalize($left) === $normalize($right);
    }
}
