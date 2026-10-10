# Installation

Install [ichinya/laramago from Packagist](https://packagist.org/packages/ichinya/laramago)
in the Laravel application's root directory. The package requires PHP `^8.2`
and Composer 2. Packagist is Composer's default repository, so no VCS repository
configuration is needed.

```sh
composer require --dev ichinya/laramago
vendor/bin/mago lint
vendor/bin/mago analyze
```

Allow the `ichinya/laramago` Composer plugin when prompted. The plugin creates
`mago.dist.json` if the application has no Mago configuration. Commit the
application's `composer.json`, `composer.lock`, and generated configuration.
Existing Mago configurations are preserved; follow the
[manual configuration instructions](../README.md#existing-configuration) to add
the preset and analyzer worker.

In PowerShell, use `vendor/bin/mago.bat` for the Mago commands.

## Updating

To update within the application's existing version constraint:

```sh
composer update ichinya/laramago --with-dependencies
```

If an older exact version or development branch is pinned, change the constraint
to the published `0.1` release series:

```sh
composer require --dev "ichinya/laramago:^0.1.1"
```

## Migrating from the earlier VCS installation

If you used the README's earlier named `repositories.laramago` entry, remove it
before selecting the published release:

```sh
composer config --unset repositories.laramago
composer require --dev "ichinya/laramago:^0.1.1"
```

If the repository was added under another name or as an array entry, remove the
matching `ichinya/laramago` VCS entry from `composer.json` instead. Remove a local
path override as well when switching back to published releases. Commit the
updated manifest and lock file.

## CI installation

Store the plugin permission in the application's manifest before running
Composer without interaction:

```sh
composer config allow-plugins.ichinya/laramago true
```

Commit that setting and the lock file. In CI, install the locked development
dependencies and run the desired checks:

```sh
composer install --no-interaction --prefer-dist
vendor/bin/mago lint
vendor/bin/mago analyze
```

The package is a development dependency, so an install with `--no-dev` omits it.

## Local development

Use a path repository only when testing a local checkout. See
[local development installation](../README.md#local-development-installation)
for the manifest example and
[local contract testing](native-framework-contracts.md#testing-local-package-changes)
for isolated consumer and mirrored-copy instructions.

Composer documents [repository selection](https://getcomposer.org/doc/05-repositories.md),
[`require` and `config` commands](https://getcomposer.org/doc/03-cli.md), and
[plugin permissions](https://getcomposer.org/doc/06-config.md#allow-plugins).
