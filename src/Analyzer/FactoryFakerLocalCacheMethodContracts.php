<?php
declare(strict_types=1);
namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\Metadata\{ClassLikeKind, FunctionLikeKind, FunctionLikeMetadata, MetadataFlags, TypeMetadata};
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\{AnyObjectType, CallableType, ClassLikeStringKind, ClassLikeStringType, ClassLikeStringVariant,
    ConditionalType, GenericParameterType, GenericParent, GenericParentKind, KeyedArrayType, ListType, MixedTruthiness,
    MixedType, NamedObjectType, ScalarType, ScalarTypeKind, SimpleAtomicType, SimpleAtomicTypeKind, StringCasing,
    StringLiteralKind, StringType, VariableType, Variance};
use Mago\Sdk\Analyzer\Type\FunctionLikeKind as IdentifierKind;
use Mago\Sdk\SourceLocation;
use PhpParser\{Node, NodeFinder};

/**
 * Source-bound signature domains for the selected local cache-manager route.
 * The importing catalogue must separately bind method/class identities, bodies,
 * current source hashes, receiver lifetime and container/provider precedence.
 * This helper never executes an application or constructs a native context.
 */
final class FactoryFakerLocalCacheMethodContracts
{
    private const APPLICATION = 'Illuminate\\Foundation\\Application';
    private const CONTAINER = 'Illuminate\\Container\\Container';
    private const CONTRACT = 'Illuminate\\Contracts\\Container\\Container';
    private const CACHE = 'Illuminate\\Cache\\CacheManager';
    private const PROVIDER = 'Illuminate\\Cache\\CacheServiceProvider';

    /** Compact current syntax only; no AST is retained in the returned recipe. */
    public static function source(Node\Stmt\ClassMethod $method, string $sourceClass): ?array
    {
        $name = strtolower($method->name->name);
        $profile = self::profile($sourceClass, $name);
        $doc = $method->getDocComment();
        if ($profile === null || $doc === null || $method->byRef || $method->isStatic() || $method->attrGroups !== []
            || count($method->params) !== count($profile['parameters']) || $method->returnType !== null) { return null; }
        $text = $doc->getText();
        // Tool-specific or additional domain tags have higher priority than this
        // narrow physical signature and cannot be silently interpreted away.
        if (preg_match('/@(?:phpstan-|psalm-|var\b|param-out\b|template-(?:covariant|contravariant)\b|assert\b)/i', $text)) { return null; }
        $tags = [];
        if (!preg_match_all('/@([a-z][a-z-]*)[ \t]+([^\r\n]*)/i', $text, $rows, PREG_OFFSET_CAPTURE)) { return null; }
        foreach ($rows[1] as $index => [$tag]) {
            $body = rtrim($rows[2][$index][0], " \t");
            $bodyOffset = $doc->getStartFilePos() + $rows[2][$index][1];
            if ($tag === 'param' || $tag === 'param-closure-this') {
                if (!preg_match('/^(.+?)[ \t]+(\$[a-zA-Z_][a-zA-Z0-9_]*)$/D', $body, $match)) { return null; }
                $key = $tag.':'.$match[2]; $typeText = $match[1];
            } elseif ($tag === 'return' || $tag === 'template') {
                $key = $tag; $typeText = $body;
            } else {
                if ($tag !== 'throws') { return null; }
                continue;
            }
            if (isset($tags[$key])) { return null; }
            $tags[$key] = ['text' => $typeText, 'span' => [$bodyOffset, $bodyOffset + strlen($typeText)]];
        }
        $expected = ['return' => $profile['returnDoc']];
        if ($profile['generic']) { $expected['template'] = 'TClass of object'; }
        $formals = [];
        foreach ($method->params as $index => $parameter) {
            $wanted = $profile['parameters'][$index];
            if (!$parameter->var instanceof Node\Expr\Variable || !is_string($parameter->var->name)
                || '$'.$parameter->var->name !== $wanted['name'] || $parameter->byRef || $parameter->variadic
                || $parameter->flags !== 0 || $parameter->attrGroups !== []
                || self::nativeSyntax($parameter->type) !== $wanted['declared']
                || self::defaultSyntax($parameter->default) !== $wanted['default']) { return null; }
            if ($wanted['doc'] !== null) { $expected['param:'.$wanted['name']] = $wanted['doc']; }
            if ($wanted['closureThis']) { $expected['param-closure-this:'.$wanted['name']] = '$this'; }
            $formals[] = $wanted + [
                'span' => self::span($parameter), 'nameSpan' => self::span($parameter->var),
                'declaredSpan' => $parameter->type === null ? null : self::span($parameter->type),
                'defaultExpressionSpan' => $parameter->default === null ? null : self::span($parameter->default),
                'defaultPrefixSpan' => $parameter->default === null ? null
                    : [$parameter->var->getEndFilePos() + 1, $parameter->default->getStartFilePos()],
            ];
        }
        if (array_keys($expected) !== array_keys(array_intersect_key($expected, $tags)) || count($expected) !== count($tags)) { return null; }
        foreach ($expected as $key => $type) {
            if (!isset($tags[$key]) || preg_replace('/[ \t]+/', ' ', $tags[$key]['text']) !== $type) { return null; }
        }
        return ['sourceClass' => $sourceClass, 'name' => $method->name->name, 'span' => self::span($method),
            'nameSpan' => self::span($method->name), 'docSpan' => [$doc->getStartFilePos(), $doc->getEndFilePos() + 1],
            'docSha256' => hash('sha256', $text), 'tags' => $tags, 'parameters' => $formals,
            'generic' => $profile['generic'], 'returnKind' => $profile['returnKind'], 'interface' => $profile['interface']];
    }

