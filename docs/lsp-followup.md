# Laravel static analysis follow-up

This follow-up revisits the 35 deferred research items and four remaining metadata export capabilities. Each item is assigned to its own implementation agent. Results distinguish implemented static subsets, partial support, and verified SDK or runtime-state blockers. Optional advisory checks do not assert Laravel runtime failure.

| Item | Scope | Status and evidence |
| --- | --- | --- |
| 21 | Literal query column references | implemented; Opt-in exact-model query-source and native-column-semantics assertions check literal columns in fully visible Model::query terminal chains; joins, mutable builders, custom dispatch and unasserted semantics defer. Twenty-eight real-Mago cases pass. |
| 23 | Search versus creation attribute arrays | implemented; Direct fresh-query firstOrNew, firstOrCreate and updateOrCreate check literal nonnumeric search keys against exact effective-source assertions; write values, creation-first createOrFirst, numeric tuple keys and mutable/custom builders defer. Thirty-five real-Mago cases and twenty-eight query-column regressions pass. |
| 29 | Related model projection columns | implemented; Closed native eager-load colon projections use an independent exact owner-relation source contract covering inherited connection and constraints. Forty genuine-Mago cases pass with both isolated hook and full plugin; mutable, pivot/through, dynamic and unasserted cases defer. |
| 34 | Query-local aggregate result properties | blocked; Real-Mago regression proves fresh and mutable aggregate calls expose identical synchronous provider context. Query-local aggregate types require source identity and receiver syntax before typing; mutable builders additionally need alias-aware invalidation. No unsafe production marker added. |
| 41 | Configuration push and prepend targets | implemented; Opt-in unchanged pre-call configuration contracts warn for concrete incompatible native push/prepend targets. Missing keys and push(null) remain valid; false, unknown values, custom dispatch and changed native bodies defer. Twenty-eight real-Mago cases and existing configuration regressions pass. |
| 46 | Environment template interpolation references | implemented; Explicit dotenv-template CLI export provides declaration and interpolation-name spans without values or runtime resolution. Requested and resolved template filenames are enforced; 58 focused checks, CLI isolation and Composer proxy tests pass. |
| 47 | Duplicate environment template declarations | implemented; Advisory exact-case duplicate declarations within explicitly selected dotenv templates; names-only first and repeated source locations, errors and uncertainty preserved. Thirty focused checks plus CLI and installed Composer proxy checks pass. |
| 48 | Vite environment name references | pending |
| 57 | Conflicting active route names | pending |
| 58 | Required named-route parameters | pending |
| 59 | Domain optional and default route parameters | pending |
| 60 | Route declaration diagnostic locations | pending |
| 68 | Controller parameter route and DI contracts | pending |
| 71 | Middleware name references | pending |
| 72 | Middleware parameter string parsing | pending |
| 83 | Policy method availability | pending |
| 84 | Gate ability name references | pending |
| 87 | Policy model argument compatibility | pending |
| 88 | Additional policy arguments | pending |
| 89 | Class-level policy abilities | pending |
| 90 | Policy discovery resolver boundaries | pending |
| 95 | Container injection attributes | pending |
| 97 | Binding interface compatibility | pending |
| 100 | Contextual binding resolution | pending |
| 104 | Route view references | pending |
| 105 | MailMessage view and markdown references | pending |
| 106 | Declarative mail content references | pending |
| 107 | assertViewIs references | pending |
| 108 | Pagination view references | pending |
| 116 | trans_choice references | pending |
| 119 | Translation replacement placeholders | implemented; Explicit PHP source export provides bounded colon-word completion candidates, source selection and original key/message token provenance. No translated values, runtime locale inference or mandatory replacement diagnostics; 77 focused checks and CLI integration pass. |
| 120 | Suspicious replacement names | pending |
| 122 | Cross-locale placeholder consistency | pending |
| 123 | Pluralization syntax contracts | pending |
| 180 | Pest closure this typing | blocked; Real-Mago identical-file probe confirms missing pre-argument file identity; SDK 1.49.0 retains the boundary. Documented source-aware API prerequisite and verified explicit closure-this PHPDoc workaround; no unsafe provider added. |
| 198 | Literal route groups and name prefixes in metadata | implemented; Route metadata composes bounded literal name groups and exact as-attribute groups, retaining raw leaf spans and ordered prefix-token provenance. Dynamic groups and incorrect magic-attribute casing defer; 74 checks and CLI regression pass. |
| 199 | JSON translation declaration metadata | implemented; Explicit JSON translation source export preserves root key byte spans, duplicate selection and PHP key normalization without translated values. Forty-nine focused checks, PHP exporter regression, CLI and installed Composer proxy checks pass; symlink cases conditional on host support. |
| 200 | Route source metadata watch | implemented; Explicit route source polling emits replacement snapshots, invalidates read/parse/racing/truncated states and deduplicates idle events. Requested and resolved extensions checked before reads; exact bytes and canonical paths hashed. Twenty-five watch checks, route/configuration regressions and source analysis pass. |
| 201 | Translation source metadata watch | implemented; PHP and JSON translation watch reuse bounded selected-source snapshots with per-mode extension checks, exact-byte detection, stale metadata invalidation and recovery. Twenty-six focused watch assertions and CLI/source checks pass; symlink-only tests skipped without host support. |
