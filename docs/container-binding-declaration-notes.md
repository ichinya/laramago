# Container binding declaration notes

Set `extra.laramago.binding-compatibility-candidates` in the application Composer metadata to enable notes during ordinary `mago analyze`:

```json
{
  "extra": {
    "laramago": {
      "binding-compatibility-candidates": {
        "diagnose": true,
        "files": ["app/Contracts/Clock.php", "app/Services/SystemClock.php", "app/Providers/AppServiceProvider.php"]
      }
    }
  }
}
```

The `ichinya/laramago/laramago-binding-compatibility-candidate` note identifies a literal `bind`, `singleton`, or `scoped` pair whose selected implementation hierarchy is complete but does not implement or extend the selected abstract. The receiver must resolve to Laravel's container method declared in the installed framework path. Custom overrides, pseudo methods, unrelated receivers, incomplete hierarchies, compatible pairs, dynamic values and custom factories defer. Named arguments are supported by the existing bounded source extractor. A missing, unreadable, invalid or truncated selected source suppresses notes.

This is an opt-in declaration convention. Laravel permits service IDs without an inheritance relationship, and the note does not claim runtime failure or refine container resolution types. It reuses the existing binding compatibility export; it does not infer active providers or effective registration order. The hierarchy is the explicitly selected source hierarchy, not a claim about runtime class loading. Supporting source files are read independently of Mago's analyzed paths, so analyzing a single registration file works. They are parsed, never executed. Registration source bytes and spans must match the analyzer snapshot; supporting declaration hashes are revalidated before using each analyzed source. Candidate lookup is indexed by file and span. Initialization refreshes the snapshots.

## Remaining contextual boundaries

Contextual `when()->needs()->give()` metadata does not prove that the chain executes on a native container or that its context is on the build stack for a later resolution. This release does not refine global `app()` or `make()` results from those declarations. Doing so would misrepresent contextual overrides as global bindings.

Injection attribute metadata likewise does not prove that a constructor is container-resolved or which registered contextual attribute handler is effective. Native configuration-attribute checks already use their separate explicit runtime catalog and native-body guards. Other attribute exports remain metadata-only; this change adds no inferred injected type and does not freeze environment-dependent authentication configuration.

Validation: `php tests/binding-compatibility-candidate-diagnostics.php` uses the real Mago worker and full Laravel plugin, covers default-off and malformed options, native and custom receivers, inheritance uncertainty, case-insensitive class names, single-file analysis, missing source and stale hierarchy snapshots. Existing `tests/binding-compatibility-export.php`, `tests/container-injection-attribute-export.php`, and `tests/contextual-binding-metadata-export.php` preserve source-only behavior.