    /** Uses only the supplied genuine native declaration and explicit SDK lookups. */
    public static function current(IssueFilterContext $context, FunctionLikeMetadata $native, array $source,
        string $absolutePath, string $projectRoot): array
    {
        $result = ['admitted' => false, 'stage' => 'current physical local-cache signature source',
            'sourceHashes' => [], 'nativeBindings' => []];
        $path = self::path($absolutePath, $projectRoot);
        if ($path === null || !isset($source['sourceClass'], $source['name'], $source['parameters'], $source['tags'])
            || self::profile($source['sourceClass'], strtolower($source['name'])) === null) { return $result; }
        $bytes = @file_get_contents($path);
        if ($bytes === false || self::slice($bytes, $source['docSpan']) === null
            || hash('sha256', self::slice($bytes, $source['docSpan'])) !== $source['docSha256']) { return $result; }
        $result['sourceHashes'][$path] = hash('sha256', $bytes);
        if ($native->kind !== FunctionLikeKind::Method || $native->identifier->kind !== IdentifierKind::Method || $native->identifier->class === null
            || strcasecmp($native->identifier->class, $source['sourceClass']) !== 0
            || strcasecmp($native->identifier->name, $source['name']) !== 0 || !$native->hasDocblock
            || $native->flags->contains(MetadataFlags::BUILTIN) || $native->flags->contains(MetadataFlags::MAGIC_METHOD)
            || $native->flags->contains(MetadataFlags::BY_REFERENCE) || $native->static
            || count($native->parameters) !== count($source['parameters']) || $native->declaredReturnType !== null
            || $native->whereConstraints !== [] || !self::located($native->location, $path, $source['span'], $projectRoot)
            || !self::located($native->nameLocation, $path, $source['nameSpan'], $projectRoot)) { return $result; }
        $result['stage'] = 'current source-declared TClass owner and object constraint';
        if (!self::templates($native, $source)) { return $result; }
        foreach ($source['parameters'] as $index => $formal) {
            $parameter = $native->parameters[$index];
            $result['stage'] = 'current local-cache formal '.$formal['name'];
            if ($parameter->name !== $formal['name'] || $parameter->outType !== null
                || !self::located($parameter->location, $path, $formal['span'], $projectRoot)
                || !self::located($parameter->nameLocation, $path, $formal['nameSpan'], $projectRoot)
                || $parameter->flags->contains(MetadataFlags::BY_REFERENCE) || $parameter->flags->contains(MetadataFlags::VARIADIC)
                || $parameter->flags->contains(MetadataFlags::HAS_DEFAULT) !== ($formal['default'] !== null)) { return $result; }
            if ($formal['declared'] === null ? $parameter->declaredType !== null
                : !self::metadata($parameter->declaredType, false, false, $path, $formal['declaredSpan'], $projectRoot)
                    || !self::domain($parameter->declaredType->type, $formal['declared'], $source, true)) { return $result; }
            if ($formal['doc'] === null) {
                // The observed Container::make array domain originates in its
                // actual Container interface, not an arbitrary inherited tag.
                if (!self::inheritedArray($context, $parameter->type, $source, $path, $projectRoot, $result)) { return $result; }
            } elseif (!self::metadata($parameter->type, true, false, $path, $source['tags']['param:'.$formal['name']]['span'], $projectRoot)
                || !self::domain($parameter->type->type, $formal['doc'], $source)) { return $result; }
            if ($formal['default'] === null) {
                if ($parameter->defaultType !== null) { return $result; }
            } else {
                $prefix = self::slice($bytes, $formal['defaultPrefixSpan']);
                if ($prefix === null || !preg_match('/^\s*(=)\s*$/D', $prefix, $match, PREG_OFFSET_CAPTURE)) { return $result; }
                $defaultSpan = [$formal['defaultPrefixSpan'][0] + $match[1][1], $formal['defaultExpressionSpan'][1]];
                if (!self::metadata($parameter->defaultType, false, true, $path, $defaultSpan, $projectRoot)
                    || !self::domain($parameter->defaultType->type, $formal['default'], $source)) { return $result; }
            }
            if ($formal['closureThis']) {
                if (!self::metadata($parameter->closureThisType, true, false, $path,
                    $source['tags']['param-closure-this:'.$formal['name']]['span'], $projectRoot)
                    || !self::domain($parameter->closureThisType->type, '$this', $source)) { return $result; }
            } elseif ($parameter->closureThisType !== null) { return $result; }
        }
        $result['stage'] = 'current source-declared local-cache return domain';
        if (!self::metadata($native->returnType, true, false, $path, $source['tags']['return']['span'], $projectRoot)
            || !self::domain($native->returnType->type, $source['returnKind'], $source)) { return $result; }
        foreach ($result['sourceHashes'] as $file => $hash) { if (@hash_file('sha256', $file) !== $hash) { return $result; } }
        $result['stage'] = 'current physical local-cache signature admitted'; $result['admitted'] = true;
        return $result;
    }

