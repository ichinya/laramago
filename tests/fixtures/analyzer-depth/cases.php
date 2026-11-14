<?php

declare(strict_types=1);

namespace AnalyzerDepth;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

function takesInt(int $value): int { return $value; }
final class Plain { public int $number = 1; public function number(): int { return 1; } }
final class Magic { public function __get(string $name): mixed { return null; } }

function unknownFunctionBad(): int { return missingDepthFunction(); } // DEPTH unknown-function bad
function unknownFunctionGood(): int { return takesInt(1); } // DEPTH unknown-function good
function unknownClassBad(): object { return new MissingDepthClass; } // DEPTH unknown-class bad
function unknownClassGood(): object { return new Plain; } // DEPTH unknown-class good
function undefinedVariableBad(): int { return $absent; } // DEPTH undefined-variable bad
function undefinedVariableGood(): int { $present = 1; return $present; } // DEPTH undefined-variable good
function missingArgumentBad(): int { return takesInt(); } // DEPTH missing-argument bad
function missingArgumentGood(): int { return takesInt(1); } // DEPTH missing-argument good

function maybeUndefinedBad(bool $flag): int { if ($flag) { $value = 1; } return $value; } // DEPTH maybe-undefined bad
function maybeUndefinedGood(bool $flag): int { $value = 0; if ($flag) { $value = 1; } return $value; } // DEPTH maybe-undefined good
function magicPropertyBad(Magic $value): mixed { return $value->missing; } // DEPTH magic-property bad
function magicPropertyGood(Magic $value): mixed { return $value->__get('missing'); } // DEPTH magic-property good
function unknownMethodBad(Plain $value): int { return $value->missing(); } // DEPTH unknown-method bad
function unknownMethodGood(Plain $value): int { return $value->number(); } // DEPTH unknown-method good

/** @param int $value */ // DEPTH incompatible-phpdoc bad
function incompatibleDocBad(string $value): string { return $value; }
/** @param string $value */ // DEPTH incompatible-phpdoc good
function incompatibleDocGood(string $value): string { return $value; }

function returnTypeBad(): int { return 'wrong'; } // DEPTH return-type bad
function returnTypeGood(): int { return 1; } // DEPTH return-type good
function propertyTypeBad(Plain $value): void { $value->number = 'wrong'; } // DEPTH property-type bad
function propertyTypeGood(Plain $value): void { $value->number = 1; } // DEPTH property-type good
function unreachableBad(): int { return 1;
    return 2; // DEPTH unreachable bad
}
function unreachableGood(bool $flag): int { if ($flag) { return 1; }
    return 2; // DEPTH unreachable good
}
function impossibleTypeBad(Plain $value): bool { return $value instanceof \DateTimeImmutable; } // DEPTH impossible-type bad
function impossibleTypeGood(Plain|\DateTimeImmutable $value): bool { return $value instanceof \DateTimeImmutable; } // DEPTH impossible-type good
function argumentTypeBad(): int { return takesInt('wrong'); } // DEPTH argument-type bad
function argumentTypeGood(): int { return takesInt(1); } // DEPTH argument-type good

