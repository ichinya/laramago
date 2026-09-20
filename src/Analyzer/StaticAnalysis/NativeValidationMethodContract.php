<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;
use PhpParser\NodeFinder;

/** Complete syntax contracts for the supported Laravel validation entry points. */
final class NativeValidationMethodContract
{
    /**
     * Fingerprints of complete method ASTs from laravel/framework 7c75fbf.
     * Whitespace and comments are ignored; signatures, defaults and bodies are not.
     * An unrecognized installed version safely defers to native Mago behavior.
     *
     * @var array<string, array<string, string>>
     */
    private const FINGERPRINTS = [
        'Illuminate\\Validation\\Factory' => [
            'make' => '45f58a7eaab01d0ce21f92a00e328b7a8eed6e2b3739e3f973473ce3433a3f3d',
            'validate' => '54cfcddaad555f6e4be467a00c00b513e1ba5f15506a52835546a14218a17209',
        ],
        'Illuminate\\Validation\\Validator' => [
            'setrules' => '78c091b10bb1d173133ba153e14524b1455e2ff7dc164048c0a6ebd5d5851b53',
            'addrules' => '77989b911d8b696cc451b8c95717520558fac8749eac7dd6056eae4110d904f1',
            'sometimes' => '5014a35a4d8aa8007bc4b236cfdaecc361530528462bcc92431858430abac717',
        ],
    ];

    private readonly PhpSource $source;

    public function __construct(string $root)
    {
        $this->source = new PhpSource($root);
    }

    public function matches(string $class, string $method, string $file): bool
    {
        $method = strtolower($method);
        if (! isset(self::FINGERPRINTS[$class][$method])) {
            return false;
        }
        $nodes = $this->source->read(str_starts_with($file, '//?/') ? substr($file, 4) : $file);
        if ($nodes === null) {
            return false;
        }
        $declaration = (new NodeFinder)->findFirst(
            $nodes,
            static fn (Node $node): bool => (
                $node instanceof Node\Stmt\Class_
                && strcasecmp($node->namespacedName?->toString() ?? '', $class) === 0
            ),
        );

        if (! $declaration instanceof Node\Stmt\Class_) {
            return false;
        }
        $required = match ($method) {
            'validate' => ['validate', 'make'],
            'setrules' => ['setrules', 'addrules'],
            'sometimes' => ['sometimes', 'addrules'],
            default => [$method],
        };
        foreach ($required as $name) {
            $node = $declaration->getMethod($name);
            if ($node === null) {
                return false;
            }
            try {
                if (self::fingerprint($node) !== self::FINGERPRINTS[$class][$name]) {
                    return false;
                }
            } catch (\JsonException) {
                return false;
            }
        }

        return true;
    }

    /** Comments and file positions cannot change a method contract. */
    public static function fingerprint(Node\Stmt\ClassMethod $method): string
    {
        return hash('sha256', json_encode(self::structure($method), JSON_THROW_ON_ERROR));
    }

    /** @return array<mixed>|scalar|null */
    private static function structure(mixed $value): array|string|int|float|bool|null
    {
        if ($value instanceof Node) {
            $result = [$value->getType()];
            $properties = get_object_vars($value);
            foreach ($value->getSubNodeNames() as $name) {
                $result[$name] = self::structure($properties[$name] ?? null);
            }

            return $result;
        }
        if (is_array($value)) {
            $result = [];
            /** @var mixed $item */
            foreach ($value as $key => $item) {
                $result[$key] = self::structure($item);
            }

            return $result;
        }

        return is_scalar($value) ? $value : null;
    }
}