    private static function inheritedArray(IssueFilterContext $context, ?TypeMetadata $effective, array $source,
        string $methodPath, string $root, array &$result): bool
    {
        if (strcasecmp($source['sourceClass'], self::CONTAINER) !== 0 || strcasecmp($source['name'], 'make') !== 0
            || $effective === null || !$effective->fromDocblock || !$effective->inferred
            || !self::domain($effective->type, 'array', $source)) { return false; }
        $relative = 'vendor/laravel/framework/src/Illuminate/Contracts/Container/Container.php';
        $path = self::path($relative, $root);
        if ($path === null || self::samePath($path, $methodPath)) { return false; }
        $reader = new PhpSource($root); $nodes = $reader->read($relative);
        if ($nodes === null) { return false; }
        $interfaces = (new NodeFinder)->find($nodes, static fn(Node $node): bool => $node instanceof Node\Stmt\Interface_
            && strcasecmp($node->namespacedName?->toString() ?? '', self::CONTRACT) === 0);
        if (count($interfaces) !== 1) { return false; }
        $interface = $interfaces[0]; $method = $interface->getMethod('make');
        if ($method === null || $method->stmts !== null) { return false; }
        $recipe = self::source($method, self::CONTRACT); $hash = $reader->contentHash($relative);
        if ($recipe === null || $hash === null || !$reader->isCurrent()
            || !self::located($effective->location, $path, $recipe['tags']['param:$parameters']['span'], $root)) { return false; }
        $classSpan = self::span($interface); $nameSpan = self::span($interface->name);
        // Release whole syntax trees before any reentrant SDK RPC.
        unset($reader, $nodes, $interfaces, $interface, $method);
        $result['sourceHashes'][$path] = $hash;
        $result['nativeBindings'][] = ['kind' => 'interface', 'name' => self::CONTRACT];
        $class = $context->codebase->getInterface(self::CONTRACT);
        if ($class === null || $class->kind !== ClassLikeKind::Interface || $class->hasIncompleteHierarchy()
            || $class->flags->contains(MetadataFlags::BUILTIN) || strcasecmp($class->name, self::CONTRACT) !== 0
            || strcasecmp($class->originalName, self::CONTRACT) !== 0
            || !self::located($class->location, $path, $classSpan, $root)
            || !self::located($class->nameLocation, $path, $nameSpan, $root)) { return false; }
        $result['nativeBindings'][] = ['kind' => 'method', 'queryClass' => self::CONTRACT, 'name' => 'make'];
        $native = $context->codebase->getDeclaringMethod(self::CONTRACT, 'make');
        if ($native === null || $native->kind !== FunctionLikeKind::Method || $native->identifier->kind !== IdentifierKind::Method || !$native->abstract || $native->static
            || strcasecmp($native->identifier->class ?? '', self::CONTRACT) !== 0 || $native->identifier->name !== 'make'
            || $native->flags->contains(MetadataFlags::BUILTIN) || $native->flags->contains(MetadataFlags::BY_REFERENCE)
            || !self::located($native->location, $path, $recipe['span'], $root)
            || !self::located($native->nameLocation, $path, $recipe['nameSpan'], $root)
            || count($native->parameters) !== 2 || !self::templates($native, $recipe)) { return false; }
        // Verify the complete physical interface signature, including its own
        // conditional return, abstract domain, declared array and default.
        // Its parameter is explicitly documented, so this does not recurse
        // into the inferred Container::make fallback a second time.
        $contract = self::current($context, $native, $recipe, $path, $root);
        $result['interfaceSignatureStage'] = $contract['stage'];
        return $contract['admitted'] && @hash_file('sha256', $path) === $hash;
    }

