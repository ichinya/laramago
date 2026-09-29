<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Type\MixedType;
use Mago\Sdk\Analyzer\Type\Visibility;
use PhpParser\PrettyPrinter\Standard;

/** Verify that the installed Query Builder still implements Laravel's native aggregate contract. */
final class NativeQueryAggregate
{
    private const QUERY = 'Illuminate\\Database\\Query\\Builder';

    public function __construct(
        private readonly PhpSource $source,
    ) {}

    public function proves(Codebase $codebase, string $name): bool
    {
        $method = $codebase->getMethod(self::QUERY, $name)
            ?? $codebase->getDeclaringMethod(self::QUERY, $name);
        if (
            $method === null
            || strcasecmp($method->identifier->class ?? '', self::QUERY) !== 0
            || $method->static
            || $method->visibility !== Visibility::Public
            || count($method->parameters) !== 1
            || $method->parameters[0]->name !== '$column'
            || ! ($method->returnType?->type->atomicTypes[0] ?? null) instanceof MixedType
            || count($method->returnType?->type->atomicTypes ?? []) !== 1
            || ! str_ends_with(
                str_replace('\\', '/', $this->source->path($method->location->file ?? '')),
                '/laravel/framework/src/Illuminate/Database/Query/Builder.php',
            )
        ) {
            return false;
        }
        $node = (new ModelReflection($codebase, $this->source))->methodNode($method);

        if ($node === null) {
            return false;
        }
        $expected = match ($name) {
            'sum' => '$result = $this->aggregate(__FUNCTION__, [$column]);'."\n".'return $result ?: 0;',
            'avg' => 'return $this->aggregate(__FUNCTION__, [$column]);',
            'average' => 'return $this->avg($column);',
            default => null,
        };

        return $expected !== null && (new Standard)->prettyPrint($node->stmts ?? []) === $expected;
    }
}
