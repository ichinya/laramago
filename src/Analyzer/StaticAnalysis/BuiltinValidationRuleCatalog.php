<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** Positive method metadata from a structurally compatible installed validator source. */
final class BuiltinValidationRuleCatalog
{
    /** @var array<string, string>|null Canonical snake name => validator method. */
    private ?array $rules = null;

    private ?string $version = null;

    private ?string $sourcePath = null;

    /** @var array<string, string> Parser short rule => normalized method suffix. */
    private array $aliases = [];

    public function __construct(string $projectRoot)
    {
        $root = rtrim(str_replace('\\', '/', $projectRoot), '/');
        $vendor = self::vendorDirectory($root);
        if ($vendor === null) {
            return;
        }

        foreach (['laravel/framework/src/Illuminate/Validation', 'illuminate/validation'] as $relative) {
            $directory = $vendor.'/'.$relative;
            $validator = self::declaration(
                $directory.'/Validator.php',
                'Validator',
                'Illuminate\\Validation',
            );
            $parser = self::declaration(
                $directory.'/ValidationRuleParser.php',
                'ValidationRuleParser',
                'Illuminate\\Validation',
            );
            $trait = self::declaration(
                $directory.'/Concerns/ValidatesAttributes.php',
                'ValidatesAttributes',
                'Illuminate\\Validation\\Concerns',
            );
            if (
                ! $validator instanceof Node\Stmt\Class_
                || ! $parser instanceof Node\Stmt\Class_
                || ! $trait instanceof Node\Stmt\Trait_
            ) {
                continue;
            }
            if (! self::usesValidationTrait($validator) || ! self::hasNativeDispatch($validator, $parser)) {
                return;
            }

            $rules = [];
            foreach ($trait->getMethods() as $method) {
                $name = $method->name->toString();
                $match = [];
                if (preg_match('/^validate([A-Z][A-Za-z0-9]*)$/D', $name, $match) !== 1) {
                    continue;
                }
                if (! self::isRuleMethod($method, $match[1])) {
                    continue;
                }
                $canonical = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $match[1]));
                $rules[$canonical] = $name;
            }
            if ($rules === []) {
                return;
            }
            ksort($rules);
            $this->rules = $rules;
            $this->sourcePath = $directory;
            $this->version = self::installedVersion(
                $vendor,
                str_starts_with($relative, 'laravel/') ? 'laravel/framework' : 'illuminate/validation',
            );
            $this->aliases = self::parserAliases($parser);