    private static function profile(string $class, string $name): ?array
    {
        $key = strtolower($class.'::'.$name);
        $generic = in_array(strtolower($class), array_map('strtolower', [self::APPLICATION, self::CONTAINER, self::CONTRACT]), true)
            && in_array($name, ['make', 'resolve'], true);
        $spec = match ($key) {
            strtolower(self::APPLICATION.'::make'), strtolower(self::CONTRACT.'::make') =>
                [['$abstract', 'string|class-string<TClass>', null, null], ['$parameters', 'array', 'array', '[]']],
            strtolower(self::CONTAINER.'::make') =>
                [['$abstract', 'string|class-string<TClass>', null, null], ['$parameters', null, 'array', '[]']],
            strtolower(self::APPLICATION.'::resolve'), strtolower(self::CONTAINER.'::resolve') =>
                [['$abstract', 'string|class-string<TClass>|callable', null, null], ['$parameters', 'array', null, '[]'], ['$raiseEvents', 'bool', null, 'true']],
            strtolower(self::APPLICATION.'::booting') => [['$callback', 'callable', null, null]],
            strtolower(self::APPLICATION.'::loadDeferredProviderIfNeeded'), strtolower(self::CONTAINER.'::getAlias') => [['$abstract', 'string', null, null]],
            strtolower(self::APPLICATION.'::registerCoreContainerAliases'), strtolower(self::PROVIDER.'::register') => [],
            strtolower(self::CACHE.'::extend') => [['$driver', 'string', null, null], ['$callback', '\\Closure', 'Closure', null]],
            default => null,
        };
        if ($spec === null) { return null; }
        $parameters = [];
        foreach ($spec as [$parameter, $doc, $declared, $default]) {
            $parameters[] = ['name' => $parameter, 'doc' => $doc, 'declared' => $declared, 'default' => $default,
                'closureThis' => $key === strtolower(self::CACHE.'::extend') && $parameter === '$callback'];
        }
        $return = $generic ? 'conditional' : ($name === 'getalias' ? 'string' : ($name === 'extend' ? '$this' : 'void'));
        return ['parameters' => $parameters, 'generic' => $generic, 'interface' => strcasecmp($class, self::CONTRACT) === 0,
            'returnKind' => $return, 'returnDoc' => $generic ? '($abstract is class-string<TClass> ? TClass : mixed)' : $return];
    }

