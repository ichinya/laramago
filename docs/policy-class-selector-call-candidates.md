# Policy class selector call candidates

`PolicyClassSelectorCallExport` records a bounded source-only transformation for
direct native `Gate` facade syntax. When a supported call passes an imported or
fully qualified `Model::class` as its arguments value, or as index zero of a
plain literal list, the export records that Laravel normalizes the value with
`Arr::wrap` and removes that leading string before invoking a policy method.

This rule is independent of the ability name. The exporter does not classify
`create`, `viewAny`, or any other name as class-level. It retains literal ability
names only as source provenance. For `allows`, `denies`, `check`, `any`, and
`none`, a plain nonempty list of literal abilities is supported because Laravel
applies the same arguments to each ability. `authorize`, `inspect`, and `raw`
require one literal string ability in this export.

Each candidate retains the selected source path and SHA-256 hash, call and
literal byte spans, the resolved selector class, the scalar or literal-list input
shape, and the expressions left after selector removal. Remaining expressions
have source spans, syntax kinds, and positions after Laravel's injected user
argument. Their runtime values and types are not evaluated.

The export deliberately does not resolve a policy, map an ability to a policy
method, verify a callback signature, infer guest eligibility, or prove that the
call executes. `runtimePolicyResolved` is always false, and the scope is
non-exhaustive. Gate definitions, callbacks, container replacement, policy
`before` methods, magic dispatch, custom facades, instance calls, dynamic ability
expressions, `self::class`, literal class-name strings, keyed arrays, and array
unpacking remain outside this contract. Unsupported syntax is counted but is not
reported as a runtime error.

Only explicitly selected project-relative PHP files are read. The exporter does
not load the application autoloader, execute selected PHP, bootstrap Laravel,
read environment values, or connect to a database. Reads are limited to 256
files, 1 MiB per file, 8 MiB total, and 20,000 exported calls. Source errors and
reached limits remain visible in the result envelope.
