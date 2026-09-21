# Diagnostic refinements

This pass adds executable checks to the earlier bounded integrations. Every
direction was reviewed independently. Runtime-dependent checks still require the
documented assertions; they do not discover effective Laravel state by executing
the application.

## Default behavior fix

The preset now [excludes package-internal tests and research snapshots](package-source-isolation.md)
from dependency indexing. Framework test doubles must not replace the installed
framework's real contracts. Application tests and production package sources stay
indexed. This correction requires no opt-in analysis contract.

## Additional checks

| Direction | New behavior | Remaining boundary |
| --- | --- | --- |
| Routes | [Conflicting route names](effective-route-name-conflicts.md) in an explicitly asserted final effective manifest, with current source anchors | Automatic registration/cache selection and controller DI compatibility |
| Middleware | [Native parameter types](middleware-parameters.md) that cannot accept Pipeline string tokens | Weak scalar coercion, custom dispatch and runtime map discovery |
| Policies | [Nullable and object union arguments](policy-call-contracts.md), preserving native required-argument semantics | Automatic policy selection, scalar coercion and dynamic arguments |
| Container | [Final per-consumer injection types](contextual-injection-contracts.md) checked against native constructor parameters | Runtime build-stack and attribute-handler discovery; no global type override |
| Templates | [Missing native Mailer render templates](mail-render-references.md) under an effective factory and complete catalog contract | Setters, managers, mutable Content, route/pagination runtime selection |
| Translations | [Native choice lookups](native-translation-choice-references.md), checking every asserted possible locale's JSON and PHP fallback catalogs | Helper dispatch, automatic locale selection and plural selector execution |
| Vite | [Structured AST diagnostics](vite-environment-ast.md) against an explicit complete exposed-name inventory | Runtime/build-mode discovery, dotenv values and dynamic names |

The aggregate and Pest source-context blockers were independently retested. The
existing [aggregate proposal](query-aggregate-sdk-proposal.md) and
[Pest boundary/workaround](sdk-pest-closure-context.md) still apply. No speculative
provider or repeated documentation-only implementation was added for these items.

PHP checks are part of `composer check`. The optional JavaScript adapter is tested
separately with `npm test --prefix tools/vite-environment-ast` after installing its
locked dependencies. It remains optional for PHP and Composer users.
