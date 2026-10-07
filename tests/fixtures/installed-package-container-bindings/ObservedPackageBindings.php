<?php

declare(strict_types=1);

namespace Example\Fortify\StaticAnalysis;
use Ichinya\Laramago\Analyzer\StaticAnalysis\{PhpSource,ModelReflection};

use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\Metadata\ClassLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use PhpParser\Node;

/**
 * Reads a recognized installed package's default binding declaration offline.
 *
 * This is a library declaration mapping, like the core alias table, rather than
 * an assertion of the application's effective runtime container. The caller must
 * give its configured application binding catalog priority, including unknown
 * catalog states. Custom runtime registration remains outside this boundary.
 */
final class InstalledPackageContainerBindings
{
    public array $stages = [];
    private const ABSTRACT = 'Laravel\\Fortify\\Contracts\\TwoFactorAuthenticationProvider';
    private const CONCRETE = 'Laravel\\Fortify\\TwoFactorAuthenticationProvider';
    private const PROVIDER = 'Laravel\\Fortify\\FortifyServiceProvider';

    /** Recognized Laravel 13.31 / Fortify 1.39 method syntax, excluding comments. */
    private const METHODS = [
        'Illuminate\\Foundation\\Application' => [
            'vendor/laravel/framework/src/Illuminate/Foundation/Application.php',
            ['registerConfiguredProviders' => 'e36536a66b8aa10b305e1b81860f3b8f117bea8836955d0ac5f4a95abbd3126a'],
        ],
        'Illuminate\\Foundation\\PackageManifest' => [
            'vendor/laravel/framework/src/Illuminate/Foundation/PackageManifest.php',
            [
                'providers' => 'f036c09103de7b4ca7b37db83363497703119fb48da15e2ffbbb615261e16ab0',
                'config' => '730ae7a273bd2dd8c73ab1609d360a0231cc0a3477f160afddb07e2ae1a302dd',
                'build' => '4a8cf6a961c7e314255692653485e6193bd8972ab730e16aca36e70bcdb8b7a9',
                'packagesToIgnore' => '007997533f8ee3a51d276bad97acb356ce53ce7ade7364d0a79f6698d57f880a',
            ],
        ],
        self::PROVIDER => [
            'vendor/laravel/fortify/src/FortifyServiceProvider.php',
            ['register' => 'a18c84bef93336e4927554cb5aae1b0164c8cc72acb6f79671823a27cf365a4a'],
        ],
    ];

    private readonly PhpSource $source;

    public function __construct(string $root)
    {
        $this->source = new PhpSource($root);
    }

