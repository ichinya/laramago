<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\FunctionReturnTypeProvider;
use Mago\Sdk\Analyzer\FunctionTarget;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\InvocationKind;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\ArrayKeyKind;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;

/** Widen concrete list keys to int after Laravel copies the array into a Collection. */
final class CollectionHelperProvider implements FunctionReturnTypeProvider, InitializationHook
{
    private const COLLECTION = 'Illuminate\\Support\\Collection';
    private const HELPER_DOC = 'e5330e486e833b96cbc07d68b6008600819de3d0cbec440ea3b6a187859a02f9';
    private const BODIES = [
        'helper' => 'ea1f7183116511e13a8b522e0e217088a28709a11cca59dedcd750ab3d2f9342',
        'constructor' => '9db0f9d522bb87a9a74615a14d685d168e16cc88c24a46d1f553c5e951595962',
        'getArrayableItems' => 'a1db7f7a56fa3d37dda376f89465d9fc73a937ead8c62ae3ad94c4213c182dc8',
        'from' => '88f740c36ec9618ca400aa98fce978b4df99534dcb84003ed5fcb5e99a3ef256',
    ];

    private ?PhpSource $source = null;
    private ?bool $native = null;

    public function __construct(
        private readonly string $root,
    ) {}

    public function initialize(InitializationContext $context): void
    {
        $this->source = null;
        $this->native = null;
    }

    public function getTargets(): array
    {
        return [FunctionTarget::exact('collect')];
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $call = $context->invocation;
        if ($call->kind !== InvocationKind::Function || count($call->arguments) !== 1) {
            return null;
        }
        $argument = $call->getArgument(0, 'value');
        if ($argument === null || $argument->unpacked || $argument->placeholder || $argument->type === null) {
            return null;
        }
        $input = $argument->type;
        if (count($input->atomicTypes) !== 1) {
            return null;
        }
        $array = $input->atomicTypes[0];
        if ($array instanceof ListType) {
            $keys = Type::int();
            $values = $this->listValues($array);
        } elseif ($array instanceof KeyedArrayType) {
            [$keys, $values] = $this->shape($array);
        } else {
            return null;
        }
        if (
            $keys === null || $values === null || ! CollectionItemProperty::concrete($values)
            || $this->unknownValue($values)
            || ! ($this->native ??= $this->nativeSource($context->codebase))
        ) {
            return null;
        }

        return Type::namedObject(self::COLLECTION, $keys, $values);
    }

    private function listValues(ListType $array): ?Type
    {
        $values = [];
        foreach ($array->knownElements ?? [] as $element) {
            $values[(string) $element->type] = $element->type;
        }
        if (! $this->unknownValue($array->elementType)) {
            $values[(string) $array->elementType] = $array->elementType;
        }
        $result = null;
        foreach ($values as $type) {
            $result = $result === null ? $type : Type::union($result, $type);
        }

        return $result;
    }

    /** @return array{?Type, ?Type} */
    private function shape(KeyedArrayType $array): array
    {
        if ($array->keyType !== null || $array->valueType !== null || $array->knownItems === null || $array->knownItems === []) {
            return [null, null];
        }
        $integer = false;
        $string = false;
        $values = [];
        foreach ($array->knownItems as $item) {
            if ($item->key->kind === ArrayKeyKind::Integer) {
                $integer = true;
            } elseif ($item->key->kind === ArrayKeyKind::String) {
                if (is_numeric($item->key->value)) {
                    // PHP's numeric-string key conversion is narrower than is_numeric().
                    return [null, null];
                }
                $string = true;
            } else {
                return [null, null];
            }
            $values[(string) $item->type] = $item->type;
        }
        $value = null;
        foreach ($values as $itemType) {
            $value = $value === null ? $itemType : Type::union($value, $itemType);
        }
        $keys = $integer && $string
            ? Type::union(Type::int(), Type::string())
            : ($integer ? Type::int() : Type::string());

        return [$keys, $value];
    }

