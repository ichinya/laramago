# PHPStan and Larastan compatibility

Laramago implements Laravel contracts on the Mago SDK. PHPStan and Larastan are
comparison tools, not runtime dependencies. Compare the same PHP files at the
same revision: files outside PHPStan's configured paths are additional Mago
coverage, not a regression in that comparison.

## Local mixed assignments

PHPStan's maximum level checks unsafe uses of `mixed`; storing it in an ordinary
local variable does not require a guessed concrete type. Laramago follows that
policy for plain local assignments and non-reference `foreach` bindings. Mago
still tracks `mixed` and reports incompatible arguments, returns, array access,
method calls and property access.

The compatibility filter matches parsed syntax against the exact analyzed
in-memory bytes. It does not remove assignment warnings on object properties,
destructuring, references, global or static variables, or superglobals. Unknown
syntax and parse failures preserve the original diagnostic.

## Explicit casts

Known Eloquent casts determine reads ahead of general `@property` declarations;
explicit `@property-read`, physical properties and typed accessor directions
retain priority. Compatible array casts keep source-certified list items, keys,
shapes and nullable details. A virtual general `float` property with a known
decimal cast accepts its `int|float|string` writer domain. Explicit write tags,
actual setters and physical properties retain their separate contracts. String
storage is not numeric validation: malformed strings can fail a later decimal
read. Unknown casts retain native analysis.

PHP defines conversion to `array` for every value, including objects, raw input
and `null` (an empty array). PHPStan accepts these conversions. Laramago removes
Mago's cast diagnostic only at a verified array cast; array item types stay
unchanged, and an incompatible return or unsafe item access remains an error.

Redundant explicit casts are also accepted, matching PHPStan's default rules.
Boolean conversion of `mixed` and explicit string-to-number casts are accepted
as well. Invalid object casts and array-to-string conversions remain visible,
including an invalid scalar cast nested inside a valid outer array cast.

Nullable or false scalar operands use PHP's defined concatenation and numeric
conversions. Mixed boolean conditions and comparisons are permitted, while
mixed arithmetic, mixed concatenation, invalid nested calls and incompatible
result types remain errors.

The `numeric` type means an integer, a float or a numeric string. Argument and
return compatibility checks accept that type when the complete declared union
permits all three, including numeric fields inside return records. They check
the actual callable metadata and the exact expression span. Narrower numeric
ranges, nullable values, missing fields and unrelated wrong values remain
diagnostics.

In PHP files without `strict_types=1`, native `float` parameters and returns
accept numeric strings. Laramago certifies the physical float declaration,
effective native contract, call binding and current source before accepting
that boundary. Parameter conversion follows the caller's file mode; return
conversion follows the declaring file's mode. Stronger PHPDoc, references,
uncertain dispatch, nullable values and arbitrary strings retain diagnostics.
This policy does not change virtual property write contracts.

Own physical methods may coexist with simple named `@mixin` declarations when
the resolved source tags agree with current native metadata. The callable still
comes from the matching physical declaration. Mixin-only or inherited dispatch,
generic or conflicting tags, unknown targets and changed source defer.

## Raw request properties

Laravel's native `Request::__get()` reads input and falls back to a route value.
An arbitrary input field is therefore a supported read with a raw `mixed`
result. Larastan expresses this through its universal object crate configuration.
Laramago supplies the native readable contract through a property provider,
without implying validation or permitting writes.

Declared properties and PHPDoc remain authoritative; custom getters defer.
Eloquent models do not receive this raw-input contract, so unknown model
attributes and misspelled relationship names still produce diagnostics.

## Array assertion keys

After a verified native `Testo\Assert::array($value)` call, a local variable's
array keys are `int|string`. This replaces the assertion's unusable mixed key
type while preserving known values, records and nonempty constraints. Unknown
items remain mixed and invalid item operations remain diagnostics.

The provider checks the installed assertion, its delegate's builtin array
guard and the never-returning failure method through source and metadata.
Custom or stronger assertion contracts, properties, superglobals, unpacked
arguments and first-class callables defer. This rule does not change keys of
arbitrary iterators or collections.

