# Pest expectation forwarding

Laramago corrects Mago's result type for builtin Pest expectation methods that
Mago finds through `@mixin Pest\Mixins\Expectation`. Pest's `Expectation::__call`
runs the mixin assertion and returns the original `Pest\Expectation`, whereas
native Mago otherwise reports the internal mixin object. Negated calls return
the original expectation; `each` and higher-order calls retain their wrappers.
Native Mago still checks the mixin method's arguments.

This applies only when the installed `Pest\Expectation` and forwarding wrapper
methods match complete AST fingerprints from Pest 3.x commit [f108313b](https://github.com/pestphp/pest/tree/f108313b52e8c28dc7121ce34303f817a3790202)
or Pest 4.x commit [5b2293f](https://github.com/pestphp/pest/tree/5b2293f67adcf1b2320b33f521b94a692d18f360). The source is read as
syntax; Pest and application code are never loaded. Exact native methods,
PHPDoc pseudo-methods and direct wrapper declarations retain priority.

The provider does not publish names from `PestExpectationCatalog`: its selected
source declarations are positive evidence, not proof of the effective runtime
extension registry. Unknown methods, dynamic extensions, delegated value-object
methods, property-based higher-order entry, and unrecognized Pest versions
remain under native Mago analysis.
