<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\CodebaseScanContext;
use Mago\Sdk\Analyzer\CodebaseScanHook;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeKind;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\CallableParameter;
use Mago\Sdk\Analyzer\Type\CallableSignature;
use Mago\Sdk\Analyzer\Type\CallableType;
use Mago\Sdk\Analyzer\Type\ConditionalType;
use Mago\Sdk\Analyzer\Type\FunctionLikeIdentifier;
use Mago\Sdk\Analyzer\Type\VariableType;
use Mago\Sdk\Analyzer\Type\Visibility;
use Mago\Sdk\Analyzer\TypeComparator;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** Possible writes from literal stored callbacks; native signatures and callback scheduling remain unchanged. */
final class StoredReferencePossibleWrites implements InitializationHook, CodebaseScanHook
{
    private array $files = [];
    private array $classes = [];
    private array $hydrated = [];
    private int $hydratedBytes = 0;
    private bool $started = false;
    private bool $complete = false;
    private bool $failed = false;
    private int $bytes = 0;
    private ?array $scanFailure = null;
    private readonly StoredCallbackSourceDeclarations $sources;

    public function __construct(private readonly string $root = '.') { $this->sources = new StoredCallbackSourceDeclarations($root); }
    public function getTargets(): array { return ['**']; }
    public function initialize(InitializationContext $context): void {
        $this->files = $this->classes = $this->hydrated = []; $this->hydratedBytes = 0; $this->started = $this->complete = $this->failed = false; $this->bytes = 0; $this->scanFailure = null;
        $this->sources->initialize($context);
    }
    public function scan(CodebaseScanContext $context): void {
        if ($context->firstBatch) { $this->files = $this->classes = $this->hydrated = []; $this->hydratedBytes = 0; $this->started = true; $this->failed = false; $this->bytes = 0; $this->scanFailure = null; }
        elseif (!$this->started || $this->complete) { $this->scanFailure ??= ['reason' => 'unexpected-scan-lifecycle', 'started' => $this->started, 'previousComplete' => $this->complete]; $this->failed = true; }
        $this->complete = false; $this->sources->scan($context);
        foreach ($context->files as $file) {
            $path = RefreshedModelProperties::path($file->path); $this->bytes += strlen($file->contents);
            if ($context->cancellation->isCancelled() || $this->failed || $path === '' || isset($this->files[$path])
                || strlen($file->contents) > 2_000_000 || $this->bytes > 67_108_864 || count($this->files) >= 100_000) {
                $this->scanFailure ??= ['reason' => $context->cancellation->isCancelled() ? 'cancelled' : ($this->failed ? 'already-failed'
                    : ($path === '' ? 'empty-path' : (isset($this->files[$path]) ? 'duplicate-file-path'
                    : (strlen($file->contents) > 2_000_000 ? 'source-file-bound' : ($this->bytes > 67_108_864 ? 'total-source-bound' : 'source-file-count-bound'))))),
                    'file' => $file->path, 'sha256' => hash('sha256', $file->contents), 'bytes' => strlen($file->contents),
                    'totalBytes' => $this->bytes, 'filesBeforeFailure' => count($this->files)];
                $this->failed = true; break;
            }
            try {
                $nodes = self::parse($file->contents);
            } catch (\PhpParser\Error|\LogicException $failure) {
                $this->scanFailure ??= ['reason' => 'source-parse-or-name-resolution', 'file' => $file->path,
                    'sha256' => hash('sha256', $file->contents), 'exceptionClass' => $failure::class, 'message' => $failure->getMessage(),
                    'totalBytes' => $this->bytes, 'filesBeforeFailure' => count($this->files)];
                $this->failed = true; break;
            }
            $entry = ['file' => $file->path, 'diskFile' => $this->disk($file->path), 'contents' => $file->contents, 'hash' => hash('sha256', $file->contents), 'proofs' => []];
            if ((new NodeFinder)->findFirst($nodes, static fn (Node $node): bool => $node instanceof Node\DeclareItem && strtolower($node->key->name) === 'ticks') === null) {
                $pending = $nodes;
                while ($pending !== []) {
                    $node = array_shift($pending);
                    if ($node instanceof Node\Stmt\Namespace_) { array_push($pending, ...$node->stmts); continue; }
                    if (!$node instanceof Node\Stmt\Class_ || $node->name === null || $node->namespacedName === null) { continue; }
                    $name = strtolower($node->namespacedName->toString());
                    if (isset($this->classes[$name])) {
                        $previous = $this->classes[$name];
                        $this->scanFailure ??= ['reason' => 'duplicate-source-class-name', 'name' => $name,
                            'file' => $file->path, 'sha256' => $entry['hash'], 'span' => [$node->getStartFilePos(), $node->getEndFilePos() + 1],
                            'previous' => ['file' => $previous['entry']['file'], 'sha256' => $previous['entry']['hash'],
                                'span' => [$previous['start'], $previous['end']]], 'totalBytes' => $this->bytes, 'filesBeforeFailure' => count($this->files)];
                        $this->failed = true; break 2;
                    }
                    $this->classes[$name] = ['name' => $node->namespacedName->toString(), 'start' => $node->getStartFilePos(),
                        'end' => $node->getEndFilePos() + 1, 'entry' => array_diff_key($entry, ['contents' => true, 'proofs' => true])];
                    foreach ($node->getMethods() as $method) {
                        foreach (self::candidates($method) as $proof) { $entry['proofs'][] = $proof + ['scope' => $method, 'owner' => $node->namespacedName->toString(), 'callerClass' => $node] + $entry; }
                    }
                }
            }
            // Full source bytes and AST nodes are retained only by actual candidate proofs.
            $this->files[$path] = array_diff_key($entry, ['contents' => true]);
        }
        if ($context->cancellation->isCancelled()) { $this->scanFailure ??= ['reason' => 'cancelled-after-scan']; $this->failed = true; }
        if ($this->failed) { $this->files = $this->classes = $this->hydrated = []; $this->hydratedBytes = 0; }
        $this->complete = $context->lastBatch && $this->started && !$this->failed;
    }
    public function proofs(string $file, string $contents): array {
        $entry = $this->complete && $this->started && !$this->failed ? ($this->files[RefreshedModelProperties::path($file)] ?? null) : null;
        return $entry !== null && $entry['hash'] === hash('sha256', $contents) && self::current($entry) ? $entry['proofs'] : [];
    }
    public static function current(array $entry): bool { return StoredCallbackSourceDeclarations::current($entry); }

