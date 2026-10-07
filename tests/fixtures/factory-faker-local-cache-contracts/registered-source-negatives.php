<?php
declare(strict_types=1);

// Source templates only. Each must replace a real Composer-declared file.
return [
    'generator-direct-binding' => '<?php namespace Example; function registration(\Illuminate\Container\Container $app): void {$app->bind(\Faker\Generator::class, fn () => new \Faker\Generator());}',
    'generator-alias-first-key' => '<?php namespace Example; function registration(\Illuminate\Container\Container $app): void {$app->alias(\Faker\Generator::class, \stdClass::class);}',
    'generator-alias-second-key' => '<?php namespace Example; function registration(\Illuminate\Container\Container $app): void {$app->alias(\stdClass::class, \Faker\Generator::class);}',
    'unknown-key-alias-second-generator' => '<?php namespace Example; function registration(\Illuminate\Container\Container $app, string $unknown): void {$app->alias($unknown, \Faker\Generator::class);}',
    'dynamic-container-key' => '<?php namespace Example; function registration(\Illuminate\Container\Container $app, string $unknown): void {$app->instance($unknown, new \stdClass());}',
    'dynamic-container-method' => '<?php namespace Example; function registration(string $method): void {app()->{$method}(\stdClass::class);}',
    'unknown-extension-receiver' => '<?php namespace Example; function registration(object $receiver): void {$receiver->extend("example", static fn () => null);}',
    'nested-generator-provider-registration' => '<?php namespace Example; function registration(\Faker\Generator $generator): void {\Illuminate\Support\Facades\DB::extend("example", $generator->addProvider(new \stdClass()));}',
    'nested-generator-binding' => '<?php namespace Example; function registration(\Illuminate\Container\Container $app): void {\Illuminate\Support\Facades\DB::extend("example", $app->bind(\Faker\Generator::class, static fn () => new \Faker\Generator()));}',
    'database-root-instance' => '<?php namespace Example; function registration(\Illuminate\Container\Container $app): void {$app->instance("db", new \stdClass());}',
    'cache-root-instance' => '<?php namespace Example; function registration(\Illuminate\Container\Container $app): void {$app->instance("cache", new \stdClass());}',
    'application-root-binding' => '<?php namespace Example; function registration(\Illuminate\Container\Container $app): void {$app->bind(\Illuminate\Contracts\Foundation\Application::class, static fn () => new \stdClass());}',
    'selected-owning-class-alias' => '<?php namespace Example; class_alias(\stdClass::class, \Illuminate\Support\ServiceProvider::class);',
    'selected-runtime-receiver-alias' => '<?php namespace Example; class_alias(\stdClass::class, \Carbon\CarbonImmutable::class);',
    'dynamic-alias-target' => '<?php namespace Example; function registration(string $target): void {class_alias(\stdClass::class, $target);}',
    'registered-provider-alias-before' => '<?php namespace Example; class_alias(\stdClass::class, RegisteredProvider::class); final class RegisteredProvider extends \Illuminate\Support\ServiceProvider {public function register(): void {$this->app->singleton(function (): \stdClass {return new \stdClass;});}}',
    'registered-provider-alias-after' => '<?php namespace Example; final class RegisteredProvider extends \Illuminate\Support\ServiceProvider {public function register(): void {$this->app->singleton(function (): \stdClass {return new \stdClass;});}} class_alias(\stdClass::class, RegisteredProvider::class);',
    'closure-return-generator-key' => '<?php namespace Example; final class RegisteredProvider extends \Illuminate\Support\ServiceProvider {public function register(): void {$this->app->singleton(function (): \Faker\Generator {return new \Faker\Generator;});}}',
    'closure-return-arg-doc-priority' => '<?php namespace Example; final class Handler {} final class RegisteredProvider extends \Illuminate\Support\ServiceProvider {public function register(): void {$this->app->singleton(/** @return \Faker\Generator */ function (): Handler {return new Handler;});}}',
    'closure-return-nullable' => '<?php namespace Example; final class Handler {} final class RegisteredProvider extends \Illuminate\Support\ServiceProvider {public function register(): void {$this->app->singleton(function (): ?Handler {return null;});}}',
    'closure-return-union' => '<?php namespace Example; final class Handler {} final class RegisteredProvider extends \Illuminate\Support\ServiceProvider {public function register(): void {$this->app->singleton(function (): Handler|\stdClass {return new Handler;});}}',
    'provider-own-app-field' => '<?php namespace Example; final class Handler {} final class RegisteredProvider extends \Illuminate\Support\ServiceProvider {protected $app; public function register(): void {$this->app->singleton(function (): Handler {return new Handler;});}}',
    'provider-own-constructor' => '<?php namespace Example; final class Handler {} final class RegisteredProvider extends \Illuminate\Support\ServiceProvider {public function __construct() {} public function register(): void {$this->app->singleton(function (): Handler {return new Handler;});}}',
    'provider-own-trait' => '<?php namespace Example; trait ExtraRegistration {} final class Handler {} final class RegisteredProvider extends \Illuminate\Support\ServiceProvider {use ExtraRegistration; public function register(): void {$this->app->singleton(function (): Handler {return new Handler;});}}',
    'input-prefix-use' => '<?php namespace Example; use Symfony\Component\Console\Input\{ArgvInput,InputDefinition}; $registration=function (): void {$input=new ArgvInput(); $other=$input; try {$input->bind(new InputDefinition());} catch (\RuntimeException $exception) {}};',
    'input-second-try-statement' => '<?php namespace Example; use Symfony\Component\Console\Input\{ArgvInput,InputDefinition}; $registration=function (): void {$input=new ArgvInput(); try {$first=true; $input->bind(new InputDefinition());} catch (\RuntimeException $exception) {}};',
];