function missingParameterBad($value): int { return 1; } // DEPTH missing-parameter bad
function missingParameterGood(int $value): int { return 1; } // DEPTH missing-parameter good
function missingReturnBad(int $value) { return $value; } // DEPTH missing-return bad
function missingReturnGood(int $value): int { return $value; } // DEPTH missing-return good
function iterableValueBad(array $items): int { return count($items); } // DEPTH iterable-value bad
/** @param list<int> $items */
function iterableValueGood(array $items): int { return count($items); } // DEPTH iterable-value good
function unionArgumentBad(int|string $value): int { return takesInt($value); } // DEPTH union-argument bad
function unionArgumentGood(int|string $value): int { return takesInt((int) $value); } // DEPTH union-argument good
function unionMethodBad(Plain|\DateTimeImmutable $value): int { return $value->number(); } // DEPTH union-method bad
function unionMethodGood(Plain|\DateTimeImmutable $value): int { return $value instanceof Plain ? $value->number() : 0; } // DEPTH union-method good
function nullableMethodBad(?Plain $value): int { return $value->number(); } // DEPTH nullable-method bad
function nullableMethodGood(?Plain $value): ?int { return $value?->number(); } // DEPTH nullable-method good
function nullablePropertyBad(?Plain $value): int { return $value->number; } // DEPTH nullable-property bad
function nullablePropertyGood(?Plain $value): ?int { return $value?->number; } // DEPTH nullable-property good
function explicitMixedArgumentBad(mixed $value): int { return takesInt($value); } // DEPTH explicit-mixed-argument bad
function explicitMixedArgumentGood(mixed $value): int { return is_int($value) ? takesInt($value) : 0; } // DEPTH explicit-mixed-argument good
function explicitMixedMethodBad(mixed $value): int { return $value->number(); } // DEPTH explicit-mixed-method bad
function explicitMixedMethodGood(mixed $value): int { return $value instanceof Plain ? $value->number() : 0; } // DEPTH explicit-mixed-method good
function implicitMixedArgumentBad($value): int {
    return takesInt($value); // DEPTH implicit-mixed-argument bad
}
function implicitMixedArgumentGood(mixed $value): int {
    return is_int($value) ? takesInt($value) : 0; // DEPTH implicit-mixed-argument good
}
function implicitMixedMethodBad($value): int {
    return $value->number(); // DEPTH implicit-mixed-method bad
}
function implicitMixedMethodGood(mixed $value): int {
    return $value instanceof Plain ? $value->number() : 0; // DEPTH implicit-mixed-method good
}

/** @param array{name:string} $row */
function arrayShapeBad(array $row): int { return $row['name']; } // DEPTH array-shape bad
/** @param array{name:string} $row */
function arrayShapeGood(array $row): string { return $row['name']; } // DEPTH array-shape good
/** @param callable(int): int $callback */
function callableArgumentBad(callable $callback): int { return $callback('wrong'); } // DEPTH callable-argument bad
/** @param callable(int): int $callback */
function callableArgumentGood(callable $callback): int { return $callback(1); } // DEPTH callable-argument good
/**
 * @template T
 * @param T $value
 * @return T
 */
function identity(mixed $value): mixed { return $value; }
function genericIdentityBad(): int { return identity('wrong'); } // DEPTH generic-identity bad
function genericIdentityGood(): string { return identity('right'); } // DEPTH generic-identity good

/** @property string $label */
final class DepthModel extends Model {}
function modelPropertyBad(DepthModel $model): int { return $model->label; } // DEPTH model-property bad
function modelPropertyGood(DepthModel $model): string { return $model->label; } // DEPTH model-property good
/** @param Collection<int, string> $items */
function collectionFirstBad(Collection $items): int { return $items->first(); } // DEPTH collection-first bad
/** @param Collection<int, string> $items */
function collectionFirstGood(Collection $items): ?string { return $items->first(); } // DEPTH collection-first good
/** @return array<string, int> */
function configValueBad(): array { return config('depth.count'); } // DEPTH config-value bad
function configValueGood(): int { return config('depth.count'); } // DEPTH config-value good
function queryNullableBad(): string { return DepthModel::query()->first()->label; } // DEPTH query-nullable bad
function queryNullableGood(): ?string { return DepthModel::query()->first()?->label; } // DEPTH query-nullable good

final class SchemaModel extends Model { protected $table = 'schema_models'; }
function migratedPropertyBad(SchemaModel $model): int { return $model->label; } // DEPTH migrated-property bad
function migratedPropertyGood(SchemaModel $model): string { return $model->label; } // DEPTH migrated-property good
function configDefaultBad(): string { return config('depth.absent', 42); } // DEPTH config-default bad
function configDefaultGood(): int { return config('depth.absent', 42); } // DEPTH config-default good
