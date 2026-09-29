<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer;

use Ichinya\Laramago\Analyzer\StaticAnalysis\ModelReflection;
use Ichinya\Laramago\Analyzer\StaticAnalysis\PhpSource;
use Mago\Sdk\Analyzer\InitializationContext;
use Mago\Sdk\Analyzer\InitializationHook;
use Mago\Sdk\Analyzer\PropertyTarget;
use Mago\Sdk\Analyzer\PropertyType;
use Mago\Sdk\Analyzer\PropertyTypeProvider;
use Mago\Sdk\Analyzer\PropertyTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;

/** Interpret PHPStan's `TraversableCollection|Item[]` shorthand on documented model properties. */
final class IterablePropertyDocProvider implements PropertyTypeProvider, InitializationHook
{
    private ?PhpSource $source = null;

    public function __construct(private readonly string $root = '.') {}

    public function initialize(InitializationContext $context): void
    {
        $this->source = null;
    }

    public function getTargets(): array
    {
        return [PropertyTarget::allProperties(ModelReflection::MODEL)];
    }

    public function getPropertyType(PropertyTypeProviderContext $context): ?PropertyType
    {
        $class = $context->access->class;
        $property = $context->access->property;
        $codebase = $context->codebase;
        if ($codebase->getProperty($class, '$'.$property) !== null) {
            return null;
        }
        $magic = $codebase->getDeclaringMagicProperty($class, '$'.$property)
            ?? $codebase->getMagicProperty($class, '$'.$property);
        $documented = $magic?->type;
        if ($documented === null || ! $documented->fromDocblock || $magic->declaredType !== null) {
            return null;
        }
        $collection = null;
        $array = null;
        $nullable = false;
        foreach ($documented->type->atomicTypes as $atomic) {
            if ($atomic instanceof NamedObjectType && $collection === null) {
                $collection = $atomic;
            } elseif ($atomic instanceof KeyedArrayType && $array === null) {
                $array = $atomic;
            } elseif ($atomic instanceof SimpleAtomicType && $atomic->kind === SimpleAtomicTypeKind::Null && ! $nullable) {
                $nullable = true;
            } else {
                return null;
            }
        }
        if (
            $collection === null || $array === null
            || count($documented->type->atomicTypes) !== ($nullable ? 3 : 2)
            || $collection->parameters !== null || $collection->intersections !== null
            || $array->knownItems !== null || $array->valueType === null
            || count($array->valueType->atomicTypes) !== 1
            || ! $array->valueType->atomicTypes[0] instanceof NamedObjectType
        ) {
            return null;
        }
        $item = $array->valueType->atomicTypes[0];
        $collectionMetadata = $codebase->getClass($collection->name);
        if (
            ($item->parameters ?? []) !== [] || $item->intersections !== null
            || ! $codebase->classExists($item->name)
            || ! in_array(strtolower(ModelReflection::MODEL), $codebase->getClassAncestors($item->name), true)
            || $collectionMetadata === null || $collectionMetadata->hasIncompleteHierarchy()
            || count($collectionMetadata->templates) !== 2
            || ! $context->types->isContainedBy(
                Type::namedObject($collection->name),
                Type::namedObject(\Traversable::class),
            )
        ) {
            return null;
        }
        $tag = $this->tag($context, $class, $property);
        if ($tag === null || ! self::shorthand($tag[1], $collection->name, $item->name, $nullable, $tag[2], $tag[3])) {
            return null;
        }
        $refined = Type::namedObject(
            $collection->name,
            $array->keyType ?? Type::union(Type::int(), Type::string()),
            $array->valueType,
        );
        if ($nullable) {
            $refined = Type::union($refined, Type::null());
        }

        return new PropertyType($refined, $tag[0] === 'read' ? null : $refined);
    }

    /** @return array{string, string, string, array<string, string>}|null */
    private function tag(PropertyTypeProviderContext $context, string $class, string $property): ?array
    {
        $current = $class;
        while ($current !== null) {
            $metadata = $context->codebase->getClass($current);
            if ($metadata === null) {
                return null;
            }
            $tag = $this->tagInClass($current, $metadata->location->file, $property);
            if ($tag === false) {
                return null;
            }
            if ($tag !== null) {
                return $tag;
            }
            $current = $metadata->directParentClass;
        }

        return null;
    }

