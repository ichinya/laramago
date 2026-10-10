<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Metadata\ClassLikeKind;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\Metadata\PropertyMetadata;
use Mago\Sdk\Analyzer\Metadata\TypeMetadata;
use Mago\Sdk\Analyzer\PropertyType;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\TypeComparator;
use Mago\Sdk\Analyzer\Type\ArrayItem;
use Mago\Sdk\Analyzer\Type\FloatType;
use Mago\Sdk\Analyzer\Type\FloatTypeKind;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Analyzer\Type\TypeFlags;
use Mago\Sdk\Analyzer\Type\Visibility;
use PhpParser\Node;
use PhpParser\NodeFinder;

/** Certify general model tags without discarding explicit directional reads or writes. */
final class ModelPropertyReadContracts
{
    /** @var array<string, string> */
    private array $snapshots = [];

    public function __construct(private readonly PhpSource $source) {}

    public function metadataCastContract(
        Codebase $codebase,
        TypeComparator $types,
        string $class,
        string $property,
        PropertyType $actual,
        bool $actualSetter = false,
        bool $actualGetter = false,
    ): ?PropertyType {
        $metadata = $this->general($codebase, $types, $class, $property);
        if ($metadata === null || $actual->readType === null) {
            return null;
        }
        $write = $actualSetter ? $actual->writeType : $metadata->writeType?->type;
        if (! $actualSetter && $write === null) {
            $tags = $this->tags($codebase, $class, $property);
            $readTag = $tags === null || $metadata->type === null ? null : $this->tagAt($tags, $metadata->type);
            $writeTags = $tags === null ? [] : array_values(array_filter($tags, static fn (array $tag): bool => $tag['kind'] === 'write'));
            $write = $writeTags === []
                ? ($this->decimalWrite($codebase, $types, $class, $property, $metadata, $actual, $readTag) ?? $metadata->type?->type)
                : $this->sourceWrite($writeTags, $readTag);
        }
        if ($write === null) {
            return null;
        }
        $read = $actual->readType;
        if (! $actualGetter) {
            $read = $this->arrayRead($codebase, $types, $class, $property, $metadata, $read) ?? $read;
        }
        // General nullable tags remain conservative even for a non-null column.
        if (! $actualGetter && self::nullable($metadata->type?->type) && ! self::nullable($read)) {
            $read = Type::union($read, Type::null());
        }

        return $this->current() ? new PropertyType($read, $write) : null;
    }

    /** Known decimal storage accepts numeric input without a physical float field.
     * @param array{owner:string, file:string, kind:string, type:string, start:int, end:int}|null $readTag */
    private function decimalWrite(
        Codebase $codebase,
        TypeComparator $types,
        string $class,
        string $property,
        PropertyMetadata $metadata,
        PropertyType $actual,
        ?array $readTag,
    ): ?Type {
        if ($readTag === null || $readTag['kind'] !== 'general' || $metadata->type === null || $actual->writeType === null) {
            return null;
        }
        $floats = 0;
        foreach ($metadata->type->type->atomicTypes as $atom) {
            if ($atom instanceof SimpleAtomicType && $atom->kind === SimpleAtomicTypeKind::Null) {
                continue;
            }
            if (! $atom instanceof ScalarType || $atom->kind !== ScalarTypeKind::Float
                || ! $atom->refinement instanceof FloatType || $atom->refinement->kind !== FloatTypeKind::General
                || $atom->refinement->value !== null) {
                return null;
            }
            ++$floats;
        }
        if ($floats !== 1) {
            return null;
        }
        $casts = (new ModelReflection($codebase, $this->source))->casts($class);
        $cast = is_array($casts) ? ($casts[$property] ?? null) : null;
        if (! is_string($cast) || preg_match('/^decimal:(?:0|[1-9][0-9]*)$/iD', $cast) !== 1) {
            return null;
        }
        $builtin = AttributeTypes::cast($cast, $codebase)?->writeType;
        $atoms = array_values(array_filter($actual->writeType->atomicTypes,
            static fn ($atom): bool => ! ($atom instanceof SimpleAtomicType && $atom->kind === SimpleAtomicTypeKind::Null)));
        $category = $atoms === [] ? null : Type::fromAtomics(...$atoms)->withFlags($actual->writeType->flags);
        if ($builtin === null || $category === null || ! $types->equals($category, $builtin)) {
            return null;
        }
        $write = $actual->writeType;

        return self::nullable($metadata->type->type) && ! self::nullable($write) ? Type::union($write, Type::null()) : $write;
    }

