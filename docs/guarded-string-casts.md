# Guarded string cast compatibility

Laramago preserves native expression types while applying two narrow policies to
the complete generic-object-to-string cast diagnostic.

The first follows PHPStan's declared-method interpretation of
`is_object($value) && is_callable([$value, '__toString'])`. The cast must be inside
that exact successful branch, use the checked variable, and occur before any
intervening evaluation that can change it. Laramago verifies current source,
native caller metadata and the actual builtin predicate contracts, including
is_object's conditional return and its object assertion. References, altered
variables, other callable methods, syntax-only checks and unprotected casts retain
their native diagnostics.

This is an explicit declared-method compatibility policy. A magic-only __call
implementation can make is_callable succeed without making a PHP string cast
safe. The policy does not prove runtime Stringable support. Concrete bad objects
retain their cast Errors.

The second follows Larastan's benevolent conversion of native Request::route
results for a nonempty literal parameter name and a literal string default.
Laramago verifies the exact Request formal, physical framework declaration, native
method projections, documented conditional and builtin helper contracts. Zero
arguments, null/dynamic parameter names, callback/array defaults, named arguments,
receiver writes/references and custom Request implementations retain diagnostics.

Native object, string and null alternatives stay unchanged. These policies do not
introduce a general object-to-string type or suppress unrelated diagnostics.
Every issue evaluation owns its proof state, so SDK reentry and extension worker
count cannot transfer authority between calls.

`php tests/guarded-string-casts.php` analyzes independent fixtures without executing
their bodies or loading their application autoload. It requires six exact native
Error removals, negative native Error witnesses, complete issue preservation,
AlwaysKeep observation, 130 genuine controls with 92 actual SDK cache mutations,
restoration, and isolated/full-registry agreement with one and three extension
workers. Cache-control runs use one analyzer thread; production threading is
unchanged.

Twenty-one negative owners require genuine native Errors. An outside-branch cast
whose native operand remains mixed has no native Error; its refusal is checked
only as a constructed negative after a genuine positive, with that distinction
recorded in the test receipt.
