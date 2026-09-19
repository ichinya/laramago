<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;
use PhpParser\ParserFactory;

/** Reads the installed Blade compiler's native compile methods without loading Laravel. */
final class BladeNativeDirectiveCatalog
{
    /** @var list<string>|null */
    private ?array $names = null;

    public function __construct(string $projectRoot)
    {
        $root = rtrim($projectRoot, '/\\');
        $vendor = self::vendorDirectory($root);
        if ($vendor === null) {
            return;
        }
        foreach (['laravel/framework/src/Illuminate/View/Compilers', 'illuminate/view/Compilers'] as $candidate) {
            $directory = $vendor.'/'.$candidate;
            if (! is_file($directory.'/BladeCompiler.php')) {
                continue;
            }
            $class = self::declaration($directory.'/BladeCompiler.php', Node\Stmt\Class_::class, 'BladeCompiler');
            if (! $class instanceof Node\Stmt\Class_ || $class->extends?->toString() !== 'Compiler') {
                return;
            }
            $base = self::declaration($directory.'/Compiler.php', Node\Stmt\Class_::class, 'Compiler');
            if (! $base instanceof Node\Stmt\Class_ || $base->extends !== null) {
                return;
            }
            foreach ($base->stmts as $member) {
                if ($member instanceof Node\Stmt\TraitUse) {
                    return;
                }
            }
            $names = [...self::compileMethods($base->stmts), ...self::compileMethods($class->stmts)];
            foreach ($class->stmts as $statement) {
                if (! $statement instanceof Node\Stmt\TraitUse) {
                    continue;
                }
                if ($statement->adaptations !== []) {
                    return;
                }
                foreach ($statement->traits as $traitName) {
                    $trait = $traitName->toString();
                    if ($trait === 'ReflectsClosures') {
                        continue;
                    }
                    $match = [];
                    if (preg_match('/^Concerns\\\\(Compiles[A-Za-z]+)$/D', $trait, $match) !== 1) {
                        return;
                    }
                    $declaration = self::declaration(
                        $directory.'/Concerns/'.$match[1].'.php',
                        Node\Stmt\Trait_::class,
                        $match[1],
                    );
                    if (! $declaration instanceof Node\Stmt\Trait_) {
                        return;
                    }
                    foreach ($declaration->stmts as $member) {
                        if ($member instanceof Node\Stmt\TraitUse) {
                            return;
                        }
                    }
                    $names = [...$names, ...self::compileMethods($declaration->stmts)];
                }
            }
            if ($names === []) {
                return;
            }
            // These are handled by Blade's raw-block pass, not compile methods.
            $this->names = array_values(array_unique([...$names, 'verbatim', 'endverbatim']));

            return;
        }
    }

    /** @return list<string>|null Null means no supported installed compiler source. */
    public function names(): ?array
    {
        return $this->names;
    }

    private static function vendorDirectory(string $root): ?string
    {
        $source = @file_get_contents($root.'/composer.json');
        /** @var mixed $composer */
        $composer = $source === false ? [] : json_decode($source, true);
        /** @var mixed $config */
        $config = is_array($composer) ? $composer['config'] ?? [] : null;
        /** @var mixed $vendor */
        $vendor = is_array($config) ? $config['vendor-dir'] ?? 'vendor' : null;
        if (! is_string($vendor) || $vendor === '' || str_contains($vendor, "\0") || str_contains($vendor, '$')) {
            return null;
        }
        $vendor = str_replace('\\', '/', $vendor);
        $absolute = str_starts_with($vendor, '/') || preg_match('~^[A-Za-z]:/~', $vendor) === 1;

        return $absolute ? rtrim($vendor, '/') : $root.'/'.trim($vendor, '/');
    }

    /** @param array<array-key, Node\Stmt> $members
     * @return list<string>
     */
    private static function compileMethods(array $members): array
    {
        $names = [];
        foreach ($members as $member) {
            if (! $member instanceof Node\Stmt\ClassMethod) {
                continue;
            }
            $method = $member->name->toString();
            $match = [];
            if (preg_match('/^compile([A-Za-z0-9_]+)$/D', $method, $match) === 1) {
                $names[] = strtolower($match[1]);
            }
        }

        return $names;
    }

    /** @param class-string<Node\Stmt\ClassLike> $type */
    private static function declaration(string $path, string $type, string $name): ?Node\Stmt\ClassLike
    {
        $source = @file_get_contents($path);
        if ($source === false || strlen($source) > 1048576) {
            return null;
        }
        try {
            $nodes = (new ParserFactory)
                ->createForNewestSupportedVersion()
                ->parse($source);
        } catch (\PhpParser\Error) {
            return null;
        }
        if ($nodes === null) {
            return null;
        }
        foreach ($nodes as $node) {
            $body = $node instanceof Node\Stmt\Namespace_ ? $node->stmts : [$node];
            foreach ($body as $statement) {
                if ($statement instanceof $type && $statement->name?->toString() === $name) {
                    return $statement;
                }
            }
        }

        return null;
    }
}
