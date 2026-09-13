# ichinya/laramago

A Composer package with a Laravel preset for the native Mago CLI.

```sh
composer require --dev ichinya/laramago
vendor/bin/mago lint
```

During installation, Composer asks for permission to run the `ichinya/laramago`
plugin. Once allowed, the plugin creates `mago.dist.json` in the application root.
The `carthage-software/mago` dependency provides `vendor/bin/mago`; this package
uses that executable directly, without a wrapper or Laravel service provider.

The package is currently local. The command above is intended for use after
publication to a Composer repository. See the local installation instructions below.

## How it works

The plugin runs on Composer's `post-install-cmd` and `post-update-cmd` events.
If the application has no Mago configuration, it creates a file such as:

```json
{
    "extends": "vendor/ichinya/laramago/presets/laravel.toml",
    "source": {
        "paths": ["app", "bootstrap", "config", "database", "routes", "tests"],
        "includes": ["vendor"]
    }
}
```

Only existing standard directories are added to `paths`. If none exist, the
plugin uses `.`. Add custom directories such as `src` or `Modules` yourself.
Dependency paths respect Composer's `vendor-dir` setting.

Mago loads `mago.dist.json` automatically and inherits the package settings through
`extends`. Updating the package updates the shared preset, while application
settings stay in the root configuration. Commit the generated configuration.
Set the application's PHP version with the root `php-version` option; the preset
does not pin it.

## Settings

- Laravel linter integration, optional `strict_types`, and support for `?:`.
- More permissive thresholds for method counts, parameter counts, and complexity.
- Formatting based on Mago's built-in Pint preset.
- Exclusions for caches, Blade templates, node_modules, and IDE helper files.
- Vendor dependencies available for type information, outside lint/format targets.
- Type errors and missing methods remain visible in the analyzer. Unused definition
  checks are disabled because Laravel often invokes definitions indirectly.

```sh
vendor/bin/mago config --show linter
vendor/bin/mago list-files
vendor/bin/mago lint
vendor/bin/mago format --check
vendor/bin/mago analyze
```

Use `vendor/bin/mago.bat` in PowerShell. Laravel linter integration does not provide
complete Eloquent support in the analyzer. The package does not generate overlays
or replace Larastan. `examples/compatibility.toml` is an optional fragment with
targeted suppressions for the `[analyzer]` section of your TOML configuration.

## Existing configuration

The plugin preserves any `mago.{toml,yaml,yml,json}` or
`mago.dist.{toml,yaml,yml,json}` file and prints the preset path.
For an existing TOML configuration, add this **at the top level, before any sections**:

```toml
extends = "vendor/ichinya/laramago/presets/laravel.toml"
```

For JSON, add an `extends` property; for YAML, add an `extends` key.
If the configuration already inherits other files, add the preset to its parent list.
For a custom `vendor-dir`, use the path printed by the plugin.
Keep your source paths and includes. Application settings override scalar values
from the preset; Mago concatenates arrays, so inherited exclusions remain active.

Use this manual setup when automatic configuration creation is skipped, such as
when Composer runs with `--no-plugins`.

Removing the package leaves the application configuration in place. Remove its
`extends` reference to the package or replace it with your own settings before
running Mago again.

## Local installation before publication

Add a path repository to the test Laravel application's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "C:/projects/laramago",
            "options": {"symlink": false}
        }
    ]
}
```

Then run:

```sh
composer config allow-plugins.ichinya/laramago true
composer require --dev ichinya/laramago:@dev
vendor/bin/mago lint
```

Use `symlink: false` to test a regular package copy inside vendor.
For CI, store plugin permission in the application's `config.allow-plugins`.
The package has not been published or registered on Packagist.

## Development

PHP 8.2+, Composer 2, Mago ^1.48.1.

```sh
composer validate --strict
php tests/run.php
```

Installer tests cover configuration creation, custom vendor directories, repeated
installation, preservation of all eight user configuration variants, and fallback
source paths.

A real Composer path repository installation was also checked in an isolated
Windows project: automatic configuration creation, native `vendor/bin/mago.bat lint`,
Laravel integration, an application rule override, configuration preservation on
repeated `composer install`, and a nonzero exit status for invalid PHP.
That check used Mago 1.48.1; a full Laravel CI run was not performed.
