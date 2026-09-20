# Volt route component references

Laramago checks literal `Volt::route($uri, $componentName)` references when an
application explicitly asserts its complete effective component-name set:

```json
{
  "extra": {
    "laramago": {
      "reference-catalogs": {
        "volt-route-components": {
          "complete": true,
          "names": ["pages.home", "pages.account"]
        }
      }
    }
  }
}
```

The names are exact and case-sensitive. The assertion must include every
accepted case form. `names` must be a list of unique,
nonempty strings. Omit this configuration, use `complete: false`, or give an
invalid list to leave absence unknown. The list must cover the effective
runtime universe, including mounted Volt files, ordinary Livewire components,
custom resolvers and overrides. The separate `livewire-components` registration
catalog does not prove this universe complete.

The warning means a literal is absent from the application's asserted name
catalog; it does not prove the route was executed or that rendering failed.
Only names containing a dot or hyphen are checked. Bare strings that could be PHP class names remain unknown even under this
assertion.

The hook accepts positional and named `componentName` arguments on the installed
`Livewire\Volt\Volt` facade. It checks that the facade resolves to
`Livewire\Volt\VoltManager` and that the manager's route method forwards the
component name to Livewire creation. Dynamic strings, unpacked calls, changed
forwarding, absent Volt sources and unrelated classes remain unknown. It reads
source only; it never boots Laravel or executes application code.

The signature and forwarding follow the official [Volt facade](https://github.com/livewire/volt/blob/18ae5b1f4bd900288d4c9db91110eb9d4eb6357a/src/Volt.php),
[Volt manager](https://github.com/livewire/volt/blob/18ae5b1f4bd900288d4c9db91110eb9d4eb6357a/src/VoltManager.php),
and [mounted directories](https://github.com/livewire/volt/blob/18ae5b1f4bd900288d4c9db91110eb9d4eb6357a/src/MountedDirectories.php).
