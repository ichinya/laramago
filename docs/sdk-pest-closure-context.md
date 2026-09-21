# Source-aware Pest closure context

Pest binds test closures to a generated test-case class chosen for the current
test file. Mago can type a closure's `$this` from a callable parameter, but an
extension cannot currently choose that parameter type from the call-site file.

## Minimal two-file case

Assume Pest has two explicit file selections:

```php
<?php

// tests/Pest.php
pest()->extend(AlphaCase::class)->in('AlphaTest.php');
pest()->extend(BetaCase::class)->in('BetaTest.php');
```

The selected files can contain byte-for-byte identical calls:

```php
<?php

// tests/AlphaTest.php and tests/BetaTest.php
it('same', function (): void {
    $this->shared();
});
```

The first closure needs `AlphaCase` as its `$this` type and the second needs
`BetaCase`. The function name, argument source, and byte offsets do not
distinguish the calls. Using a global offset-to-type cache can therefore apply
one file's context to another file.

This is not limited to methods that happen to share a name. The selected class
controls every property, method, ancestor, and trait method visible through
`$this`, so the analyzer needs the actual call-site file before it analyzes the
closure argument.

## Current SDK boundary

The installed version verified on 2026-09-21 is Mago 1.48.1. Its
`CallableSignatureProviderContext` contains `phpVersion`, `codebase`,
`invocation`, `types`, and `cancellation`. `Invocation` contains the invocation
kind, callable name, declaring class, receiver type, a `Span`, and arguments.
Each argument has source text and another `Span`. `Span` is only a start/end
byte range within an unspecified file.

`CodebaseScanContext` supplies `SourceFile` objects, including their paths,
before provider requests start. The later callable-signature request has no
source identifier that can be joined to those files. `NodeAnalysisContext`
does contain a `SourceFile`, but node hooks run after argument analysis and cannot
change the closure type used while arguments were analyzed. An issue filter can
remove diagnostics but cannot type `$this`.

A real Mago 1.48.1 protocol fixture analyzed two identical files through one
`CallableSignatureOverride`. Both callbacks reported the same invocation span
`33..87`, argument spans `36..42` and `44..86`, identical argument expressions,
and null pre-analysis argument types. Mago reported `undefined-variable` and
`mixed-method-access` in each file. This demonstrates that a provider cannot
select the correct per-file context from the data it receives.

Mago 1.49.0 retains the same context and
invocation fields. Upgrading from 1.48.1 to 1.49.0 alone does not remove this
boundary.

## Required SDK contract

The pre-argument context should expose the call-site `SourceFile`:

```php
final class CallableSignatureProviderContext
{
    public function __construct(
        public readonly PHPVersion $phpVersion,
        public readonly Codebase $codebase,
        public readonly SourceFile $source,
        public readonly Invocation $invocation,
        public readonly TypeComparator $types,
        public readonly CancellationTokenInterface $cancellation,
    ) {}
}
```

A canonical call-site path would be the minimum useful alternative. It must use
the same identity and normalization as `CodebaseScanContext::files`, and it must
identify the caller rather than the helper's declaration. The association must
remain stable across scan batches, parallel analysis, and provider requests.

With that contract, a Pest provider can look up the selected file in a complete
effective-context catalog before returning a `closureThisType`. It must still
decline when the helper identity is ambiguous, when dynamic registration makes
the effective context incomplete, or when the callback already has a native,
PHPDoc, or custom closure-this contract. Source identity solves call-site
selection; it does not by itself prove Pest's generated class and traits.

## Explicit PHPDoc workaround

Mago supports `@param-closure-this` on a closure parameter. A project-owned
wrapper can make one known context explicit without an analyzer extension:

```php
<?php

/** @param-closure-this ProjectTestCase $test */
function project_it(string $description, Closure $test): void
{
    it($description, $test);
}

project_it('example', function (): void {
    $this->projectOnlyMethod();
});
```

The same tag can be placed on a project-controlled analyzer stub for `it` when
all calls covered by that declaration share one valid test-case type. A Mago
1.48.1 fixture using the tag completed with `No issues found`; a static closure
still produces Mago's native `Cannot use $this in a static closure` diagnostic.

The annotation is a uniform, explicit contract. It cannot express two different
file-selected Pest contexts on one global helper declaration, which is why the
source-aware provider API is still needed.

## Reproducible regression and upstream acceptance test

Run the checked-in real-engine fixture without installing Pest or Laravel:

```console
php tests/pest-closure-sdk-boundary.php
```

On 2026-09-21 all ten assertions passed with both the installed Mago 1.48.1
and a separately downloaded Mago 1.49.0 binary with its matching SDK. The
fixture accepts `MAGO_BINARY` and `MAGO_SDK_AUTOLOAD` for paired upstream
verification; normal package checks use the installed dependency. This does
not establish whole-package compatibility with a newer release.

The fixture creates two byte-identical test files and a minimal global `it`
declaration, starts one actual SDK worker, and records both pre-argument
requests. It asserts identical public request data, null argument types, and
native diagnostics in both files. Explicit field-shape assertions force this
boundary to be reconsidered when the SDK adds context; matching records alone
must not become a permanent justification to keep the feature disabled.

The same fixture verifies a usable workaround with two separately annotated
wrappers: each sees its own class, calling the other class's method remains an
error, and a static closure still rejects `$this`. Annotations express a
project-owned contract; they do not prove that Pest binds the matching class at
runtime. The fixture never executes analyzed source or loads application code.

For an upstream fix, retain the identical file contents and select different
contexts by the newly exposed caller identity. Require the following results:

- Both callbacks receive different call-site identities, matching scan sources.
- Selecting AlphaCase and BetaCase yields independent closure scopes.
- Wrong-case methods and static-closure `$this` still produce diagnostics.
- Reversed input order and multiple workers preserve the same per-file result.
- An explicit existing `@param-closure-this` contract remains authoritative.

Only the current boundary and explicit workaround are executable today; the
source-aware acceptance cases require the new API. This probe is suitable for
an upstream issue attachment, but no issue has been submitted automatically.

## Source links

- [Mago 1.48.1 callable-signature context](https://github.com/carthage-software/mago/blob/1.48.1/composer/src/Sdk/Analyzer/CallableSignatureProviderContext.php)
- [Mago 1.48.1 invocation](https://github.com/carthage-software/mago/blob/1.48.1/composer/src/Sdk/Analyzer/Invocation.php)
- [Mago 1.49.0 release notes](https://github.com/carthage-software/mago/releases/tag/1.49.0)
- [Mago 1.49.0 callable-signature context](https://github.com/carthage-software/mago/blob/1.49.0/composer/src/Sdk/Analyzer/CallableSignatureProviderContext.php)
