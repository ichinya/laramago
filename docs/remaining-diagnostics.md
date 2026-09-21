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
| Container | Pending independent implementation. |
| Views, mail and pagination | Pending independent implementation. |
| Translations | Pending independent implementation. |
| Vite | Pending independent implementation. |
| Query-local aggregates | Pending SDK boundary review. |
| Pest closure context | Pending SDK boundary review. |