Consecutive verified array assertions can also narrow literal nested offsets.
Each prefix must already be proven to be a native PHP array by the preceding
assertions. Readonly native `hasKeys()` and `doesNotHaveKeys()` checks may occur
in the chain. ArrayAccess objects, dynamic keys, intervening assignments or
opaque calls, control-flow boundaries and ambiguous cross-file call spans defer.
Reference escapes and unverified successful assertion logging also defer.
Native logging, record constructors and readonly key inspections are checked
for callbacks, hooks and destructors before they can preserve an earlier fact.
The index uses analyzed source snapshots, without executing test or application code.

## Immediate repeated scalar getters

After a verified native `Testo\Assert::notNull()` observes a nullable string
getter, its immediate repeated call can retain the non-null result. The public
getter must only read one ordinary physical field on a final class. Current
source and native metadata must agree for the caller, receiver, getter, field
and installed assertion's scalar-only successful logging path.

This refinement applies to the first evaluated expression of the next statement
in the same lexical block. It does not change the getter's declaration or carry
the fact across calls, assignments, callbacks or other intervening operations.
Magic storage, inaccessible getters, stronger annotations, changed assertion
contracts, incomplete analyzed snapshots and ambiguous fileless spans retain
native analysis. Newly precise incompatible arguments remain errors.

## Defensive array reads

The default Laravel analyzer policy permits this protective boundary pattern:

```php
$data = readPayload();
$text = is_array($data) ? ($data['field'] ?? null) : null;
if (! is_string($text)) {
    throw new \UnexpectedValueException('Expected text.');
}
```

When Mago already knows that the fresh local is `array<array-key, mixed>`,
Laramago omits the redundant array-check warning at this exact verified pattern.
This is a diagnostic policy: native array types, control flow and all error
diagnostics remain unchanged. It does not imply that the fallback is reachable.

The read must use one literal key and null fallbacks, followed immediately by
the native string predicate and builtin throwing constructor. Local provenance,
predicate/exception/caller metadata and current analyzed bytes are checked.
References, mutation or escapes of the local, alternative branches, shadowed
predicates, custom exception constructors, incomplete scans and changed source
retain the native advisory. The policy does not ignore the issue code globally.
`php tests/defensive-array-guards.php --integrated` verifies these boundaries.

## Script include storage


A direct top-level assignment such as `$value = require $path;` stores an
untyped result without claiming how the included file returns. Laramago permits
the native `mixed-assignment` advisory at that plain variable binding. This
bounded policy covers `include`, `include_once`, `require` and `require_once`.
The included file and application bootstrap are never executed for this policy.

The result remains `mixed`. Argument, property, method and array use errors
remain unchanged; an actual native `instanceof` guard supplies its own narrowing.
Top-level function-result assignments, nested or conditional assignments,
references, shared or special bindings, stronger variable annotations and
dynamic symbol-table operations retain native analysis. The filter uses the
SDK's in-memory file and the exact native Warning envelope and variable span.
`php tests/script-mixed-assignment.php --integrated` verifies these boundaries.

## Defensive CLI argument normalization

A direct script binding of `$_SERVER['argv'] ?? []` followed by an array guard,
a by-value loop and an exiting non-string guard can defensively build a fresh
string list. Laramago permits the exact native `redundant-condition` Warning
for that array guard when the current source and native builtin contracts agree.
The input and output types and every native Error remain unchanged.

Unconditional `try` blocks without `finally` are supported. Function scopes,
conditional roots, references, aliases, dynamic bindings, keyed loops and
changed native diagnostic envelopes retain their checks. Consumer bootstrap,
autoload and script bodies are never executed to infer these contracts.
`php tests/argv-normalization-advisory.php --integrated` verifies these boundaries
with complete native reports, genuine metadata controls and worker-count equality.

## Repeated local object documentation

