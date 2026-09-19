# PHP Livewire component references

Laramago checks literal component names in native Livewire 3 and 4
`Livewire::test()`, `Livewire::mount()`, `Livewire::new()` and their direct
`LivewireManager` equivalents. It reads PHP syntax through Mago and does not
bootstrap the application, resolve components, or run tests.

Missing-name warnings are opt-in. The project must assert its **complete
effective** component name set in `composer.json`:

```json
{
  "extra": {
    "laramago": {
      "reference-catalogs": {
        "livewire-components": {
          "complete": true,
          "names": ["posts.show", "admin::dashboard", "search-box"]
        }
      }
    }
  }
}
```

The assertion covers every name accepted at runtime, including conventional
components, explicit registrations, package namespaces, and custom missing
component resolvers. `complete: false`, a missing catalog, duplicate or invalid
entries, and a non-list `names` value disable the warning. An empty `names`
list is valid only when it truthfully describes the effective registry.

For example, with the catalog above, `Livewire::test('posts.missing')` receives
`laramago-missing-livewire-component`. `Livewire::test('posts.show')` does not.
The check accepts only literal strings containing `.`, `-`, or `:`. A simple
string such as `'Counter'` could also be a PHP class name, so it is left to
native analysis. `Counter::class`, objects, dynamic expressions, argument
unpacking, facade subclasses and manager subclasses also defer.

The hook verifies installed Livewire method signatures and bodies for `test`,
`mount`, and `new`, the facade accessor and Laravel facade dispatch metadata
before reporting. Changed implementations, a namespace-local `Livewire\app()`
that would shadow native `new` or `mount` forwarding, selected container
bindings that replace the Livewire manager, and absent Livewire packages defer. Native PHPDoc and
method diagnostics remain in force. This contract does not infer completeness
from source catalogs or conventional directory scans: either may omit runtime
registrations and resolver results.

The pinned upstream test fixtures come from Livewire 3.x commit
`1de96cea779167c2369b620a9f6793d2351370c6` and 4.x commit
`5e4cd6366f916f84882551c02905ff82a6cdc9dc` under the MIT license.
