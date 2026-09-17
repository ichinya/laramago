# Explicit macro catalogs

Opt in through the application's `composer.json`:

```json
{
  "extra": {
    "laramago": {
      "macro-files": ["bootstrap/macros.php"]
    }
  }
}
```

Listing a file declares that its accepted registrations are active before the
analyzed calls and that the catalog covers all mutations of those registries.
Laramago does not execute the files, infer provider activation, or prove runtime
ordering. Without this option, no macro provider is registered.

Catalog paths are relative PHP paths under `app/` or `bootstrap/`. The current
path syntax accepts ASCII letters, digits, underscores, hyphens, dots and slashes;
parent traversal is rejected. Namespaces and imported class aliases are resolved.

Only unconditional top-level registrations with literal names and non-static
closures or arrow functions are accepted. Parameters and return values need
supported native types. Named, default, variadic and reference parameters retain
SDK signature semantics. Macro names remain case-sensitive.

```php
use Illuminate\Support\Str;

Str::macro('repeatLabel', fn (string $label, int $count = 1): string => str_repeat($label, $count));
```

Native methods and PHPDoc declarations take priority. Receivers must use the
original Macroable registry and dispatch methods. Inherited receiver registries,
catalog subclass mutations, conditional or duplicate registrations, function and
provider bodies, untyped callbacks, static closures, contextual types and
intersections defer. Dynamic names, mixins and flushes invalidate that class;
dynamic receivers/method names and unreadable catalog files invalidate the catalog.

External runtime registrations cannot be discovered reliably through the current
SDK. The explicit completeness guarantee is therefore required. Only accepted
class/method targets are registered; no global wildcard dispatch is added.

`php tests/macros.php` checks actual Mago diagnostics and includes execution traps.
Catalog caches live for one worker run; rerun analysis after changing declarations.
