# Container injection attribute candidates

`ContainerInjectionAttributeExport::export(string $root, array $files)` reports
Laravel built-in contextual attributes attached to parameters in explicitly
selected PHP sources. The export is syntax metadata for declarations. It does
not infer the value or type that an effective container invocation supplies.

The recognized source names are `Auth`, `Authenticated`, `CurrentUser`, `Cache`,
`Config`, `Context`, `Database`, `DB`, `Give`, `Log`, `RequestAttribute`,
`RouteParameter`, `Storage`, and `Tag` in the
`Illuminate\Container\Attributes` namespace. Imports and aliases resolve
statically. Each attributed parameter retains:

- its named function, method, closure, or arrow-function source location;
- the original native parameter type, resolved native type name, parameter
  flags, callable PHPDoc span, source hash, and byte spans;
- every recognized built-in attribute in source order, plus other parameter
  attributes without class-hierarchy assumptions;
- positional or named argument spans, expression kinds, literal provenance,
  and a constructor-role candidate based on the pinned native declarations.

Argument expressions are never executed. Arrays stay unevaluated, constants
stay constants, and class constants remain source candidates. In particular,
`Give`'s native property is named `class`, but Laravel forwards it as an
arbitrary container identifier to `make()`. A `Service::class` expression is
therefore reported as class-name syntax and never promoted to the effective
injected type.

The top-level scope explicitly records that container invocation, installed
framework declarations, effective contextual handlers, and injected value types
were not validated. This matters for all candidate declarations:

- ordinary direct PHP calls and callbacks may supply their own arguments;
- a custom container may use different resolution rules;
- Laravel's registered contextual attribute handler takes precedence over the
  built-in attribute's static resolver;
- `Tag` can yield no values or heterogeneous values, while `Config`, `Context`,
  request, route, and authentication attributes read mutable runtime state;
- more than one contextual attribute can be written on a parameter, but this
  export preserves source order without asserting which resolver is effective.

Existing native types, PHPDoc, custom analyzer contracts, and Mago diagnostics
remain unchanged. The export installs no type provider and emits no definite
runtime error. Existing Config and Storage reference checks are separate opt-in
policies with their own complete-catalog requirements.

The exporter accepts at most 256 project-relative PHP files, 1 MiB per file,
8 MiB total, and 20,000 attributed parameters. It rejects traversal and
non-PHP sources, deduplicates resolved files, preserves parse/read failures, and
never loads the selected project's Composer autoloader or executes project PHP.
Its schema has `scope`, `selection`, `contracts`, `errors`, `truncated`, and
`truncationReasons` fields. Every contract sets
`runtimeResolutionValidated: false` and `effectiveInjectedType: null`.
