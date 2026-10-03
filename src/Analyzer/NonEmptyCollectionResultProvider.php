<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ModelReflection;
use Ichinya\Laramago\Analyzer\StaticAnalysis\NonEmptyCollectionCalls;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\InvocationKind;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\Visibility;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;

/** Preserve the item type for audited Laravel and native PHP 8.5 array helpers. */
final class NonEmptyCollectionResultProvider implements MethodReturnTypeProvider, InitializationHook
{
    private const COLLECTION = 'Illuminate\\Support\\Collection';
    private const ELOQUENT = 'Illuminate\\Database\\Eloquent\\Collection';
    private const ARR = 'Illuminate\\Support\\Arr';
    private const ENUMERATES = 'Illuminate\\Support\\Traits\\EnumeratesValues';
    private const METHODS = [
        self::COLLECTION => [
            '__construct' => '74fe1ac3022426e464b6098b2c399a3098f0f9a2791ca3e622279c7d1035d587',
            'isEmpty' => '23a9cacd310ff203d6106b99a637e82861bf37c3793727d0e92914881c88cc23',
            'first' => 'aee6882462785459088b3afab45493820990e7b8ffbba8f0a62d5615e4a34709',
            'last' => '375f4ef6bc9d9f6ef6d4630702f8432e7862e78b445b766e1f23216fe0b8a6b4',
        ],
        self::ARR => [
            'from' => 'ea663323bb49b41e6dd951a73ac502b071aaf3e13e5886a2c5c5e6ba6027a6ba',
            'first' => '5e61953b981c91c92723ba2f7aba9163b6b9765415678ea19edccc54ae60662a',
            'last' => 'fa5ded9e287262e635a700e072e97cb37b8fa513df541be8284ba1b33ce8eb28',
        ],
        self::ENUMERATES => ['getArrayableItems' => 'b9261f1a5556357b2372adb26f6f3c28073ac2a85e3b2a3acce4a90d57a75246'],
    ];
    private const FILES = [
        self::COLLECTION => 'Illuminate/Collections/Collection.php',
        self::ELOQUENT => 'Illuminate/Database/Eloquent/Collection.php',
        self::ARR => 'Illuminate/Collections/Arr.php',
        self::ENUMERATES => 'Illuminate/Collections/Traits/EnumeratesValues.php',
    ];
    private const DOCS = [
        self::COLLECTION => '396138adb0ae5aff9a8ab41fe1f1bcb75b130d109bb80741dc35cf2a24e5d101',
        self::ELOQUENT => '9bf7b1f7e37eddd396683fcedeb69f90aab343765f575434ab2fbe898736bef1',
        self::ENUMERATES => '9e6b154190265c80a2caa71248d73355a0debca6be31fb17a315d5d050ae1029',
    ];

    public readonly NonEmptyCollectionCalls $calls;
    private PhpSource $source;
    private int $generation = -1;

    public function __construct(private readonly string $root = '.')
    {
        $this->calls = new NonEmptyCollectionCalls($root);
        $this->source = new PhpSource($root);
    }

