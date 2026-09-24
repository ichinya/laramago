<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ContainerBindings;
use Ichinya\Laramago\Analyzer\StaticAnalysis\ModelReflection;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\AliasType;
use Mago\Sdk\Analyzer\Type\CallableType;
use Mago\Sdk\Analyzer\Type\ConditionalType;
use Mago\Sdk\Analyzer\Type\FunctionLikeKind;
use Mago\Sdk\Analyzer\Type\GenericParameterType;
use Mago\Sdk\Analyzer\Type\IntegerType;
use Mago\Sdk\Analyzer\Type\IntegerTypeKind;
use Mago\Sdk\Analyzer\Type\MixedType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind;
use Mago\Sdk\Analyzer\Type\VariableType;
use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard;

/** Carries analyzed closure results through Laravel's standard DB facade contract. */
final class TransactionProvider implements MethodReturnTypeProvider
{
    private const DB = 'Illuminate\\Support\\Facades\\DB';

    private readonly FacadeCallResolver $facades;
    private readonly PhpSource $source;
    private readonly ContainerBindings $bindings;
    private ?bool $nativeForwarding = null;

    public function __construct(string $projectRoot = '.')
    {
        $this->facades = new FacadeCallResolver($projectRoot);
        $this->source = new PhpSource($projectRoot);
        $this->bindings = new ContainerBindings($projectRoot);
    }

    public function getTargets(): array
    {
        return [MethodTarget::exact(self::DB, 'transaction')];
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $call = $context->invocation;
        $receiver = $call->receiverType;
        $object = $receiver?->atomicTypes[0] ?? null;
        if (
            $receiver === null
            || count($receiver->atomicTypes) !== 1
            || ! $object instanceof NamedObjectType
            || $object->name !== self::DB
            || $this->facades->rootClass($context->codebase, $receiver) !== 'Illuminate\\Database\\DatabaseManager'
        ) {
            return null;
        }
        if (
            $this->bindings->configured('db.connection')
            || $this->bindings->configured("Illuminate\\Database\\ConnectionInterface")
        ) {
            return null;
        }
        $standard = $context->codebase->getDeclaringMethod("Illuminate\\Database\\Connection", 'transaction');
        $standardResult = $standard?->returnType?->type->atomicTypes[0] ?? null;
        if (
            $standard === null
            || ! str_ends_with(
                str_replace("\\", '/', $this->source->path($standard->location->file ?? '')),
                '/laravel/framework/src/Illuminate/Database/Concerns/ManagesTransactions.php',
            )
            || ! $standardResult instanceof GenericParameterType
        ) {
            return null;
        }
        $standardCallback = $standard->parameters[0]->type->type->atomicTypes[0] ?? null;
        $callbackResult = $standardCallback instanceof CallableType
            ? $standardCallback->signature?->returnType?->atomicTypes[0] ?? null
            : null;
        if (
            ! $standardCallback instanceof CallableType
            || ! $standardCallback->signature?->closure
            || ! $callbackResult instanceof GenericParameterType
            || $callbackResult != $standardResult
            || count($standard->parameters) !== 2
            || $standard->parameters[0]->name !== '$callback'
            || $standard->parameters[1]->name !== '$attempts'
        ) {
            return null;
        }
        $class = $context->codebase->getClassLike(self::DB);
        if (! str_ends_with(
            str_replace('\\', '/', $this->source->path($class?->location->file ?? '')),
            '/laravel/framework/src/Illuminate/Support/Facades/DB.php',
        )) {
            return null;
        }
        $method = $context->codebase->getMethod(self::DB, 'transaction');
        $reflection = new ModelReflection($context->codebase, $this->source);
        if (
            $method === null
            || $reflection->methodNode($method) !== null
            || ! ($method->returnType->type->atomicTypes[0] ?? null) instanceof MixedType
        ) {
            return null;
        }
        $dispatcher = $context->codebase->getDeclaringMethod(self::DB, '__callStatic');
        if ($dispatcher?->identifier->class !== FacadeCallResolver::FACADE) {
            return null;
        }
        foreach ($call->arguments as $argument) {
            if (
                $argument->unpacked
                || $argument->placeholder
                || $argument->name !== null
                && ! in_array($argument->name, ['callback', 'attempts'], true)
            ) {
                return null;
            }
        }
        if (count($call->arguments) > 2) {
            return null;
        }
        $callback = $call->getArgument(0, 'callback')?->type;
        if ($callback === null) {
            return null;
        }
        $result = null;
        foreach ($callback->atomicTypes as $atom) {
            if (! $atom instanceof CallableType || $atom->signature !== null && ! $atom->signature->closure) {
                return null;
            }
            $return = $atom->signature?->returnType;
            $identifier = $atom->signature?->source;
            if ($atom->alias !== null) {
                if ($atom->alias->kind !== FunctionLikeKind::Closure) {
                    return null;
                }
                $identifier = $atom->alias;
                $metadata = $context->codebase->getFunctionLike($identifier);
                $return = $metadata?->returnType->type ?? $metadata?->declaredReturnType?->type;
            }
            if (
                $identifier?->kind === FunctionLikeKind::Closure
                && ($return === null || self::unresolved($return))
            ) {
                $native = $context->codebase->getFunctionLike($identifier)?->declaredReturnType;
                // A native closure return type is enforced by PHP even when
                // body inference retains an unresolved contextual type.
                if (
                    $native !== null
                    && ! $native->fromDocblock
                    && ! $native->inferred
                    && ! self::unresolved($native->type)
                    && $this->nativeForwarding($context, $standard)
                ) {
                    $return = $native->type;
                }
            }
            if (
                $return === null
                || array_filter($return->atomicTypes, static fn ($part): bool => $part instanceof MixedType) !== []
                || self::unresolved($return)
            ) {
                return null;
            }
            // Mixed elements retain their containing array/object contract; unresolved
            // templates and contextual types still belong to the native analyzer.
            foreach ($return->atomicTypes as $part) {
                if ($part instanceof SimpleAtomicType && $part->kind === SimpleAtomicTypeKind::Void) {
                    $return = Type::null();
                }
            }
            $result = $result === null ? $return : Type::union($result, $return);
        }
        $attempts = $call->getArgument(1, 'attempts');
        if ($attempts === null) {
            return $result;
        }
        if ($attempts->type === null || ! $context->types->isContainedBy($attempts->type, Type::int())) {
            return null;
        }
        if ($context->types->isContainedBy(
            $attempts->type,
            Type::fromAtomic(new ScalarType(ScalarTypeKind::Integer, new IntegerType(IntegerTypeKind::From, 1))),
        )) {
            return $result;
        }
        if ($context->types->isContainedBy(
            $attempts->type,
            Type::fromAtomic(new ScalarType(ScalarTypeKind::Integer, new IntegerType(IntegerTypeKind::To, maximum: 0))),
        )) {
            return Type::null();
        }

        return Type::union($result, Type::null());
    }

