<?php

declare (strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\EnvironmentValueProvider;
use Ichinya\Laramago\Analyzer\StaticAnalysis\{ConfigurationIndex, ContainerBindings, DiagnosticArrayTypes, NativeFacade, PhpSource};
use Mago\Sdk\Analyzer\{CodebaseScanContext, CodebaseScanHook, InitializationContext, InitializationHook, IssueFilterContext, Type};
use Mago\Sdk\Analyzer\Metadata\{FunctionLikeMetadata, MetadataFlags};
use Mago\Sdk\Analyzer\Type\{ConditionalType, MixedType, VariableType, ScalarType, ScalarTypeKind};
use Mago\Sdk\Analyzer\Assertion\{TypeAssertion, TypeAssertionKind};
use Mago\Sdk\Reporting\{AnnotationKind, Level, ReportedIssue};
use PhpParser\{Node, NodeFinder};
/** Intentional defensive-boundary Warning policy. Values and native control flow stay unchanged. */
final class DefensiveBoundaryGuardProof implements InitializationHook, CodebaseScanHook
{
    private array $files = [];
    private bool $started = false;
    private bool $complete = false;
    private bool $failed = false;
    private bool $requestRegistrationHazard = false;
    private int $bytes = 0;
    public function __construct(private readonly string $root)
    {
    }
    public function initialize(InitializationContext $context): void
    {
        $this->reset();
    }
    public function reset(): void
    {
        $this->files = [];
        $this->started = $this->complete = $this->failed = $this->requestRegistrationHazard = false;
        $this->bytes = 0;
    }
    public function getTargets(): array
    {
        return ['**'];
    }
    public function scan(CodebaseScanContext $context): void
    {
        if ($context->firstBatch) {
            $this->reset();
            $this->started = true;
        } elseif (!$this->started || $this->complete) {
            $this->failed = true;
        }
        foreach ($context->files as $file) {
            $this->bytes += strlen($file->contents);
            if ($context->cancellation->isCancelled() || $this->bytes > 64000000 || isset($this->files[$file->path])) {
                $this->failed = true;
                break;
            }
            try {
                $nodes = DefensiveBoundaryGuardSource::parse($file->contents);
                $proofs = DefensiveBoundaryGuardSource::compile($nodes);
            } catch (\Throwable) {
                $this->failed = true;
                break;
            }
            foreach ((new NodeFinder())->find($nodes, static fn(Node $node): bool => $node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\MethodCall) as $call) {
                if (!$call->name instanceof Node\Identifier || !in_array(strtolower($call->name->toString()), ['macro', 'mixin', 'flushmacros'], true)) {
                    continue;
                }
                if (str_ends_with(DefensiveBoundarySourceProfile::path($file->path), '/vendor/laravel/framework/src/Illuminate/Foundation/Providers/FoundationServiceProvider.php')) {
                    continue;
                }
                if ($call instanceof Node\Expr\MethodCall || !$call->class instanceof Node\Name || strcasecmp($call->class->toString(), 'Illuminate\Http\Request') === 0) {
                    $this->requestRegistrationHazard = true;
                }
            }
            // Retain compact scalar certificates and hashes, never complete ASTs.
            $this->files[$file->path] = ['sha256' => hash('sha256', $file->contents), 'proofs' => $proofs];
        }
        $this->complete = $context->lastBatch && !$this->failed;
    }
    public function prove(IssueFilterContext $context): array
    {
        return $this->proveSite($context, null);
    }

    /** Native contracts for a source-selected configuration predicate, without forging a diagnostic. */
    public function proveConfigurationGuard(IssueFilterContext $context, array $span): array
    {
        return $this->proveSite($context, $span);
    }

    private function proveSite(IssueFilterContext $context, ?array $sourceSpan): array
    {
        $receipt = ['remove' => false, 'reason' => 'no-current-complete-source-certificate', 'source' => null, 'nativePredicates' => [], 'nativeProducers' => [], 'policy' => 'intentional-defensive-boundary-advisories', 'nativeTypesChanged' => false, 'nativeControlFlowChanged' => false, 'index' => ['started' => $this->started, 'complete' => $this->complete, 'failed' => $this->failed, 'requestRegistrationHazard' => $this->requestRegistrationHazard]];
        if (!$this->started || !$this->complete || $this->failed || $context->cancellation->isCancelled() || !isset($this->files[$context->file]) || $this->files[$context->file]['sha256'] !== hash('sha256', $context->contents)) {
            return $receipt;
        }
        $annotation = count($context->issue->annotations) === 1 ? $context->issue->annotations[0] : null;
        if ($annotation === null || $annotation->kind !== AnnotationKind::Primary || $annotation->file !== null && $annotation->file !== '') {
            return $receipt;
        }
        $proof = $this->files[$context->file]['proofs'][DefensiveBoundaryGuardSource::key($sourceSpan ?? [$annotation->span->start, $annotation->span->end])] ?? null;
        if ($proof === null) {
            return $receipt;
        }
        $receipt['source'] = $proof;
        $receipt['reason'] = 'exact-native-warning-envelope-unproved';
        if ($sourceSpan !== null && ($proof['selectedSite']['kind'] !== 'predicate'
            || $proof['selectedSite']['name'] !== 'is_array' || count($proof['predicates']) !== 1
            || $proof['predicates'][0]['origin']['family'] !== 'configuration')) {
            return $receipt;
        }
        if ($sourceSpan === null && !self::envelope($context, $proof)) {
            return $receipt;
        }
        $receipt['reason'] = 'current-source-native-caller-unproved';
        if (!self::caller($context, $proof['scope'])) {
            return $receipt;
        }
        $nodes = DefensiveBoundaryGuardSource::parse($context->contents);
        $finder = new NodeFinder();
        // Keep only the selected call syntax across SDK awaits. Full file ASTs must not stay suspended in concurrent Fibers.
        $spans=[];foreach($proof['requiredFunctions'] as $call){$spans[]=$call['span'];}
        foreach($proof['predicates'] as $predicate){foreach($predicate['origin']['roots'] as $root){$spans[]=$root['span'];}foreach($predicate['origin']['interveningReads']??[] as $read){$spans[]=$read['call'];}foreach($predicate['origin']['slices']??[] as $slice){$spans[]=$slice;}}
        $selectedNodes=[];foreach($spans as $span){$selectedNodes[DefensiveBoundaryGuardSource::key($span)]=$finder->findFirst($nodes,static fn(Node $node):bool=>DefensiveBoundaryGuardSource::span($node)===$span);}
        unset($nodes,$finder,$spans);
        $at = static fn(array $span): ?Node => $selectedNodes[DefensiveBoundaryGuardSource::key($span)]??null;
        $receipt['selectedSliceNativeAttempts'] = [];
        foreach ($proof['predicates'] as $predicate) {
            foreach ($predicate['origin']['slices'] ?? [] as $slice) {
                $nativeSlice = $context->codebase->getFunction('array_slice');
                $receipt['selectedSliceNativeAttempts'][] = $nativeSlice === null ? null : self::compact($nativeSlice);
            }
        }
        $receipt['reason'] = 'native-predicate-or-pure-operand-contract-unproved';
        foreach ($proof['requiredFunctions'] as $call) {
            $node = $at($call['span']);
            $native = $node instanceof Node\Expr\FuncCall ? self::builtin($context, $node, $call['name']) : null;
            $attempt = $context->codebase->getFunction($call['name']);
            $receipt['nativePredicates'][$call['name']] = ($attempt === null ? [] : self::compact($attempt)) + ['admitted' => $native !== null];
            if ($native === null) {
                return $receipt;
            }
        }
        $receipt['reason'] = 'native-boundary-producer-contract-unproved';
        foreach ($proof['predicates'] as $predicate) {
            foreach ($predicate['origin']['roots'] as $root) {
                $key = $root['kind'] . ':' . DefensiveBoundaryGuardSource::key($root['span']);
                if (isset($receipt['nativeProducers'][$key])) {
                    continue;
                }
                $native = $this->producer($context, $root, $at($root['span']), $proof['scope']);
                $receipt['nativeProducers'][$key] = $native;
                if (!$native['admitted']) {
                    return $receipt;
                }
            }
            foreach ($predicate['origin']['interveningReads'] ?? [] as $read) {
                if (!self::byValueRead($context, $at($read['call']), $read)) {
                    $receipt['reason'] = 'intervening-call-may-write-boundary-value';
                    return $receipt;
                }
            }
            foreach ($predicate['origin']['slices'] ?? [] as $span) {
                $call = $at($span);
                if (!$call instanceof Node\Expr\FuncCall || self::builtin($context, $call, 'array_slice') === null) {
                    return $receipt;
                }
            }
        }
        $receipt['reason'] = 'native-rejection-contract-unproved';
        if (!$this->rejection($context, $proof['rejection'])) {
            return $receipt;
        }
        $receipt['remove'] = true;
        $receipt['reason'] = 'current-native-warning-and-certified-defensive-boundary';
        foreach ($proof['requiredFunctions'] as $call) {
            if (in_array($call['name'], ['is_array', 'is_string', 'is_int', 'array_is_list'], true)) {
                $receipt['controlSymbol'] = ['kind' => 'function', 'name' => $call['name']];
                break;
            }
        }
        return $receipt;
    }
    public static function builtin(IssueFilterContext $context, Node\Expr\FuncCall $call, string $name): ?FunctionLikeMetadata
    {
        if (!$call->name instanceof Node\Name || strcasecmp($call->name->toString(), $name) !== 0) {
            return null;
        }
        if (!$call->name instanceof Node\Name\FullyQualified) {
            $qualified = $call->name->getAttribute('namespacedName');
            if ($qualified instanceof Node\Name && strcasecmp($qualified->toString(), $name) !== 0 && $context->codebase->getFunction($qualified->toString()) !== null) {
                return null;
            }
        }
        $native = $context->codebase->getFunction($name);
        if ($native === null || $native->kind->name !== 'Function_' || $native->identifier->class !== null || strcasecmp($native->identifier->name, $name) !== 0 || !$native->flags->contains(MetadataFlags::BUILTIN) || $native->flags->contains(MetadataFlags::USER_DEFINED) || $native->flags->contains(MetadataFlags::BY_REFERENCE) || $native->returnType === null || $native->declaredReturnType === null) {
            return null;
        }
        if (isset(DefensiveBoundaryBuiltinProfiles::HASHES[$name])) {
            $canonical = static function (mixed $value) use (&$canonical): mixed {
                if (is_object($value)) {
                    $value = get_object_vars($value);
                }
                if (is_array($value)) {
                    foreach ($value as $key => $item) {
                        $value[$key] = $canonical($item);
                    }
                    if (!array_is_list($value)) {
                        ksort($value);
                    }
                }
                return $value;
            };
            if (hash('sha256', json_encode($canonical(self::compact($native)), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) !== DefensiveBoundaryBuiltinProfiles::HASHES[$name]) {
                return null;
            }
        } elseif ($native->templates !== []) {
            return null;
        }
        $arity = match ($name) {
            'is_array', 'is_string', 'is_int', 'array_is_list', 'ctype_digit' => [1, 1],
            'count', 'basename', 'array_slice', 'getopt', 'unpack' => [1, 4],
            default => null,
        };
        if ($arity === null || count($call->args) < $arity[0] || count($call->args) > $arity[1]) {
            return null;
        }
        foreach ($call->args as $index => $argument) {
            $parameter = $native->parameters[$index] ?? null;
            if (!$argument instanceof Node\Arg || $argument->byRef || $argument->unpack || $argument->name !== null || $parameter === null || $parameter->flags->contains(MetadataFlags::BY_REFERENCE) || $parameter->outType !== null || $parameter->closureThisType !== null) {
                return null;
            }
        }
        $returns = match ($name) {
            'is_array', 'is_string', 'is_int', 'array_is_list', 'ctype_digit' => Type::bool(),
            'count' => Type::int(),
            'basename' => Type::string(),
            'array_slice' => Type::array(Type::fromAtomic(new ScalarType(ScalarTypeKind::ArrayKey)), Type::mixed()),
            'getopt', 'unpack' => Type::union(Type::array(Type::fromAtomic(new ScalarType(ScalarTypeKind::ArrayKey)), Type::mixed()), Type::false()),
            default => null,
        };
        if ($returns === null || !$context->types->isContainedBy($native->declaredReturnType->type, $returns)) {
            return null;
        }
        if (in_array($name, ['is_array', 'is_string', 'is_int'], true)) {
            $parameter = count($native->parameters) === 1 ? $native->parameters[0] : null;
            if ($parameter === null || $parameter->name !== '$value' || $parameter->type === null || $parameter->declaredType === null || !$context->types->equals($parameter->type->type, Type::mixed()) || !$context->types->equals($parameter->declaredType->type, Type::mixed())) {
                return null;
            }
            $conditional = count($native->returnType->type->atomicTypes) === 1 ? $native->returnType->type->atomicTypes[0] : null;
            if ($conditional instanceof ConditionalType) {
                $domain = match ($name) {
                    'is_array' => Type::array(Type::fromAtomic(new ScalarType(ScalarTypeKind::ArrayKey)), Type::mixed()),
                    'is_string' => Type::string(),
                    'is_int' => Type::int(),
                };
                $assertions = $native->ifTrueAssertions;
                $assertion = $assertions['$value'][0] ?? null;
                if ($conditional->negated || count($conditional->subject->atomicTypes) !== 1 || !$conditional->subject->atomicTypes[0] instanceof VariableType || $conditional->subject->atomicTypes[0]->name !== '$value' || !$context->types->equals($conditional->target, $domain) || !$context->types->equals($conditional->then, Type::true()) || !$context->types->equals($conditional->otherwise, Type::false()) || array_keys($assertions) !== ['$value'] || count($assertions['$value']) !== 1 || !$assertion instanceof TypeAssertion || $assertion->kind !== TypeAssertionKind::IsType || !$context->types->equals($assertion->type, $domain)) {
                    return null;
                }
            } else {
                return null;
            }
        }
        return $native;
    }
    private function producer(IssueFilterContext $context, array $root, ?Node $node, ?array $scope): array
    {
        $receipt = ['admitted' => false, 'kind' => $root['kind']];
        if ($root['kind'] === 'server-argv') {
            return ['kind' => 'server-argv', 'sourceProfile' => 'ordinary-superglobal-argv-read; no SDK global variable API or runtime content guarantee', 'admitted' => true];
        }
        if (in_array($root['kind'], ['getopt', 'unpack'], true)) {
            $native = $node instanceof Node\Expr\FuncCall ? self::builtin($context, $node, $root['kind']) : null;
            return ['admitted' => $native !== null, 'kind' => $root['kind'], 'native' => $native === null ? null : self::compact($native), 'literalFormat' => $root['format'] ?? null];
        }
        if (in_array($root['kind'], ['config-helper', 'config-facade'], true)) {
            if ((new ContainerBindings($this->root))->configured('config')) {
                return $receipt + ['reason' => 'configured-or-unknown-config-binding'];
            }
            if ($root['kind'] === 'config-helper') {
                if (!$node instanceof Node\Expr\FuncCall || !$node->name instanceof Node\Name) {
                    return $receipt;
                }
                if (!$node->name instanceof Node\Name\FullyQualified) {
                    $qualified = $node->name->getAttribute('namespacedName');
                    if ($qualified instanceof Node\Name && strcasecmp($qualified->toString(), 'config') !== 0 && $context->codebase->getFunction($qualified->toString()) !== null) {
                        return $receipt;
                    }
                }
                $native = $context->codebase->getFunction('config');
                if (!$this->profile($native, 'config-helper') || !self::parameters($native, ['$key', '$default']) || !self::permissiveConfigReturn($native)) {
                    return $receipt;
                }
            } else {
                if (!(new NativeFacade($this->root))->dispatchesClass($context->codebase, 'Illuminate\Support\Facades\Config', 'config', 'Illuminate\Config\Repository', 'get')) {
                    return $receipt;
                }
                $native = $context->codebase->getDeclaringMethod('Illuminate\Config\Repository', 'get');
                if (!$this->profile($native, 'config-repository') || !self::parameters($native, ['$key', '$default']) || !self::mixed($native->returnType?->type)) {
                    return $receipt;
                }
            }
            $index = new ConfigurationIndex(new PhpSource($this->root));
            $default = count($node->args) === 2 ? self::literalType($node->args[1]->value) : Type::null();
            $type = $index->lookup($root['key'], $default, EnvironmentValueProvider::nativeSource($context->codebase, $index->source));
            if ($type === null) {
                return $receipt + ['reason' => 'unknown-static-config-key-or-value'];
            }
            return ['admitted' => true, 'kind' => $root['kind'], 'key' => $root['key'], 'sourceConfigurationType' => (string) $type, 'native' => self::compact($native), 'runtimeMutationGuarantee' => false];
        }
        if ($root['kind'] === 'request-pseudo') {
            if ($this->requestRegistrationHazard || $scope === null) {
                return $receipt + ['reason' => 'custom-or-unknown-request-registration'];
            }
            $receiver = $root['receiver'];
            $class = $receiver['class'];
            $seen = [];
            $found = false;
            while ($class !== null && !isset($seen[strtolower($class)])) {
                $seen[strtolower($class)] = true;
                $metadata = $context->codebase->getClass($class);
                if ($metadata === null || $metadata->hasIncompleteHierarchy() || $metadata->mixins !== []) {
                    return $receipt;
                }
                if (strcasecmp($class, 'Illuminate\Http\Request') === 0) {
                    if (!in_array('validate', array_map('strtolower', $metadata->pseudoMethods), true) || !in_array('illuminate\support\traits\macroable', array_map('strtolower', $metadata->usedTraits), true)) {
                        return $receipt;
                    }
                    $bytes = @file_get_contents((new PhpSource($this->root))->path($metadata->location->file));
                    if ($bytes === false || preg_match_all('/@method[^\r\n]*\bvalidate\([^\r\n]*/', $bytes, $pseudoLines) !== 1 || trim($pseudoLines[0][0]) !== '@method array validate(array $rules, ...$params)') {
                        return $receipt;
                    }
                    $declaration = (new NodeFinder())->findFirst(DefensiveBoundaryGuardSource::parse($bytes), static fn(Node $item): bool => $item instanceof Node\Stmt\Class_ && strcasecmp($item->namespacedName?->toString() ?? '', 'Illuminate\Http\Request') === 0);
                    if ($declaration === null || $declaration->getMethod('validate') !== null || $declaration->getMethod('__call') !== null || strcasecmp($metadata->directParentClass ?? '', 'Symfony\Component\HttpFoundation\Request') !== 0) {
                        return $receipt;
                    }
                    $found = true;
                    break;
                }
                if (in_array('validate', array_map('strtolower', $metadata->methods), true) || in_array('validate', array_map('strtolower', $metadata->pseudoMethods), true) || in_array('__call', array_map('strtolower', $metadata->methods), true)) {
                    return $receipt;
                }
                $class = $metadata->directParentClass;
            }
            $dispatcher = $context->codebase->getDeclaringMethod('Illuminate\Support\Traits\Macroable', '__call');
            $registration = $context->codebase->getDeclaringMethod('Illuminate\Foundation\Providers\FoundationServiceProvider', 'registerRequestValidation');
            if (!$found || !$this->profile($dispatcher, 'request-dispatch') || !$this->profile($registration, 'request-registration')) {
                return $receipt;
            }
            $caller = self::nativeCaller($context, $scope);
            $parameter = null;
            foreach ($caller?->parameters ?? [] as $candidate) {
                if ($candidate->name === '$' . $receiver['parameterName']) {
                    $parameter = $candidate;
                    break;
                }
            }
            if ($parameter === null || $parameter->declaredType === null || $parameter->type === null || $parameter->flags->contains(MetadataFlags::BY_REFERENCE) || $parameter->location->file === null || DefensiveBoundarySourceProfile::path($parameter->location->file) !== DefensiveBoundarySourceProfile::path($context->file) || [$parameter->location->span->start, $parameter->location->span->end] !== $receiver['parameter'] || !$context->types->equals($parameter->declaredType->type, Type::namedObject($receiver['class'])) || !$context->types->equals($parameter->type->type, Type::namedObject($receiver['class']))) {
                return $receipt;
            }
            return ['admitted' => true, 'kind' => 'request-pseudo', 'receiverClass' => $receiver['class'], 'nativePseudoNamePresent' => true, 'nativeDispatcher' => self::compact($dispatcher), 'nativeRegistrationMethod' => self::compact($registration), 'physicalValidateMethodInvented' => false, 'runtimeRegistrationExecuted' => false];
        }
        return $receipt;
    }
    private function rejection(IssueFilterContext $context, array $rejection): bool
    {
        if ($rejection['kind'] === 'positive-cli-normalization') {
            return $this->rejection($context, $rejection['nestedRejection']);
        }
        if ($rejection['kind'] === 'throw-validation-exception') {
            $native = $context->codebase->getDeclaringMethod('Illuminate\Validation\ValidationException', 'withMessages');
            return $this->profile($native, 'validation-exception') && $native->static && self::parameters($native, ['$messages']);
        }
        if ($rejection['kind'] !== 'throw-builtin-constructor') {
            return true;
        }
        $parents = ['UnexpectedValueException' => 'RuntimeException', 'RuntimeException' => 'Exception', 'LogicException' => 'Exception', 'InvalidArgumentException' => 'LogicException'];
        $class = $rejection['class'];
        if (!isset($parents[$class])) {
            return false;
        }
        while ($class !== 'Exception') {
            $metadata = $context->codebase->getClass($class);
            if ($metadata === null || !$metadata->flags->contains(MetadataFlags::BUILTIN) || $metadata->flags->contains(MetadataFlags::USER_DEFINED) || $metadata->hasIncompleteHierarchy() || strcasecmp($metadata->directParentClass ?? '', $parents[$class]) !== 0) {
                return false;
            }
            $class = $parents[$class];
        }
        $constructor = $context->codebase->getDeclaringMethod($rejection['class'], '__construct');
        return $constructor !== null && $constructor->flags->contains(MetadataFlags::BUILTIN) && !$constructor->static && strcasecmp($constructor->identifier->class ?? '', 'Exception') === 0 && count($constructor->parameters) === 3 && $constructor->parameters[0]->name === '$message' && $constructor->parameters[0]->type !== null && $context->types->equals($constructor->parameters[0]->type->type, Type::string()) && !$constructor->parameters[0]->flags->contains(MetadataFlags::BY_REFERENCE);
    }
    private function profile(?FunctionLikeMetadata $native, string $role): bool
    {
        if ($native === null || $native->location->file === null || $native->flags->contains(MetadataFlags::BUILTIN) || $native->flags->contains(MetadataFlags::BY_REFERENCE) || $native->abstract) {
            return false;
        }
        $profile = DefensiveBoundaryFrameworkProfiles::PROFILES[$role];
        $path = DefensiveBoundarySourceProfile::path((new PhpSource($this->root))->path($native->location->file));
        if (!str_ends_with($path, '/' . $profile['path']) || strcasecmp($native->identifier->class ?? '', $profile['class'] ?? '') !== 0 || strcasecmp($native->identifier->name, $profile['method']) !== 0) {
            return false;
        }
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            return false;
        }
        $node = DefensiveBoundarySourceProfile::selected(DefensiveBoundaryGuardSource::parse($bytes), $profile['class'], $profile['method']);
        return $node !== null && DefensiveBoundarySourceProfile::fingerprint($node) === $profile['fingerprint'] && self::location($native, $node, $native->location->file);
    }
    private static function parameters(?FunctionLikeMetadata $native, array $names): bool
    {
        if ($native === null || count($native->parameters) !== count($names)) {
            return false;
        }
        foreach ($names as $index => $name) {
            $parameter = $native->parameters[$index];
            if ($parameter->name !== $name || $parameter->flags->contains(MetadataFlags::BY_REFERENCE) || $parameter->flags->contains(MetadataFlags::VARIADIC) || $parameter->outType !== null) {
                return false;
            }
        }
        return true;
    }
    private static function permissiveConfigReturn(FunctionLikeMetadata $native): bool
    {
        $type = $native->returnType?->type;
        $atomic = count($type?->atomicTypes ?? []) === 1 ? $type->atomicTypes[0] : null;
        return $atomic instanceof MixedType || $atomic instanceof ConditionalType;
    }
    private static function byValueRead(IssueFilterContext $context, ?Node $node, array $read): bool
    {
        if ($read['byReference'] || $read['unpacked']) {
            return false;
        }
        $native = null;
        if ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name && $node->name instanceof Node\Identifier) {
            $native = $context->codebase->getDeclaringMethod($node->class->toString(), $node->name->toString());
        } elseif ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
            $native = $context->codebase->getFunction($node->name->toString());
        }
        $parameter = $native?->parameters[$read['argumentIndex']] ?? null;
        return $native !== null && !$native->flags->contains(MetadataFlags::BY_REFERENCE) && $parameter !== null && !$parameter->flags->contains(MetadataFlags::BY_REFERENCE) && $parameter->outType === null;
    }
    private static function caller(IssueFilterContext $context, ?array $scope): bool
    {
        if ($scope === null) {
            return true;
        }
        // Lexical scripts have no invented native callable identifier.
        $native = self::nativeCaller($context, $scope);
        if ($native === null || $native->flags->contains(MetadataFlags::BUILTIN) || $native->flags->contains(MetadataFlags::BY_REFERENCE) || $native->location->file === null || DefensiveBoundarySourceProfile::path($native->location->file) !== DefensiveBoundarySourceProfile::path($context->file) || !in_array($native->location->span->start, $scope['startAlternatives'], true) || $native->location->span->end !== $scope['span'][1]) {
            return false;
        }
        if ($scope['class'] !== null) {
            $class = $context->codebase->getClass($scope['class']);
            if ($class === null || $class->hasIncompleteHierarchy()) {
                return false;
            }
        }
        return true;
    }
    private static function nativeCaller(IssueFilterContext $context, array $scope): ?FunctionLikeMetadata
    {
        return $scope['class'] === null ? $context->codebase->getFunction($scope['name']) : $context->codebase->getDeclaringMethod($scope['class'], $scope['name']);
    }
    private static function location(FunctionLikeMetadata $native, Node $node, string $path): bool
    {
        $starts = [$node->getStartFilePos()];
        foreach ($node->getComments() as $comment) {
            $starts[] = $comment->getStartFilePos();
        }
        foreach ($node->attrGroups as $attribute) {
            $starts[] = $attribute->getStartFilePos();
        }
        return $native->location->file !== null && DefensiveBoundarySourceProfile::path($native->location->file) === DefensiveBoundarySourceProfile::path($path) && in_array($native->location->span->start, $starts, true) && $native->location->span->end === $node->getEndFilePos() + 1;
    }
    private static function literalType(Node $node): ?Type
    {
        return match (true) {
            $node instanceof Node\Scalar\String_ => Type::literalString($node->value),
            $node instanceof Node\Scalar\Int_ => Type::literalInt($node->value),
            $node instanceof Node\Expr\Array_ && $node->items === [] => Type::fromAtomic(new \Mago\Sdk\Analyzer\Type\KeyedArrayType([], null, null, false)),
            $node instanceof Node\Expr\ConstFetch && strtolower($node->name->toString()) === 'null' => Type::null(),
            default => null,
        };
    }
    private static function mixed(?Type $type): bool
    {
        return $type !== null && count($type->atomicTypes) === 1 && $type->atomicTypes[0] instanceof MixedType;
    }
    public static function envelope(IssueFilterContext $context, array $proof): bool
    {
        $issue = $context->issue;
        $annotation = $issue->annotations[0];
        if ($issue->level !== Level::Warning || $issue->link !== null || $issue->edits !== []) {
            return false;
        }
        $site = $proof['selectedSite']['kind'];
        if ($issue->code === 'redundant-type-comparison' && $site === 'predicate' && preg_match('/^Redundant type assertion: `([^`]+)` is already `([^`]+)`\.$/D', $issue->message, $match)) {
            $selected = null;
            foreach ($proof['predicates'] as $predicate) {
                if ($predicate['span'] === $proof['selectedSite']['span']) {
                    $selected = $predicate;
                    break;
                }
            }
            if ($selected === null || trim(substr($context->contents, $selected['argument'][0], $selected['argument'][1] - $selected['argument'][0])) !== $match[1]) {
                return false;
            }
            $target = match ($proof['selectedSite']['name']) {
                'is_array' => 'array<array-key, mixed>',
                'is_string' => 'string',
                'is_int' => 'int',
                'array_is_list' => 'list<string>',
                default => null,
            };
            // The public bounded parser does not currently recognize the printed array-key alias.
            // Decode only this exact standard native domain; this constructs no replacement value type.
            $type = $match[2] === 'array<array-key, mixed>' ? Type::array(Type::union(Type::int(), Type::string()), Type::mixed()) : DiagnosticArrayTypes::parse($match[2]);
            $container = match ($proof['selectedSite']['name']) {
                'is_array' => Type::array(Type::union(Type::int(), Type::string()), Type::mixed()),
                'is_string' => Type::string(),
                'is_int' => Type::int(),
                'array_is_list' => Type::list(Type::string()),
                default => null,
            };
            return $type !== null && $container !== null && !self::mixed($type) && $context->types->isContainedBy($type, $container) && $issue->notes === ['The assertion against `' . $target . '` always holds because `' . $match[1] . '` is `' . $match[2] . '`.'] && $issue->help === 'Consider removing this assertion or replacing it with `default` if used in a `match` arm.' && $annotation->message === 'Argument `' . $match[1] . '` already has type `' . $match[2] . '`';
        }
        if ($issue->code === 'impossible-condition' && in_array($site, ['condition', 'negated-predicate'], true)) {
            return $issue->message === 'This condition (type `false`) will always evaluate to false.' && $issue->notes === ['Because this condition is always false, the code block it controls will never be executed.'] && $issue->help === 'Check the logic of this expression. If the code block is intended to be unreachable, consider removing it. Otherwise, revise the condition.' && $annotation->message === 'Expression of type `false` is always falsy';
        }
        if ($issue->code === 'redundant-condition' && in_array($site, ['condition', 'predicate'], true)) {
            return $issue->message === 'This condition (type `true`) will always evaluate to true.' && $issue->notes === ['Because this condition is always true, the code block it controls will always execute if this part of the code is reached.', 'The explicit condition might be redundant.'] && $issue->help === "Consider simplifying or removing the conditional check if the guarded code should always execute, or verify the expression's logic if a conditional check is truly needed." && $annotation->message === 'Expression of type `true` is always truthy';
        }
        if ($issue->code === 'redundant-type-comparison' && $site === 'negated-predicate' && preg_match('/^Redundant condition: variable `([^`]+)`( \(type `([^`]+)`\))? is already known to be `([^`]+)`\.$/D', $issue->message, $match)) {
            $selected = null;
            foreach ($proof['predicates'] as $predicate) {
                if ($predicate['span'][0] === $proof['selectedSite']['span'][0] + 1 && $predicate['span'][1] === $proof['selectedSite']['span'][1]) {
                    $selected = $predicate;
                    break;
                }
            }
            $target = match ($proof['selectedSite']['name']) {
                'is_array' => 'array<array-key, mixed>',
                'is_string' => 'string',
                'is_int' => 'int',
                default => null,
            };
            if ($selected === null || $target === null || $match[4] !== $target || trim(substr($context->contents, $selected['argument'][0], $selected['argument'][1] - $selected['argument'][0])) !== $match[1]) {
                return false;
            }
            return $issue->notes === ['The assertion that variable `' . $match[1] . '`' . ($match[2] ?? '') . ' is of type `' . $target . '` is redundant.'] && $issue->help === 'This condition is redundant and can be removed to simplify the code.' && $annotation->message === 'This condition always evaluates to true';
        }
        if ($issue->code === 'impossible-type-comparison' && in_array($site, ['empty-comparison', 'negated-predicate'], true) && preg_match('/^Impossible condition: variable `([^`]+)`( \(type `([^`]+)`\))? can never be `([^`]+)`\.$/D', $issue->message, $match)) {
            $variable = $match[1] . ($match[2] ?? '');
            $target = $match[4];
            if ($site === 'empty-comparison' && $target !== 'empty-countable' || $site === 'negated-predicate' && ($proof['selectedSite']['name'] ?? null) !== 'array_is_list') {
                return false;
            }
            return $issue->notes === ['The type of variable `' . $match[1] . '`' . ($match[2] ?? '') . ' is incompatible with the assertion that it is `' . $target . '`.'] && $issue->help === 'This condition is impossible and the associated code block will never execute. Review the types and condition logic.' && $annotation->message === 'This condition always evaluates to false';
        }
        return false;
    }
    public static function compact(FunctionLikeMetadata $native): array
    {
        return ['symbol' => ($native->identifier->class === null ? '' : $native->identifier->class . '::') . $native->identifier->name, 'kind' => $native->kind->name, 'location' => $native->location, 'flags' => $native->flags->bits, 'parameterNames' => array_column($native->parameters, 'name'), 'declaredReturn' => $native->declaredReturnType === null ? null : (string) $native->declaredReturnType->type, 'effectiveReturn' => $native->returnType === null ? null : (string) $native->returnType->type, 'parameters' => self::projection($native->parameters), 'declaredReturnProjection' => self::projection($native->declaredReturnType), 'effectiveReturnProjection' => self::projection($native->returnType), 'ifTrueAssertions' => self::projection($native->ifTrueAssertions), 'templates' => self::projection($native->templates)];
    }
    private static function projection(mixed $value): mixed
    {
        if ($value instanceof \UnitEnum) {
            return ['enum' => $value::class, 'name' => $value->name];
        }
        if (is_object($value)) {
            $value = ['objectClass' => $value::class] + get_object_vars($value);
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::projection($item);
            }
        }
        return $value;
    }
    public static function canonical(object|array $value): array
    {
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }
}