            return;
        }
    }

    /** @return array<string, string>|null Null means the installed native source was not proven. */
    public function rules(): ?array
    {
        return $this->rules;
    }

    /** Returns positive built-in evidence only; null never proves an unknown rule invalid. */
    public function methodFor(string $name): ?string
    {
        if ($this->rules === null) {
            return null;
        }
        $name = trim(explode(':', $name, 2)[0]);
        if (! preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/D', $name)) {
            return null;
        }
        // Match Str::studly's separator and case handling for rule names.
        $studly = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $name)));
        $studly = $this->aliases[$studly] ?? $studly;
        $method = 'validate'.$studly;

        // PHP method dispatch is case insensitive, even though normalizeRule's
        // short aliases above are case sensitive.
        foreach ($this->rules as $nativeMethod) {
            if (strcasecmp($nativeMethod, $method) === 0) {
                return $nativeMethod;
            }
        }

        return null;
    }

    public function frameworkVersion(): ?string
    {
        return $this->version;
    }

    public function sourcePath(): ?string
    {
        return $this->sourcePath;
    }

    private static function isRuleMethod(Node\Stmt\ClassMethod $method, string $suffix): bool
    {
        if ($method->isStatic() || $method->isPrivate() || $method->stmts === null) {
            return false;
        }
        if ($method->params === []) {
            // These are explicit parser-visible control rules in Laravel's validator.
            return in_array($suffix, ['Bail', 'Nullable', 'Sometimes', 'Exclude'], true);
        }

        if (count($method->params) < 2) {
            return false;
        }
        $first = $method->params[0]->var;
        $second = $method->params[1]->var;
        if (! $first instanceof Node\Expr\Variable || ! $second instanceof Node\Expr\Variable) {
            return false;
        }
        $attribute = $first->name;
        $value = $second->name;

        return is_string($attribute) && $attribute === 'attribute' && is_string($value) && $value === 'value';
    }

    private static function usesValidationTrait(Node\Stmt\Class_ $validator): bool
    {
        foreach ($validator->stmts as $statement) {
            if (! $statement instanceof Node\Stmt\TraitUse || $statement->adaptations !== []) {
                continue;
            }
            foreach ($statement->traits as $name) {
                if (ResolvedClassIdentity::is($name, 'Illuminate\\Validation\\Concerns\\ValidatesAttributes')) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function hasNativeDispatch(Node\Stmt\Class_ $validator, Node\Stmt\Class_ $parser): bool
    {
        $dispatch = $validator->getMethod('validateAttribute');
        $parse = $parser->getMethod('parseStringRule');
        $normalize = $parser->getMethod('normalizeRule');
        if ($dispatch === null || $parse === null || $normalize === null) {
            return false;
        }
        $finder = new NodeFinder;
        $parsed = $finder->findFirst(
            $dispatch,
            static fn (Node $node): bool => (
                $node instanceof Node\Expr\StaticCall
                && $node->class instanceof Node\Name
                && ResolvedClassIdentity::is($node->class, 'Illuminate\\Validation\\ValidationRuleParser')
                && $node->name instanceof Node\Identifier
                && $node->name->toString() === 'parse'
            ),
        );
        $dynamic = $finder->findFirst(
            $dispatch,
            static fn (Node $node): bool => (
                $node instanceof Node\Expr\MethodCall
                && $node->var instanceof Node\Expr\Variable
                && $node->var->name === 'this'
                && $node->name instanceof Node\Expr\Variable
                && $node->name->name === 'method'
            ),
        );
        $studly = $finder->findFirst(
            $parse,
            static fn (Node $node): bool => (
                $node instanceof Node\Expr\StaticCall
                && $node->class instanceof Node\Name
                && ResolvedClassIdentity::is($node->class, 'Illuminate\\Support\\Str')
                && $node->name instanceof Node\Identifier
                && $node->name->toString() === 'studly'
            ),
        );
        $aliases = $finder->findFirst($normalize, static fn (Node $node): bool => $node instanceof Node\Expr\Match_);

        return $parsed !== null && $dynamic !== null && $studly !== null && $aliases !== null;
    }

    /** @return array<string, string> */
    private static function parserAliases(Node\Stmt\Class_ $parser): array
    {
        $method = $parser->getMethod('normalizeRule');
        if ($method === null) {
            return [];
        }
        $match = (new NodeFinder)->findFirstInstanceOf($method, Node\Expr\Match_::class);
        if (! $match instanceof Node\Expr\Match_) {
            return [];
        }
        $aliases = [];
        foreach ($match->arms as $arm) {
            if (! $arm->body instanceof Node\Scalar\String_) {
                continue;
            }
            foreach ($arm->conds ?? [] as $condition) {
                if ($condition instanceof Node\Scalar\String_) {
                    $aliases[$condition->value] = $arm->body->value;
                }
            }
        }

        return $aliases;
    }

    private static function vendorDirectory(string $root): ?string
    {
        $source = @file_get_contents($root.'/composer.json');
        /** @var mixed $composer */
        $composer = $source === false ? [] : json_decode($source, true);
        /** @var mixed $config */
        $config = is_array($composer) ? $composer['config'] ?? [] : null;
        /** @var mixed $vendor */
        $vendor = is_array($config) ? $config['vendor-dir'] ?? 'vendor' : 'vendor';
        if (! is_string($vendor) || $vendor === '' || str_contains($vendor, "\0") || str_contains($vendor, '$')) {
            return null;
        }
        $vendor = str_replace('\\', '/', $vendor);
        $absolute = str_starts_with($vendor, '/') || preg_match('~^[A-Za-z]:/~', $vendor) === 1;

        return $absolute ? rtrim($vendor, '/') : $root.'/'.trim($vendor, '/');
    }

    private static function installedVersion(string $vendor, string $package): ?string
    {
        $source = @file_get_contents($vendor.'/composer/installed.json');
        /** @var mixed $installed */
        $installed = $source === false ? null : json_decode($source, true);
        /** @var mixed $packages */
        $packages = is_array($installed) ? $installed['packages'] ?? $installed : null;
        if (! is_array($packages)) {
            return null;
        }
        /** @var mixed $entry */
        foreach ($packages as $entry) {
            if (is_array($entry) && ($entry['name'] ?? null) === $package && is_string($entry['version'] ?? null)) {
                return $entry['version'];
            }
        }

        return null;
    }

    private static function declaration(string $path, string $name, string $namespace): ?Node\Stmt\ClassLike
    {
        $source = @file_get_contents($path);
        if ($source === false || strlen($source) > 1048576) {
            return null;
        }
        try {
            $nodes = (new ParserFactory)
                ->createForNewestSupportedVersion()
                ->parse($source);
            $resolved = (new NodeTraverser(new NameResolver))->traverse($nodes ?? []);
        } catch (\PhpParser\Error) {
            return null;
        }

        return ResolvedClassIdentity::uniqueDeclaration($resolved, $namespace.'\\'.$name);
    }
}
