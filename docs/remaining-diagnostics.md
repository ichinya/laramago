# Remaining diagnostic integration

This pass follows the bounded metadata work in [the follow-up queue](lsp-followup.md).
Each direction has an independent implementation agent. Source metadata is not
treated as proof of effective Laravel runtime state. New diagnostics remain
conditional on the documented contracts; unknown behavior stays with native Mago.

| Direction | Result |
| --- | --- |
| Routes | Explicit domain, required URI, optional URI and defaulted parameter descriptors feed the existing real Mago missing-parameter check. Effective route conflicts and controller dependency resolution remain unproven. See [parameter contracts](effective-route-parameter-contracts.md). |
| Middleware | Opt-in real Mago warnings for missing required handle arguments resolve explicitly asserted alias/group maps, including nested groups and inherited declarations. Callable and uncertain dispatch defer. See [middleware parameters](middleware-parameters.md). |
| Policies and Gate | Explicit effective dispatch mappings enable real Mago missing-argument and native object-type checks, including class selectors. Runtime discovery, guest state and weak scalar coercion remain outside inference. See [policy call contracts](policy-call-contracts.md). |
| Container | Real Mago declaration notes identify incompatible selected binding hierarchies with source-hash checks and native receiver guards. Contextual build stacks and effective injection handlers remain unknown. See [binding notes](container-binding-declaration-notes.md). |
| Views, mail and pagination | Optional Mago source-policy Notes check literal references against explicit context-specific permitted lists; Markdown HTML/text contexts remain separate. Runtime finder and dynamic receivers remain unknown. See [template reference policy](template-reference-policy.md). |
| Translations | Optional Mago Notes compare explicitly associated locale placeholders and selected replacement-name conventions, using current analyzed source hashes. Plural selection and runtime loading remain unresolved. See [translation source quality](translation-source-quality-diagnostics.md). |
| Vite | An optional isolated Babel adapter exports genuine JS/TS/JSX AST references with bounded inputs/output and explicit UTF-16 spans. It never executes application code and adds no Node requirement to Composer or Mago. Runtime env existence remains unknown. See [Vite AST export](vite-environment-ast.md). |
| Query-local aggregates | Still SDK-blocked on both 1.48.1 and 1.49.0. The durable collision probe detects future context-field additions; an [upstream API proposal](query-aggregate-sdk-proposal.md) defines source identity and safe fresh-chain acceptance criteria. No production type approximation was added. |
| Pest closure context | Still SDK-blocked on both 1.48.1 and 1.49.0. A checked-in real-engine regression covers identical cross-file contexts and explicit closure wrappers, retaining invalid-method/static-closure errors. See [SDK boundary and workaround](sdk-pest-closure-context.md). |
