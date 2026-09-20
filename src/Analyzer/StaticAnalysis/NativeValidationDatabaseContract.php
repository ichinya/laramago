<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;

/** Whole-method contracts for the installed native database-rule parsing path. */
final class NativeValidationDatabaseContract
{
    /**
     * Hashes of every method AST in the pinned Laravel framework source. This
     * includes the parser, validator dispatch and database rule interpretation.
     * Comments and whitespace do not affect the contract.
     */
    private const SOURCES = [
        'Validator.php' => [
            'Illuminate\\Validation\\Validator',
            'eb85a93731ecfc72f4b88f1780bbe1d1a9ea5240714b23f506be35ae0ab8f130',
        ],
        'ValidationRuleParser.php' => [
            'Illuminate\\Validation\\ValidationRuleParser',
            'fb9d72dd77f7c3736db5053122a492f41fde5c53fa6b0446ba309a7b8839d04a',
        ],
        'Concerns/ValidatesAttributes.php' => [
            'Illuminate\\Validation\\Concerns\\ValidatesAttributes',
            '8cec7a520bf29ff2ebbf5c2f1d06c1188b53010d9b31a21e1e6d2b2ee61db87f',
        ],
    ];

    public function __construct(
        private readonly string $root,
    ) {}

    public function matches(): bool
    {
        $source = new PhpSource($this->root);
        $directory = (new BuiltinValidationRuleCatalog($this->root))->sourcePath();
        if ($directory === null) {
            return false;
        }
        foreach (self::SOURCES as $file => [$name, $expected]) {
            $nodes = $source->read($directory.'/'.$file);
            if ($nodes === null) {
                return false;
            }
            $declaration = ResolvedClassIdentity::uniqueDeclaration($nodes, $name);
            if (! $declaration instanceof Node\Stmt\Class_ && ! $declaration instanceof Node\Stmt\Trait_) {
                return false;
            }
            if ($file === 'Validator.php' && ! $this->usesNativeAttributes($declaration)) {
                return false;
            }
            $methods = [];
            foreach ($declaration->getMethods() as $method) {
                $methods[] =
                    strtolower($method->name->toString()).':'.NativeValidationMethodContract::fingerprint($method);
            }
            if (hash('sha256', implode('|', $methods)) !== $expected) {
                return false;
            }
        }

        return true;
    }

    private function usesNativeAttributes(Node\Stmt\Class_|Node\Stmt\Trait_ $validator): bool
    {
        foreach ($validator->stmts as $statement) {
            if (! $statement instanceof Node\Stmt\TraitUse || $statement->adaptations !== []) {
                continue;
            }
            foreach ($statement->traits as $trait) {
                if (ResolvedClassIdentity::is($trait, 'Illuminate\\Validation\\Concerns\\ValidatesAttributes')) {
                    return true;
                }
            }
        }

        return false;
    }
}