A simple named-class `@var` immediately before a plain local assignment can
repeat the type already inferred by Mago. Laramago permits that exact native
`redundant-docblock-type` Warning when its two annotations certify equality and
match the current type token and variable binding. Import resolution, the
concrete enclosing scope and complete non-template class metadata are checked.
This is an advisory policy; the annotation, inferred type and every native
argument, method and return Error remain unchanged.

Top-level or conditional assignments, references, dynamic or shared bindings,
anonymous scopes, scalar, union and generic annotations, unknown classes and
stronger or contradictory documentation retain native analysis. A missing or
changed native diagnostic or source certificate also retains the Warning.
`php tests/redundant-local-object-docblocks.php --integrated` verifies standalone,
single-file and full-worker behavior with exact complete diagnostic comparisons.

## Repeated object documentation in foreach guards

A simple named-class `@var` before the first method-call `if` in a keyless,
by-value `foreach` can repeat the existing item type. Laramago permits the
native `redundant-docblock-type` Warning only when its single annotation
certifies that equality and matches the current type token and loop binding.
Current imports, the concrete enclosing scope and complete non-template class
metadata must agree. The documentation, types and every native Error remain
unchanged.

The same single-annotation equality policy covers a simple local object tag on
the sole ordinary argument of an immediate `$this` method call after a matching
`instanceof` guard. Root `if` and `elseif` branches are supported. Compounded or
nested guards, intervening statements, named/unpacked arguments and uncertain
or shared variables retain the original diagnostic. Incorrect arguments still
produce their native errors; no type or callable contract is replaced.

References, aliases, shared or dynamic bindings, anonymous scopes, keyed loops,
unknown classes, generic or stronger annotations and changed diagnostic
certificates retain native analysis.
`php tests/foreach-object-docblocks.php --integrated` verifies standalone,
single-file and full-worker behavior with complete diagnostic comparisons.

## Exhaustively checked lists in yielded records

When a fresh decoded JSON list is checked with native predicates, a by-value
loop that throws for every non-string element proves its element type.
Laramago preserves that proof when the list is copied into an optional field
and the fresh record is yielded in a one-element wrapper. Every other field
and its optionality remain part of the complete native containment check
against the current declared iterable value type.

References, aliases, captures, intervening mutations, caught rejection,
incomplete checks, unknown predicates and stronger element contracts retain
their diagnostics. This correction does not supply a universal return type or
accept arbitrary iterable transformations. The source and native decoder,
predicate, exception and enclosing declaration contracts must agree.
`php tests/validated-yielded-lists.php --integrated` verifies actual Mago
diagnostics, exact Error corrections and retained negative cases.

## Documented items in bare Eloquent collections

A native chunk callback can declare `Collection` without generic arguments even
when its source query has one concrete model type. Laramago restores only an
independently documented public property read from an unchanged by-value
`foreach` item. It verifies current query, hydration, collection and iterator
implementations and the model's real property declaration or PHPDoc. Physical
base-model properties and explicitly stronger generic annotations keep priority.
Known casts refine general property reads; explicit `@property-read` tags retain
priority, and directional write contracts remain independent.

The rule does not change the nominal callback declaration, infer unknown fields,
or supply types for model methods. Custom builders or collections, replacing
query receiver chains, mutation, escapes, references, unsupported helper calls,
stale source and ambiguous fileless spans retain native analysis. The type is
supplied at the proven read only and is never memoized across occurrences.
`php tests/contextual-collection-members.php --integrated` verifies these boundaries.
Read-only helpers may use an item property as a dictionary key, including nested
keys; writing the selected array cell does not write the key expression. Direct,
indexed, destructured and foreach field writes, and field references still defer.

## Finite mapper constructor arguments

A native `array_map()` invoked directly with a fresh literal list and an inline
static callback has a bounded input domain. For an explicitly typed `string`
formal forwarded as the first constructor argument, every nonempty string
literal is checked against both that formal and the real consumer's documented
`non-empty-string` parameter. Existing constructor argument checks remain active.