    /** Preserve compatible array detail without deriving reads from a write-only tag. */
    private function arrayRead(
        Codebase $codebase,
        TypeComparator $types,
        string $class,
        string $property,
        PropertyMetadata $metadata,
        Type $actual,
    ): ?Type {
        $documented = $metadata->type === null ? null : self::nonNullArray($metadata->type->type);
        $category = self::nonNullArray($actual);
        if ($documented === null || $category === null) {
            return null;
        }
        $tags = $this->tags($codebase, $class, $property);
        $tag = $tags === null || $metadata->type === null ? null : $this->tagAt($tags, $metadata->type);
        if ($tag === null || $tag['kind'] !== 'general') {
            return null;
        }
        $casts = (new ModelReflection($codebase, $this->source))->casts($class);
        $cast = is_array($casts) ? ($casts[$property] ?? null) : null;
        if (! is_string($cast) || ! in_array(strtolower($cast), ['array', 'json'], true)) {
            return null;
        }
        $builtin = AttributeTypes::cast($cast, $codebase)?->readType;
        if ($builtin === null
            || ! $types->equals($category, $builtin)
            || ! $types->isContainedBy($documented, $category)) {
            return null;
        }

        return self::nullable($actual) ? Type::union($documented, Type::null()) : $documented;
    }

    private static function nonNullArray(Type $type): ?Type
    {
        $atoms = [];
        foreach ($type->atomicTypes as $atom) {
            if ($atom instanceof SimpleAtomicType && $atom->kind === SimpleAtomicTypeKind::Null) {
                continue;
            }
            if (! $atom instanceof ListType && ! $atom instanceof KeyedArrayType) {
                return null;
            }
            $atoms[] = $atom;
        }

        return $atoms === [] ? null : Type::fromAtomics(...$atoms)->withFlags($type->flags);
    }

    /** The effective read is general or absent; a source-backed read tag always wins. */
    public function general(Codebase $codebase, TypeComparator $types, string $class, string $property): ?PropertyMetadata
    {
        if ($codebase->getDeclaringProperty($class, '$'.$property) !== null
            || $codebase->getProperty($class, '$'.$property) !== null) {
            return null;
        }
        $metadata = $codebase->getDeclaringMagicProperty($class, '$'.$property)
            ?? $codebase->getMagicProperty($class, '$'.$property);
        if ($metadata === null || ltrim($metadata->name, '$') !== $property
            || $metadata->declaredType !== null || $metadata->type === null
            || ! $metadata->type->fromDocblock || $metadata->type->inferred
            || $metadata->hooks !== [] || $metadata->readVisibility !== Visibility::Public
            || $metadata->writeVisibility !== Visibility::Public
            || $metadata->flags->contains(MetadataFlags::STATIC)
            || $metadata->flags->contains(MetadataFlags::READONLY)) {
            return null;
        }
        $tags = $this->tags($codebase, $class, $property);
        if ($tags === null || array_filter($tags, static fn (array $tag): bool => $tag['kind'] === 'read') !== []) {
            return null;
        }
        $typeTag = $this->tagAt($tags, $metadata->type);
        if ($typeTag === null || ! in_array($typeTag['kind'], ['general', 'write'], true)
            || ! $this->matches($typeTag['type'], $metadata->type->type, $types)) {
            return null;
        }
        $sameOwner = array_filter($tags, static fn (array $tag): bool => $tag['owner'] === $typeTag['owner']
            && $tag['kind'] === $typeTag['kind']);
        if (count($sameOwner) !== 1) {
            return null;
        }
        if ($metadata->writeType !== null) {
            $writeTag = $this->tagAt($tags, $metadata->writeType);
            if ($writeTag === null || ! in_array($writeTag['kind'], ['general', 'write'], true)
                || ! $metadata->writeType->fromDocblock || $metadata->writeType->inferred
                || ! $this->matches($writeTag['type'], $metadata->writeType->type, $types)) {
                return null;
            }
        } elseif ($typeTag['kind'] === 'general') {
            $writeTags = array_values(array_filter($tags, static fn (array $tag): bool => $tag['kind'] === 'write'));
            if ($writeTags !== [] && $this->sourceWrite($writeTags, $typeTag) === null) {
                return null;
            }
        }

        return $this->current() ? $metadata : null;
    }

