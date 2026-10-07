# Driver extension forwarding


Driver extensions are checked separately from transaction macro replacements.
A visible `DB::extend()` registration preserves the scoped callback result
when the unchanged manager crosses a physical `Connection` parameter boundary,
returns that connection, and all known connection subclasses use the native
transaction implementation or the physically checked standard SQL Server override. The extension body must only register the resolver.
Changed `configure()` or `extend()` bodies, custom transaction implementations,
incompatible native metadata, unknown bindings and macro replacements retain
Mago diagnostics. No resolver or application method is executed.

The standard SQL Server implementation returns the documented callback result in
both normal paths: forwarding to the parent transaction or committing its own
transaction. Current physical bodies and native templates must agree. Five
actual SDK metadata controls and three changed-source controls preserve complete
reports and restore the correction after the original contract returns. Custom
driver overrides and stronger result declarations retain their diagnostics.

Anonymous connection subclasses are matched to their current physical `new class`
construction by exact source boundaries and a unique nested class declaration.
Their parent, class kind and declared methods are checked against Mago metadata,
and transaction overrides receive the same checks as named subclasses. Six
actual SDK cache controls and a changed anonymous transaction implementation
verify rejection and exact restoration without executing the fixture code.
