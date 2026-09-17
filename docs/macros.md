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
analyzed calls, that files and statements run in the listed source order, and
that the catalog covers all mutations of those registries. Laramago does not
execute the files, infer provider activation, or independently prove that runtime
ordering. Without this option, no macro provider is registered. An explicitly
malformed `macro-files` value fails closed.

Catalog paths are relative PHP paths under `app/` or `bootstrap/`. The current
path syntax accepts ASCII letters, digits, underscores, hyphens, dots and slashes;
parent traversal is rejected. Namespaces and imported class aliases are resolved.

Top-level registrations with literal names are accepted. They may be direct or
inside an `if`/`elseif`/`else` whose condition is statically decidable from
`true`, `false`, boolean negation and conjunction/disjunction, or a literal
`ClassName::hasMacro('name')` query over earlier catalog registrations. Every
operand of a boolean expression must be decidable. Ordered queries still require
the queried class to use the original Macroable registry methods; otherwise the
contract defers. Unknown conditions keep every affected registration unknown.

A contract can come from a non-static closure or arrow function, a literal
`[Handler::class, 'publicStaticMethod']` array, or a directly constructed
invokable object such as `new Handler(...)`. Array targets must resolve to public
static methods; invokable objects must resolve to public instance `__invoke`
methods. Callable methods need complete, non-generic metadata. Closure parameters
and return values need supported native types. Named, default and variadic
parameters retain SDK signature semantics. Reference parameters and return
values defer because magic dispatch does not preserve caller reference semantics.
Macro names remain case-sensitive.

```php
use Illuminate\Support\Str;

Str::macro('repeatLabel', fn (string $label, int $count = 1): string => str_repeat($label, $count));

Str::macro('normalizeLabel', [LabelNormalizer::class, 'normalize']);

Str::macro('decorateLabel', new LabelDecorator('prefix:'));

if (! Str::hasMacro('slugLabel')) {
    Str::macro('slugLabel', fn (string $label): string => Str::slug($label));
}
```

Native methods and PHPDoc declarations take priority. Receivers must use the
original Macroable registry and dispatch methods. Inherited receiver registries,
catalog subclass mutations, unknown conditional or duplicate registrations,
function and provider bodies, untyped callbacks, first-class callable closures,
arbitrary callable strings or arrays, inaccessible or mismatched callable
methods, contextual types and unresolved generic contracts defer. Dynamic names,
mixins and flushes invalidate that class; dynamic receivers/method names and
unreadable catalog files invalidate the catalog.

External runtime registrations cannot be discovered reliably through the current
SDK. The explicit completeness guarantee is therefore required. Only accepted
class/method targets are registered; no global wildcard dispatch is added.

`php tests/macros.php`, `php tests/macro-callables.php` and
`php tests/macro-contracts.php` check actual Mago diagnostics and include
execution traps. Catalog caches live for one worker run; rerun analysis after
changing declarations.