    /** Return only a realizable integer writer domain, not a claim about invocation order or exact cache values. */
    public function domain(Codebase $codebase, TypeComparator $types, array $proof): ?Type {
        $proof['types'] = $types;
        if (!$this->complete || !$this->started || $this->failed || !self::current($proof) || !$this->sources->nativeClosure($codebase)
            || $this->sources->method($codebase, $types, $proof['owner'], $proof['scope']->name->name) === null) { return null; }
        $store = $proof['configure']->params[0]->type->toString();
        $declaration = $this->declaration($store);
        if ($declaration === null || $this->sources->classSource($codebase, $store) === null || !self::current($declaration['entry'])) { return null; }
        $source = $declaration['node'];
        // A possible reference write does not require construction or callback dispatch to complete.
        if ($source->isAbstract()) { return null; }
        $reader = $this->field($codebase, $types, $declaration, $proof['readerProperty']);
        $writer = $this->field($codebase, $types, $declaration, $proof['writerProperty']);
        if ($reader === null || $writer === null || !$reader->closure || !$writer->closure
            || count($reader->parameters) !== 2 || count($writer->parameters) !== 4
            || $reader->returnType === null || !$types->equals($reader->returnType, Type::mixed())
            || $writer->returnType === null || !$types->equals($writer->returnType, Type::bool())
            || !$this->closure($codebase, $types, $proof['configure'], $proof, false)
            || !$this->closure($codebase, $types, $proof['reader'], $proof, true)
            || !$this->closure($codebase, $types, $proof['writer'], $proof, false)) { return null; }
        // Every ordinary input and the writer result must agree with the physical callback grammar.
        foreach([[$proof['reader'],0,$reader->parameters[0]->type],[$proof['writer'],0,$writer->parameters[0]->type],[$proof['writer'],2,$writer->parameters[2]->type]] as [$closure,$index,$expected]) {
            $physical=self::syntaxType($closure->params[$index]->type??null,$proof['owner']);
            if($physical===null || $expected===null || !$types->equals($physical,$expected)) { return null; }
        }
        foreach([[$proof['reader'],1],[$proof['writer'],3]] as [$closure,$index]) {
            $parameter=$closure->params[$index]->type??null;
            if(!$parameter instanceof Node\Name || strcasecmp($parameter->toString(),'Closure')!==0) { return null; }
        }
        $physicalReturn=self::syntaxType($proof['writer']->returnType,$proof['owner']);
        if($physicalReturn===null || !$types->equals($physicalReturn,Type::bool())) { return null; }
        foreach ([$reader, $writer] as $signature) {
            foreach ($signature->parameters as $parameter) {
                if ($parameter->type === null || $parameter->byReference || $parameter->variadic || $parameter->hasDefault || $parameter->closureThisType !== null) { return null; }
            }
        }
        $input = self::syntaxType($proof['writer']->params[1]->type, $proof['owner']);
        if ($input === null || !$types->isContainedBy(Type::int(), $input) || !$types->equals($input, $writer->parameters[1]->type)
            || !$types->equals($reader->parameters[0]->type, Type::string()) || !$types->equals($writer->parameters[0]->type, Type::string())
            || !$types->equals($writer->parameters[2]->type, Type::int())
            || !self::continuation($reader->parameters[1]->type, [], Type::mixed(), $types)
            || !self::continuation($writer->parameters[3]->type, [$input], Type::bool(), $types)
            || !$this->helper($codebase, $types, $proof, $store) || !self::predicate($codebase, $types, $proof['predicate'])
            || $this->sources->construction($codebase, $types, $proof['throw']->class->toString()) === null) { return null; }
        if (!self::current($proof) || !self::current($declaration['entry'])) { return null; }
        return Type::int();
    }

