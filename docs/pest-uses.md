# Static Pest use declarations

`PestUsesCatalog` reads selected PHP sources as syntax. It does not load Pest,
include test files, invoke callbacks, or discover tests at runtime. Opt in with
`composer.json`:

```json
{
  "extra": {
    "laramago": {
      "pest-uses": {
        "sources": ["tests/Pest.php", "tests/Feature/LocalTest.php"],
        "test-files": ["tests/Feature/LocalTest.php", "tests/Unit/ExampleTest.php"]
      }
    }
  }
}
```

The source list asserts declaration order. Both lists contain existing PHP files
inside the project root; symlink escapes are rejected. The result is ordered
positive metadata: source path and line, lexically resolved class or trait names,
normalized target paths, and matches from the selected test-file list. A `null`
result means that the selection or source could not be read safely. An empty
result does not prove that a test has no Pest configuration.

Recognized forms include `uses(TestCase::class, Trait::class)->in('Feature')`,
`pest()->extend(TestCase::class)->use(Trait::class)->in('Feature')`, and
`pest()->in('Feature/*Job*.php')->extend(TestCase::class)`. The aliases
`extends()` and `uses()` are accepted as the first call on `pest()` only;
the resulting `UsesCall` exposes only `extend()` and `use()`. A bare `pest()`
does not register anything. Without `in()`, `pest()` in `tests/Pest.php`
targets its directory, while `uses()` targets its source file. Each later `in()`
replaces earlier targets, as Pest does. Literal strings, `__DIR__`, and their
concatenation are accepted as path expressions. `*` and `?` patterns are matched
only against explicitly selected test files and their ancestor directories;
filesystem `glob()` is never called.
Wildcard segments do not match leading dots unless the pattern starts with a
literal dot, keeping matches portable across platform glob implementations.

Unsupported or dynamic expressions are omitted. The catalog does not classify
names as classes or traits, choose an effective test case, model hooks or groups,
or infer closure `$this`. Those decisions require separate analysis. It does not
claim that the selected files cover every runtime test or that every source
declaration executes.
