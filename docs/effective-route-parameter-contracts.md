# Effective domain and URI parameter contracts

The existing `named-route-parameters` check accepts either a list of required
parameter names or an explicit descriptor separating domain, required URI,
optional URI, and effective defaulted parameters:

```json
{
  "extra": {
    "laramago": {
      "named-route-parameters": {
        "native-url-generation": true,
        "url-defaults-complete": true,
        "routes": {
          "tenant.show": {
            "domain": ["tenant"],
            "uri": ["account"],
            "optional-uri": ["page"],
            "defaulted": ["account"]
          }
        }
      }
    }
  }
}
```

For this contract, `route('tenant.show')` reports
`laramago-missing-named-route-parameter` for `tenant`.
`route('tenant.show', ['tenant' => 'acme'])` satisfies the contract.
The same check applies to the existing guarded native URL and redirect APIs.
The contract does not establish that a named route exists; the separate
`named-routes` configuration remains independent.

All four descriptor fields are required lists of unique ASCII parameter
identifiers. Domain, required URI and optional URI categories must be disjoint.
Every defaulted name must belong to one of these categories. Unknown fields,
default values instead of names, duplicate names and malformed descriptors
disable the parameter contract conservatively. Existing list configurations
retain their behavior.

These are explicit assertions about the application's effective URL generation
configuration. `defaulted` means omission is allowed by an effective usable
default; it does not mean that a `defaults()` expression merely occurs in route
source. `optional-uri` asserts that omission is valid. Domain parameters are
required unless explicitly defaulted. Laramago does not load application PHP,
read `.env`, select registrations, or infer these assertions from metadata.
Dynamic arrays, positional fallback, custom URL dispatch and unsupported calls
retain their existing conservative behavior.

## Remaining route boundaries

Duplicate source names are already available as opt-in Mago notes through
`route-name-duplicate-candidates`. Two declarations with identical HTTP method,
domain and URI may represent replacement, while the same name on different
surviving routes may be a conflict. Providers, conditional registration and
cached routes can change this distinction. Selecting source files does not
prove an effective collection; the note must not become an active-conflict
error. The separate [effective route manifest](effective-route-name-conflicts.md)
can now diagnose duplicate names when the user explicitly asserts the final
surviving collection, native identity semantics and cache/source selection. It
does not infer that final collection or resolve replacement order from source.

Controller source contracts likewise do not establish runtime argument errors.
For example, a controller `(Request $request, string $slug)` can receive the
request from the container and the slug from route parameters. A binder or
middleware may replace or add parameters, and a custom controller dispatcher
can change resolution. Matching PHP parameter names against URI placeholders
would incorrectly treat positional dispatch as named dispatch. A future check
needs an independently asserted native dispatcher, effective ordered parameter
set, binding behavior and dependency-resolution contract. The current source
export remains review evidence, not a runtime compatibility verdict.