    private static function templates(FunctionLikeMetadata $native, array $source): bool
    {
        if (!$source['generic']) { return $native->templates === []; }
        if (count($native->templates) !== 1) { return false; }
        $template = $native->templates[0];
        return $template->name === 'TClass' && self::owner($template->definingEntity, $source)
            && $template->default === null && $template->variance === Variance::Invariant && !$template->readonly
            && self::objectConstraint($template->constraint);
    }

    private static function domain(Type $type, string $expected, array $source, bool $declared = false): bool
    {
        if ($expected === 'string|class-string<TClass>' || $expected === 'string|class-string<TClass>|callable') {
            if (!self::flags($type, false) || count($type->atomicTypes) !== ($expected === 'string|class-string<TClass>' ? 2 : 3)) { return false; }
            $kinds = [];
            foreach ($type->atomicTypes as $atomic) {
                $kind = self::generalString($atomic) ? 'string' : (self::classString($atomic, $source) ? 'class-string'
                    : (self::callable($atomic, false, false) ? 'callable' : 'unsupported'));
                if (isset($kinds[$kind])) { return false; } $kinds[$kind] = true;
            }
            return isset($kinds['string'], $kinds['class-string']) && !isset($kinds['unsupported'])
                && ($expected !== 'string|class-string<TClass>|callable' || isset($kinds['callable']));
        }
        if (count($type->atomicTypes) !== 1) { return false; }
        $atomic = $type->atomicTypes[0];
        if ($expected === 'conditional') {
            return self::flags($type, true) && $atomic instanceof ConditionalType && !$atomic->negated
                && count($atomic->subject->atomicTypes) === 1 && self::flags($atomic->subject, false)
                && $atomic->subject->atomicTypes[0] instanceof VariableType && $atomic->subject->atomicTypes[0]->name === '$abstract'
                && count($atomic->target->atomicTypes) === 1 && self::flags($atomic->target, false)
                && self::classString($atomic->target->atomicTypes[0], $source)
                && count($atomic->then->atomicTypes) === 1 && self::flags($atomic->then, true)
                && $atomic->then->atomicTypes[0] instanceof GenericParameterType
                && $atomic->then->atomicTypes[0]->name === 'TClass' && self::owner($atomic->then->atomicTypes[0]->definingEntity, $source)
                && in_array($atomic->then->atomicTypes[0]->intersections, [null, []], true)
                && self::objectConstraint($atomic->then->atomicTypes[0]->constraint) && self::domain($atomic->otherwise, 'mixed', $source);
        }
        if ($expected === '$this') {
            return self::flags($type, true) && $atomic instanceof NamedObjectType && $atomic->name === '$this'
                && $atomic->static && $atomic->isThis && !$atomic->remappedParameters
                && in_array($atomic->parameters, [null, []], true) && in_array($atomic->variances, [null, []], true)
                && in_array($atomic->intersections, [null, []], true);
        }
        if (!self::flags($type, false)) { return false; }
        return match ($expected) {
            'string' => self::generalString($atomic),
            'bool' => $atomic instanceof ScalarType && $atomic->kind === ScalarTypeKind::Boolean && $atomic->refinement === null,
            'true' => $atomic instanceof ScalarType && $atomic->kind === ScalarTypeKind::Boolean && $atomic->refinement === true,
            'void' => $atomic instanceof SimpleAtomicType && $atomic->kind === SimpleAtomicTypeKind::Void,
            'mixed' => $atomic instanceof MixedType && !$atomic->empty && !$atomic->issetFromLoop && !$atomic->nonNull
                && $atomic->truthiness === MixedTruthiness::Undetermined,
            'array' => $atomic instanceof KeyedArrayType && $atomic->knownItems === null && !$atomic->nonEmpty
                && $atomic->keyType !== null && $atomic->valueType !== null && self::arrayKey($atomic->keyType)
                && self::domain($atomic->valueType, 'mixed', $source),
            '[]' => $atomic instanceof ListType && !$atomic->nonEmpty && $atomic->knownCount === 0 && $atomic->knownElements === []
                && count($atomic->elementType->atomicTypes) === 1 && self::flags($atomic->elementType, false)
                && $atomic->elementType->atomicTypes[0] instanceof SimpleAtomicType
                && $atomic->elementType->atomicTypes[0]->kind === SimpleAtomicTypeKind::Never,
            'callable' => self::callable($atomic, false, false),
            '\\Closure', 'Closure' => self::callable($atomic, true, $declared),
            default => false,
        };
    }

