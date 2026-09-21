# Remaining diagnostic integration

This pass follows the bounded metadata work in [the follow-up queue](lsp-followup.md).
Each direction has an independent implementation agent. Source metadata is not
treated as proof of effective Laravel runtime state. New diagnostics remain
conditional on the documented contracts; unknown behavior stays with native Mago.

| Direction | Result |
| --- | --- |
| Routes | Explicit domain, required URI, optional URI and defaulted parameter descriptors feed the existing real Mago missing-parameter check. Effective route conflicts and controller dependency resolution remain unproven. See [parameter contracts](effective-route-parameter-contracts.md). |
| Middleware | In progress. |
| Policies and Gate | In progress. |
| Container | Pending independent implementation. |
| Views, mail and pagination | Pending independent implementation. |
| Translations | Pending independent implementation. |
| Vite | Pending independent implementation. |
| Query-local aggregates | Pending SDK boundary review. |
| Pest closure context | Pending SDK boundary review. |
