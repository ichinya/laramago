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

The focused compatibility tests run the real Mago executable with and without
the extension and retain negative cases. The package's `composer check` includes
them. These policies do not add an analyzer ignore list, issue baseline, or a
PHPStan process to the analysis worker.
