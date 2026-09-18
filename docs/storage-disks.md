# Complete storage disk contracts

Laramago does not assume that `config/filesystems.php` is the complete runtime
disk registry. Applications, service providers, packages and tests can add,
replace or remove disks. An application can opt into missing-disk diagnostics in
`composer.json` only when its runtime registry stays equal to the static config:

```json
{
  "extra": {
    "laramago": {
      "storage-disks": {
        "complete": true,
        "runtime-disks-unchanged": true
      }
    }
  }
}
```

`complete: true` asserts that every runtime disk name appears as a literal string
key in `config/filesystems.php` under `disks`. `runtime-disks-unchanged: true`
asserts that no application, provider, package or test registration adds,
replaces, forgets or otherwise changes that set of names. Both assertions are
required. Omit this contract when either assertion cannot be maintained.

The `filesystems.disks` array must also be source-complete: dynamic values are
allowed, but unpacked entries, dynamic keys, numeric keys and dynamic ancestor
replacement disable the diagnostic. Laramago checks an exact native
`Illuminate\Support\Facades\Storage::disk()` call only when the installed facade
still uses the `filesystem` accessor and the exact native
`Illuminate\Filesystem\FilesystemManager::disk()` declaration. The exact native
`Illuminate\Container\Attributes\Storage` injection attribute is also checked
when its installed promoted `disk` constructor property and static `resolve()`
body still forward through the container's `filesystem` service to `disk()`.
A cataloged `config` or `filesystem` container binding, custom contextual
attribute handler, or uncertain binding catalog disables the diagnostic.

An absent literal name produces `laramago-missing-storage-disk`. Names are case
sensitive. Positional arguments, facade `name:` arguments and attribute `disk:`
arguments are supported. Dynamic values, concatenations, unpacking and
first-class callables defer. Empty and `"0"` names also defer because the native
manager treats them as requests for its default driver rather than named-disk
lookups. Direct manager calls, custom facades or attributes, `fake()`,
`persistentFake()`, `forgetDisk()` and adapter methods are outside this subset.
Existing native method, PHPDoc and argument diagnostics remain unchanged.

The extension reads Composer JSON, configuration PHP syntax and installed
framework source only. It does not bootstrap Laravel, execute configuration or
provider code, resolve a facade, or inspect a runtime filesystem manager. A stale
or incorrect explicit contract can therefore cause false warnings.