    public function concrete(Codebase $codebase, string $abstract): ?string
    {
        $discovered = $this->discovered();
        $this->stages = ['stage' => 'literal-package-discovery', 'abstractRecognized' => $abstract === self::ABSTRACT, 'discovery' => $discovered, 'methods' => []];
        if ($abstract !== self::ABSTRACT || !$discovered) {
            return null;
        }
        $reflection = new ModelReflection($codebase, $this->source);
        foreach (self::METHODS as $class => [$path, $methods]) {
            $metadata = $codebase->getClass($class);
            $this->stages['stage'] = 'current-native-library-class';
            $this->stages['classAttempt'] = $metadata === null ? null : ['name' => $metadata->name, 'file' => $metadata->location->file,
                'unresolvedHierarchyDependencies' => $metadata->unresolvedHierarchyDependencies, 'physicalExpectedFile' => $this->physicalFile($metadata->location->file,$path)];
            if (
                $metadata === null
                || $metadata->hasIncompleteHierarchy()
                || ! $this->physicalFile($metadata->location->file, $path)
            ) {
                return null;
            }
            foreach ($methods as $name => $fingerprint) {
                $method = $codebase->getDeclaringMethod($class, $name);
                $node = $method === null ? null : $reflection->methodNode($method);
                $this->stages['stage'] = 'current-native-library-method-profile';
                $this->stages['methods'][$class.'::'.$name] = ['metadataAvailable' => $method !== null, 'owner' => $method?->identifier->class,
                    'file' => $method?->location->file, 'physicalExpectedFile' => $method !== null && $this->physicalFile($method->location->file,$path),
                    'nodeAvailable' => $node !== null, 'fingerprint' => $node === null ? null : AstContract::fingerprint($node), 'expectedFingerprint' => $fingerprint];
                if (
                    $method === null
                    || $method->identifier->class !== $class
                    || ! $this->physicalFile($method->location->file, $path)
                    || $node === null
                    || AstContract::fingerprint($node) !== $fingerprint
                ) {
                    return null;
                }
            }
        }
        $provider = $codebase->getClass(self::PROVIDER);
        $serviceProvider = $codebase->getClass('Illuminate\\Support\\ServiceProvider');
        $contract = $codebase->getInterface(self::ABSTRACT);
        $concrete = $codebase->getClass(self::CONCRETE);
        $this->stages['stage'] = 'current-native-package-receiver-contract';
        $this->stages['packageClasses'] = ['provider' => $provider === null ? null : ['name' => $provider->name,'parent' => $provider->directParentClass,
            'traits' => $provider->usedTraits,'properties' => $provider->properties,'propertyOverrides' => $this->providerPropertyOverrides($provider)],
            'serviceProvider' => $serviceProvider === null ? null : ['name' => $serviceProvider->name,'file' => $serviceProvider->location->file,'unresolvedHierarchyDependencies' => $serviceProvider->unresolvedHierarchyDependencies],
            'contract' => $contract === null ? null : ['name' => $contract->name,'file' => $contract->location->file,'unresolvedHierarchyDependencies' => $contract->unresolvedHierarchyDependencies],
            'concrete' => $concrete === null ? null : ['name' => $concrete->name,'file' => $concrete->location->file,'flags' => $concrete->flags->bits,'templates' => count($concrete->templates),
                'interfaces' => $concrete->parentInterfaces,'unresolvedHierarchyDependencies' => $concrete->unresolvedHierarchyDependencies]];
        if (
            $provider === null
            || strcasecmp($provider->directParentClass ?? '', 'Illuminate\\Support\\ServiceProvider') !== 0
            || $provider->usedTraits !== []
            || $serviceProvider === null
            || $serviceProvider->hasIncompleteHierarchy()
            || ! $this->physicalFile($serviceProvider->location->file, 'vendor/laravel/framework/src/Illuminate/Support/ServiceProvider.php')
            || $contract === null
            || $contract->hasIncompleteHierarchy()
            || ! $this->physicalFile($contract->location->file, 'vendor/laravel/fortify/src/Contracts/TwoFactorAuthenticationProvider.php')
            || $concrete === null
            || $concrete->flags->contains(MetadataFlags::ABSTRACT)
            || $concrete->hasIncompleteHierarchy()
            || $concrete->templates !== []
            || ! $this->physicalFile($concrete->location->file, 'vendor/laravel/fortify/src/TwoFactorAuthenticationProvider.php')
            || ! in_array(strtolower(self::ABSTRACT), array_map(strtolower(...), $concrete->parentInterfaces), true)
            || $this->providerPropertyOverrides($provider)
        ) {
            return null;
        }
        $register = $codebase->getDeclaringMethod(self::PROVIDER, 'register');
        $statements = $register === null ? [] : ($reflection->methodNode($register)?->stmts ?? []);
        $found = [];
        $this->stages['stage'] = 'literal-singleton-factory-declaration';
        foreach ($statements as $statement) {
            $call = $statement instanceof Node\Stmt\Expression ? $statement->expr : null;
            if (
                ! $call instanceof Node\Expr\MethodCall
                || ! $call->name instanceof Node\Identifier
                || $call->name->toString() !== 'singleton'
                || ! $call->var instanceof Node\Expr\PropertyFetch
                || ! $call->var->var instanceof Node\Expr\Variable
                || $call->var->var->name !== 'this'
                || ! $call->var->name instanceof Node\Identifier
                || $call->var->name->toString() !== 'app'
                || count($call->args) !== 2
                || ! $call->args[0] instanceof Node\Arg
                || ! $call->args[1] instanceof Node\Arg
                || PhpSource::value($call->args[0]->value) !== self::ABSTRACT
            ) {
                continue;
            }
            $factory = $call->args[1]->value;
            $expression = $factory instanceof Node\Expr\Closure && count($factory->stmts) === 1 && $factory->stmts[0] instanceof Node\Stmt\Return_
                ? $factory->stmts[0]->expr
                : null;
            if (
                ! $expression instanceof Node\Expr\New_
                || ! $expression->class instanceof Node\Name\FullyQualified
                || $expression->class->toString() !== self::CONCRETE
            ) {
                return null;
            }
            $found[] = $expression->class->toString();
        }

        $this->stages['found'] = $found; $this->stages['stage'] = $found === [self::CONCRETE] ? 'admitted' : 'singleton-declaration-not-admitted';
        return $found === [self::CONCRETE] ? $concrete->name : null;
    }