The callback's native metadata is obtained through an optional engine-coordinate
lookup and independently validated against the complete current callback source,
signature and locations. An unavailable or changed lookup format defers. The
coordinate never supplies an inferred type. Included constructor declarations
are read without executing them, preserving their native/PHPDoc contracts,
defaults, references and argument binding. Empty or unknown inputs, captures in
explicit closures, escaped callbacks, mutation before the selected value and
unsupported stronger annotations retain the original report.

## Array records and XML children

Documented return records may contain additional string keys when every
required field and its value type are proven. A narrow return filter verifies
the actual Mago metadata and each known value instead of treating an unknown
array as a valid record. Missing, optional or incorrectly typed required fields
remain errors. Argument records and direct `$this` property assignments also
permit extra keys, recursively checking required fields and generic map keys.
Property hooks, closure assignments, unpacked calls and unsupported type syntax
defer to Mago.

Named PHPDoc array aliases are resolved from Mago's class metadata, with bounded
recursion and cycle checks. An unresolved alias retains the native diagnostic.

Dynamic reads on the native `SimpleXMLElement` class have a
`SimpleXMLElement|null` contract. A missing child can be an empty proxy, and a
further child access on that proxy can be null. This keeps unsafe nested access,
`count()` and non-null returns checked. The SDK cannot currently distinguish
root elements from empty proxies, so some first-level reads still need a guard.
Subclass and declared-property contracts are left to the native analyzer.

## Inherited PHPDoc contracts

Native Eloquent `$fillable` and `$hidden` properties accept `list<string>`
PHPDoc, matching Larastan's model stubs. The filter verifies the original
framework trait declarations and their inherited metadata. Wrong item types,
non-list keys, custom parent overrides and native property changes retain
their diagnostics.

For a documented `Collection` return implementing a documented `Enumerable`
return, an `array-key` parent key permits concrete integer or string keys, and
an unrestricted `mixed` parameter permits a more specific type. This follows
PHPStan's benevolent `array-key` and mixed comparison. The installed Collection
must map its templates to Enumerable unchanged. Other parameters still obey
the declared variance; explicit parent bindings and unsupported mappings defer.

PHPStan's level `max` does not enable `reportMaybesInMethodSignatures` by
default. Laramago accepts a narrower child PHPDoc parameter when its type is
contained by the parent's documented type and the native child parameter.
Native parameter contravariance remains required. Reference parameters,
method templates, `never` types and explicitly bound generic parents defer. An unbound
direct parent's explicit `mixed` template default is supported. Calls to the
child still use its documented parameter type and reject invalid arguments.

## Non-falling-through retry loops

A final unconditional `for` loop cannot reach the end of its callable. A
bounded syntax check removes the corresponding missing-return diagnostic,
including retry loops that return from `try` and continue after `catch`.
Finite or conditional loops, escaping control flow, generators and unsupported
constructs defer. Wrong return expressions keep their own diagnostics.

## Regression checks

Other semantic providers preserve record types through `array_replace`,
interpret PHPStan's `Collection|Item[]` shorthand, and apply Laravel's SQL
aggregate contracts (`sum` is numeric; `avg` and `average` are also nullable).
Native `abort_if` and `abort_unless` establish the condition that must hold when
execution continues. Native `collect()` generalizes concrete array keys to
`int` or `string`, preserving their item types. Nested `where` and `orWhere`
callbacks on standard relations receive the related Eloquent builder. Each
provider retains ordinary argument and return checks.

Native Eloquent `chunk`, `chunkById`, `chunkByIdDesc` and `orderedChunkById`
callbacks receive the model's hydrated Eloquent collection, including a proven
custom collection. Verified framework bodies and declarations determine when
the override applies. Untyped callback parameters receive the model generics;
Mago currently keeps the generic defaults of an explicitly declared bare
`Collection` parameter inside the callback body. Those remaining diagnostics
are preserved. Changed implementations, custom builders and explicit model
PHPDoc keep their own contracts.

