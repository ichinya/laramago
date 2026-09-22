# Remaining adaptation boundaries

The 2026-09-22 reconciliation of the [197-item queue](lsp-adaptation-queue.md)
found 156 bounded implementations, 35 already-covered items and six deferred
items. Sixteen formerly deferred rows had been superseded by the executable
checks in [diagnostic refinements](diagnostic-refinements.md). This is a status
correction, not sixteen new features or a reduction in application diagnostics.

An implemented row can still require explicit application assertions or provide
only declaration-policy Notes. It does not mean automatic discovery of effective
runtime state. Each row links its supported subset and remaining restrictions.

## Deferred items and acceptance requirements

| Item | Available now | Requirement for the remaining behavior |
| --- | --- | --- |
| 34: Query-local aggregate properties | Aggregate-name checks and a [real-engine SDK collision probe](query-aggregate-sdk-boundary.md) | Synchronous source-file identity and receiver syntax before return typing for closed fresh chains; engine-managed alias and mutation invalidation for mutable builders. Matching Mago/SDK 1.50.0 still lacks the required context. |
| 68: Controller route/DI compatibility | [Controller signature and route source metadata](controller-route-contract-candidates.md); separate explicit constructor injection checks | Independently established native dispatcher, effective ordered route parameters, bindings and dependency resolution. URI/PHP parameter-name matching alone is insufficient; positional dispatch must be preserved. |
| 90: Automatic policy discovery | [Resolver-channel metadata](policy-discovery-boundaries.md), explicit mappings and opt-in policy call checks | Effective runtime mappings, guesser, hierarchy and container-selected policy object. Source candidates and conventional class names alone cannot establish selection. |
| 116: `trans_choice()` helper diagnostics | [Helper reference metadata](translation-choice-references.md); separately implemented [native Translator/Lang choice diagnostics](native-translation-choice-references.md) | Proven helper ownership/dispatch and every possible effective locale, catalog and loader contract. Native method coverage does not establish helper coverage. |
| 123: Pluralization syntax verdicts | [Plural branch candidate metadata](translation-plural-branch-candidates.md) and placeholder source-quality Notes | Effective selector and locale semantics, including permissive native parsing and custom selectors. Branch metadata alone must not become an invalid-syntax verdict. |
| 180: Automatic Pest closure `$this` | [Explicit closure PHPDoc workaround and SDK regression](sdk-pest-closure-context.md) | Source-file identity before closure argument typing. Matching Mago/SDK 1.50.0 still gives indistinguishable contexts for conflicting files. |

These are not missing blanket suppressions. None is completed by assigning
`mixed`, executing application bootstrap, reading environment values, or treating
an asserted configuration as an automatically discovered fact. Environment-dependent
Auth selection remains unchanged.

## Verification of the reconciliation

The existing real-Mago suites for effective route conflicts, named-route
parameters, middleware parameters, policy call contracts, contextual injection,
binding declaration notes, template reference policies and translation
source-quality diagnostics were rerun. The optional Babel adapter passed all
14 Node tests. These are focused checks, not a new full package run.

The preceding full `composer check` at e995822 passed all 308 configured commands;
the reconciliation changes documentation only. Private application reports stay
in the ignored report directory and are not part of this public inventory.
