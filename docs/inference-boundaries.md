# Static inference boundaries

Laramago uses syntax and analyzer metadata. An unsupported case keeps its native
type and diagnostics; support is not extended by evaluating application PHP,
environment values, container factories or database queries.

## Query and guard state

The installed Mago SDK's `Invocation` carries receiver type, arguments, declaring
class and a byte span. `ReturnTypeProviderContext` has no source-file identity or
receiver expression. A return provider therefore cannot reconstruct the identity
and mutable history of an arbitrary query builder from its type alone.

This limits inference for `select`/`addSelect` chains, aggregate aliases such as
`withCount`, and variables holding differently configured builders of the same
class. Caching state by class name or globally matching byte offsets would leak
information between unrelated queries or files. `NodeAnalysisContext` supplies
source syntax after file analysis, too late to change that invocation's inferred
return type. These features need suitable engine support for expression identity
and state propagation before a provider can safely refine them.

Standard authentication guards present a related limitation: their declared
`user()` result is `Authenticatable|null`, and they do not declare a model type
parameter. A previously selected guard name is not available in a later user
invocation. Explicit authentication metadata can describe known entry points;
custom guards can expose a more specific native or PHPDoc user contract.

## Generic magic calls

`CallableSignatureProviderContext` is requested before argument expressions are
analyzed; argument type fields are null at that stage. `EffectiveCallableSignature`
transports effective parameters, named-argument policy and a display name, but
does not transport method template declarations.

Concrete class-template bindings can be substituted into supported parameter and
return containers. Arbitrary forwarded method-template inference cannot be
replaced by guessing types from argument spelling. Generic ancestry can sometimes
be recovered from verified source declarations, as in supported cast contracts;
unresolved mappings still defer.

Magic forwarding also does not establish caller-reference semantics. A native
method receiving references is not automatically a valid reference contract for
an outer `__call` or facade invocation that collected ordinary argument values.

## Application contracts and mixed values

Some mixed values come from application declarations. A bare `array` return does
not describe tuple elements, and a callback wrapper returning `mixed` needs an
explicit template contract to preserve its callback result. PHPDoc tags should
have separate lines; a second tag embedded in the text of the first tag may not
be parsed as a declaration. `tests/mixed-reproductions.php` verifies these cases
with both native Mago and the extension.

A cache, session or input default describes the fallback branch only. It does
not constrain an existing stored value. Similarly, validation checks accepted
representations without necessarily converting them: integer, boolean and `in`
rules are not casts. Unknown external data needs validation or an explicit
application contract at its boundary.

The [coverage matrix](static-analysis-support.md) records concrete supported
subsets. Passing synthetic cases establishes those contracts; differences between
analyzers or lower diagnostic counts alone do not prove every remaining finding
is a false positive.
