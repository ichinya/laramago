<?php

namespace FloatFixtures;

file_put_contents(__DIR__.'/declaration-body-executed', 'Analyzed declarations must never execute.');

/** @return numeric-string */
function numeric(): string { return '2.50'; }
function arbitrary(): string { return 'arbitrary text'; }
function consume(float $value): void {}
function pair(string $label, float $value): void {}
/** @param float(1.0) $value */
function narrow(float $value): void {}
/** @param float $value */
function documented($value): void {}
function reference(float &$value): void {}
function variadic(float ...$values): void {}

final class DecimalInput
{
    /** @var numeric-string */
    public string $amount = '2.50';
}

final class FloatSink
{
    public static function take(float $value): void {}
}

class OpenFloatSink
{
    public static function take(float $value): void {}
}

final class ChildFloatSink extends OpenFloatSink
{
    /** @param float(1.0) $value */
    public static function take(float $value): void {}
}

/** @property float $amount */
final class VirtualFloatStorage
{
    public function __get(string $name): mixed { return null; }
    public function __set(string $name, mixed $value): void {}
}
