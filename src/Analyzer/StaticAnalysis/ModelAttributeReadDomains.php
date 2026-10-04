<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\TypeComparator;
use PhpParser\Node;
use PhpParser\NodeFinder;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** Independent virtual read contracts and bounded, achievable primitive cast values. */
final class ModelAttributeReadDomains
{
    private readonly PhpSource $source;
    private readonly SchemaIndex $schema;
    /** @var array<string, string> */
    private array $snapshots = [];
    /** @var list<string> */
    private array $migrations = [];
    /** @var list<string> */
    private array $directories = [];
    private bool $usable = true;

    public function __construct(private readonly string $root = '.', private readonly array $analyzedHashes = [])
    {
        $this->source = new PhpSource($root);
        $this->schema = new SchemaIndex($this->source);
        $this->schema->load();
        $paths = ['database/migrations'];
        $composer = $root.'/composer.json';
        if (is_file($composer)) {
            $contents = @file_get_contents($composer);
            $data = $contents === false ? null : json_decode($contents, true);
            if (! is_array($data)) { $this->usable = false; }
            else {
                $extra = $data['extra']['laramago']['migration-paths'] ?? [];
                if (! is_array($extra) || ! array_is_list($extra) || array_filter($extra, static fn ($path): bool => ! is_string($path) || $path === '')) { $this->usable = false; }
                else { $paths = [...$paths, ...$extra]; }
                $this->snapshots[$composer] = hash('sha256', $contents);
            }
        }
        try {
            foreach (array_unique($paths) as $path) {
                $directory = $this->source->path($path);
                $this->directories[] = $directory;
                if (! is_dir($directory)) { continue; }
                foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                    if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') { continue; }
                    $name = $file->getRealPath() ?: $file->getPathname();
                    if (count($this->migrations) >= 100_000 || $file->getSize() > 2_000_000 || $this->source->read($name) === null) { $this->usable = false; break 2; }
                    $hash = $this->source->contentHash($name);
                    if ($hash === null) { $this->usable = false; break 2; }
                    $this->snapshots[$name] = $hash;
                    $this->migrations[] = $name;
                }
            }
        } catch (\UnexpectedValueException) { $this->usable = false; }
        usort($this->migrations, static fn (string $left, string $right): int => [basename($left), $left] <=> [basename($right), $right]);
        $this->usable = $this->usable && $this->source->warnings === [];
    }

    public function resolve(Codebase $codebase, string $class, string $property, int|string|bool|null $expected, bool $intermediate = false, ?TypeComparator $types = null): ?Type
    {
        if (! $this->usable || $codebase->getDeclaringProperty($class, '$'.$property) !== null) { return null; }
        $reflection = new ModelReflection($codebase, $this->source);
        $studly = ModelReflection::studly($property);
        // Even a broad documented read type does not make a constant accessor variable.
        foreach (['get'.$studly.'Attribute', lcfirst($studly)] as $name) {
            if ($reflection->method($class, $name) !== null) { return null; }
        }
        foreach (['getCasts', 'getTable', 'getConnectionName'] as $name) {
            if ($reflection->customMethod($class, $name) !== null) { return null; }
        }
        $casts = $reflection->casts($class);
        $table = $reflection->table($class);
        $cast = is_array($casts) ? ($casts[$property] ?? null) : null;
        $column = $this->schema->column($table, $property);
        if (! is_string($cast) || $column === null || $table === null || ! $this->possible($table, $property, $cast, $column, $expected, $intermediate)) { return null; }
        $metadata = $codebase->getClass($class);
        if ($metadata === null || $metadata->hasIncompleteHierarchy() || ! $this->retain($metadata->location->file)
            || ! $this->modelMatches($codebase, $class)) { return null; }
        $declarations = [$metadata];
        foreach ([...$metadata->parentClasses, ...$metadata->usedTraits] as $ancestor) {
            $parent = $codebase->getClassLike($ancestor);
            if ($parent === null || $parent->hasIncompleteHierarchy() || $parent->location->file === null) { return null; }
            $declarations[] = $parent;
            $file = RefreshedModelProperties::path($parent->location->file);
            if (str_contains($file, '/laravel/framework/src/Illuminate/Database/Eloquent/') || str_contains($file, '/laravel/framework/src/illuminate/database/eloquent/')) { continue; }
            if (! $this->retain($parent->location->file) || ! $this->modelMatches($codebase, $ancestor)) { return null; }
        }
        if ($types !== null) {
            foreach ($declarations as $declaration) {
                foreach ($declaration->typeAliases as $alias) {
                    if (! $this->retain($alias->location->file)) { return null; }
                    $contents = @file_get_contents($this->source->path(RefreshedModelProperties::path($alias->location->file)));
                    $parsed = $contents === false ? null : DiagnosticArrayTypes::parse(substr($contents, $alias->location->span->start, $alias->location->span->length()));
                    $resolved = DiagnosticArrayTypes::resolveAliases($alias->type, $codebase);
                    if ($parsed === null || $resolved === null || ! $types->equals($parsed, $resolved)) { return null; }
                }
            }
        }
        foreach (['casts', 'table', 'connection', 'primaryKey', 'incrementing', 'keyType'] as $name) {
            $declaration = $codebase->getDeclaringProperty($class, '$'.$name);
            $file = $declaration?->nameLocation?->file ?? $declaration?->defaultType?->location->file ?? $declaration?->location?->file;
            if ($file !== null && ! $this->retain($file)) { return null; }
        }
        $castMethod = $reflection->customMethod($class, 'casts');
        if ($castMethod !== null && ! $this->retain($castMethod->location->file)) { return null; }
        $magic = $codebase->getDeclaringMagicProperty($class, '$'.$property);
        if ($magic !== null) {
            // Declared reads retain priority over inferred casts and separate writes.
            if ($magic->type === null || ! $magic->type->fromDocblock || $magic->readVisibility !== \Mago\Sdk\Analyzer\Type\Visibility::Public
                || $magic->flags->contains(\Mago\Sdk\Analyzer\Metadata\MetadataFlags::WRITEONLY)
                || $magic->hooks !== [] || ! $this->retain($magic->type->location->file)
                || ! $this->documented($magic->type->location->file, $magic->type->location->span->start, $magic->type->location->span->end, $property)) { return null; }
            $read = DiagnosticArrayTypes::resolveAliases($magic->type->type, $codebase);
            $contents = @file_get_contents($this->source->path(RefreshedModelProperties::path($magic->type->location->file)));
            if ($contents === false) { return null; }
            $printed = DiagnosticArrayTypes::parse(substr($contents, $magic->type->location->span->start, $magic->type->location->span->length()));
            if ($printed !== null && $types !== null && ($read === null || ! $types->equals($printed, $read))) { return null; }
        } else {
            $read = AttributeTypes::cast($cast, $codebase)?->readType;
            if ($read !== null && $column->nullable) { $read = Type::union($read, Type::null()); }
        }
        return $read !== null && $this->current() ? $read : null;
    }

    /** Anchor an effective timestamp constant to its current declaring class or trait. */
    public function constantString(Codebase $codebase, string $class, string $name): ?string
    {
        $bound = $codebase->getClassConstant($class, $name);
        $value = $bound?->inferredType?->getLiteralString();
        if ($bound === null || $value === null || $bound->name !== $name || ! $this->retain($bound->location->file)) { return null; }
        $file = RefreshedModelProperties::path($bound->location->file ?? '');
        $names = [$class, ...$codebase->getClassAncestors($class)];
        for ($position = 0; $position < count($names); ++$position) {
            if (count($names) > 10_000) { return null; }
            $metadata = $codebase->getClassLike($names[$position]);
            if ($metadata === null || $metadata->hasIncompleteHierarchy()) { return null; }
            foreach ($metadata->usedTraits as $trait) { if (! in_array($trait, $names, true)) { $names[] = $trait; } }
        }
        foreach ((new NodeFinder)->findInstanceOf($this->source->read($file) ?? [], Node\Stmt\ClassLike::class) as $node) {
            if ($node->name === null || $node->namespacedName === null || ! in_array(strtolower($node->namespacedName->toString()), array_map('strtolower', $names), true)) { continue; }
            $owner = $codebase->getClassLike($node->namespacedName->toString());
            if ($owner === null || $owner->hasIncompleteHierarchy() || RefreshedModelProperties::path($owner->location->file ?? '') !== $file
                || RefreshedModelProperties::path($owner->nameLocation?->file ?? '') !== $file
                || $owner->location->span->end !== $node->getEndFilePos() + 1
                || $owner->nameLocation?->span->start !== $node->name->getStartFilePos()
                || $owner->nameLocation?->span->end !== $node->name->getEndFilePos() + 1) { return null; }
            foreach ($node->getConstants() as $declaration) {
                foreach ($declaration->consts as $constant) {
                    if ($constant->name->name !== $name) { continue; }
                    if ($bound->location->span->start > $constant->name->getStartFilePos()
                        || ! in_array($bound->location->span->end, [$constant->getEndFilePos() + 1, $declaration->getEndFilePos() + 1], true)
                        || PhpSource::value($constant->value) !== $value) { return null; }
                    return $this->current() ? $value : null;
                }
            }
        }
        return null;
    }

    /** Metadata-only dependencies must still describe the current declaration, including newly added overrides. */
    private function modelMatches(Codebase $codebase, string $class): bool
    {
        $metadata = $codebase->getClassLike($class);
        if ($metadata === null || $metadata->location->file === null) { return false; }
        foreach ((new NodeFinder)->findInstanceOf($this->source->read(RefreshedModelProperties::path($metadata->location->file)) ?? [], Node\Stmt\ClassLike::class) as $node) {
            if ($node->name === null || $node->namespacedName === null || strcasecmp($node->namespacedName->toString(), $class) !== 0) { continue; }
            if ($metadata->location->span->end !== $node->getEndFilePos() + 1 || $metadata->nameLocation?->span->start !== $node->name->getStartFilePos()
                || $metadata->nameLocation?->span->end !== $node->name->getEndFilePos() + 1) { return false; }
            foreach ($node->getMethods() as $method) {
                $bound = $codebase->getMethod($class, $method->name->name);
                if ($bound === null || strcasecmp($bound->identifier->class ?? '', $class) !== 0
                    || RefreshedModelProperties::path($bound->location->file ?? '') !== RefreshedModelProperties::path($metadata->location->file)
                    || $bound->location->span->end !== $method->getEndFilePos() + 1
                    || $bound->nameLocation?->span->start !== $method->name->getStartFilePos() || $bound->nameLocation?->span->end !== $method->name->getEndFilePos() + 1) { return false; }
            }
            foreach ($node->getConstants() as $declaration) {
                foreach ($declaration->consts as $constant) {
                    if (! in_array($constant->name->name, ['CREATED_AT', 'UPDATED_AT'], true)) { continue; }
                    $bound = $codebase->getClassConstant($class, $constant->name->name);
                    $value = PhpSource::value($constant->value);
                    if ($bound === null || ! is_string($value) || $bound->inferredType?->getLiteralString() !== $value
                        || RefreshedModelProperties::path($bound->location->file ?? '') !== RefreshedModelProperties::path($metadata->location->file)
                        || $bound->location->span->start > $constant->name->getStartFilePos()
                        || ! in_array($bound->location->span->end, [$constant->getEndFilePos() + 1, $declaration->getEndFilePos() + 1], true)) { return false; }
                }
            }
            $reflection = new ModelReflection($codebase, $this->source);
            foreach ($node->getProperties() as $declaration) {
                foreach ($declaration->props as $item) {
                    $bound = $codebase->getDeclaringProperty($class, '$'.$item->name->name);
                    if ($bound === null || $bound->nameLocation === null || RefreshedModelProperties::path($bound->nameLocation->file) !== RefreshedModelProperties::path($metadata->location->file)
                        || $bound->nameLocation->span->start !== $item->name->getStartFilePos() || $bound->nameLocation->span->end !== $item->name->getEndFilePos() + 1) { return false; }
                    if ($item->default !== null && ($bound->defaultType === null
                        || RefreshedModelProperties::path($bound->defaultType->location->file) !== RefreshedModelProperties::path($metadata->location->file)
                        || $bound->defaultType->location->span->start !== $item->default->getStartFilePos()
                        || $bound->defaultType->location->span->end !== $item->default->getEndFilePos() + 1)) { return false; }
                    $value = $item->default === null ? null : PhpSource::value($item->default);
                    if ($value === UnknownValue::Value && in_array($item->name->name, ['casts', 'table', 'connection', 'primaryKey', 'incrementing', 'keyType', 'builder', 'touches'], true)) { return false; }
                    $atoms = $bound->defaultType?->type->atomicTypes ?? [];
                    $list = count($atoms) === 1 ? $atoms[0] : null;
                    // A closed nonempty list has a never remainder; that does not make its known elements empty.
                    $nonemptyList = $list instanceof \Mago\Sdk\Analyzer\Type\ListType
                        && ($list->nonEmpty || ($list->knownCount ?? 0) > 0 || ($list->knownElements ?? []) !== []
                            || is_array($value) && $value !== [] && array_is_list($value));
                    $boundValue = $nonemptyList ? self::listLiteral($bound->defaultType->type) : $reflection->default($class, $item->name->name);
                    if ($boundValue === UnknownValue::Value) { $boundValue = self::listLiteral($bound->defaultType?->type); }
                    if ($value !== UnknownValue::Value && ! self::sameLiteral($boundValue, $value)) { return false; }
                }
            }
            return true;
        }
        return false;
    }

    /** Decode only a fully specified nonempty list; source equality still checks each literal and its position. */
    private static function listLiteral(?Type $type, int $depth = 0): array|UnknownValue
    {
        if ($type === null || $depth > 16 || count($type->atomicTypes) !== 1) { return UnknownValue::Value; }
        $list = $type->atomicTypes[0];
        if (! $list instanceof \Mago\Sdk\Analyzer\Type\ListType || ! $list->nonEmpty || $list->knownCount === null
            || $list->knownCount < 1 || $list->knownCount > 100_000 || $list->knownElements === null
            || ! array_is_list($list->knownElements) || count($list->knownElements) !== $list->knownCount) { return UnknownValue::Value; }
        $values = [];
        foreach ($list->knownElements as $position => $element) {
            if ($element->optional || $element->index !== $position || count($element->type->atomicTypes) !== 1) { return UnknownValue::Value; }
            $atom = $element->type->atomicTypes[0];
            if ($atom instanceof \Mago\Sdk\Analyzer\Type\SimpleAtomicType && $atom->kind === \Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind::Null) { $value = null; }
            elseif ($atom instanceof \Mago\Sdk\Analyzer\Type\ScalarType) {
                $value = match ($atom->kind) {
                    \Mago\Sdk\Analyzer\Type\ScalarTypeKind::String, \Mago\Sdk\Analyzer\Type\ScalarTypeKind::ClassLikeString => $element->type->getLiteralString(),
                    \Mago\Sdk\Analyzer\Type\ScalarTypeKind::Integer => $element->type->getLiteralInt(),
                    \Mago\Sdk\Analyzer\Type\ScalarTypeKind::Boolean => $element->type->getLiteralBool(),
                    default => null,
                };
                if ($value === null) { return UnknownValue::Value; }
            } else { $value = self::listLiteral($element->type, $depth + 1); }
            if ($value === UnknownValue::Value) { return UnknownValue::Value; }
            $values[] = $value;
        }
        return $values;
    }

    private static function sameLiteral(mixed $left, mixed $right): bool
    {
        if (! is_array($left) || ! is_array($right)) { return $left === $right; }
        if (count($left) !== count($right)) { return false; }
        foreach ($left as $key => $value) {
            if (! array_key_exists($key, $right) || ! self::sameLiteral($value, $right[$key])) { return false; }
        }
        return true;
    }

    private function documented(string $file, int $start, int $end, string $property): bool
    {
        foreach ((new NodeFinder)->findInstanceOf($this->source->read(RefreshedModelProperties::path($file)) ?? [], Node\Stmt\ClassLike::class) as $class) {
            $comment = $class->getDocComment();
            if ($comment === null || $start < $comment->getStartFilePos() || $end > $comment->getEndFilePos() + 1) { continue; }
            preg_match_all('/@(?:psalm-|phpstan-)?property(?:-read)?\s+([^\s]+)\s+\$'.preg_quote($property, '/').'\b/', $comment->getText(), $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[1] as [$type, $offset]) {
                if ($start === $comment->getStartFilePos() + $offset && $end === $start + strlen($type)) { return true; }
            }
        }
        return false;
    }

    private function retain(?string $file): bool
    {
        if ($file === null || str_starts_with($file, '@')) { return false; }
        $path = $this->source->path(RefreshedModelProperties::path($file));
        if ($this->source->read($path) === null) { return false; }
        $hash = $this->source->contentHash($path);
        if ($hash === null) { return false; }
        $analyzed = $this->analyzedHashes[RefreshedModelProperties::path($file)] ?? $this->analyzedHashes[RefreshedModelProperties::path($path)] ?? null;
        if ($analyzed !== null && $analyzed !== $hash) { return false; }
        $this->snapshots[$path] = $hash;
        return true;
    }

    public function current(): bool
    {
        $files = [];
        try {
            foreach ($this->directories as $directory) {
                if (! is_dir($directory)) { continue; }
                foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                    if ($file->isFile() && strtolower($file->getExtension()) === 'php') { $files[] = $file->getRealPath() ?: $file->getPathname(); }
                    if (count($files) > 100_000) { return false; }
                }
            }
        } catch (\UnexpectedValueException) { return false; }
        $old = $this->migrations;
        sort($files);
        sort($old);
        if ($old !== $files) { return false; }
        foreach ($this->snapshots as $file => $hash) {
            $size = @filesize($file);
            $contents = $size !== false && $size <= 2_000_000 ? @file_get_contents($file) : false;
            if ($contents === false || hash('sha256', $contents) !== $hash) { return false; }
        }
        return true;
    }

    private function possible(string $table, string $property, string $cast, Column $column, int|string|bool|null $value, bool $intermediate): bool
    {
        if (! $this->ordinaryColumn($table, $property)) { return false; }
        if ($value === null) { return $column->nullable; }
        $parts = explode(':', strtolower($cast), 2);
        if (in_array($parts[0], ['int', 'integer'], true)) {
            return $column->type === 'int' && is_int($value) && ($value === 0 || $intermediate && $this->integerFits($table, $property, $value));
        }
        if (in_array($parts[0], ['bool', 'boolean'], true)) { return $column->type === 'bool' && is_bool($value); }
        if ($parts[0] !== 'decimal' || $column->type !== 'decimal' || ! is_string($value)
            || ! isset($parts[1]) || preg_match('/^(?:0|[1-9][0-9]?)$/D', $parts[1]) !== 1) { return false; }
        $scale = (int) $parts[1];
        if ($scale > 30 || preg_match('/^-?(?:0|[1-9][0-9]*)'.($scale === 0 ? '' : '\.[0-9]{'.$scale.'}').'$/D', $value) !== 1
            || str_starts_with($value, '-0') && ! preg_match('/[1-9]/', $value)) { return false; }
        $precision = $this->decimalPrecision($table, $property);
        if ($precision === null || $precision[1] !== $scale || strlen(ltrim(explode('.', ltrim($value, '-'), 2)[0], '0')) > $precision[0] - $scale) { return false; }
        return ! $precision[2] || ! str_starts_with($value, '-');
    }

    /** Generated/foreign/identity/check constraints are not represented by Column and must defer. */
    private function ordinaryColumn(string $table, string $property): bool
    {
        $found = false;
        foreach ($this->migrations as $file) {
            foreach ((new NodeFinder)->findInstanceOf($this->source->read($file) ?? [], Node\Stmt\Class_::class) as $migration) {
                foreach ((new NodeFinder)->findInstanceOf($migration->getMethod('up')?->stmts ?? [], Node\Expr\StaticCall::class) as $call) {
                    if (! $call->class instanceof Node\Name || ! in_array(strtolower($call->class->toString()), ['schema', 'illuminate\\support\\facades\\schema'], true)
                        || PhpSource::value(PhpSource::argument($call->args, 0, 'table')) !== $table) { continue; }
                    $callback = PhpSource::argument($call->args, 1, 'callback');
                    if (! $callback instanceof Node\Expr\Closure) { return false; }
                    foreach ((new NodeFinder)->findInstanceOf($callback->stmts, Node\Expr\MethodCall::class) as $method) {
                        if (! $method->name instanceof Node\Identifier) { return false; }
                        $name = strtolower($method->name->name);
                        // This phase does not reconstruct constraints across column/table renames.
                        if (in_array($name, ['renamecolumn', 'dropcolumn', 'dropconstrainedforeignid'], true)) { return false; }
                    }
                    foreach ($callback->stmts as $statement) {
                        if (! $statement instanceof Node\Stmt\Expression || ! $statement->expr instanceof Node\Expr\MethodCall) { continue; }
                        [, $chain] = PhpSource::chain($statement->expr);
                        $first = $chain[0] ?? null;
                        if ($first === null || PhpSource::value(PhpSource::argument($first->args, 0, 'column')) !== $property) { continue; }
                        $found = true;
                        $name = $first->name instanceof Node\Identifier ? strtolower($first->name->name) : '';
                        if (! in_array($name, ['integer', 'unsignedinteger', 'biginteger', 'unsignedbiginteger', 'smallinteger', 'unsignedsmallinteger', 'tinyinteger', 'unsignedtinyinteger', 'mediuminteger', 'unsignedmediuminteger', 'boolean', 'decimal', 'unsigneddecimal'], true)) { return false; }
                        if (str_ends_with($name, 'integer')) {
                            $incrementing = PhpSource::argument($first->args, 1, 'autoIncrement');
                            if ($incrementing !== null && PhpSource::value($incrementing) !== false) { return false; }
                        } elseif ($name === 'boolean' && count($first->args) !== 1) { return false; }
                        foreach (array_slice($chain, 1) as $modifier) {
                            if (! $modifier->name instanceof Node\Identifier || ! in_array(strtolower($modifier->name->name), ['nullable', 'unsigned', 'default', 'index', 'unique', 'comment', 'change', 'after', 'first'], true)) { return false; }
                        }
                    }
                }
            }
        }
        return $found;
    }

    /** Only used to rule out genuine contradictions in intervening integer assertions. */
    private function integerFits(string $table, string $property, int $value): bool
    {
        $range = null;
        foreach ($this->migrations as $file) {
            foreach ((new NodeFinder)->findInstanceOf($this->source->read($file) ?? [], Node\Stmt\Class_::class) as $migration) {
                foreach ((new NodeFinder)->findInstanceOf($migration->getMethod('up')?->stmts ?? [], Node\Expr\StaticCall::class) as $call) {
                    if (! $call->class instanceof Node\Name || ! in_array(strtolower($call->class->toString()), ['schema', 'illuminate\\support\\facades\\schema'], true)
                        || PhpSource::value(PhpSource::argument($call->args, 0, 'table')) !== $table) { continue; }
                    $callback = PhpSource::argument($call->args, 1, 'callback');
                    if (! $callback instanceof Node\Expr\Closure) { return false; }
                    foreach ($callback->stmts as $statement) {
                        if (! $statement instanceof Node\Stmt\Expression || ! $statement->expr instanceof Node\Expr\MethodCall) { continue; }
                        [, $chain] = PhpSource::chain($statement->expr);
                        $first = $chain[0] ?? null;
                        if ($first === null || PhpSource::value(PhpSource::argument($first->args, 0, 'column')) !== $property || ! $first->name instanceof Node\Identifier) { continue; }
                        $name = strtolower($first->name->name);
                        $unsigned = str_starts_with($name, 'unsigned');
                        $unsignedArgument = PhpSource::argument($first->args, 2, 'unsigned');
                        $unsigned = $unsigned || $unsignedArgument !== null && PhpSource::value($unsignedArgument) === true;
                        foreach (array_slice($chain, 1) as $modifier) { $unsigned = $unsigned || $modifier->name instanceof Node\Identifier && strtolower($modifier->name->name) === 'unsigned'; }
                        $name = str_replace('unsigned', '', $name);
                        $bits = match ($name) { 'tinyinteger' => 8, 'smallinteger' => 16, 'mediuminteger' => 24, 'integer' => 32, 'biginteger' => 64, default => 0 };
                        $range = $bits === 0 ? null : [$unsigned, $bits];
                    }
                }
            }
        }
        if ($range === null) { return false; }
        [$unsigned, $bits] = $range;
        if ($bits === 64) { return ! $unsigned || $value >= 0; }
        $maximum = (1 << ($unsigned ? $bits : $bits - 1)) - 1;
        $minimum = $unsigned ? 0 : -$maximum - 1;
        return $value >= $minimum && $value <= $maximum;
    }

    /** Precision is deliberately separate from Column's lossy type/nullability representation. @return array{int, int, bool}|null */
    private function decimalPrecision(string $table, string $property): ?array
    {
        $result = null;
        foreach ($this->migrations as $file) {
            foreach ((new NodeFinder)->findInstanceOf($this->source->read($file) ?? [], Node\Stmt\Class_::class) as $migration) {
                foreach ((new NodeFinder)->findInstanceOf($migration->getMethod('up')?->stmts ?? [], Node\Expr\StaticCall::class) as $call) {
                    if (! $call->class instanceof Node\Name || ! in_array(strtolower($call->class->toString()), ['schema', 'illuminate\\support\\facades\\schema'], true)
                        || ! $call->name instanceof Node\Identifier || ! in_array(strtolower($call->name->name), ['create', 'table'], true)
                        || PhpSource::value(PhpSource::argument($call->args, 0, 'table')) !== $table) { continue; }
                    $callback = PhpSource::argument($call->args, 1, 'callback');
                    if (! $callback instanceof Node\Expr\Closure) { return null; }
                    foreach ($callback->stmts as $statement) {
                        if (! $statement instanceof Node\Stmt\Expression || ! $statement->expr instanceof Node\Expr\MethodCall) { continue; }
                        [$receiver, $chain] = PhpSource::chain($statement->expr);
                        $first = $chain[0] ?? null;
                        if ($first === null || PhpSource::value(PhpSource::argument($first->args, 0, 'column')) !== $property) { continue; }
                        $result = null;
                        $kind = $first->name instanceof Node\Identifier ? strtolower($first->name->name) : '';
                        if (! in_array($kind, ['decimal', 'unsigneddecimal'], true)) { continue; }
                        $total = PhpSource::value(PhpSource::argument($first->args, 1, 'total'));
                        $places = PhpSource::value(PhpSource::argument($first->args, 2, 'places'));
                        if (! is_int($total) || ! is_int($places) || $total < 1 || $total > 65 || $places < 0 || $places > $total) { continue; }
                        $unsigned = $kind === 'unsigneddecimal';
                        foreach (array_slice($chain, 1) as $modifier) {
                            $name = $modifier->name instanceof Node\Identifier ? strtolower($modifier->name->name) : '';
                            if ($name === 'unsigned' && $modifier->args === []) { $unsigned = true; }
                            elseif (! in_array($name, ['nullable', 'default', 'index', 'unique', 'comment', 'change', 'after', 'first'], true)) { continue 2; }
                        }
                        $result = [$total, $places, $unsigned];
                    }
                }
            }
        }
        return $result;
    }
}