    private function field(Codebase $codebase, TypeComparator $types, array $declaration, string $property): ?CallableSignature {
        $class = $declaration['node']; $owner = $class->namespacedName->toString();
        $field = $codebase->getDeclaringProperty($owner, '$'.$property); $matches = [];
        foreach ($class->getProperties() as $statement) {
            foreach ($statement->props as $item) { if ($item->name->name === $property) { $matches[] = [$statement, $item]; } }
        }
        if ($field === null || count($matches) !== 1 || $field->name !== '$'.$property || $field->hooks !== []
            || $field->readVisibility !== Visibility::Public || $field->writeVisibility !== Visibility::Public
            || $field->attributes !== [] || $field->declaredType === null || $field->declaredType->fromDocblock || $field->declaredType->inferred
            || $field->type === null || !$field->type->fromDocblock || $field->type->inferred || $field->writeType !== null
            || $field->defaultType === null) { return null; }
        foreach ([MetadataFlags::STATIC, MetadataFlags::READONLY, MetadataFlags::VIRTUAL_PROPERTY, MetadataFlags::PROMOTED_PROPERTY,
            MetadataFlags::ASYMMETRIC_PROPERTY, MetadataFlags::WRITEONLY] as $flag) { if ($field->flags->contains($flag)) { return null; } }
        [$statement, $item] = $matches[0];
        $doc = self::doc($statement, 'var', null, $class);
        $declared = self::syntaxType($statement->type, $owner);
        if (count($statement->props) !== 1 || !$statement->isPublic() || $statement->isStatic() || $statement->isReadonly()
            || $statement->hooks !== [] || $item->default === null || !self::null($item->default) || $doc === null || $declared === null
            || !$types->equals($declared, Type::union(StoredCallbackSourceDeclarations::callableType(true), Type::null()))
            || !$types->equals($declared, $field->declaredType->type) || !$types->equals($doc['type'], $field->type->type)
            || !$types->equals($field->defaultType->type, Type::null()) || !$types->isContainedBy(Type::null(), $doc['type'])
            || !StoredCallbackSourceDeclarations::located($field->nameLocation, $item->name, $declaration['entry']['file'])
            || !StoredCallbackSourceDeclarations::located($field->declaredType->location, $statement->type, $declaration['entry']['file'])
            || !StoredCallbackSourceDeclarations::located($field->defaultType->location, $item->default, $declaration['entry']['file'])
            || !self::docLocated($field->type->location, $doc, $declaration['entry']['file'])
            || $field->location !== null && !StoredCallbackSourceDeclarations::located($field->location, $statement, $declaration['entry']['file'])) { return null; }
        $nonNull = array_values(array_filter($doc['type']->atomicTypes, static fn ($atom): bool => !$atom instanceof \Mago\Sdk\Analyzer\Type\SimpleAtomicType || $atom->kind !== \Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind::Null));
        $atom = count($nonNull) === 1 ? $nonNull[0] : null;
        return $atom instanceof CallableType && $atom->alias === null && $atom->signature !== null && $atom->signature->source === null
            && !$atom->signature->pure && $atom->signature->constraints === [] ? $atom->signature : null;
    }