    private function unknownValue(Type $type): bool
    {
        foreach ($type->atomicTypes as $atom) {
            if ($atom instanceof SimpleAtomicType && $atom->kind === SimpleAtomicTypeKind::Never) {
                return true;
            }
        }

        return false;
    }

    /** Verify the installed helper and its array-preserving constructor path. */
    private function nativeSource(Codebase $codebase): bool
    {
        $source = $this->source ??= new PhpSource($this->root);
        $function = $codebase->getFunction('collect');
        $helper = $function?->location->file;
        if ($helper === null || ! self::pathEnds($helper, '/laravel/framework/src/Illuminate/Collections/helpers.php')) {
            return false;
        }
        $declaration = (new NodeFinder)->findFirst(
            $source->read($helper) ?? [],
            static fn (Node $node): bool => $node instanceof Node\Stmt\Function_
                && $node->name->toString() === 'collect',
        );
        if (
            ! $declaration instanceof Node\Stmt\Function_
            || $declaration->getDocComment() === null
            || hash('sha256', str_replace(["\r\n", "\r"], "\n", $declaration->getDocComment()->getText())) !== self::HELPER_DOC
            || self::fingerprint($declaration->stmts ?? []) !== self::BODIES['helper']
        ) {
            return false;
        }
        $collectionFile = $codebase->getClass(self::COLLECTION)?->location->file;
        $arrFile = $codebase->getClass('Illuminate\\Support\\Arr')?->location->file;
        if (
            $collectionFile === null || ! self::pathEnds($collectionFile, '/laravel/framework/src/Illuminate/Collections/Collection.php')
            || $arrFile === null || ! self::pathEnds($arrFile, '/laravel/framework/src/Illuminate/Collections/Arr.php')
        ) {
            return false;
        }
        $traitFile = dirname($collectionFile).'/Traits/EnumeratesValues.php';
        $collection = self::declaration($source, $collectionFile, Node\Stmt\Class_::class, 'Collection');
        $trait = self::declaration($source, $traitFile, Node\Stmt\Trait_::class, 'EnumeratesValues');
        $arr = self::declaration($source, $arrFile, Node\Stmt\Class_::class, 'Arr');
        if (
            ! $collection instanceof Node\Stmt\Class_
            || $collection->extends !== null
            || ! $trait instanceof Node\Stmt\Trait_
            || ! $arr instanceof Node\Stmt\Class_
            || $arr->extends !== null
        ) {
            return false;
        }
        $usesTrait = (new NodeFinder)->findFirst(
            $collection->stmts,
            static function (Node $node): bool {
                if (! $node instanceof Node\Stmt\TraitUse) {
                    return false;
                }
                foreach ($node->traits as $traitName) {
                    if (strcasecmp($traitName->toString(), 'Illuminate\\Support\\Traits\\EnumeratesValues') === 0) {
                        return true;
                    }
                }

                return false;
            },
        );
        if ($usesTrait === null) {
            return false;
        }
        foreach ([
            [$collection->getMethod('__construct'), 'constructor'],
            [$trait->getMethod('getArrayableItems'), 'getArrayableItems'],
            [$arr->getMethod('from'), 'from'],
        ] as [$method, $name]) {
            if ($method === null || self::fingerprint($method->stmts ?? []) !== self::BODIES[$name]) {
                return false;
            }
        }

        return true;
    }

    /** @param class-string<Node> $kind */
    private static function declaration(PhpSource $source, string $file, string $kind, string $name): ?Node
    {
        return (new NodeFinder)->findFirst(
            $source->read($file) ?? [],
            static fn (Node $node): bool => $node instanceof $kind && $node->name?->toString() === $name,
        );
    }

    private static function pathEnds(string $path, string $suffix): bool
    {
        return str_ends_with(str_replace('\\', '/', $path), $suffix);
    }

    /** Normalize only formatting and ordinary comments, preserving source operations. */
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
}