    private static function unresolved(mixed $value): bool
    {
        if (
            $value instanceof GenericParameterType
            || $value instanceof ConditionalType
            || $value instanceof AliasType
            || $value instanceof VariableType
        ) {
            return true;
        }
        if ($value instanceof NamedObjectType && ($value->static || $value->isThis)) {
            return true;
        }
        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        return is_array($value) && array_filter($value, self::unresolved(...)) !== [];
    }

    /** Verify the installed facade, manager and connection return the callback result. */
    private function nativeForwarding(ReturnTypeProviderContext $context, FunctionLikeMetadata $standard): bool
    {
        if ($this->nativeForwarding !== null) {
            return $this->nativeForwarding;
        }
        $reflection = new ModelReflection($context->codebase, $this->source);
        $methods = [
            [
                'Illuminate\\Support\\Facades\\Facade', '__callStatic', 'Support/Facades/Facade.php',
                ['8813e8c2f49c311d7a726a5e2715730809f4691de133e99cec786ed30b7295a0', 'baad17d9ba4289202784f958b6d184c8936fed4723b3c51b2f7eb38d353bbacd'],
            ],
            [
                'Illuminate\\Database\\DatabaseManager', '__call', 'Database/DatabaseManager.php',
                ['fc784a81307969508645d6bd57825d6d84a844ebd05911ee26af8d16ee75c198', '19e835ab439a3ea7a11024b8c0bf06f0ef629b070493bece0088e535b61217fc'],
            ],
            [
                'Illuminate\\Database\\DatabaseManager', 'connection', 'Database/DatabaseManager.php',
                ['214cbffcc5cdef30c96179408e5ec08705015930d1a3c9e8ff0758bcf72ed618', 'd04b45429f5e8ba6f4d86e5e5ba32140596fefe472ea9782086ea28c1f0fb282'],
            ],
            [
                'Illuminate\\Database\\Connection', 'transaction', 'Database/Concerns/ManagesTransactions.php',
                ['d96c6f269c69ebc3420a70187a9256382792110bb673146861eea331c48b82c3', '4c879b7a9deefdbe4587015e8ae9a74a900cf8b32417002bddb80054a56e2674'],
            ],
        ];
        foreach ($methods as [$class, $name, $file, $hashes]) {
            $method = $name === 'transaction' ? $standard : $context->codebase->getDeclaringMethod($class, $name);
            if (
                $method === null
                || strcasecmp($method->identifier->class ?? '', $name === 'transaction' ? 'Illuminate\\Database\\Concerns\\ManagesTransactions' : $class) !== 0
                || ! str_ends_with(
                    str_replace('\\', '/', $this->source->path($method->location->file ?? '')),
                    '/laravel/framework/src/Illuminate/'.$file,
                )
            ) {
                return $this->nativeForwarding = false;
            }
            $node = $reflection->methodNode($method);
            if ($node === null || ! in_array(self::fingerprint($node), $hashes, true)) {
                return $this->nativeForwarding = false;
            }
        }

        return $this->nativeForwarding = true;
    }

    private static function fingerprint(Node $node): string
    {
        $normalized = '';
        foreach (token_get_all('<?php '.(new Standard)->prettyPrint([$node])) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT], true)) {
                    continue;
                }
                $normalized .= str_replace(["\r\n", "\r"], "\n", $token[1]);
            } else {
                $normalized .= $token;
            }
        }

        return hash('sha256', $normalized);
    }
}
