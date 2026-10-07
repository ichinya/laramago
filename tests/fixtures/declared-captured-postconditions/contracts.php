<?php
namespace Testo;
final class Assert {
    /**
     * @psalm-assert !null $actual
     * @phpstan-assert !null $actual
     */
    public static function notNull(mixed $actual, string $message = ''): void { if ($actual === null) { throw new \RuntimeException; } }
    /**
     * @template ExpectedType
     * @param ExpectedType $expected
     * @psalm-assert =ExpectedType $actual
     * @phpstan-assert =ExpectedType $actual
     */
    public static function same(mixed $actual, mixed $expected, string $message = ''): void { if ($actual !== $expected) { throw new \RuntimeException; } }
    /** @phpstan-assert true $actual */
    public static function true(mixed $actual, string $message = ''): void { if ($actual !== true) { throw new \RuntimeException; } }
}
namespace Illuminate\Support;
/**
 * @template TKey of array-key
 * @template TValue
 * @implements \ArrayAccess<TKey, TValue>
 */
class Collection implements \ArrayAccess {
    /** @var array<TKey, TValue> */
    protected $items = [];
    /** @param array<TKey, TValue> $items */
    public function __construct(array $items = []) { $this->items = $items; }
    /**
     * @param TKey $offset
     * @return TValue
     */
    public function offsetGet($offset): mixed { return $this->items[$offset]; }
    /** @param TKey $offset */
    public function offsetExists($offset): bool { return isset($this->items[$offset]); }
    /**
     * @param TKey|null $offset
     * @param TValue $value
     */
    public function offsetSet($offset, $value): void { if (is_null($offset)) { $this->items[] = $value; } else { $this->items[$offset] = $value; } }
    /** @param TKey $offset */
    public function offsetUnset($offset): void { unset($this->items[$offset]); }
}
namespace Illuminate\Database\Eloquent;
abstract class Model {
    /** @return Collection<int, static> */
    public function newCollection(array $models = []) { return new Collection($models); }
}
/**
 * @template TKey of array-key
 * @template TModel of Model
 * @extends \Illuminate\Support\Collection<TKey, TModel>
 */
class Collection extends \Illuminate\Support\Collection {}
namespace Illuminate\Database\Eloquent\Factories;
/** @template TFactory of Factory */
trait HasFactory {
    /** @return TFactory */
    public static function factory($count = null, $state = []) { throw new \RuntimeException('Declaration only.'); }
    /** @return TFactory|null */
    protected static function newFactory() { return null; }
}
/** @template TModel of \Illuminate\Database\Eloquent\Model */
abstract class Factory {
    /** @var class-string<TModel> */
    protected $model;
    /** @var int|null */
    protected $count;
    /**
     * @param int|null $count
     * @return static
     */
    public function count(?int $count) { return $this->newInstance(['count' => $count]); }
    /** @return static */
    protected function newInstance(array $arguments = []) { return new static; }
    /**
     * @param array<string, mixed> $attributes
     * @return \Illuminate\Database\Eloquent\Collection<int, TModel>
     */
    public function create($attributes = []) { throw new \RuntimeException('Declaration only.'); }
}
namespace App\Models;
final class Specimen extends \Illuminate\Database\Eloquent\Model {
    /** @use \Illuminate\Database\Eloquent\Factories\HasFactory<\Database\Factories\SpecimenFactory> */
    use \Illuminate\Database\Eloquent\Factories\HasFactory;
}
final class InheritedSpecimen extends \Illuminate\Database\Eloquent\Model {
    /** @use \Illuminate\Database\Eloquent\Factories\HasFactory<\Database\Factories\InheritedSpecimenFactory> */
    use \Illuminate\Database\Eloquent\Factories\HasFactory;
}
final class UninitializedSpecimen extends \Illuminate\Database\Eloquent\Model {
    /** @use \Illuminate\Database\Eloquent\Factories\HasFactory<\Database\Factories\UninitializedSpecimenFactory> */
    use \Illuminate\Database\Eloquent\Factories\HasFactory;
}
namespace Database\Factories;
use App\Models\InheritedSpecimen as ImportedModel;
/** @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Specimen> */
final class SpecimenFactory extends \Illuminate\Database\Eloquent\Factories\Factory {
    protected $model = \App\Models\Specimen::class;
}
/** @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\InheritedSpecimen> */
abstract class InheritedSpecimenFactoryBase extends \Illuminate\Database\Eloquent\Factories\Factory {
    protected $model = ImportedModel::class;
}
final class InheritedSpecimenFactory extends InheritedSpecimenFactoryBase {}
/** @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\UninitializedSpecimen> */
final class UninitializedSpecimenFactory extends \Illuminate\Database\Eloquent\Factories\Factory {}
namespace InventedWarningContracts;
final class Row { public function __construct(public ?string $value = null) {} }
final class HookedRow { public ?string $value { get => 'known'; } }
final class StageDispatcher {
    public function invoke(?callable $hook = null): void { if ($hook !== null) { $hook('example'); } }
}
final class DirectDispatcher {
    public function invoke(\stdClass $input, \Closure $next): \stdClass {
        $output = $next($input);
        if (!$output instanceof \stdClass) { throw new \UnexpectedValueException; }
        return $output;
    }
}
function nullableValue(\stdClass $input): ?string { return null; }
function mutateRow(Row $row): string { $row->value = null; return 'Changed while evaluating the message.'; }
function message(): string { return 'Unknown message expression.'; }
file_put_contents(__DIR__.'/contracts-executed', 'Fixture declarations must never execute.');