    private function providerPropertyOverrides(ClassLikeMetadata $provider): bool
    {
        foreach ($provider->properties as $property) {
            if (in_array(strtolower(ltrim($property, '$')), ['bindings', 'singletons'], true)) {
                return true;
            }
        }

        return false;
    }

    private function physicalFile(?string $file, string $relative): bool
    {
        if ($file === null || str_starts_with($file, '@')) {
            return false;
        }
        $actual = realpath($this->source->path($file));
        $expected = realpath($this->source->path($relative));

        return $actual !== false && $expected !== false && $actual === $expected;
    }

    /** Standard installed.json discovery declarations; never requires a PHP cache. */
    private function discovered(): bool
    {
        $root = $this->json('composer.json');
        $installed = $this->json('vendor/composer/installed.json');
        $package = $this->json('vendor/laravel/fortify/composer.json');
        if ($root === null || $installed === null || $package === null || ($package['name'] ?? null) !== 'laravel/fortify') {
            return false;
        }
        $config = $root['config'] ?? [];
        if (! is_array($config) || (isset($config['vendor-dir']) && $config['vendor-dir'] !== 'vendor')) {
            return false;
        }
        $extra = $root['extra'] ?? [];
        $laravel = is_array($extra) ? ($extra['laravel'] ?? []) : null;
        $ignored = is_array($laravel) ? ($laravel['dont-discover'] ?? []) : null;
        if (! is_array($ignored) || ! array_is_list($ignored) || count(array_filter($ignored, is_string(...))) !== count($ignored)) {
            return false;
        }
        $packages = $installed['packages'] ?? $installed;
        if (! is_array($packages) || ! array_is_list($packages)) {
            return false;
        }
        $found = [];
        foreach ($packages as $entry) {
            if (! is_array($entry) || ! is_string($entry['name'] ?? null)) {
                return false;
            }
            $entryExtra = $entry['extra'] ?? [];
            $entryLaravel = is_array($entryExtra) ? ($entryExtra['laravel'] ?? []) : null;
            $entryIgnored = is_array($entryLaravel) ? ($entryLaravel['dont-discover'] ?? []) : null;
            if (! is_array($entryIgnored) || ! array_is_list($entryIgnored) || count(array_filter($entryIgnored, is_string(...))) !== count($entryIgnored)) {
                return false;
            }
            $ignored = [...$ignored, ...$entryIgnored];
            if ($entry['name'] === 'laravel/fortify') {
                $found[] = $entryLaravel['providers'] ?? null;
            }
        }
        $declared = $package['extra']['laravel']['providers'] ?? null;

        return ! in_array('*', $ignored, true)
            && ! in_array('laravel/fortify', $ignored, true)
            && $found === [[self::PROVIDER]]
            && $declared === [self::PROVIDER];
    }

    /** @return array<array-key, mixed>|null */
    private function json(string $relative): ?array
    {
        $bytes = @file_get_contents($this->source->path($relative));
        $value = $bytes === false ? null : json_decode($bytes, true);

        return is_array($value) ? $value : null;
    }
}