    public function initialize(InitializationContext $context): void
    {
        $this->calls->reset();
        $this->source = new PhpSource($this->root);
        $this->generation = -1;
    }
    public function getTargets(): array
    {
        return [MethodTarget::exact(self::COLLECTION, 'first'), MethodTarget::exact(self::COLLECTION, 'last'),
            MethodTarget::exact(self::ELOQUENT, 'first'), MethodTarget::exact(self::ELOQUENT, 'last')];
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        if ($this->generation !== $this->calls->generation) {
            $this->source = new PhpSource($this->root);
            $this->generation = $this->calls->generation;
        }
        $call = $context->invocation;
        $candidate = $this->calls->call($call->span);
        $receiver = $call->receiverType?->atomicTypes[0] ?? null;
        if (! $context->phpVersion->isAtLeast(\Mago\Sdk\PHPVersion::fromParts(8, 5))
            || $candidate === null || $call->kind !== InvocationKind::InstanceMethod || $call->arguments !== []
            || ! in_array(strtolower($call->name), ['first', 'last'], true)
            || strcasecmp($call->declaringClass ?? '', self::COLLECTION) !== 0
            || strcasecmp($call->name, $candidate['call']->name->name) !== 0
            || count($call->receiverType?->atomicTypes ?? []) !== 1 || ! $receiver instanceof NamedObjectType
            || ! in_array($receiver->name, [self::COLLECTION, self::ELOQUENT], true)
            || strcasecmp($receiver->name, $candidate['origin']->class->toString()) !== 0
            || $receiver->static || $receiver->isThis || ($receiver->intersections ?? []) !== []
            || count($receiver->parameters ?? []) !== 2) { return null; }
        $value = $receiver->parameters[1];
        if (! ExplicitGenericType::concrete($value)) { return null; }
        foreach ($value->atomicTypes as $atomic) {
            if ($atomic instanceof SimpleAtomicType) { return null; }
        }
        if (! $this->callerMatches($context, $candidate) || ! $this->native($context, $receiver->name, strtolower($call->name))) { return null; }
        return $value;
    }

    /** @param array{file: string, owner: ?string, name: string, scope: Node\Stmt\Function_|Node\Stmt\ClassMethod, call: Node\Expr\MethodCall, origin: Node\Expr\New_} $candidate */
    private function callerMatches(ReturnTypeProviderContext $context, array $candidate): bool
    {
        $scope = $candidate['scope'];
        $owner = $candidate['owner'];
        $name = $candidate['name'];
        $caller = $owner === null ? $context->codebase->getFunction($name) : $context->codebase->getDeclaringMethod($owner, $name);
        if ($caller === null || strcasecmp($caller->identifier->class ?? '', $owner ?? '') !== 0
            || strcasecmp($caller->identifier->name, $name) !== 0
            || $this->calls->path($caller->location->file ?? '') !== $this->calls->path($candidate['file'])
            || $caller->nameLocation?->span->start !== $scope->name->getStartFilePos()
            || $caller->nameLocation?->span->end !== $scope->name->getEndFilePos() + 1
            || $caller->location->span->start > $scope->getStartFilePos()
            || $caller->location->span->end !== $scope->getEndFilePos() + 1
            || $caller->flags->contains(MetadataFlags::BY_REFERENCE) || $caller->globalsAccessed !== []
            || count($caller->parameters) !== count($scope->params)) { return false; }
        foreach ($scope->params as $index => $syntax) {
            $parameter = $caller->parameters[$index];
            if ($parameter->name !== '$'.$syntax->var->name
                || $parameter->nameLocation->span->start !== $syntax->var->getStartFilePos()
                || $parameter->nameLocation->span->end !== $syntax->var->getEndFilePos() + 1
                || $parameter->flags->contains(MetadataFlags::BY_REFERENCE)
                || $parameter->flags->contains(MetadataFlags::VARIADIC) !== $syntax->variadic) { return false; }
        }
        return true;
    }

