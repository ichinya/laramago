<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\EvaluatedRuntime;
use Ichinya\Laramago\Analyzer\StaticAnalysis\EnvironmentDefaultContracts;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\FunctionReturnTypeProvider;
use Mago\Sdk\Analyzer\FunctionTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\MixedType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;

/**
 * Refines native literal env reads from either opt-in evaluated values or the
 * installed Laravel source. Explicit scalar defaults declare the generalized
 * return type under Larastan's policy. Calls without a default retain the
 * native string, boolean and null possibilities.
 * Machine-dependent literals are never produced; uncertain calls defer.
 */
final class EnvironmentValueProvider implements FunctionReturnTypeProvider
{
    /** Laravel's env helper and Env methods in the installed framework source. */
    private const NATIVE_BODIES = [
        'helper' => '3ed0edccf0160a2a66fa82f8145d0b2d6d7fc9b5691852239420c3538328b41a',
        'get' => '521aed191e1f51c685672e55ac57de3a1bc09e92303f186461040a0fc61e53e5',
        'getOption' => 'b14b5ff49f0942e928739daa650c1eb28075624cfb689dab20f59b8fd1bdbc58',
        'getRepository' => 'e5e427937a27e31bff257e95251294da6b0d228dad5bc36d33b9e99de973a232',
    ];

    private ?PhpSource $source = null;

    public function __construct(
        private readonly string $root,
    ) {}

    public function getTargets(): array
    {
        return [FunctionTarget::exact('env')];
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $return = $this->frameworkHelper($context->codebase)?->returnType?->type;
        // A concrete application declaration always wins over the framework helper.
        if ($return === null || count($return->atomicTypes) !== 1 || ! $return->atomicTypes[0] instanceof MixedType) {
            return null;
        }
        $call = $context->invocation;
        $argument = $call->getArgument(0, 'key');
        $key = $argument?->type?->getLiteralString();
        if (
            $argument === null
            || $argument->unpacked
            || $argument->placeholder
            || $key === null
            || $key === ''
        ) {
            return null;
        }
        $default = $call->getArgument(1, 'default');
        if ($default !== null && ($default->unpacked || $default->placeholder || $default->type === null)) {
            return null;
        }
        $entry = EvaluatedRuntime::envEntry($this->root, $key);
        if ($entry !== null && $entry['found']) {
            return match (true) {
                is_string($entry['value']) => Type::string(),
                is_int($entry['value']) => Type::int(),
                is_float($entry['value']) => Type::float(),
                is_bool($entry['value']) => Type::bool(),
                $entry['value'] === null => Type::null(),
                default => null,
            };
        }
        // An absent variable resolves to the call's default: the helper's own
        // null when no default argument is given, matching the missing-key
        // semantics of the config provider.
        if ($entry !== null && $default === null) {
            return Type::null();
        }
        $defaultType = $default?->type ?? Type::null();
        foreach ($defaultType->atomicTypes as $atom) {
            // Non-scalar default arguments keep native behavior; Laravel's env
            // helper itself never produces arrays, so they stay unproven here.
            if (
                ! $atom instanceof ScalarType
                && ! $atom instanceof SimpleAtomicType
                && ! $atom instanceof KeyedArrayType
                && ! $atom instanceof ListType
            ) {
                return null;
            }
        }

        if ($entry !== null) {
            return $defaultType;
        }

        if (! self::nativeSource($context->codebase, $this->source ??= new PhpSource($this->root))) {
            return null;
        }
        if ($default !== null) {
            return EnvironmentDefaultContracts::type($defaultType) ?? self::staticType($defaultType);
        }

        return self::staticType($defaultType);
    }

    /** Native Env only: repository strings are normalized to string, bool or null. */
    public static function staticType(Type $default): Type
    {
        return (string) $default === 'null'
            ? Type::union(Type::string(), Type::bool(), Type::null())
            : Type::union(Type::string(), Type::bool(), Type::null(), $default);
    }

    /** Fail closed if the installed helper or any method in its value path changes. */
    public static function nativeSource(Codebase $codebase, PhpSource $source): bool
    {
        $function = $codebase->getFunction('env');
        $helperPath = $function?->location->file;
        $return = $function?->returnType?->type;
        if (
            $helperPath === null
            || ! str_ends_with(str_replace('\\', '/', $helperPath), '/laravel/framework/src/Illuminate/Support/helpers.php')
            || $return === null
            || count($return->atomicTypes) !== 1
            || ! $return->atomicTypes[0] instanceof MixedType
        ) {
            return false;
        }
        $helper = (new NodeFinder)->findFirst(
            $source->read($helperPath) ?? [],
            static fn (Node $node): bool => $node instanceof Node\Stmt\Function_
                && strcasecmp($node->name->toString(), 'env') === 0,
        );
        if (
            ! $helper instanceof Node\Stmt\Function_
            || self::fingerprint($helper->stmts ?? []) !== self::NATIVE_BODIES['helper']
        ) {
            return false;
        }
        $statement = count($helper->stmts ?? []) === 1 ? $helper->stmts[0] : null;
        $call = $statement instanceof Node\Stmt\Return_ ? $statement->expr : null;
        $name = $call instanceof Node\Expr\StaticCall && $call->class instanceof Node\Name
            ? $call->class->getAttribute('resolvedName') ?? $call->class
            : null;
        if (! $name instanceof Node\Name || strcasecmp($name->toString(), 'Illuminate\\Support\\Env') !== 0) {
            return false;
        }

        $class = $codebase->getClass('Illuminate\\Support\\Env');
        $path = $class?->location->file;
        if (
            $path === null
            || ! str_ends_with(str_replace('\\', '/', $path), '/laravel/framework/src/Illuminate/Support/Env.php')
        ) {
            return false;
        }
        $declaration = (new NodeFinder)->findFirst(
            $source->read($path) ?? [],
            static fn (Node $node): bool => $node instanceof Node\Stmt\Class_
                && $node->namespacedName?->toString() === 'Illuminate\\Support\\Env',
        );
        if (! $declaration instanceof Node\Stmt\Class_ || $declaration->extends !== null) {
            return false;
        }
        foreach (['get', 'getOption', 'getRepository'] as $name) {
            $method = $declaration->getMethod($name);
            if (
                $method === null
                || $method->returnType !== null
                || self::fingerprint($method->stmts ?? []) !== self::NATIVE_BODIES[$name]
            ) {
                return false;
            }
        }

        return true;
    }

    /** Ignore formatting and ordinary comments, retaining every source token. */
    private static function fingerprint(array $statements): string
    {
        $normalized = '';
        foreach (token_get_all('<?php '.(new Standard)->prettyPrint($statements)) as $token) {
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

    private function frameworkHelper(Codebase $codebase): ?\Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata
    {
        $function = $codebase->getFunction('env');
        $file = str_replace('\\', '/', $function?->location->file ?? '');

        return str_ends_with($file, '/laravel/framework/src/Illuminate/Support/helpers.php') ? $function : null;
    }
}