Literal `selectRaw()` projections support `COUNT(*) AS alias` and
`SUM(known_numeric_column) AS alias` on a complete fluent Eloquent query.
Count permits integer or numeric-string driver values; sum additionally permits
float and null. Model casts, accessors, scopes, custom query behavior, dynamic
SQL, bindings and builder variables defer. Since the SDK return context omits
the caller filename, the provider uses all analyzed source snapshots and rejects
colliding call spans across files. Its separate plugin retains invocation spans
without changing memoization for other providers.

Authentication can use native literal `env()` defaults and the current process
environment when no applicable dotenv file, configuration cache, environment
path customization or source-level environment mutation makes that result
uncertain. This reads syntax and process data only. A dotenv file is not loaded,
and application bootstrap, configuration and providers are not executed.
Explicit auth metadata still takes priority; unsupported environments defer.

Native output parameters of `preg_match`, `preg_match_all` and `proc_open` may
initialize a fresh local variable. Their reference-initialization advisory is
removed only at a verified builtin output argument. A prior `mixed` value is
also accepted because these parameters are overwritten. Undefined reads,
ordinary references and unsafe command arguments remain checked.

Stringable objects passed to native plain string parameters are accepted at
weakly typed call sites, following PHP's implicit conversion. A strict call
site, a nullable value, a non-Stringable object or a narrower PHPDoc constraint
continues to produce its original diagnostics.

Weakly typed return contracts also accept concrete Stringable values, including
values nested in validation-rule arrays, matching PHPStan's return comparison.
Every value and key is checked against the complete documented return type and
the native return declaration. The native object types are preserved: array
elements are not converted at runtime. Strict files, nullable or mixed values,
invalid keys, missing fields and narrower string constraints retain diagnostics.
Closures, reference returns and unsupported type syntax defer.

Native Eloquent `newCollection()` with no arguments or an explicit empty array
returns the receiver model's collection with integer keys. Verified custom
collection classes retain their declared type. Populated arrays, custom factories,
modified collection resolvers and unknown cache declarations retain native analysis.
Custom trait reuse, template constraints, defaults and model PHPDoc mappings
also keep their original contracts.

An object `@var` on a direct assignment or an ordinary by-value `foreach` header
may repeat the inferred type when the native equality advisory, current source,
caller and concrete class metadata agree. This advisory policy leaves the type
unchanged and preserves every usage Error. References, aliases, selected writes,
captures, stronger annotations and unsupported scopes retain native diagnostics.

The focused compatibility tests run the real Mago executable with and without
the extension and retain negative cases. The package's `composer check` includes
them. These policies do not add an analyzer ignore list, issue baseline, or a
PHPStan process to the analysis worker.

Ordinary unannotated local assignments and by-value foreach bindings may store
`mixed` under PHPStan's default policy. Laramago removes only the source-certified
assignment advisory, preserving native types and all unsafe-use Errors. Stronger
annotations, references, captures, shared storage and dynamic bindings defer.

Verified native literal `env()` calls with an explicit scalar default declare
its generalized type, following Larastan. Literal defaults are widened, and
calls without a default retain `string|bool|null`. Dynamic keys, unsupported
defaults and modified helpers defer. Static configuration indexing continues
to retain the broader native environment possibilities.

Eloquent factory declarations may use a general `@var string` for their protected
`$model` field when its literal model class agrees with the factory's single
generic model argument and the installed parent `class-string<TModel>` contract.
Laramago checks the current declaration, native metadata, inherited traits and
known storage changes before accepting this declaration. This policy preserves
the property types and all use-site diagnostics. Different models, missing
generics, native property types, writes, shadows and references retain diagnostics.

The false operand policy matches complete native warning envelopes and exact
source operand spans. Its bounded grammar accepts only false/integer ordered
comparisons with an integer literal or an unmodified native integer parameter,
and false/string concatenation with literal string peers. Comparison uses PHP
boolean comparison semantics; the policy does not reinterpret false as numeric
zero. Nullable, mixed, object, resource and unsupported numeric domains defer.
It changes no expression types and keeps every native Error.

