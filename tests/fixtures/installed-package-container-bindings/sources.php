<?php
declare(strict_types=1);
return array (
  'bootstrap/app.php' => '<?php
throw new RuntimeException(\'Application bootstrap must never execute.\');
',
  'bootstrap/bindings.php' => '<?php
',
  'cases.php' => '<?php
function declaredPackageBinding(): string { return app(\\Laravel\\Fortify\\Contracts\\TwoFactorAuthenticationProvider::class)->generateSecretKey(24); }
function declaredPackageBindingResolve(): string { return resolve(\\Laravel\\Fortify\\Contracts\\TwoFactorAuthenticationProvider::class)->generateSecretKey(24); }
function argumentStillChecked(): string { return app(\\Laravel\\Fortify\\Contracts\\TwoFactorAuthenticationProvider::class)->generateSecretKey(\'bad\'); }
function tooManyStillChecked(): string { return app(\\Laravel\\Fortify\\Contracts\\TwoFactorAuthenticationProvider::class)->generateSecretKey(24, 42); }
function returnStillChecked(): int { return app(\\Laravel\\Fortify\\Contracts\\TwoFactorAuthenticationProvider::class)->generateSecretKey(24); }
function unknownPackageService(): string { return app(\\FixtureContracts\\UnregisteredService::class)->createToken(); }
function fakerLiteralTrue(\\Faker\\Generator $generator): string { return $generator->words(2, true).\' label\'; }
function fakerLiteralFalse(\\Faker\\Generator $generator): string { return $generator->words(2, false).\' label\'; }
function fakerBoolean(\\Faker\\Generator $generator, bool $asText): string { return $generator->words(2, $asText).\' label\'; }
function fakerCustomGenerator(\\FixtureContracts\\CustomGenerator $generator): string { return $generator->words(2, true).\' label\'; }
function fakerCustomProvider(\\Faker\\Generator $generator): string { $generator->addProvider(new \\FixtureContracts\\CustomWordsProvider()); return $generator->words(2, true).\' label\'; }
function definitelyArray(): string { return [\'entry\'].\' label\'; }
function definitelyObject(): string { return (new \\FixtureContracts\\Unprintable()).\' label\'; }
function mixedOperand(mixed $value): string { return $value.\' label\'; }',
  'composer.json' => '{
    "name": "example\\/package-contract-fixture",
    "extra": {
        "laramago": {
            "binding-files": []
        }
    }
}',
  'custom.php' => '<?php
namespace FixtureContracts;
interface UnregisteredService { public function createToken(): string; }
class ReplacementProvider implements \\Laravel\\Fortify\\Contracts\\TwoFactorAuthenticationProvider {
    public function generateSecretKey(): string { return \'replacement\'; }
    public function qrCodeUrl($companyName, $companyEmail, $secret): string { return \'\'; }
    public function verify($secret, $code): bool { return false; }
}
class WrongProvider {}
class CustomGenerator extends \\Faker\\Generator {
    public function words($count = 3, $asText = false): array { return [\'custom\']; }
}
class CustomWordsProvider {
    public function words($count = 3, $asText = false): array { return [\'custom\']; }
}
class Unprintable {}
function expectString(string $value): void {}
function expectInt(int $value): void {}',
  'database/migrations/trap.php' => '<?php
throw new RuntimeException(\'Migration execution is forbidden.\');
',
  'vendor/composer/installed.json' => '{
    "packages": [
        {
            "name": "laravel\\/fortify",
            "extra": {
                "laravel": {
                    "providers": [
                        "Laravel\\\\Fortify\\\\FortifyServiceProvider"
                    ]
                }
            }
        }
    ]
}',
  'vendor/fakerphp/faker/src/Faker/Generator.php' => '<?php
namespace Faker;
/** @method array|string words($nb = 3, $asText = false) */
class Generator {
/**
 * @param string $method
 * @param array  $attributes
 */
public function __call($method, $attributes)
{
    return $this->format($method, $attributes);
}
public function format($format, $arguments = [])
{
    return call_user_func_array($this->getFormatter($format), $arguments);
}
/**
 * @param string $format
 *
 * @return callable
 */
public function getFormatter($format)
{
    if (isset($this->formatters[$format])) {
        return $this->formatters[$format];
    }
    if (method_exists($this, $format)) {
        $this->formatters[$format] = [$this, $format];
        return $this->formatters[$format];
    }
    // "Faker\\Core\\Barcode->ean13"
    if (preg_match(\'|^([a-zA-Z0-9\\\\\\\\]+)->([a-zA-Z0-9]+)$|\', $format, $matches)) {
        $this->formatters[$format] = [$this->ext($matches[1]), $matches[2]];
        return $this->formatters[$format];
    }
    foreach ($this->providers as $provider) {
        if (method_exists($provider, $format)) {
            $this->formatters[$format] = [$provider, $format];
            return $this->formatters[$format];
        }
    }
    throw new \\InvalidArgumentException(sprintf(\'Unknown format "%s"\', $format));
}
public function addProvider($provider)
{
    array_unshift($this->providers, $provider);
    $this->formatters = [];
}
}
',
  'vendor/laravel/fortify/composer.json' => '{
    "name": "laravel\\/fortify",
    "extra": {
        "laravel": {
            "providers": [
                "Laravel\\\\Fortify\\\\FortifyServiceProvider"
            ]
        }
    }
}',
  'vendor/laravel/fortify/src/Contracts/TwoFactorAuthenticationProvider.php' => '<?php

namespace Laravel\\Fortify\\Contracts;

interface TwoFactorAuthenticationProvider
{
    /**
     * Generate a new secret key.
     *
     * @return string
     */
    public function generateSecretKey();

    /**
     * Get the two factor authentication QR code URL.
     *
     * @param  string  $companyName
     * @param  string  $companyEmail
     * @param  string  $secret
     * @return string
     */
    public function qrCodeUrl($companyName, $companyEmail, $secret);

    /**
     * Verify the given token.
     *
     * @param  string  $secret
     * @param  string  $code
     * @return bool
     */
    public function verify($secret, $code);
}
',
  'vendor/laravel/fortify/src/FortifyServiceProvider.php' => '<?php
namespace Laravel\\Fortify;
class FortifyServiceProvider extends \\Illuminate\\Support\\ServiceProvider {
/**
 * Register any application services.
 *
 * @return void
 */
public function register()
{
    $this->mergeConfigFrom(__DIR__ . \'/../config/fortify.php\', \'fortify\');
    $this->configurePasskeys();
    $this->registerResponseBindings();
    $this->app->singleton(\\Laravel\\Fortify\\Contracts\\TwoFactorAuthenticationProvider::class, function ($app) {
        return new \\Laravel\\Fortify\\TwoFactorAuthenticationProvider($app->make(\\PragmaRX\\Google2FA\\Google2FA::class), $app->make(\\Illuminate\\Contracts\\Cache\\Repository::class));
    });
    $this->app->scoped(\\Laravel\\Fortify\\Contracts\\RedirectsIfTwoFactorAuthenticatable::class, function ($app) {
        return $app->make(\\Laravel\\Fortify\\Actions\\RedirectIfTwoFactorAuthenticatable::class);
    });
    $this->app->bind(\\Illuminate\\Contracts\\Auth\\StatefulGuard::class, function () {
        return \\Illuminate\\Support\\Facades\\Auth::guard(config(\'fortify.guard\', null));
    });
}
}
',
  'vendor/laravel/fortify/src/TwoFactorAuthenticationProvider.php' => '<?php
namespace Laravel\\Fortify;
class TwoFactorAuthenticationProvider implements \\Laravel\\Fortify\\Contracts\\TwoFactorAuthenticationProvider {
/**
 * Generate a new secret key.
 *
 * @param  int  $secretLength
 * @return string
 */
public function generateSecretKey(int $secretLength = 16)
{
    return $this->engine->generateSecretKey($secretLength);
}
/**
 * Get the two factor authentication QR code URL.
 *
 * @param  string  $companyName
 * @param  string  $companyEmail
 * @param  string  $secret
 * @return string
 */
public function qrCodeUrl($companyName, $companyEmail, $secret)
{
    return $this->engine->getQRCodeUrl($companyName, $companyEmail, $secret);
}
/**
 * Verify the given code.
 *
 * @param  string  $secret
 * @param  string  $code
 * @return bool
 */
public function verify($secret, $code)
{
    if (is_int($customWindow = config(\'fortify-options.two-factor-authentication.window\'))) {
        $this->engine->setWindow($customWindow);
    }
    $timestamp = $this->engine->verifyKeyNewer($secret, $code, optional($this->cache)->get($key = \'fortify.2fa_codes.\' . md5($code)));
    if ($timestamp !== false) {
        if ($timestamp === true) {
            $timestamp = $this->engine->getTimestamp();
        }
        optional($this->cache)->put($key, $timestamp, ($this->engine->getWindow() ?: 1) * 60);
        return true;
    }
    return false;
}
}
',
  'vendor/laravel/framework/src/Illuminate/Foundation/Application.php' => '<?php
namespace Illuminate\\Foundation;
class Application {
/**
 * Register all of the configured providers.
 *
 * @return void
 */
public function registerConfiguredProviders()
{
    $providers = (new \\Illuminate\\Support\\Collection($this->make(\'config\')->get(\'app.providers\')))->partition(fn($provider) => str_starts_with($provider, \'Illuminate\\\\\'));
    $providers->splice(1, 0, [$this->make(\\Illuminate\\Foundation\\PackageManifest::class)->providers()]);
    (new \\Illuminate\\Foundation\\ProviderRepository($this, new \\Illuminate\\Filesystem\\Filesystem(), $this->getCachedServicesPath()))->load($providers->collapse()->toArray());
    $this->fireAppCallbacks($this->registeredCallbacks);
}
}
',
  'vendor/laravel/framework/src/Illuminate/Foundation/helpers.php' => '<?php

/**
 * Get the available container instance.
 *
 * @template TClass of object
 *
 * @param  string|class-string<TClass>|null  $abstract
 * @return ($abstract is class-string<TClass> ? TClass : ($abstract is null ? \\Illuminate\\Foundation\\Application : mixed))
 */
function app($abstract = \\null, array $parameters = [])
{
    if (\\is_null($abstract)) {
        return \\Illuminate\\Container\\Container::getInstance();
    }
    return \\Illuminate\\Container\\Container::getInstance()->make($abstract, $parameters);
}
/**
 * Resolve a service from the container.
 *
 * @template TClass of object
 *
 * @param  string|class-string<TClass>  $name
 * @return ($name is class-string<TClass> ? TClass : mixed)
 */
function resolve($name, array $parameters = [])
{
    return \\app($name, $parameters);
}',
  'vendor/laravel/framework/src/Illuminate/Foundation/PackageManifest.php' => '<?php
namespace Illuminate\\Foundation;
class PackageManifest {
/**
 * Get all of the service provider class names for all packages.
 *
 * @return array
 */
public function providers()
{
    return $this->config(\'providers\');
}
/**
 * Get all of the values for all packages for the given configuration name.
 *
 * @param  string  $key
 * @return array
 */
public function config($key)
{
    return (new \\Illuminate\\Support\\Collection($this->getManifest()))->flatMap(fn($configuration) => (array) ($configuration[$key] ?? []))->filter()->all();
}
/**
 * Build the manifest and write it to disk.
 *
 * @return void
 */
public function build()
{
    $packages = [];
    if ($this->files->exists($path = $this->vendorPath . \'/composer/installed.json\')) {
        $installed = json_decode($this->files->get($path), true);
        $packages = $installed[\'packages\'] ?? $installed;
    }
    $ignoreAll = in_array(\'*\', $ignore = $this->packagesToIgnore());
    $this->write((new \\Illuminate\\Support\\Collection($packages))->mapWithKeys(function ($package) {
        return [$this->format($package[\'name\']) => $package[\'extra\'][\'laravel\'] ?? []];
    })->each(function ($configuration) use (&$ignore) {
        $ignore = array_merge($ignore, $configuration[\'dont-discover\'] ?? []);
    })->reject(function ($configuration, $package) use ($ignore, $ignoreAll) {
        return $ignoreAll || in_array($package, $ignore) || empty($configuration);
    })->all());
}
/**
 * Get all of the package names that should be ignored.
 *
 * @return array
 */
protected function packagesToIgnore()
{
    if (!is_file($this->basePath . \'/composer.json\')) {
        return [];
    }
    return json_decode(file_get_contents($this->basePath . \'/composer.json\'), true)[\'extra\'][\'laravel\'][\'dont-discover\'] ?? [];
}
}
',
  'vendor/laravel/framework/src/Illuminate/Support/ServiceProvider.php' => '<?php
namespace Illuminate\\Support; class ServiceProvider { protected $app; }
',
);
