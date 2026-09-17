# Static integration coverage

Laramago reads syntax and Mago metadata without bootstrapping Laravel, executing
application code, or connecting to a database. Each feature below is a bounded
implementation; it does not imply support for every dynamic Laravel behavior.

| Integration | Implemented | Deferred |
| --- | --- | --- |
| Migration preparation | Fresh scalar preparation/literal locals plus bounded flat-list foreach declarations inside Blueprint callbacks; known names, nullable flags, renames and drop lists | PHP/SQL evaluation, dynamic values, expired locals, references, nested/keyed loops, arbitrary calls and conditional schema changes |
| Query predicates | Standard key, membership, null, range and date filters retain the model through query chains; installed signatures and scope precedence | Custom builders/dispatch, arbitrary predicates, SQL validation and property refinement from filters |
| Selected query fields | Direct model value/pluck with raw key coercion and exact-table qualification; literal terminal aliases through object intersections | Stateful builder projections, aggregate properties and SQL expressions; raw alias values do not inherit source casts |
| Relationship methods | Ten standard factories, application-trait declarations with lexical self/late static, native using/as pivot contracts and timestamp/trashed-parent modifiers | Explicit PHPDoc remains authoritative; unresolved model/trait generics, dynamic MorphTo, arbitrary chains and custom factories defer |
| Relation forwarding | Standard relation result, sorting and selected aggregate methods | Custom relations/builders, MorphTo, exhaustive forwarding |
| Relationship callbacks | Four whereHas-family methods with documented or inferred relation types; literal dotted paths; withWhereHas Builder/Relation union | Dynamic paths, arbitrary eager-load callbacks, parameterized relations and custom dispatch |
| Relation names | Existing methods returning a known non-relation class | Missing names that may be registered dynamically |
| Custom builder forwarding | Public builder methods with explicit direct class template bindings, substituted parameters/results and generic fluent returns | Method templates, generic ancestor remapping, reference signatures, unresolved containers and custom dispatch |
| Generic builders and collections | Explicit factory PHPDoc arguments, reordered template parameters and builder factory late-static arguments | Inferring missing arguments from class-string selectors and unbound generic contracts |
| Authentication | Nullable default model for Request/helper/facade users; explicit Composer auth contracts for environment-dependent model selection and custom guard classes | Standard selected guard users keep native Authenticatable contracts; environment values and runtime mutations are not evaluated; custom guard chains keep declared user contracts |
| Validated request fields | Literal rules refine top-level/nested open shapes and dotted keys, explicit array ancestors, conservative wildcard arrays, literal Rule::in and supported date formats | Raw/magic request properties, dynamic/unknown rules, overlapping wildcard paths, custom pipelines and callable defaults; no validation casts or cardinality guarantees |
| Collection operations | Whole-value null filtering on standard collections; known model/required-shape property maps, model unions with zero-argument methods and literal-property min/max/sum | Custom subclasses, callback/key filtering, unsafe input branches, optional direct shape access and union method calls with arguments; aggregate null/overflow semantics are retained |
| HTTP test assertions | Laravel and optional Laratesto Inertia/JSON callbacks, nested fluent scopes, native Inertia page envelopes and flash assertions | Arbitrary response macros/helpers, selected props and JSON value shapes; Laratesto envelope inference requires zero arguments |
| Advanced casts | Single-return literal Castable factories; ancestor/interface bindings with nested generics, lists, array maps/shapes and unions | Unsupported type syntax, trait/method templates, ambiguous bindings, conditional/anonymous factories and constructor arguments; generic constraints use native declaration validation |
| Factory collections | Custom model collections for counted create/createQuietly/make | Dynamic collection resolution; Laravel's explicit Many methods correctly keep the base collection |
| Container and facades | Concrete facade roots/public forwarding plus opt-in static bind/singleton/alias catalogs for helpers, native make and facade accessors | Uncataloged runtime bindings, contextual/mutating registrations, generic/reference contracts and custom dispatch; explicit contracts retain priority |
| Configuration | Literal helper/native Config facade keys, nested values/shapes, provable defaults and parse warnings | Arbitrary repositories, environment evaluation and runtime mutation tracking; cataloged core-service replacement disables the static index |
| Translations and views | Known PHP/JSON strings with explicit or literal configured initial locale and JSON precedence; native view overload inference verified | Missing-reference diagnostics, dynamic locales and custom loaders/paths; cataloged config replacement disables initial-locale inference |
| Routes and middleware | Duplicate URI parameters on native Router/Route calls and lexically resolved native Route facade imports/aliases | Runtime aliases, custom dispatch, named-route registries, middleware resolution and dynamic routing |
| Macros | Opt-in catalogs with typed closures, static callable arrays, invokable objects and bounded boolean/ordered hasMacro guards | Runtime discovery, activation/order proof, unknown conditions, dynamic registry mutations and reference contracts |

Native declarations and explicit contracts retain priority. Unknown cases defer to
Mago rather than receiving universal mixed-property or unknown-method exemptions.
Cataloged replacement or uncertainty of core auth, validator or config services
disables the corresponding standard-service assumptions.
Some conservative refinements intentionally retain additional possible values.
The [inference boundaries](inference-boundaries.md) explain SDK constraints,
mutable query state and application contracts that remain outside these subsets.

`composer check` runs the real analyzer and worker against isolated synthetic
fixtures. Tests cover both accepted code and diagnostics that must remain, with
execution traps and workspaces containing spaces. Provider-disabled comparisons
verify that selected refinements improve on the installed native behavior.

See the README for individual contracts, [macro catalogs](macros.md) for their
explicit runtime assumptions, and [route parameter validation](route-parameters.md)
for that diagnostic's exact scope. Private application comparisons stay in ignored
development artifacts and are not part of the package.
