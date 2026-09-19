# Literal path helpers

Laravel's eight path helpers return `string` natively. Laramago can retain an
exact path for a literal call when the project explicitly asserts that helper's
effective root in `composer.json`:

```json
{
  "extra": {
    "laramago": {
      "path-helper-bases": {
        "base_path": "C:/projects/example",
        "storage_path": "D:/example-storage"
      }
    }
  }
}
```

Each entry is independent and must be an absolute path. The value is the exact
runtime result of the zero-argument helper, after any `Application::setBasePath`,
`use*Path`, environment, bootstrap, or container changes. It is an assertion
by the application owner, not a path inferred from Composer's project root.
Keep the assertion current for every environment in which the analysis result
is used. A missing or invalid entry leaves that helper's native type intact.

For the installed native helper body, Laramago applies Laravel's
`Illuminate\Filesystem\join_paths` behavior to a single literal or omitted
`$path` argument. For example, with the contract above,
`storage_path('logs/app.log')` has the literal result
`D:/example-storage\logs/app.log` on Windows. Dynamic arguments, changed
helper forwarding, and a helper with explicit return PHPDoc defer to Mago.
The assertion also promises that `app()` dispatch reaches the effective
Application path methods and that those methods retain Laravel's native
argument-joining behavior. The provider verifies the helper's forwarding body,
but does not inspect the runtime container or execute the Application. A custom
dispatch or path method needs its own native/PHPDoc contract. Laravel preserves
a trailing separator in the asserted base and appends another separator for a
nonempty child; the literal result preserves both. No file-existence assertion
is made by this provider.

For `require` and `require_once`, Laramago reports a missing required file when
Mago resolves the operand to one absolute local path and its parent directory
can be enumerated without finding the target name. This includes a path
returned by an asserted helper, as well as an absolute literal independent of
helpers. The warning describes the analyzed filesystem snapshot. Relative
paths, stream wrappers, dynamic values, inaccessible directories and optional
`include`/`include_once` targets are left to Mago and PHP's runtime behavior.
A file created later at runtime can make the snapshot warning stale.