    /** A source-certified general scalar key tag, never a write-only contract. */
    public function scalarKeyRead(Codebase $codebase, TypeComparator $types, string $class, string $property): ?Type
    {
        $metadata = $this->general($codebase, $types, $class, $property);
        $tags = $metadata === null ? null : $this->tags($codebase, $class, $property);
        $tag = $tags === null || $metadata->type === null ? null : $this->tagAt($tags, $metadata->type);
        $read = $metadata?->type?->type;
        if ($tag === null || $tag['kind'] !== 'general' || $read === null
            || ! $types->isContainedBy($read, Type::union(Type::int(), Type::string(), Type::null()))) {
            return null;
        }

        return $this->current() ? $read : null;
    }

    /** @return list<array{owner:string, file:string, kind:string, type:string, start:int, end:int}>|null */
    private function tags(Codebase $codebase, string $class, string $property): ?array
    {
        $pending = [$class];
        $visited = [];
        $tags = [];
        for ($position = 0; $position < count($pending); ++$position) {
            if (count($pending) > 10_000) {
                return null;
            }
            $name = $pending[$position];
            if (isset($visited[strtolower($name)])) {
                continue;
            }
            $visited[strtolower($name)] = true;
            $metadata = $codebase->getClassLike($name);
            if ($metadata === null || $metadata->hasIncompleteHierarchy()
                || strcasecmp(ltrim($metadata->name, '\\'), ltrim($name, '\\')) !== 0
                || strcasecmp(ltrim($metadata->originalName, '\\'), ltrim($name, '\\')) !== 0) {
                return null;
            }
            if ($metadata->directParentClass !== null) {
                $pending[] = $metadata->directParentClass;
            }
            array_push($pending, ...$metadata->usedTraits);
            // Framework fallback metadata has no user property tags to reinterpret.
            if (strcasecmp($name, ModelReflection::MODEL) === 0
                || str_starts_with(strtolower($name), 'illuminate\\database\\eloquent\\concerns\\')) {
                continue;
            }
            $file = $metadata->location->file;
            if ($file === null || str_starts_with($file, '@')) {
                return null;
            }
            $path = RefreshedModelProperties::path($file);
            $nodes = $this->source->read($path);
            $hash = $this->source->contentHash($path);
            if ($nodes === null || $hash === null) {
                return null;
            }
            $this->snapshots[$this->source->path($path)] = $hash;
            $declaration = null;
            foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Stmt\ClassLike::class) as $node) {
                if (strcasecmp($node->namespacedName?->toString() ?? '', $name) === 0) {
                    $declaration = $node;
                    break;
                }
            }
            if ($declaration === null || $declaration->name === null
                || $metadata->kind !== match (true) {
                    $declaration instanceof Node\Stmt\Class_ => ClassLikeKind::Class_,
                    $declaration instanceof Node\Stmt\Trait_ => ClassLikeKind::Trait,
                    $declaration instanceof Node\Stmt\Interface_ => ClassLikeKind::Interface,
                    $declaration instanceof Node\Stmt\Enum_ => ClassLikeKind::Enum,
                }
                || $metadata->nameLocation?->file !== $metadata->location->file
                || $metadata->location->span->end !== $declaration->getEndFilePos() + 1
                || $metadata->nameLocation?->span->start !== $declaration->name->getStartFilePos()
                || $metadata->nameLocation?->span->end !== $declaration->name->getEndFilePos() + 1) {
                return null;
            }
            foreach ($declaration->getProperties() as $field) {
                foreach ($field->props as $item) {
                    if (! in_array($item->name->name, ['casts', 'table', 'connection', 'primaryKey', 'keyType', 'incrementing'], true)) {
                        continue;
                    }
                    $native = $codebase->getDeclaringProperty($name, '$'.$item->name->name);
                    if ($native === null || ltrim($native->name, '$') !== $item->name->name
                        || $native->nameLocation?->file !== $metadata->location->file
                        || $native->nameLocation?->span->start !== $item->name->getStartFilePos()
                        || $native->nameLocation?->span->end !== $item->name->getEndFilePos() + 1) {
                        return null;
                    }
                    $sourceDefault = PhpSource::value($item->default, $name, $name);
                    $nativeDefault = (new ModelReflection($codebase, $this->source))->default($name, $item->name->name);
                    if ($sourceDefault === UnknownValue::Value || $nativeDefault === UnknownValue::Value
                        || ! ($item->name->name === 'casts'
                            ? self::sameCastMap($sourceDefault, $nativeDefault)
                            : $sourceDefault === $nativeDefault)) {
                        return null;
                    }
                }
            }
            $doc = $declaration->getDocComment();
            if ($doc === null) {
                continue;
            }
            // An explicit read can coexist with a general/write tag and merged flags.
            $text = $doc->getText();
            preg_match_all('/(?i:@(?:(?:psalm|phpstan)-)?property(?<kind>-read|-write)?)\h+(?<type>[^\r\n]+?)\h+\$'.preg_quote($property, '/').'\b/', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($matches as $match) {
                $raw = $match['type'][0];
                $type = trim($raw);
                $start = $doc->getStartFilePos() + $match['type'][1] + (strlen($raw) - strlen(ltrim($raw)));
                $tags[] = ['owner' => strtolower($name), 'file' => $path,
                    'kind' => match (strtolower($match['kind'][0] ?? '')) { '-read' => 'read', '-write' => 'write', default => 'general' },
                    'type' => $type, 'start' => $start, 'end' => $start + strlen($type)];
            }
        }