    private static function callable(object $atomic, bool $closure, bool $hasDefault): bool
    {
        if (!$atomic instanceof CallableType || $atomic->alias !== null || $atomic->signature === null) { return false; }
        $signature = $atomic->signature;
        if ($signature->closure !== $closure || $signature->pure || $signature->source !== null
            || $signature->constraints !== [] || count($signature->parameters) !== 1 || $signature->returnType === null) { return false; }
        $parameter = $signature->parameters[0];
        return $parameter->name === null && !$parameter->byReference && $parameter->variadic
            && $parameter->hasDefault === $hasDefault && $parameter->closureThisType === null
            && $parameter->type !== null && self::domain($parameter->type, 'mixed', [])
            && self::domain($signature->returnType, 'mixed', []);
    }
    private static function generalString(object $atomic): bool
    {
        return $atomic instanceof ScalarType && $atomic->kind === ScalarTypeKind::String && $atomic->refinement instanceof StringType
            && $atomic->refinement->literalKind === StringLiteralKind::General && $atomic->refinement->literalValue === null
            && !$atomic->refinement->nonEmpty && !$atomic->refinement->truthy && !$atomic->refinement->numeric
            && !$atomic->refinement->callable && $atomic->refinement->casing === StringCasing::Unspecified;
    }
    private static function classString(object $atomic, array $source): bool
    {
        if (!$atomic instanceof ScalarType || $atomic->kind !== ScalarTypeKind::ClassLikeString
            || !$atomic->refinement instanceof ClassLikeStringType) { return false; }
        $refinement = $atomic->refinement;
        return $refinement->variant === ClassLikeStringVariant::Generic && $refinement->kind === ClassLikeStringKind::Class_
            && $refinement->literal === null && $refinement->parameterName === 'TClass'
            && $refinement->constraint instanceof AnyObjectType && $refinement->definingEntity !== null
            && self::owner($refinement->definingEntity, $source);
    }
    private static function owner(GenericParent $owner, array $source): bool
    {
        return $owner->kind === GenericParentKind::FunctionLike && strcasecmp($owner->name, $source['sourceClass']) === 0
            && strcasecmp($owner->member ?? '', $source['name']) === 0;
    }
    private static function objectConstraint(Type $type): bool
    {
        // POPULATED is a documented codebase lifecycle bit on this plain
        // builtin object constraint. All other flags and all atoms stay exact.
        return self::flags($type, null) && count($type->atomicTypes) === 1 && $type->atomicTypes[0] instanceof AnyObjectType;
    }
    private static function flags(Type $type, ?bool $populated): bool
    {
        $expected = ['hadTemplate', 'byReference', 'referenceFree', 'possiblyUndefinedFromTry', 'possiblyUndefined',
            'ignoreNullableIssues', 'ignoreFalsableIssues', 'fromTemplateDefault', 'populated', 'nullsafeNull', 'fromUnspecifiedTemplate'];
        $actual = get_object_vars($type->flags);
        if (array_keys($actual) !== $expected) { return false; }
        foreach ($actual as $name => $value) {
            if (!is_bool($value) || ($name === 'populated' ? $populated !== null && $value !== $populated : $value !== false)) { return false; }
        }
        return true;
    }
    private static function arrayKey(Type $type): bool
    {
        return self::flags($type, false) && count($type->atomicTypes) === 1 && $type->atomicTypes[0] instanceof ScalarType
            && $type->atomicTypes[0]->kind === ScalarTypeKind::ArrayKey && $type->atomicTypes[0]->refinement === null;
    }
    private static function metadata(?TypeMetadata $metadata, bool $doc, bool $inferred, string $path, array $span, string $root): bool
    {
        return $metadata !== null && $metadata->fromDocblock === $doc && $metadata->inferred === $inferred
            && self::located($metadata->location, $path, $span, $root);
    }
    private static function located(?SourceLocation $location, string $path, array $span, string $root): bool
    {
        $file = $location === null ? null : self::path($location->file, $root);
        return $file !== null && self::samePath($file, $path) && [$location->span->start, $location->span->end] === $span;
    }
    private static function path(string $path, string $root): ?string
    {
        $path = str_replace('\\', '/', $path); $root = rtrim(str_replace('\\', '/', $root), '/');
        if (str_starts_with($path, '//?/')) { $path = substr($path, 4); }
        if (!str_starts_with($path, '/') && !preg_match('~^[A-Za-z]:/~', $path)) { $path = $root.'/'.$path; }
        if (preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path)) { return null; }
        $comparison = DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
        $rootComparison = DIRECTORY_SEPARATOR === '\\' ? strtolower($root) : $root;
        return str_starts_with($comparison, $rootComparison.'/') ? $path : null;
    }
    private static function samePath(string $a, string $b): bool
    {
        return DIRECTORY_SEPARATOR === '\\' ? strcasecmp($a, $b) === 0 : $a === $b;
    }
    private static function nativeSyntax(?Node $node): ?string
    {
        if ($node === null) { return null; }
        return $node instanceof Node\Identifier ? strtolower($node->name)
            : ($node instanceof Node\Name && strcasecmp($node->toString(), 'Closure') === 0 ? 'Closure' : 'unsupported');
    }
    private static function defaultSyntax(?Node $node): ?string
    {
        if ($node === null) { return null; }
        if ($node instanceof Node\Expr\Array_ && $node->items === []) { return '[]'; }
        return $node instanceof Node\Expr\ConstFetch && strtolower($node->name->toString()) === 'true' ? 'true' : 'unsupported';
    }
    private static function slice(string $bytes, array $span): ?string
    {
        return count($span) === 2 && is_int($span[0]) && is_int($span[1]) && $span[0] >= 0 && $span[1] >= $span[0]
            && $span[1] <= strlen($bytes) ? substr($bytes, $span[0], $span[1] - $span[0]) : null;
    }
    private static function span(Node $node): array { return [$node->getStartFilePos(), $node->getEndFilePos() + 1]; }
}