    private function native(ReturnTypeProviderContext $context, string $class, string $getter): bool
    {
        $codebase = $context->codebase;
        $reflection = new ModelReflection($codebase, $this->source);
        foreach (['__construct', 'isEmpty', $getter] as $name) {
            if (strcasecmp($codebase->getDeclaringMethod($class, $name)?->identifier->class ?? '', self::COLLECTION) !== 0) { return false; }
        }
        if (strcasecmp($codebase->getDeclaringMethod($class, 'getArrayableItems')?->identifier->class ?? '', self::ENUMERATES) !== 0) { return false; }
        foreach ([self::COLLECTION, self::ENUMERATES, self::ARR, $class] as $owner) {
            $metadata = $codebase->getClassLike($owner);
            $file = $metadata?->location->file;
            if ($metadata === null || $metadata->hasIncompleteHierarchy() || ! self::nativeFile($file, self::FILES[$owner])
                || ! $this->calls->sourceMatches($file)) { return false; }
            $node = (new NodeFinder)->findFirst($this->source->read($file) ?? [], static fn (Node $node): bool =>
                $node instanceof Node\Stmt\ClassLike && isset($node->namespacedName) && strcasecmp($node->namespacedName->toString(), $owner) === 0);
            if ($node === null || $metadata->location->span->start > $node->getStartFilePos()
                || $metadata->location->span->end !== $node->getEndFilePos() + 1
                || $metadata->nameLocation?->span->start !== $node->name->getStartFilePos()
                || $metadata->nameLocation?->span->end !== $node->name->getEndFilePos() + 1) { return false; }
            if (isset(self::DOCS[$owner]) && hash('sha256', str_replace(["\r\n", "\r"], "\n", $node->getDocComment()?->getText() ?? '')) !== self::DOCS[$owner]) { return false; }
            if ($owner === self::COLLECTION) {
                $property = (new NodeFinder)->findFirst($node->stmts, static fn (Node $node): bool =>
                    $node instanceof Node\Stmt\Property && count($node->props) === 1 && $node->props[0]->name->name === 'items');
                if ($property === null || self::fingerprint($property) !== '11fcb9e800dc23240bc49849f2132613d4739d60e6d6fb06dc5cfd94de833782') { return false; }
            }
        }
        $property = $codebase->getDeclaringProperty($class, '$items');
        // Mago omits source locations for included property metadata. The parsed
        // native declaration above establishes storage; exposed hooks still veto.
        if ($property === null || $property->hooks !== []
            || $property->location !== null && ! self::nativeFile($property->location->file, self::FILES[self::COLLECTION])) { return false; }
        foreach (self::METHODS as $owner => $methods) {
            foreach ($methods as $name => $hash) {
                if (in_array($name, ['first', 'last'], true) && $name !== $getter) { continue; }
                $method = $codebase->getDeclaringMethod($owner, $name);
                $file = $method?->location->file;
                if ($method === null || strcasecmp($method->identifier->class ?? '', $owner) !== 0
                    || $method->abstract || $method->flags->contains(MetadataFlags::BY_REFERENCE)
                    || $method->static !== ($owner === self::ARR)
                    || $method->visibility !== ($name === 'getArrayableItems' ? Visibility::Protected : Visibility::Public)
                    || ! self::nativeFile($file, self::FILES[$owner]) || ! $this->calls->sourceMatches($file)) { return false; }
                $node = $reflection->methodNode($method);
                if ($node === null || self::fingerprint($node) !== $hash || count($method->parameters) !== count($node->params)) { return false; }
                foreach ($node->params as $index => $syntax) {
                    $parameter = $method->parameters[$index];
                    if ($parameter->name !== '$'.$syntax->var->name || $parameter->flags->contains(MetadataFlags::BY_REFERENCE)
                        || $parameter->flags->contains(MetadataFlags::VARIADIC)) { return false; }
                }
            }
        }
        foreach (['is_null', 'is_scalar', 'is_array', 'array_first', 'array_last'] as $name) {
            if (! ($codebase->getFunction($name)?->flags->contains(MetadataFlags::BUILTIN) ?? false)
                || $codebase->functionExists('Illuminate\\Support\\'.$name)
                || $codebase->functionExists('Illuminate\\Support\\Traits\\'.$name)) { return false; }
        }
        return true;
    }

    private static function nativeFile(?string $file, string $suffix): bool
    {
        return str_ends_with(str_replace('\\', '/', $file ?? ''), '/laravel/framework/src/'.$suffix);
    }

    private static function fingerprint(Node $node): string
    {
        $normalized = '';
        foreach (token_get_all('<?php '.(new Standard)->prettyPrint([$node])) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_OPEN_TAG], true)) { continue; }
                $normalized .= str_replace(["\r\n", "\r"], "\n", $token[1]);
            } else { $normalized .= $token; }
        }
        return hash('sha256', $normalized);
    }
}