    /** @return array{string, string, string, array<string, string>}|false|null */
    private function tagInClass(string $class, ?string $file, string $property): array|false|null
    {
        if ($file === null || str_starts_with($file, '@')) {
            return null;
        }
        $nodes = ($this->source ??= new PhpSource($this->root))->read($file) ?? [];
        foreach ($nodes as $top) {
            $namespace = $top instanceof Namespace_ ? $top->name?->toString() ?? '' : '';
            $statements = $top instanceof Namespace_ ? $top->stmts : [$top];
            foreach ($statements as $node) {
                if (! $node instanceof Class_ || strcasecmp($node->namespacedName?->toString() ?? '', $class) !== 0) {
                    continue;
                }
                return self::parseTag(
                    $node->getDocComment()?->getText() ?? '',
                    $property,
                    $namespace,
                    self::imports($statements),
                );
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $imports
     * @return array{string, string, string, array<string, string>}|false|null
     */
    private static function parseTag(string $doc, string $property, string $namespace, array $imports): array|false|null
    {
        preg_match_all(
            '/^\h*(?:\/\*\*|\*)?\h*@property(?<kind>-read|-write)?\h+(?<type>[^\r\n$]+?)\h+\$(?<property>[A-Za-z_][A-Za-z0-9_]*)(?=\h|$)/m',
            $doc,
            $matches,
            PREG_SET_ORDER,
        );
        $found = null;
        foreach ($matches as $match) {
            if ($match['property'] !== $property) {
                continue;
            }
            if ($found !== null || ($match['kind'] ?? '') === '-write') {
                return false;
            }
            $found = [($match['kind'] ?? '') === '-read' ? 'read' : 'both', trim($match['type']), $namespace, $imports];
        }

        return $found;
    }

    /** @param list<Node> $statements
     * @return array<string, string> */
    private static function imports(array $statements): array
    {
        $imports = [];
        foreach ($statements as $statement) {
            if (! $statement instanceof Use_ && ! $statement instanceof GroupUse) {
                continue;
            }
            foreach ($statement->uses as $use) {
                $type = $use->type === Use_::TYPE_UNKNOWN ? $statement->type : $use->type;
                if ($type !== Use_::TYPE_NORMAL) {
                    continue;
                }
                $name = $statement instanceof GroupUse
                    ? $statement->prefix->toString().'\\'.$use->name->toString()
                    : $use->name->toString();
                $imports[strtolower($use->getAlias()->toString())] = $name;
            }
        }

        return $imports;
    }

    /** @param array<string, string> $imports */
    private static function shorthand(
        string $documented,
        string $collection,
        string $item,
        bool $nullable,
        string $namespace,
        array $imports,
    ): bool
    {
        $branches = explode('|', preg_replace('/\s+/', '', $documented) ?? '');
        if (count($branches) !== ($nullable ? 3 : 2)) {
            return false;
        }
        $kinds = [];
        foreach ($branches as $branch) {
            if (strcasecmp($branch, 'null') === 0 && $nullable) {
                $kinds[] = 'null';
            } elseif (str_ends_with($branch, '[]') && self::name(substr($branch, 0, -2), $item, $namespace, $imports)) {
                $kinds[] = 'item';
            } elseif (self::name($branch, $collection, $namespace, $imports)) {
                $kinds[] = 'collection';
            } else {
                return false;
            }
        }
        sort($kinds);

        return $kinds === ($nullable ? ['collection', 'item', 'null'] : ['collection', 'item']);
    }

    /** @param array<string, string> $imports */
    private static function name(string $documented, string $resolved, string $namespace, array $imports): bool
    {
        if (preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*$/D', $documented) !== 1) {
            return false;
        }
        if (str_starts_with($documented, '\\')) {
            return strcasecmp(ltrim($documented, '\\'), $resolved) === 0;
        }
        $parts = explode('\\', $documented);
        $alias = $imports[strtolower($parts[0])] ?? null;
        $actual = $alias !== null
            ? $alias.(count($parts) > 1 ? '\\'.implode('\\', array_slice($parts, 1)) : '')
            : ($namespace === '' ? '' : $namespace.'\\').$documented;

        return strcasecmp($actual, $resolved) === 0;
    }
}
