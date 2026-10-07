<?php

declare(strict_types=1);

namespace PrimitiveOperandFocus;

function lower(int|false $value, int $threshold): bool { return $value < $threshold; }
function greater(int|false $value): bool { return $value > 0; }
function grouped(int|false $value): bool { return (($value)) > 0; }
/** @param false|non-negative-int $value */
function bounded(int|false $value): bool { return $value > 0; }
/** @param false|int<1750595956, max> $value */
function rangeValue(int|false $value, int $threshold): bool { return $value < $threshold; }
function builtin(string $path): bool { return \filemtime($path) > 0; }
function nativeJson(array $value): string { return 'value='.\json_encode($value, JSON_UNESCAPED_SLASHES)."\n"; }
function rightJson(array $value): string { return 'value='.\json_encode($value, JSON_UNESCAPED_SLASHES); }
function wrongComparisonReturn(int|false $value): string { return $value > 0; }
function wrongConcatReturn(array $value): int { return 'value='.\json_encode($value); }
function divisor(int|false $value): int|float { return 1 / $value; }
function modulo(int|false $value): int { return 1 % $value; }
function mixedArithmetic(mixed $value): int { return $value + 1; }
function falseStringComparison(string|false $value): bool { return $value > 'text'; }
function nullableComparison(?int $value): bool { return $value > 0; }
function objectComparison(object|false $value): bool { return $value > 0; }
function arrayConcat(array|false $value): string { return 'value='.$value; }
function mixedConcat(mixed $value): string { return 'value='.$value; }
function objectConcat(object|false $value): string { return 'value='.$value; }
function contaminated(int|false $value, int $threshold): bool { $threshold = 'text'; return $value < $threshold; }
function reusedPeer(int|false $value, int $threshold): bool { consume($threshold); return $value < $threshold; }
function consume(mixed &$value): void {}
function siblingError(int|false $value): bool { return $value > \strlen(new \stdClass); }

namespace ShadowedOperandFocus;

function filemtime(string $path): object { return new \stdClass; }
function json_encode(array $value): array { return $value; }
function shadowComparison(string $path): bool { return filemtime($path) > 0; }
function shadowConcat(array $value): string { return 'value='.json_encode($value); }

file_put_contents(__DIR__.'/case-body-executed', 'Analyzed source body must never execute.');