Contextual Eloquent property advisories can use exact-file provenance when a bare
chunk collection loses its model argument. The property provider retains global
span collision checks; the advisory filter independently binds the warning to
the current property fetch and a concrete documented declaration. Unknown fields,
changed declarations and unsafe uses retain native diagnostics.

Validated array contracts preserve native types while recovering facts established
by exhaustive lexical guards. Only proven scalar fields of unchanged list rows
reach a source-bound inline `usort()` comparator and its direct documented return.
The default lexical policy follows PHPStan; it does not claim arbitrary runtime
alias safety. References, incomplete validation and mismatched contracts defer.
Literal top-level `getopt()` selection guards use the same native binding checks.

Guarded generic-object string casts have two explicit source-bound policies:
PHPStan's declared `__toString` callable-method guard, and Larastan's benevolent
native Request route conversion with literal parameter/default strings. Complete
native envelopes, full cast spans, actual builtin contracts and physical caller
and framework declarations are required. Object alternatives remain unchanged.
Magic-only callability does not establish runtime stringability; concrete bad
objects, unprotected casts, references and custom implementations retain Errors.
See [guarded string casts](guarded-string-casts.md) for the complete boundaries.

Renamed override parameters follow PHPStan's declaration reporting policy after
their physical declarations and native parameter variance agree. The adapter
changes only the name advisory; native signature and call Errors remain.
A potential named call using the parent's parameter name keeps the advisory.
Unknown unpacking, incomplete source scans and unsupported declarations defer.

Generic object operands in `&&` follow PHPStan's boolean reporting policy.
Only the exact native object coercion Warning at a matching source operator is
omitted. The adapter does not assume an object is truthy or change its type;
arithmetic errors and other operand advisories remain unchanged.

[Source argument contracts](source-argument-contracts.md) support a guarded,
bounded decimal conversion for `usleep` and PHPStan's declared `?string`
directory contract for `proc_open`. Both bind current source and native
metadata while preserving inferred argument and result types.

Repeated zero-argument getters follow PHPStan's default remembered-call policy
after an adjacent successful `instanceof` check. Current physical getters,
fields and native declarations are required. A literal `method_exists` fact on
`$this` also applies to an unchanged immediate arrow stored by a verified
callback method. Final owners, rebindings and unsupported storage defer.
Concrete method argument and return Errors remain active.

The default PHPStan reporting policy omits exact deprecated-method Warnings for
the supported Request `get`, coverage `forLineCoverage`, and native PHP 8.5
ReflectionMethod `setAccessible` profiles. Signatures and deprecation metadata
remain native. `DeprecatedMethodCompatibilityPlugin` accepts
`preserveAdvisories: true` to keep these Warnings.

Defensive boundary checks on verified configuration, Request validation,
CLI arguments, lists and fixed binary offsets omit their exact native
advisories. This is an intentional reporting policy: it changes no value types
or control flow and makes no runtime purity claim. Unknown producers, custom
bindings, references and business flags defer. Set `preserveAdvisories: true`
on `DefensiveBoundaryGuardPlugin` to retain its Warnings.

[Literal argument closures that capture boolean references](argument-closure-possible-writes.md)
follow PHPStan's possible-write scope policy. Closed source lifetimes and native
predicate/assertion contracts support a reachable boolean write, including a
write before a throw, without claiming callback execution. Aliases, unknown
writes, stored closures and incompatible assertion domains retain Errors.

Guarded null flow follows PHPStan's default remembered-expression and PHPDoc
certainty policies. Exact current guards, postconditions, native receiving
contracts and selected storage lifetimes can rule out null at a use. A single
non-nullable producer protected by a catch flag needs no purity assumption.
Conditional collection assertions use their actual native assertion targets.
Missing members, unrelated references, rebinding, alias writes and incompatible
receiving types retain diagnostics. Native expression types remain unchanged.

