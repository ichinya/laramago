<?php

declare(strict_types=1);

namespace Ichinya\Laramago\Analyzer\StaticAnalysis;

/**
 * Opt-in evaluation of the analyzed application's authentication configuration.
 *
 * Trust model: values come from the local environment of the machine running
 * the analysis — the same trade-off Larastan makes when it boots the whole
 * application. Only literal string config entries are kept, and the load is
 * limited to requiring `bootstrap/app.php` plus the LoadEnvironmentVariables,
 * LoadConfiguration and RegisterFacades bootstrappers, so no application
 * service provider is ever registered or booted: no database, no queues, no
 * sessions and no cache stores are touched. The only executed pieces are the
 * project's `config/*.php` files and `.env`, and every failure is swallowed
 * into a cached null result.
 */
final class EvaluatedRuntime
{
    private const ENVIRONMENT_VARIABLE = 'LARAMAGO_EVALUATE_RUNTIME';
    private const ARGV_FLAG = '--evaluate-runtime';
    private const BOOTSTRAPPERS = [
        'Illuminate\\Foundation\\Bootstrap\\LoadEnvironmentVariables',
        'Illuminate\\Foundation\\Bootstrap\\LoadConfiguration',
        'Illuminate\\Foundation\\Bootstrap\\RegisterFacades',
    ];

    /** @var array<string, ?array<string, mixed>> */
    private static array $cache = [];
    private static bool $loading = false;

    public static function enabled(): bool
    {
        if (in_array(self::ARGV_FLAG, $_SERVER['argv'] ?? [], true)) {
            return true;
        }
        $value = getenv(self::ENVIRONMENT_VARIABLE);

        return is_string($value) && $value !== ''
            && $value !== '0' && strtolower($value) !== 'false';
    }

    /**
     * The evaluated `auth` configuration of the analyzed application, or null
     * when the gate is off or the minimal load failed. The outcome — map or
     * null — is cached once per root path for the lifetime of the process.
     *
     * @return ?array{defaults: array<string, string>, guards: array<string, array<string, string>>, providers: array<string, array<string, string>>}
     */
    public static function auth(string $root): ?array
    {
        if (! self::enabled()) {
            return null;
        }
        $key = rtrim(str_replace('\\', '/', $root), '/');
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }
        if (self::$loading) {
            return null;
        }
        self::$loading = true;
        try {
            return self::$cache[$key] = self::load($root);
        } finally {
            self::$loading = false;
        }
    }

    private static function load(string $root): ?array
    {
        try {
            return self::loadAuth($root);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function loadAuth(string $root): ?array
    {
        $app = null;
        $config = null;
        try {
            $app = self::boot($root);
            if ($app === null) {
                return null;
            }
            $config = self::section($app, 'config');
            if ($config === null) {
                return null;
            }

            return self::extract(self::section($config, 'auth'));
        } finally {
            // Nothing but the small plain-array result may outlive this call.
            // RegisterFacades has pinned the application into facade statics,
            // so those references are released explicitly as well.
            self::discard($app);
            unset($app, $config);
        }
    }

    private static function discard(?object $app): void
    {
        if ($app === null) {
            return;
        }
        try {
            $facade = 'Illuminate\\Support\\Facades\\Facade';
            if (class_exists($facade, false)) {
                $facade::clearResolvedInstances();
                if (method_exists($facade, 'setFacadeApplication')) {
                    $facade::setFacadeApplication(null);
                }
            }
        } catch (\Throwable) {
            // Best effort only; failures never mask the extracted result.
        }
    }

    private static function boot(string $root): ?object
    {
        $file = $root.'/bootstrap/app.php';
        if (! is_file($file)) {
            return null;
        }
        $app = require $file;
        if (! is_object($app)) {
            return null;
        }
        foreach (self::BOOTSTRAPPERS as $bootstrapper) {
            if (! class_exists($bootstrapper, true)) {
                return null;
            }
            try {
                (new $bootstrapper)->bootstrap($app);
            } catch (\Throwable) {
                return null;
            }
        }

        return $app;
    }

    private static function section(object $container, string $key): mixed
    {
        try {
            if (method_exists($container, 'get')) {
                $resolved = $container->get($key);
                if ($resolved !== null) {
                    return $resolved;
                }
            }
        } catch (\Throwable) {
            // Fall through to the next resolution strategy.
        }
        try {
            if (method_exists($container, 'make')) {
                $resolved = $container->make($key);
                if ($resolved !== null) {
                    return $resolved;
                }
            }
        } catch (\Throwable) {
            // Fall through to the next resolution strategy.
        }
        try {
            if ($container instanceof \ArrayAccess) {
                return isset($container[$key]) ? $container[$key] : null;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private static function extract(mixed $auth): ?array
    {
        if (! is_array($auth)) {
            return null;
        }
        $defaults = [];
        if (is_array($auth['defaults'] ?? null) && self::plainString($auth['defaults']['guard'] ?? null) !== null) {
            $defaults['guard'] = $auth['defaults']['guard'];
        }
        $guards = [];
        if (is_array($auth['guards'] ?? null)) {
            foreach ($auth['guards'] as $name => $guard) {
                if (! is_string($name) || $name === '' || ! is_array($guard)) {
                    continue;
                }
                $entry = [];
                foreach (['provider', 'driver'] as $key) {
                    if (self::plainString($guard[$key] ?? null) !== null) {
                        $entry[$key] = $guard[$key];
                    }
                }
                if ($entry !== []) {
                    $guards[$name] = $entry;
                }
            }
        }
        $providers = [];
        if (is_array($auth['providers'] ?? null)) {
            foreach ($auth['providers'] as $name => $provider) {
                if (! is_string($name) || $name === '' || ! is_array($provider)) {
                    continue;
                }
                $entry = [];
                if (self::plainString($provider['driver'] ?? null) !== null) {
                    $entry['driver'] = $provider['driver'];
                }
                $model = self::plainString($provider['model'] ?? null);
                if ($model !== null) {
                    // The application's autoloader is live; a model that cannot
                    // be loaded is dropped but the rest of the map is kept.
                    try {
                        $exists = class_exists($model, true);
                    } catch (\Throwable) {
                        $exists = false;
                    }
                    if ($exists) {
                        $entry['model'] = $model;
                    }
                }
                if ($entry !== []) {
                    $providers[$name] = $entry;
                }
            }
        }

        return ['defaults' => $defaults, 'guards' => $guards, 'providers' => $providers];
    }

    private static function plainString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
