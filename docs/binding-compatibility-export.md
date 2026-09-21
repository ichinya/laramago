# Binding compatibility candidates

`BindingCompatibilityExport::export($root, $files)` is a bounded, source-only
producer for an optional container declaration quality advisory. It reads only
the explicitly selected project-relative PHP files. It never loads an
application Composer autoloader, executes selected PHP, boots Laravel, reads
environment values, or contacts a database.

The exporter finds direct two-argument `bind`, `singleton`, and `scoped` method
call candidates whose abstract is a literal `::class`. A literal concrete
`::class` is compared with uniquely selected class and interface declarations.
Compatibility follows selected `extends` and `implements` edges. Missing or
duplicate declarations, unresolved ancestors, dynamic registrations, and
custom factories stay unknown. Exported registrations, arguments, and selected
declarations retain half-open byte spans and the SHA-256 hash of their source.

An `incompatible-under-selected-declarations` result is advisory evidence under
the selected declaration set. Laravel permits an interface key to be bound to
an unrelated object and does not enforce interface compatibility during
registration or resolution. Later rebinding, aliases, contextual bindings,
extenders, and custom container dispatch may also change effective behavior.
The export therefore sets `runtimeContainerValidated` and
`runtimeFailureClaimed` to `false`; it must not be presented as a definite
runtime error.

This producer does not change Mago types. Native declarations, PHPDoc, existing
container inference, and custom factories retain their current priority. A
consumer should require an explicit opt-in policy before displaying advisory
results and should invalidate locations when the exported content hash no
longer matches the indexed source.
