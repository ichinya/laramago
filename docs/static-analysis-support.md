# Static integration coverage

Laramago reads syntax and Mago metadata without bootstrapping Laravel, executing
application code, or connecting to a database. Each feature below is a bounded
implementation; it does not imply support for every dynamic Laravel behavior.

| Integration | Implemented | Deferred |
| --- | --- | --- |
| Migration preparation | Fresh scalar locals inside Blueprint callbacks, including literal SQL-expression selection through `DB::getDriverName()` | PHP/SQL evaluation, variable column names, reassignments, references, arbitrary calls and conditional schema changes |
| Query predicates | Standard key, membership, null, range and date filters retain the model through query chains; installed signatures and scope precedence | Custom builders/dispatch, arbitrary predicates, SQL validation and property refinement from filters |
| Relation forwarding | Standard relation result, sorting and selected aggregate methods | Custom relations/builders, MorphTo, exhaustive forwarding |
| Relationship callbacks | Four whereHas-family methods, concrete PHPDoc related models and literal dotted paths | withWhereHas, dynamic paths, body-only relation inference |
| Relation names | Existing methods returning a known non-relation class | Missing names that may be registered dynamically |
| Custom builder forwarding | Concrete public builder methods through a model, signatures and fluent results | Generic forwarded methods/classes and custom dispatch |
| Generic builders and collections | Explicit concrete factory PHPDoc arguments, including reordered template parameters | Inferring missing arguments from class-string selectors or late-static templates |
| Authentication | Request user model and nullability from literal standard auth configuration | Auth helper/guard chains, custom drivers, environment and runtime mutations |
| Collection operations | Whole-value filter/null removal for exact Support Collection; model method results through higher-order map on exact Support/Eloquent collections, preserving keys | Other proxy operations, property mapping, custom collections, unrelated application traits and callback/key filtering |
| Advanced casts | Single-return literal Castable factories with concrete caster contracts | Generic interface substitution, conditional/anonymous factories and constructor arguments |
| Factory collections | Custom model collections for counted create/createQuietly/make | Dynamic collection resolution; Laravel's explicit Many methods correctly keep the base collection |
| Container and facades | Concrete class-string facade root types with nullability; native container helper inference verified | Binding implementation discovery, aliases and magic facade forwarding |
| Configuration | Literal helper keys, nested values/shapes, provable defaults and parse warnings | Environment evaluation, repository/facade APIs and runtime mutation tracking |
| Translations and views | Known PHP catalog string leaves with explicit locale; native view overload inference verified | Missing-reference diagnostics, custom loaders/paths, JSON precedence and default locale inference |
| Routes and middleware | Duplicate URI parameters on explicit native Router/Route calls | Facades, named-route registries, middleware resolution and dynamic routing |
| Macros | Explicit opt-in catalogs with typed unconditional literal registrations | Runtime discovery, activation/order proof, conditional registrations and dynamic registry mutations |

Native declarations and explicit contracts retain priority. Unknown cases defer to
Mago rather than receiving universal mixed-property or unknown-method exemptions.
Some conservative refinements intentionally retain additional possible values.

`composer check` runs the real analyzer and worker against isolated synthetic
fixtures. Tests cover both accepted code and diagnostics that must remain, with
execution traps and workspaces containing spaces. Provider-disabled comparisons
verify that selected refinements improve on the installed native behavior.

See the README for individual contracts, [macro catalogs](macros.md) for their
explicit runtime assumptions, and [route parameter validation](route-parameters.md)
for that diagnostic's exact scope. Private application comparisons stay in ignored
development artifacts and are not part of the package.