Transaction wrappers preserve a caller's scoped template when a documented zero-argument `Closure(): T` formal is returned through an otherwise untyped inline callback and verified native Laravel facade, manager, and transaction implementations. The adapter preserves stronger callback declarations and defers for reference capture, rebinding, fallback branches, changed native dispatch, or configured container overrides. No callback or application code is executed.

Literal stored callbacks may write a referenced cell after another callback has been defined. A closed source lifetime and independently bound physical callback fields preserve that possible integer write when checking an `is_int` guard and subsequent addition. This adapts PHPStan's reference-scope policy without assuming callback scheduling or constructing opaque closure identifiers. It retains incompatible physical inputs/results, reference aliases, rebinding and stale source declarations; integer overflow remains `int|float`. A mixed-assignment advisory is omitted only for the same otherwise untyped captured cell and validated writer contract.

[Closed configuration arguments](closed-configuration-arguments.md) preserve a string-key array domain for a literal configuration callback only when every lexical invocation supplies a closed string-key array. The receiving declaration remains native, and unknown values do not become precise types.

[Framework default date arguments](default-date-arguments.md) recognize the concrete default date constructor at a direct `now()` argument through current native Laravel dispatch declarations. This is an explicit offline framework-default policy. Class, callable, factory, facade and container replacements retain diagnostics; the general `CarbonInterface` return contract remains unchanged.

The native DatabaseManager `Macroable::__call as macroCall` alias also preserves transaction wrapper templates under the explicit offline framework-default macro policy. Current physical trait declarations, the empty native macro-table default and unchanged `hasMacro` dispatch are required. Visible macro, facade or connection replacements, transaction registrations in explicit macro catalogs, unknown catalogs, and changed native metadata retain the original diagnostics. No runtime macro state is queried.

[Declared framework compatibility](declared-framework-compatibility.md) binds Eloquent coalesce probes, collection offset setter and scoped accumulator declarations, console option contracts and backed setter-only property reads to current source and native metadata.

[Repeated refreshed model values](repeated-refresh-values.md) restore a source-bound primitive attribute domain for one exact native no-value argument after two unconditional refreshes. This is a model refresh compatibility policy, with current native owner-field and receiving contracts; it does not reinterpret unrelated never expressions or modify native model types.

[Bounded defensive compatibility](defensive-boundary-compatibility.md) keeps native expression types and receiving errors while handling certified captured-state, configuration and response advisories. Native array-key aliases also support array callers in the existing deprecation policy. Literal local Laravel application bindings can carry a verified stored method-existence guard; aliases, reference writes and unknown bindings defer. Installed-package container defaults use current physical registration and discovery declarations after application binding catalogs have taken priority.

Typed `chunkById()` callbacks retain a collection of the selected model when the source and native declarations agree. The regression matrix preserves diagnostics for aliases, references, mutation, custom dispatch and stronger PHPDoc contracts.

A fresh, unchanged Eloquent query can certify the reporting policy for selected nullable Collection index warnings and an immediately following defensive model check. The rule requires current source and native declarations, array storage, and an uninterrupted local lifetime. It preserves undefined-key diagnostics and Errors.

A successful read of the same literal Request header can establish the scalar string branch for an unchanged receiving argument. Full source/native declaration and receiver lifetime proofs preserve unsafe, stronger documentation, reference, mutation and unrelated argument Errors.

A standard hydrated chunk collection can preserve a declared zero-argument model method through an unchanged read-only receiving helper. Native argument and return Errors, aliases, references, custom dispatch and stronger contracts remain authoritative.

[Declared and captured postconditions](declared-captured-postconditions.md) preserve closed assertion and callback evidence without changing native types.

[Defensive configuration member checks](configuration-member-guards.md) provide an explicit bounded reporting policy for dependent configuration validators.

[XML cardinality guards](xml-cardinality-guards.md) omit one exact defensive advisory only after current builtin contracts and a literal singleton cardinality check agree.

[Session array-key copies](session-array-key-arguments.md) apply the bounded benevolent-key reporting policy after native metadata and current physical forwarding agree.
