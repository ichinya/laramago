<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;
use PhpParser\NodeFinder;

/** Exact Pest 3/4 forwarding bodies; unrecognized versions defer to Mago. */
final class PestExpectationContract
{
    /**
     * Complete method AST fingerprints from pestphp/pest 3.x f108313b and
     * 4.x 5b2293f6. Comments and source positions do not affect the hash.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const METHODS = [
        'Pest\\Expectation' => [
            '__call' => [
                '40bfa32bb41392770677719765cb2b27526319f7eab0e6d25a0a43fd97460466',
                '5a26cab4cfe9306cce6012b9ff60638da0ed4b800d1fc034af45119dfff99802',
            ],
            'getexpectationclosure' => ['021d4f9b9dd62f20e0535aa117ba10a9764ad7595226dfabc56080b6b12c294e'],
            'hasmethod' => ['76e0b1a8fd392ea9ddd7a63d126fd050ee69c86c6952fe021b67a3b0121609b9'],
            'not' => ['b60f4862db78ed8623650cae2c203059baa0c0ae93ee28fbeda559a127f6e258'],
            'each' => ['447219a55eb24d98a9c03e06a54ee7b404dd0a3f60a036be5a7b869f03ca02d2'],
        ],
        'Pest\\Expectations\\OppositeExpectation' => [
            '__call' => ['323dfa6bd4f7663078e7e14d0da2d6b58aff44e0efb59cd772f3415bc017c127'],
            '__construct' => ['f0d4f958c3be7164c70b3434f52a7d7ea6574b63a330e5c719bc3815b5632814'],
        ],
        'Pest\\Expectations\\EachExpectation' => [
            '__call' => ['563bd85c36dc95b3bb2271d64979bb871ef95a22e8dc0e5e7b36d10cc2efbc28'],
            '__construct' => ['c18ab8de36901e0cb64aec0823f58faf2b385f91d59362ba455ef859ae31fc33'],
        ],
        'Pest\\Expectations\\HigherOrderExpectation' => [
            '__call' => ['5da73886c880403f2aeb2e6e6b1e17c3d7603447504b927653039ce5243cc4b3'],
            '__construct' => ['2795893799ea9a9440da61cd73a0b357d9453cc5a4f0e0204635bf38878eefad'],
            'expectationhasmethod' => ['d3da51478318adddfaa250c4054b6495c208f87a1ef40b4c1c4fe8e455637a7e'],
            'performassertion' => ['79dd93f8cc6e703bb106e28a27e9730b40ef858c0aa5e435c622d1fb63517aaf'],
        ],
    ];

    private readonly PhpSource $source;

    public function __construct(string $root)
    {
        $this->source = new PhpSource($root);
    }

    public function matches(string $class, ?string $file): bool
    {
        if ($file === null || ! isset(self::METHODS[$class])) {
            return false;
        }
        $file = str_starts_with($file, '//?/') ? substr($file, 4) : $file;
        $nodes = $this->source->read($file);
        if ($nodes === null) {
            return false;
        }
        $declarations = (new NodeFinder)->find(
            $nodes,
            static fn (Node $node): bool => (
                $node instanceof Node\Stmt\Class_
                && strcasecmp($node->namespacedName?->toString() ?? '', $class) === 0
            ),
        );
        if (count($declarations) !== 1) {
            return false;
        }
        $declaration = $declarations[0];
        if (! $declaration instanceof Node\Stmt\Class_ || ! $declaration->isFinal()) {
            return false;
        }
        foreach (self::METHODS[$class] as $name => $hashes) {
            $found = array_values(array_filter(
                $declaration->stmts,
                static fn (Node $node): bool => (
                    $node instanceof Node\Stmt\ClassMethod
                    && strcasecmp($node->name->toString(), $name) === 0
                ),
            ));
            if (count($found) !== 1) {
                return false;
            }
            $method = $found[0];
            if (! $method instanceof Node\Stmt\ClassMethod) {
                return false;
            }
            try {
                if (! in_array(self::fingerprint($method), $hashes, true)) {
                    return false;
                }
            } catch (\JsonException) {
                return false;
            }
        }

        return true;
    }

    /** Comments and file positions cannot alter a method contract. */
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
