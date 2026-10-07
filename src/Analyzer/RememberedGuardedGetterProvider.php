<?php

declare (strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ModelReflection;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\CodebaseScanContext;
use Mago\Sdk\Analyzer\CodebaseScanHook;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\InvocationKind;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\Visibility;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
/** One adjacent successful instanceof fact for a source-owned direct getter. */
final class RememberedGuardedGetterProvider implements MethodReturnTypeProvider, InitializationHook
{
    /** @var array<string, array<string, mixed>|null> */
    private array $candidates = [];
    private bool $complete = false;
    private bool $failed = false;
    private int $bytes = 0;
    public function __construct(private readonly string $root)
    {
    }
    public function getTargets(): array
    {
        return [new MethodTarget('*', '*')];
    }
    public function initialize(InitializationContext $context): void
    {
        $this->reset();
    }
    private function reset(): void
    {
        $this->candidates = [];
        $this->complete = $this->failed = false;
        $this->bytes = 0;
    }
    public function scan(CodebaseScanContext $context): void
    {
        if ($context->firstBatch) {
            $this->reset();
        }
        $this->complete = false;
        foreach ($context->files as $file) {
            $this->bytes += strlen($file->contents);
            if ($this->bytes > 64 * 1024 * 1024 || strlen($file->contents) > 1024 * 1024) {
                $this->failed = true;
                break;
            }
            try {
                $nodes = (new NodeTraverser(new NameResolver()))->traverse((new ParserFactory())->createForNewestSupportedVersion()->parse($file->contents) ?? []);
            } catch (\PhpParser\Error) {
                $this->failed = true;
                break;
            }
            $finder = new NodeFinder();
            $local = [];
            foreach ($finder->findInstanceOf($nodes, Node\Stmt\If_::class) as $if) {
                $or = $if->cond instanceof Node\Expr\BinaryOp\BooleanOr || $if->cond instanceof Node\Expr\BinaryOp\LogicalOr;
                $and = $if->cond instanceof Node\Expr\BinaryOp\BooleanAnd || $if->cond instanceof Node\Expr\BinaryOp\LogicalAnd;
                if (!$or && !$and) {
                    continue;
                }
                $parts = self::parts($if->cond, $or);
                foreach ($parts as $index => $part) {
                    $guard = $or && $part instanceof Node\Expr\BooleanNot ? $part->expr : ($and ? $part : null);
                    if (!$guard instanceof Node\Expr\Instanceof_ || !$guard->class instanceof Node\Name || !self::getter($guard->expr)) {
                        continue;
                    }
                    $use = isset($parts[$index + 1]) ? self::firstEvaluatedGetter($parts[$index + 1]) : null;
                    if ($use === null || !self::sameGetter($guard->expr, $use)) {
                        continue;
                    }
                    $local[self::key($use)] = ['file' => $file->path, 'hash' => hash('sha256', $file->contents), 'observed' => $guard->expr, 'call' => $use, 'asserted' => $guard->class->toString()];
                }
            }
            foreach ($finder->findInstanceOf($nodes, Node\Expr\CallLike::class) as $call) {
                $key = self::key($call);
                $this->candidates[$key] = array_key_exists($key, $this->candidates) ? null : $local[$key] ?? null;
                if (count($this->candidates) > 100000) {
                    $this->failed = true;
                    break;
                }
            }
        }
        if ($this->failed) {
            $this->candidates = [];
        }
        $this->complete = $context->lastBatch && !$this->failed;
    }
    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $call = $context->invocation;
        $candidate = $this->complete ? $this->candidates[$call->span->start . ':' . $call->span->end] ?? null : null;
        $receiver = $call->receiverType?->atomicTypes[0] ?? null;
        if ($candidate === null || $call->kind !== InvocationKind::InstanceMethod || $call->arguments !== [] || count($call->receiverType?->atomicTypes ?? []) !== 1 || !$receiver instanceof NamedObjectType || $receiver->static || $receiver->isThis || ($receiver->intersections ?? []) !== [] || ($receiver->parameters ?? []) !== [] || strcasecmp($call->name, $candidate['call']->name->name) !== 0 || strcasecmp($call->declaringClass ?? '', $receiver->name) !== 0) {
            return null;
        }
        $source = new PhpSource($this->root);
        $path = $source->path($candidate['file']);
        if ($candidate['hash'] !== @hash_file('sha256', $path)) {
            return null;
        }
        $method = $context->codebase->getDeclaringMethod($receiver->name, $call->name);
        $owner = $method === null ? null : $context->codebase->getClass($method->identifier->class ?? '');
        $asserted = $context->codebase->getClass($candidate['asserted']);
        $node = $method === null ? null : (new ModelReflection($context->codebase, $source))->methodNode($method);
        if ($method === null || $owner === null || $asserted === null || $owner->hasIncompleteHierarchy() || $asserted->hasIncompleteHierarchy() || $asserted->flags->contains(MetadataFlags::ABSTRACT) || $asserted->templates !== [] || $owner->templates !== [] || $method->static || $method->abstract || $method->visibility !== Visibility::Public || $method->parameters !== [] || $method->flags->contains(MetadataFlags::BY_REFERENCE) || $method->attributes !== [] || $method->templates !== [] || $method->globalsAccessed !== [] || $method->returnType === null || $method->returnType->type->flags->byReference || $node === null || $node->byRef || preg_match('/@(?:phpstan-|psalm-|mago-)?impure\b/i', $node->getDocComment()?->getText() ?? '') === 1 || count($node->stmts ?? []) !== 1 || !$node->stmts[0] instanceof Node\Stmt\Return_) {
            return null;
        }
        $read = $node->stmts[0]->expr;
        if (!$read instanceof Node\Expr\PropertyFetch || !$read->var instanceof Node\Expr\Variable || $read->var->name !== 'this' || !$read->name instanceof Node\Identifier) {
            return null;
        }
        $property = $context->codebase->getDeclaringProperty($owner->name, '$' . $read->name->name);
        if ($property === null || $property->hooks !== [] || $property->flags->contains(MetadataFlags::VIRTUAL_PROPERTY) || $property->flags->contains(MetadataFlags::BY_REFERENCE) || $property->flags->contains(MetadataFlags::STATIC)) {
            return null;
        }
        $result = Type::namedObject($asserted->name);
        if (!$context->types->isContainedBy($result, $method->returnType->type)) {
            return null;
        }
        return $result;
    }
    private static function parts(Node\Expr $node, bool $or): array
    {
        if ($or && ($node instanceof Node\Expr\BinaryOp\BooleanOr || $node instanceof Node\Expr\BinaryOp\LogicalOr) || !$or && ($node instanceof Node\Expr\BinaryOp\BooleanAnd || $node instanceof Node\Expr\BinaryOp\LogicalAnd)) {
            return [...self::parts($node->left, $or), ...self::parts($node->right, $or)];
        }
        return [$node];
    }
    private static function firstEvaluatedGetter(Node\Expr $node, int $depth = 0): ?Node\Expr\MethodCall
    {
        if ($depth > 12) {
            return null;
        }
        if (self::getter($node)) {
            return $node;
        }
        if ($node instanceof Node\Expr\BooleanNot) {
            return self::firstEvaluatedGetter($node->expr, $depth + 1);
        }
        if ($node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier) {
            return self::firstEvaluatedGetter($node->var, $depth + 1);
        }
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
            $arg = $node->args[0] ?? null;
            return $arg instanceof Node\Arg && $arg->name === null && !$arg->unpack && !$arg->byRef ? self::firstEvaluatedGetter($arg->value, $depth + 1) : null;
        }
        return null;
    }
    private static function getter(Node\Expr $node): bool
    {
        return $node instanceof Node\Expr\MethodCall && $node->var instanceof Node\Expr\Variable && is_string($node->var->name) && $node->var->name !== 'this' && $node->name instanceof Node\Identifier && $node->args === [];
    }
    private static function sameGetter(Node\Expr\MethodCall $left, Node\Expr\MethodCall $right): bool
    {
        return $left->var->name === $right->var->name && strcasecmp($left->name->name, $right->name->name) === 0;
    }
    private static function key(Node $node): string
    {
        return $node->getStartFilePos() . ':' . ($node->getEndFilePos() + 1);
    }
}
final class RememberedGuardedGetterScan implements CodebaseScanHook
{
    public function __construct(private readonly RememberedGuardedGetterProvider $provider)
    {
    }
    public function getTargets(): array
    {
        return ['**'];
    }
    public function scan(CodebaseScanContext $context): void
    {
        $this->provider->scan($context);
    }
}