    /** A literal configuration argument contributes possible writes under PHPStan's by-reference scope policy. */
    private function helper(Codebase $codebase, TypeComparator $types, array $proof, string $store): bool {
        $caller=$this->declaration($proof['owner']);$method=$caller['node']->getMethod($proof['helper'])??null;
        $metadata=$codebase->getDeclaringMethod($proof['owner'],$proof['helper']);
        if($caller===null || $method===null || $metadata===null || !$method->isPrivate() || $method->isStatic() || $method->isAbstract()
            || $method->byRef || $method->attrGroups!==[] || count($method->params)!==1 || $method->stmts===null
            || $metadata->identifier->class===null || strcasecmp($metadata->identifier->class,$proof['owner'])!==0
            || !$this->signature($metadata,$method,$proof,false)) { return false; }
        $parameter=$method->params[0];$local=self::local($parameter->var);$doc=self::doc($method,'param',$local,$caller['node']);$native=$metadata->parameters[0];
        if(!$parameter->type instanceof Node\Identifier || strtolower($parameter->type->name)!=='callable' || $local===null || $doc===null
            || $native->type===null || !$native->type->fromDocblock || $native->type->inferred || !$types->equals($doc['type'],$native->type->type)
            || !self::docLocated($native->type->location,$doc,$proof['file']) || !self::continuation($doc['type'],[Type::namedObject($store)],Type::void(),$types,false)) { return false; }
        $construction=null;$invocation=null;
        foreach($method->stmts as $statement) {
            $expression=$statement instanceof Node\Stmt\Expression?$statement->expr:null;
            if($expression instanceof Node\Expr\Assign && self::local($expression->var)!==null && $expression->expr instanceof Node\Expr\New_
                && $expression->expr->class instanceof Node\Name && strcasecmp($expression->expr->class->toString(),$store)===0 && $expression->expr->args===[]) {
                if($construction!==null){return false;}$construction=$expression;
            }
            if($expression instanceof Node\Expr\FuncCall && self::local($expression->name)===$local && self::args($expression,1)) {
                if($invocation!==null){return false;}$invocation=$expression;
            }
        }
        if($construction===null || $invocation===null || self::local($invocation->args[0]->value)!==self::local($construction->var)
            || $construction->getEndFilePos()>=$invocation->getStartFilePos()) { return false; }
        $object=self::local($construction->var);
        // Between construction and the selected call, only the original binding may be passed by value.
        $intervening=(new NodeFinder)->find($method->stmts,static fn(Node $node):bool=>$node->getStartFilePos()>$construction->getStartFilePos() && $node->getStartFilePos()<$invocation->getEndFilePos()
            && $node instanceof Node\Expr\Variable && $node->name===$object);
        foreach($intervening as $mention) {
            if($mention!==$construction->var && $mention!==$invocation->args[0]->value) { return false; }
        }
        $mentions=(new NodeFinder)->find($method->stmts,static fn(Node $node):bool=>$node instanceof Node\Expr\Variable && $node->name===$local);
        return count($mentions)===1 && self::current($caller['entry']);
    }
    private function closure(Codebase $codebase, TypeComparator $types, Node\Expr\Closure $closure, array $proof, bool $untypedReader): bool {
        if($closure->byRef || $closure->attrGroups!==[] || $closure->getDocComment()!==null || !StoredCallbackSourceDeclarations::closedScope($closure)) { return false; }
        foreach($closure->params as $parameter) {
            if(!is_string($parameter->var->name) || $parameter->byRef || $parameter->variadic || $parameter->default!==null
                || $parameter->attrGroups!==[] || self::syntaxType($parameter->type,$proof['owner'])===null) { return false; }
        }
        return $untypedReader?$closure->returnType===null:self::syntaxType($closure->returnType,$proof['owner'])!==null;
    }
    /** Declared types are authoritative; an inferred never reader return is precisely the stale-reference problem. */
    private function signature(FunctionLikeMetadata $metadata, Node\Stmt\ClassMethod|Node\Expr\Closure $node, array $proof, bool $untypedReader): bool {
        if ($node->byRef || $node->attrGroups !== [] || $metadata->flags->contains(MetadataFlags::BY_REFERENCE) || $metadata->templates !== []
            || $metadata->globalsAccessed !== [] || $metadata->whereConstraints !== [] || $metadata->attributes !== [] || $metadata->assertions !== []
            || $metadata->ifTrueAssertions !== [] || $metadata->ifFalseAssertions !== [] || $metadata->assertionsInferred
            || count($metadata->parameters) !== count($node->params) || !StoredCallbackSourceDeclarations::located($metadata->location, $node, $proof['file'])
            || !StoredCallbackSourceDeclarations::closedScope($node)) { return false; }
        if ($node instanceof Node\Stmt\ClassMethod && ($metadata->kind !== FunctionLikeKind::Method
            || strcasecmp($metadata->originalName, $node->name->name) !== 0 || $metadata->visibility !== Visibility::Private
            || !StoredCallbackSourceDeclarations::located($metadata->nameLocation, $node->name, $proof['file']))) { return false; }
        $declared = self::syntaxType($node->returnType, $proof['owner']);
        if ($untypedReader) {
            if ($node->returnType !== null || $metadata->declaredReturnType !== null || $metadata->returnType?->fromDocblock) { return false; }
        } elseif ($declared === null || $metadata->declaredReturnType === null || $metadata->declaredReturnType->fromDocblock
            || !$proof['types']->equals($declared, $metadata->declaredReturnType->type)
            || $metadata->returnType === null || !$proof['types']->equals($declared, $metadata->returnType->type)) { return false; }
        foreach ($node->params as $position => $parameter) {
            $native = $metadata->parameters[$position]; $type = self::syntaxType($parameter->type, $proof['owner']);
            if (self::local($parameter->var) === null || $native->name !== '$'.$parameter->var->name || $parameter->byRef || $parameter->variadic || $parameter->default !== null
                || $native->flags->contains(MetadataFlags::BY_REFERENCE) || $native->flags->contains(MetadataFlags::VARIADIC) || $native->flags->contains(MetadataFlags::HAS_DEFAULT)
                || $native->defaultType !== null || $native->outType !== null || $native->closureThisType !== null || $native->attributes !== []
                || $type === null || $native->declaredType === null || $native->declaredType->fromDocblock || $native->declaredType->inferred
                || !$proof['types']->equals($type, $native->declaredType->type)
                || !StoredCallbackSourceDeclarations::located($native->location, $parameter, $proof['file'])
                || !StoredCallbackSourceDeclarations::located($native->nameLocation, $parameter->var, $proof['file'])
                || !StoredCallbackSourceDeclarations::located($native->declaredType->location, $parameter->type, $proof['file'])) { return false; }
            if ($node instanceof Node\Expr\Closure && ($native->type === null || $native->type->fromDocblock || $native->type->inferred
                || !$proof['types']->equals($native->type->type, $type))) { return false; }
        }
        return true;
    }

