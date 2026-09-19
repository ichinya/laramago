<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/** Source declarations, not an assertion about runtime component data or registration. */
final readonly class BladeClassComponent
{
    /**
     * Null member lists mean that a trait or non-public constructor prevents safe extraction.
     * Types are declared PHP syntax; null types are untyped, never inferred mixed contracts.
     *
     * @param list<array{name: string, type: ?string, hasDefault: bool, variadic: bool, promoted: bool, declaredIn: string}>|null $constructor
     * @param array<string, array{type: ?string, declaredIn: string}>|null $properties
     */
    public function __construct(
        public string $class,
        public string $path,
        public ?array $constructor,
        public ?array $properties,
    ) {}
}