        return $this->current() ? $tags : null;
    }

    private static function sameCastMap(mixed $source, mixed $native): bool
    {
        // SDK keyed-array snapshots canonicalize item order; cast names select values by key.
        if (! is_array($source) || ! is_array($native) || count($source) !== count($native)) {
            return false;
        }
        foreach ($source as $key => $value) {
            if (! is_string($key) || $key === '' || ! is_string($value)
                || ! array_key_exists($key, $native) || $native[$key] !== $value) {
                return false;
            }
        }

        return true;
    }

    /** @param list<array{owner:string, file:string, kind:string, type:string, start:int, end:int}> $writes
     * @param array{owner:string, file:string, kind:string, type:string, start:int, end:int}|null $read */
    private function sourceWrite(array $writes, ?array $read): ?Type
    {
        // The SDK may omit a paired directional tag; never invent SDK metadata for it.
        if (count($writes) !== 1 || $read === null || $read['kind'] !== 'general'
            || $writes[0]['owner'] !== $read['owner'] || $writes[0]['file'] !== $read['file']) {
            return null;
        }
        $expression = preg_replace('/\s+/', '', $writes[0]['type']) ?? '';
        if (str_starts_with($expression, '?')) {
            $expression = substr($expression, 1).'|null';
        }
        if (preg_match('/\b(?:mixed|never)\b/i', $expression) === 1) {
            return null;
        }
        $type = DiagnosticArrayTypes::parse($expression);
        if ($type === null || $type->atomicTypes === []) {
            return null;
        }
        foreach ($type->atomicTypes as $atom) {
            if (! $atom instanceof ScalarType
                && ! ($atom instanceof SimpleAtomicType && $atom->kind === SimpleAtomicTypeKind::Null)) {
                return null;
            }
        }

        return $type;
    }

    /** @param list<array{owner:string, file:string, kind:string, type:string, start:int, end:int}> $tags
     * @return array{owner:string, file:string, kind:string, type:string, start:int, end:int}|null */
    private function tagAt(array $tags, TypeMetadata $type): ?array
    {
        foreach ($tags as $tag) {
            if ($tag['file'] === RefreshedModelProperties::path($type->location->file ?? '')
                && $tag['start'] === $type->location->span->start && $tag['end'] === $type->location->span->end) {
                return $tag;
            }
        }

        return null;
    }

    private function matches(string $expression, Type $native, TypeComparator $types): bool
    {
        $expression = preg_replace('/\s+/', '', $expression) ?? '';
        if (str_starts_with($expression, '?')) {
            $expression = substr($expression, 1).'|null';
        }
        $parsed = DiagnosticArrayTypes::parse($expression);
        $parsed = $parsed === null ? null : self::sourceArrayFlags($parsed);
        if ($parsed === null) {
            // Unresolved aliases/generics cannot certify a changed read policy.
            return false;
        }

        return $types->equals($parsed, $native);
    }

    /** Native optional shape members carry an undefined flag on their value type. */
    private static function sourceArrayFlags(Type $type, int $depth = 0): ?Type
    {
        if ($depth > 12) {
            return null;
        }
        $atoms = [];
        $changed = false;
        foreach ($type->atomicTypes as $atom) {
            if ($atom instanceof KeyedArrayType) {
                $items = $atom->knownItems === null ? null : [];
                foreach ($atom->knownItems ?? [] as $item) {
                    $value = self::sourceArrayFlags($item->type, $depth + 1);
                    if ($value === null) {
                        return null;
                    }
                    if ($item->optional) {
                        $value = $value->withFlags(new TypeFlags(...array_replace(get_object_vars($value->flags), ['possiblyUndefined' => true])));
                    }
                    $items[] = new ArrayItem($item->key, $item->optional, $value);
                }
                $key = $atom->keyType === null ? null : self::sourceArrayFlags($atom->keyType, $depth + 1);
                $value = $atom->valueType === null ? null : self::sourceArrayFlags($atom->valueType, $depth + 1);
                if ($atom->keyType !== null && ($key === null || $value === null)) {
                    return null;
                }
                $atoms[] = new KeyedArrayType($items, $key, $value, $atom->nonEmpty);
                $changed = true;
            } elseif ($atom instanceof ListType) {
                $element = self::sourceArrayFlags($atom->elementType, $depth + 1);
                if ($element === null) {
                    return null;
                }
                $atoms[] = new ListType($element, $atom->knownElements, $atom->knownCount, $atom->nonEmpty);
                $changed = true;
            } else {
                $atoms[] = $atom;
            }
        }

        return $changed ? Type::fromAtomics(...$atoms)->withFlags($type->flags) : $type;
    }

    private static function nullable(?Type $type): bool
    {
        foreach ($type?->atomicTypes ?? [] as $atom) {
            if ($atom instanceof SimpleAtomicType && $atom->kind === SimpleAtomicTypeKind::Null) {
                return true;
            }
        }

        return false;
    }

    public function current(): bool
    {
        foreach ($this->snapshots as $path => $hash) {
            if (@hash_file('sha256', $path) !== $hash) {
                return false;
            }
        }

        return true;
    }
}
