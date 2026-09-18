# Explicit active macro service providers

Applications can opt in to a narrow syntax-only provider source contract:

```json
{
  "extra": {
    "laramago": {
      "macro-service-providers": {
        "App\\Providers\\MacroServiceProvider": "app/Providers/MacroServiceProvider.php"
      }
    }
  }
}
```

Each map entry explicitly declares an active provider whose boot method has run
before analyzed calls. The existing macro catalog completeness guarantee applies:
all mutations of the affected registries must be covered. Entries run in map
order, after `macro-files` in their listed order. Source existence, autoloading,
package discovery and Laravel configuration do not establish activation.

Accepted source contains one direct, non-abstract subclass of
`Illuminate\Support\ServiceProvider`, with only a public instance `boot()` method
without parameters. Its body must consist entirely of direct `Class::macro(...)`
statements. Namespace aliases resolve statically. Existing macro callable, native
method priority, PHPDoc priority and registry safety rules remain applicable.
Source is parsed, never included or executed; Laravel is never bootstrapped.

Malformed/unreadable entries and unsupported source fail the whole catalog closed.
Inherited boot methods, traits, additional members, custom register/constructor
methods, conditional statements (including literal conditions), helper calls,
early returns, contextual receivers and executable file-level statements defer.
This deliberately narrow subset does not establish runtime lifecycle behavior.

Mixed-case macro names retain existing behavior: static calls preserve literal
names, but the current SDK/provider instance lookup can normalize the name and
therefore leaves mixed-case instance calls unknown. The real Mago test records
this limitation explicitly instead of treating macro names as case-insensitive.

Run `php tests/macro-boot-sources.php` for positive, negative, unlisted-provider,
and extension-disabled checks.
