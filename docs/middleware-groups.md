# Offline middleware group metadata

`MiddlewareGroupCatalog` exposes `groups()`, `contains(string)` and `isComplete()` to metadata consumers. The analyzer reports declared nested-group cycles at selected, analyzed source declarations. It does not validate middleware arguments, instantiate a kernel, execute application code, or read the effective router registry.

Select literal group map files in application Composer metadata:

```json
{"extra":{"laramago":{"middleware-groups":{"files":["bootstrap/middleware-groups.php"],"complete":false}}}}
```

Each selected file must return a literal map such as `return ['web' => [SessionMiddleware::class, 'auth:admin'], 'api' => ['throttle:api']];`. Imports and standalone declarations are supported; executable statements, conditional registration, spread expressions, constants and function calls are unknown. Later files replace earlier groups with the same exact name. Entries retain their order, duplicates and parameter suffixes. An empty group is valid. Nested and cyclic group references remain raw strings.

All selected paths must be project-relative `.php` files. Absolute paths, parent traversal, null bytes, missing files and links resolving outside the project are rejected.

Alternatively select a legacy Kernel property declaration:

```json
{"extra":{"laramago":{"middleware-groups":{"kernel-file":"app/Http/Kernel.php","kernel-class":"App\\Http\\Kernel","complete":false}}}}
```

Source selection is an explicit assertion by the application that the selected declarations describe effective registered groups, including their effective members. This assertion must account for constructors, providers, later router changes, and modern middleware configuration. Property initializers alone do not prove activation. Do not select a Kernel initializer that is subsequently changed. File selection and Kernel selection cannot be combined.

The Kernel reader inspects only a directly declared nonstatic `middlewareGroups` literal property on one matching top-level class. Inheritance, traits, conditional class declarations and dynamic initializers remain unknown. The separate `KernelMiddlewareDeclarations` reader returns declaration metadata only; it makes no activation or completeness claim. Legacy alias properties are outside this item's scope.

`complete: true` additionally asserts that every effective group is present, including framework defaults and package registrations. Only then can `contains()` return false for an absent name. Without completeness, absent names return null. Missing files, malformed options, or unsupported values invalidate the entire catalog. There is no implicit `web` or `api` group: native Kernel defaults are empty, whereas modern configuration builds defaults and applies replacements, removals, prepends and appends. Alias completeness is independent of group completeness.

The returned map is declaration metadata, not a resolved pipeline. Laravel checks exact group names before string aliases, and recursively expands nested groups before alias substitution. Direct resolution first permits a closure alias to bypass a group. No inference about route dispatch, pipeline ordering, deduplication, or parameter contracts is made here.

A false membership result proves only absence from the group catalog. It does not prove an invalid middleware reference: aliases, class names and arbitrary container bindings are separate resolution paths.

## Declared cycles

`laramago-middleware-group-cycle` warns on each group participating in an exact-key directed cycle in the asserted effective map. This is a declaration integrity fact, not a claim that every route fails: direct closure aliases can bypass group expansion and a group may never be used. Nested group traversal checks raw group keys before aliases; strings containing colons remain exact keys, without parameter splitting. The installed Laravel resolver rejects direct self references and recursively traverses longer cycles; the analyzer itself uses a bounded visited set and never executes that resolver.

Positive cycle proof does not require `complete: true`: every edge is supplied by known effective declarations. Unknown members do not prove edges. Unsupported sources invalidate all cycle diagnostics. Later selected files replace both members and diagnostic provenance; superseded declarations are not reported. Warnings appear only when the effective declaration file is analyzed by Mago and its source matches the selected contents. An unindexed metadata file is never annotated. Entry groups merely leading into a cycle and acyclic diamonds do not receive cycle warnings.
