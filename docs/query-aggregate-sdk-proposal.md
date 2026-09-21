# Synchronous source context for query-local aggregate types

This is an upstream API proposal and reproducible boundary report, not a
production aggregate provider. No issue has been published automatically.

## Reproduce without Laravel or a database

From the package checkout with development dependencies installed:

```sh
php tests/query-aggregate-sdk-boundary.php
```

The test generates two files, pads their prefixes to equal byte lengths, and
analyzes these calls with the real Mago engine:

```php
// File A: receiver is a fresh expression.
freshQuery()->withCount('items as selected_count')->firstOrFail();

// File B: receiver is an existing mutable builder.
$queryState0->withCount('items as selected_count')->firstOrFail();
```

Both receiver expressions have the same byte length. The fixture defines only
`AggregateBuilder` and `freshQuery`; Laravel is not loaded. The worker records
the public invocation fields, argument expression text and inferred types. The
two records are identical. Before comparing them, the worker checks the public
field sets of both the context and invocation, so a future added context field
invalidates this proof instead of being silently ignored.

The expected result is:

```text
PASS: fresh and mutable aggregate calls expose identical synchronous provider context
```

For an isolated SDK/engine experiment, `MAGO_BINARY` selects a binary and
`MAGO_SDK_AUTOLOAD` selects its SDK autoloader. Keep these dependencies outside
the package checkout and use matching engine and SDK versions. The test itself
never downloads or updates dependencies.

## Minimum capability for closed fresh chains

An optional synchronous provider context capability should expose:

1. The exact current source-file identity, stable within an analysis run, with
   access to the corresponding immutable syntax and resolved names.
2. The invocation syntax node and receiver node or spans tied to that file.
3. These facts before committing the return type, including at the terminal
   `firstOrFail`, `first`, `get` or pagination invocation.

The names and wire representation are an upstream design decision. A file-local
span alone is insufficient; obtaining a source file later in an after-file hook
cannot replace a previously inferred return type. An extension should declare
this capability and return `null` when it is unavailable.

With this capability, a first implementation could accept only a closed fresh
chain, preserve aggregate aliases in the final result type, and decline all
variable receivers, callbacks, scopes, unknown methods and selections whose
effect cannot be proven. This does not require pretending that all instances of
a model have the aggregate property.

## Acceptance cases for a future implementation

| Case | Required behavior |
| --- | --- |
| Fresh query with a literal aggregate and terminal call | Refine only that result, preserving nullable/native terminal semantics |
| Another fresh query for the same model | No inherited aggregate alias |
| Same offsets in another file | No cross-file state contamination |
| `select` after the aggregate | Remove the aggregate refinement |
| Builder mutation through an alias | Decline variable-chain refinement unless engine-managed alias invalidation exists |
| Conditional or escaped builder | Decline unless the engine proves the state on every path |
| Scope, callback or unknown builder method | Decline unless its effect is modeled soundly |
| Declared PHP property, PHPDoc, accessor or cast for the alias | Preserve the existing stronger contract |
| Misspelled or unrelated property | Keep native unknown-property diagnostics |

General builder variables need more than source context: object identity,
alias-aware mutation invalidation, branch joins and escape handling must be
managed by the engine. That is a separate capability from the bounded fresh-chain
proposal.

## Upstream evidence

The [return-provider contract](https://mago.carthage.software/main/en/extensions/analyzer/return-types-and-callable-signatures/)
documents receiver types and spans, but not receiver syntax or source identity.
The source snapshot at
[`116abff82d8cd9556dc3be16d5de8e199c7108d2`](https://github.com/carthage-software/mago/tree/116abff82d8cd9556dc3be16d5de8e199c7108d2/composer/src/Sdk/Analyzer)
still exposes the same fields in `Invocation`, `ReturnTypeProviderContext` and
`BeforeAnalysisContext`. The latest release discovered on 2026-09-21 was
[1.49.0](https://github.com/carthage-software/mago/releases/tag/1.49.0).

The real-engine collision probe passed on both the package's pinned 1.48.1
engine/SDK and an isolated matching 1.49.0 engine/SDK. The source review of `main`
is separate evidence; no unreleased engine was built or tested. Dependencies were
not changed, and these narrow probes are not a full compatibility check for
upgrading the package to 1.49.0.

See [the original boundary analysis](query-aggregate-sdk-boundary.md) for the
Laravel mutation example. A successful collision probe confirms the limitation;
it does not constitute support for aggregate properties.