    private static function predicate(Codebase $codebase, TypeComparator $types, Node\Expr\FuncCall $call): bool {
        if (!$call->name instanceof Node\Name || strcasecmp($call->name->toString(), 'is_int') !== 0) { return false; }
        $namespaced = $call->name->getAttribute('namespacedName');
        if (!$call->name instanceof Node\Name\FullyQualified && $namespaced instanceof Node\Name
            && strcasecmp($namespaced->toString(), 'is_int') !== 0 && $codebase->getFunction($namespaced->toString()) !== null) { return false; }
        $metadata = $codebase->getFunction('is_int'); $parameter = $metadata?->parameters[0] ?? null;
        if ($metadata === null || $metadata->kind !== FunctionLikeKind::Function_ || $metadata->identifier->kind !== \Mago\Sdk\Analyzer\Type\FunctionLikeKind::Function_
            || $metadata->identifier->class !== null || $metadata->name !== 'is_int' || $metadata->originalName !== 'is_int'
            || $metadata->identifier->name !== 'is_int' || !$metadata->flags->contains(MetadataFlags::BUILTIN) || $metadata->flags->contains(MetadataFlags::USER_DEFINED)
            || $metadata->flags->contains(MetadataFlags::BY_REFERENCE) || $metadata->templates !== [] || $metadata->whereConstraints !== [] || $metadata->globalsAccessed !== []
            || $metadata->assertions !== [] || $metadata->ifFalseAssertions !== [] || $metadata->assertionsInferred || count($metadata->parameters) !== 1
            || $parameter === null || $parameter->name !== '$value' || $parameter->flags->contains(MetadataFlags::BY_REFERENCE)
            || $parameter->flags->contains(MetadataFlags::VARIADIC) || $parameter->flags->contains(MetadataFlags::HAS_DEFAULT)
            || $parameter->defaultType !== null || $parameter->type === null || $parameter->declaredType === null
            || !$types->equals($parameter->type->type, Type::mixed()) || !$types->equals($parameter->declaredType->type, Type::mixed())
            || $metadata->declaredReturnType === null || !$types->equals($metadata->declaredReturnType->type, Type::bool())) { return false; }
        $return = $metadata->returnType?->type; $conditional = count($return?->atomicTypes ?? []) === 1 ? $return->atomicTypes[0] : null;
        $assertion = $metadata->ifTrueAssertions['$value'][0] ?? null;
        return $conditional instanceof ConditionalType && !$conditional->negated && count($conditional->subject->atomicTypes) === 1
            && $conditional->subject->atomicTypes[0] instanceof VariableType && $conditional->subject->atomicTypes[0]->name === '$value'
            && $types->equals($conditional->target, Type::int()) && $types->equals($conditional->then, Type::true()) && $types->equals($conditional->otherwise, Type::false())
            && array_keys($metadata->ifTrueAssertions) === ['$value'] && count($metadata->ifTrueAssertions['$value']) === 1
            && $assertion instanceof \Mago\Sdk\Analyzer\Assertion\TypeAssertion && $assertion->kind === \Mago\Sdk\Analyzer\Assertion\TypeAssertionKind::IsType
            && $types->equals($assertion->type, Type::int());
    }

