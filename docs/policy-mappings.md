# Explicit policy mapping metadata

`PolicyMappingCatalog` reads literal `$policies` arrays from explicitly selected
direct subclasses of Laravel's native `AuthServiceProvider`. It does not execute
providers or discover their activation. Configure the selected effective sources
in registration order in the application's `composer.json`:

```json
{
  "extra": {
    "laramago": {
      "policy-sources": [
        {"file": "app/Providers/AuthServiceProvider.php", "provider": "App\\Providers\\AuthServiceProvider"}
      ]
    }
  }
}
```

This opt-in is the application's assertion that the selected providers participate
in native policy registration in that order. Source existence alone is insufficient.
The catalog describes only those declarations: it does not prove that they are the
only registrations or that later runtime mutations, a replaced Gate binding, or
changed framework registration methods leave them effective.

The bounded syntax requires one direct provider class containing only one untyped
protected `$policies` property. Imports, literal class strings and resolved
`SomeClass::class` are supported. Model keys retain exact case and leading slashes;
later duplicate keys replace earlier values, including across selected providers.
Dynamic expressions, custom methods, traits, inherited providers, attributes,
unpacking and relative `self`, `static` or `parent` class constants defer the entire
selection. Files must use project-relative paths contained within the project.
Missing or malformed files also yield an unknown catalog.

`mappings()` returns the selected map or `null` for unknown metadata.
`policyForExactKey()` returns a selected policy string or `null`. Null is never
evidence of a missing policy: attributes, naming guesses, inherited mappings and
authorization before hooks remain outside this catalog. This metadata does not
establish policy existence, method signatures, dispatch or authorization results.
There are no analyzer diagnostics or type refinements from this catalog yet.
Direct `Gate::policy()` registrations and policy discovery are separate work.