    private static function candidates(Node\Stmt\ClassMethod $scope): array {
        if ($scope->stmts === null || !StoredCallbackSourceDeclarations::closedScope($scope) || $scope->byRef || array_filter($scope->params, static fn (Node\Param $parameter): bool => $parameter->byRef) !== []) { return []; }
        $finder = new NodeFinder; $result = [];
        foreach ($finder->findInstanceOf($scope->stmts, Node\Expr\MethodCall::class) as $call) {
            if (self::local($call->var) !== 'this' || !$call->name instanceof Node\Identifier || !self::args($call, 1) || !$call->args[0]->value instanceof Node\Expr\Closure) { continue; }
            $configure = $call->args[0]->value;
            if (count($configure->params) !== 1 || !$configure->params[0]->type instanceof Node\Name || self::local($configure->params[0]->var) === null
                || count($configure->stmts) !== 2 || !$configure->returnType instanceof Node\Identifier || strtolower($configure->returnType->name) !== 'void') { continue; }
            $slots = []; $object = $configure->params[0]->var->name;
            foreach ($configure->stmts as $statement) {
                $assignment = $statement instanceof Node\Stmt\Expression ? $statement->expr : null;
                if (!$assignment instanceof Node\Expr\Assign || !$assignment->var instanceof Node\Expr\PropertyFetch || self::local($assignment->var->var) !== $object
                    || !$assignment->var->name instanceof Node\Identifier || !$assignment->expr instanceof Node\Expr\Closure || !$assignment->expr->static) { continue 2; }
                $slots[] = ['property' => $assignment->var->name->name, 'closure' => $assignment->expr];
            }
            if ($slots[0]['property'] === $slots[1]['property']) { continue; }
            foreach ([$slots, array_reverse($slots)] as [$read, $write]) {
                $reader = $read['closure']; $writer = $write['closure'];
                if (count($reader->params) !== 2 || count($writer->params) !== 4 || count($writer->stmts) !== 2) { continue; }
                $writerAssignment = $writer->stmts[0] instanceof Node\Stmt\Expression ? $writer->stmts[0]->expr : null;
                $cell = $writerAssignment instanceof Node\Expr\Assign ? self::local($writerAssignment->var) : null;
                if ($cell === null || self::local($writerAssignment->expr) !== self::local($writer->params[1]->var)
                    || !self::captured($configure, $cell) || !self::captured($reader, $cell) || !self::captured($writer, $cell)
                    || !self::writerReturn($writer)) { continue; }
                $statements = $reader->stmts;
                if (count($statements) === 4 && self::earlyReturn($statements[0], $reader, $cell)) { array_shift($statements); }
                if (count($statements) !== 3 || !$statements[0] instanceof Node\Stmt\If_ || $statements[0]->else !== null || $statements[0]->elseifs !== []
                    || !$statements[0]->cond instanceof Node\Expr\BooleanNot || !$statements[0]->cond->expr instanceof Node\Expr\FuncCall
                    || !self::args($statements[0]->cond->expr, 1) || self::local($statements[0]->cond->expr->args[0]->value) !== $cell || count($statements[0]->stmts) !== 1) { continue; }
                $predicate = $statements[0]->cond->expr;
                if (!$predicate->name instanceof Node\Name || strcasecmp($predicate->name->toString(), 'is_int') !== 0) { continue; }
                $throw = $statements[0]->stmts[0] instanceof Node\Stmt\Expression ? $statements[0]->stmts[0]->expr : null;
                if (!$throw instanceof Node\Expr\Throw_ || !$throw->expr instanceof Node\Expr\New_ || !$throw->expr->class instanceof Node\Name
                    || !in_array(strtolower($throw->expr->class->toString()), ['exception', 'runtimeexception'], true)
                    || count($throw->expr->args) > 1 || count($throw->expr->args) === 1 && (!self::args($throw->expr, 1) || !$throw->expr->args[0]->value instanceof Node\Scalar\String_)) { continue; }
                $assignment = $statements[1] instanceof Node\Stmt\Expression ? $statements[1]->expr : null;
                $target = $assignment instanceof Node\Expr\Assign ? self::local($assignment->var) : null;
                if ($target === null || $target === $cell || !$assignment->expr instanceof Node\Expr\BinaryOp\Plus || self::local($assignment->expr->left) !== $cell
                    || !$assignment->expr->right instanceof Node\Scalar\Int_ || $assignment->expr->right->value !== 1
                    || !$statements[2] instanceof Node\Stmt\Return_ || !$statements[2]->expr instanceof Node\Expr\Ternary
                    || !$statements[2]->expr->else instanceof Node\Expr\Cast\String_ || self::local($statements[2]->expr->else->expr) !== $target
                    || self::local($statements[2]->expr->if) !== $target || !$statements[2]->expr->cond instanceof Node\Expr\BinaryOp\Identical
                    || self::local($statements[2]->expr->cond->left) === null || !$statements[2]->expr->cond->right instanceof Node\Scalar\String_) { continue; }
                $initials = array_values(array_filter($scope->stmts, static fn (Node $statement): bool => $statement instanceof Node\Stmt\Expression
                    && $statement->expr instanceof Node\Expr\Assign && self::local($statement->expr->var) === $cell && self::null($statement->expr->expr)));
                if (count($initials) !== 1 || $initials[0]->getEndFilePos() >= $call->getStartFilePos()) { continue; }
                $allowed = [$initials[0]->expr->var, $writerAssignment->var, $predicate->args[0]->value, $assignment->expr->left];
                foreach ([$configure, $reader, $writer] as $closure) { foreach ($closure->uses as $use) { if ($use->var->name === $cell) { $allowed[] = $use->var; } } }
                $keys = array_map(static fn (Node $node): string => RefreshedModelProperties::key($node), $allowed);
                if (array_filter($finder->findInstanceOf([$scope], Node\Expr\Variable::class), static fn (Node\Expr\Variable $var): bool =>
                    $var->name === $cell && !in_array(RefreshedModelProperties::key($var), $keys, true)) !== []) { continue; }
                if ($finder->findFirst([$reader], static fn (Node $node): bool => $node instanceof Node\Expr\Variable && $node->name === $target
                    && $node->getStartFilePos() < $assignment->var->getStartFilePos()) !== null) { continue; }
                $result[] = ['local' => $cell, 'configure' => $configure, 'reader' => $reader, 'writer' => $writer, 'readerProperty' => $read['property'],
                    'writerProperty' => $write['property'], 'predicate' => $predicate, 'throw' => $throw->expr, 'assignment' => $assignment, 'helper' => $call->name->name];
            }
        }
        return $result;
    }
    private static function writerReturn(Node\Expr\Closure $writer): bool {
        $return = $writer->stmts[1]; $input = self::local($writer->params[1]->var); $next = self::local($writer->params[3]->var);
        $condition = $return instanceof Node\Stmt\Return_ ? $return->expr : null;
        return $condition instanceof Node\Expr\BinaryOp\Identical && $condition->left instanceof Node\Expr\FuncCall
            && self::local($condition->left->name) === $next && self::args($condition->left, 1) && self::local($condition->left->args[0]->value) === $input
            && $condition->right instanceof Node\Expr\ConstFetch && strtolower($condition->right->name->toString()) === 'true';
    }
    private static function earlyReturn(Node $statement, Node\Expr\Closure $reader, string $cell): bool {
        if (!$statement instanceof Node\Stmt\If_ || $statement->else !== null || $statement->elseifs !== [] || count($statement->stmts) !== 1
            || !$statement->cond instanceof Node\Expr\BinaryOp\Smaller || !$statement->cond->left instanceof Node\Expr\PreInc
            || self::local($statement->cond->left->var) === null || self::local($statement->cond->left->var) === $cell
            || !$statement->cond->right instanceof Node\Scalar\Int_ || $statement->cond->right->value < 1 || !$statement->stmts[0] instanceof Node\Stmt\Return_) { return false; }
        $return = $statement->stmts[0]->expr;
        return $return instanceof Node\Expr\FuncCall && self::local($return->name) === self::local($reader->params[1]->var) && $return->args === [];
    }
    private static function captured(Node\Expr\Closure $closure, string $cell): bool {
        $uses = array_values(array_filter($closure->uses, static fn ($use): bool => $use->var->name === $cell));
        return count($uses) === 1 && $uses[0]->byRef;
    }
    private static function local(mixed $node): ?string { return $node instanceof Node\Expr\Variable && is_string($node->name) ? $node->name : null; }
    private static function null(Node $node): bool { return $node instanceof Node\Expr\ConstFetch && strtolower($node->name->toString()) === 'null'; }
    private static function args(Node $node, int $count): bool {
        return count($node->args) === $count && array_filter($node->args, static fn ($arg): bool => !$arg instanceof Node\Arg || $arg->byRef || $arg->unpack || $arg->name !== null) === [];
    }
    private static function syntaxType(?Node $node, string $owner): ?Type {
        if ($node instanceof Node\UnionType) {
            $parts = array_map(static fn (Node $part): ?Type => self::syntaxType($part, $owner), $node->types);
            return in_array(null, $parts, true) ? null : Type::union(...$parts);
        }
        return StoredCallbackSourceDeclarations::syntaxType($node, $owner);
    }
    private static function continuation(Type $type, array $parameters, Type $return, TypeComparator $types, bool $closure = true): bool {
        $atom = count($type->atomicTypes) === 1 ? $type->atomicTypes[0] : null;
        if (!$atom instanceof CallableType || $atom->alias !== null || $atom->signature === null || $atom->signature->closure !== $closure
            || $atom->signature->pure || $atom->signature->constraints !== [] || $atom->signature->source !== null
            || $atom->signature->returnType === null || !$types->equals($atom->signature->returnType, $return) || count($atom->signature->parameters) !== count($parameters)) { return false; }
        foreach ($parameters as $position => $expected) {
            $parameter = $atom->signature->parameters[$position];
            if ($parameter->type === null || !$types->equals($parameter->type, $expected) || $parameter->byReference || $parameter->variadic || $parameter->hasDefault || $parameter->closureThisType !== null) { return false; }
        }
        return true;
    }
    private static function doc(Node $node, string $kind, ?string $local, Node\Stmt\Class_ $class): ?array {
        $comment = $node->getDocComment();
        if ($comment === null || strlen($comment->getText()) > 8192 || preg_match_all('/@(?:phpstan-|psalm-)?(?:param|return|var)\b/', $comment->getText()) !== 1
            || preg_match('/@'.$kind.'[ \t]+([^\r\n*]+?)(?:\s+\*\/|$)/m', $comment->getText(), $match, PREG_OFFSET_CAPTURE) !== 1) { return null; }
        $raw = trim($match[1][0]); $offset = $match[1][1] + strspn($match[1][0], " \t");
        if ($local !== null) {
            if (preg_match('/[ \t]+\$'.preg_quote($local, '/').'$/D', $raw, $suffix, PREG_OFFSET_CAPTURE) !== 1) { return null; }
            $raw = substr($raw, 0, $suffix[0][1]);
        }
        $type = self::docType($raw, $class->getAttribute('storedDocNames', []));
        return $type === null ? null : ['type' => $type, 'start' => $comment->getStartFilePos() + $offset, 'end' => $comment->getStartFilePos() + $offset + strlen($raw)];
    }
    private static function docLocated(?\Mago\Sdk\SourceLocation $location, array $doc, string $file): bool {
        return $location !== null && RefreshedModelProperties::path($location->file) === RefreshedModelProperties::path($file)
            && $location->span->start === $doc['start'] && $location->span->end === $doc['end'];
    }
    /** Small source-backed callable grammar; aliases, effects, templates and stronger scalar refinements defer. */
    private static function docType(string $text, array $names, int $depth = 0): ?Type {
        $text = trim($text); if ($depth > 8 || $text === '' || strlen($text) > 2048) { return null; }
        if ($text[0] === '(') {
            $end = self::closing($text, 0); if ($end === strlen($text) - 1) { return self::docType(substr($text, 1, -1), $names, $depth + 1); }
        }
        $parts = self::split($text, '|');
        if (count($parts) > 1) {
            $types = array_map(static fn (string $part): ?Type => self::docType($part, $names, $depth + 1), $parts);
            return in_array(null, $types, true) ? null : Type::union(...$types);
        }
        if (preg_match('/^(Closure|callable)\(/', $text, $match) === 1) {
            $start = strlen($match[1]); $end = self::closing($text, $start);
            if ($end === null || substr($text, $end + 1, 1) !== ':') { return null; }
            $return = self::docType(substr($text, $end + 2), $names, $depth + 1); $parameters = [];
            $input = substr($text, $start + 1, $end - $start - 1);
            foreach ($input === '' ? [] : self::split($input, ',') as $part) {
                $type = self::docType($part, $names, $depth + 1); if ($type === null) { return null; } $parameters[] = new CallableParameter(type: $type);
            }
            return $return === null ? null : Type::fromAtomic(new CallableType(new CallableSignature(false, $match[1] === 'Closure', $parameters, $return, null, []), null));
        }
        $primitive = match ($text) { 'mixed' => Type::mixed(), 'null' => Type::null(), 'int' => Type::int(), 'float' => Type::float(), 'string' => Type::string(), 'bool' => Type::bool(), 'void' => Type::void(), default => null };
        if ($primitive !== null) { return $primitive; }
        $name = $names[strtolower($text)] ?? null;
        // A plain PHPDoc object has no generic argument list in the genuine SDK callable signature.
        return $name !== null && preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*$/D', $text) === 1
            ? Type::fromAtomic(new \Mago\Sdk\Analyzer\Type\NamedObjectType($name, null, null, false, false, null, false)) : null;
    }
    private static function closing(string $text, int $start): ?int {
        $depth = 0;
        for ($i = $start; $i < strlen($text); $i++) { if ($text[$i] === '(') { $depth++; } elseif ($text[$i] === ')' && --$depth === 0) { return $i; } }
        return null;
    }
    private static function split(string $text, string $separator): array {
        $depth = 0; $parts = []; $start = 0;
        for ($i = 0; $i < strlen($text); $i++) {
            if ($text[$i] === '(') { $depth++; } elseif ($text[$i] === ')') { $depth--; }
            elseif ($text[$i] === $separator && $depth === 0) { $parts[] = substr($text, $start, $i - $start); $start = $i + 1; }
        }
        $parts[] = substr($text, $start); return $parts;
    }
    /** Preserve the exact scanned namespace/PHPDoc binding resolver for late hydration. */
    private static function parse(string $contents): array
    {
                $resolver = new class extends NameResolver {
                    /** Malformed prose or type-tag tokens never become empty or partial class names. */
                    private static function docName(string $token): ?Node\Name
                    {
                        $qualified = str_starts_with($token, '\\');
                        $raw = $qualified ? substr($token, 1) : $token;
                        if ($raw === '') { return null; }
                        foreach (explode('\\', $raw) as $part) {
                            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $part) !== 1) { return null; }
                        }
                        $name = $qualified ? new Node\Name\FullyQualified($raw) : new Node\Name($raw);
                        return $name->isSpecialClassName() && !$name->isUnqualified() ? null : $name;
                    }
                    public function enterNode(Node $node) {
                        $result = parent::enterNode($node);
                        if ($node instanceof Node\Stmt\ClassLike) {
                            $names = [];
                            foreach ((new NodeFinder)->find([$node], static fn (Node $item): bool => $item->getDocComment() !== null) as $item) {
                                preg_match_all('/(?<![A-Za-z0-9_\\\\])[A-Za-z_\\\\][A-Za-z0-9_\\\\]*/', $item->getDocComment()->getText(), $tokens);
                                foreach ($tokens[0] as $token) {
                                    $name = self::docName($token);
                                    if ($name === null) { continue; }
                                    $names[strtolower($token)] = $this->getNameContext()->getResolvedClassName($name)->toString();
                                }
                            }
                            $node->setAttribute('storedDocNames', $names);
                        }
                        return $result;
                    }
                };
                return (new NodeTraverser($resolver))->traverse((new ParserFactory)->createForNewestSupportedVersion()->parse($contents) ?? []);
    }

    /** Only current, unique, complete analyzed class bindings can hydrate a consumed body or field. */
    public function declaration(string $name): ?array
    {
        $key = strtolower($name);
        $binding = $this->classes[$key] ?? null;
        if (! $this->complete || ! $this->started || $this->failed || $binding === null || ! self::current($binding['entry'])) { return null; }
        if (isset($this->hydrated[$key])) {
            $entry = $this->hydrated[$key];
            return self::current($entry['entry']) ? $entry : null;
        }
        $entry = $binding['entry'];
        $bytes = @file_get_contents($entry['diskFile']);
        if ($bytes === false || strlen($bytes) > 2_000_000 || hash('sha256', $bytes) !== $entry['hash']
            || count($this->hydrated) >= 32 || $this->hydratedBytes + strlen($bytes) > 8_388_608) { return null; }
        try { $nodes = self::parse($bytes); }
        catch (\PhpParser\Error|\LogicException) { return null; }
        $pending = $nodes;
        $matches = [];
        while ($pending !== []) {
            $node = array_shift($pending);
            if ($node instanceof Node\Stmt\Namespace_) { array_push($pending, ...$node->stmts); continue; }
            if ($node instanceof Node\Stmt\Class_ && $node->name !== null && $node->namespacedName !== null
                && strtolower($node->namespacedName->toString()) === $key) { $matches[] = $node; }
        }
        if (count($matches) !== 1 || $matches[0]->getStartFilePos() !== $binding['start']
            || $matches[0]->getEndFilePos() + 1 !== $binding['end'] || ! self::current($entry)) { return null; }
        $this->hydratedBytes += strlen($bytes);
        return $this->hydrated[$key] = ['node' => $matches[0], 'entry' => $entry];
    }
    private function disk(string $file): string {
        $file = RefreshedModelProperties::path($file); return preg_match('~^(?:/|[a-z]:/)~i', $file) === 1 ? $file : rtrim($this->root, '/').'/'.$file;
    }
}
