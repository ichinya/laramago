# Blade source references

`BladeReferenceParser::parse($source)` reads one Blade source string without
compiling Blade, loading a view, or executing application PHP. It returns a
`BladeReferenceScan` with literal view and translation references. Each
`BladeReference` has a decoded `name`, `kind`, `origin`, `requirement`, and a
half-open `start`/`end` byte span in the original source. The span covers the
contents of the quoted PHP literal, without its quote characters. For escaped
PHP strings, the bytes in that span can differ from the decoded name.

Supported view directives are `@extends`, `@include`, `@includeIsolated`,
`@includeIf`, `@includeWhen`, `@includeUnless`, literal view `@component`, and
the main and empty views of `@each`. Native `@component` class references are
not view references. `@includeIf` is `optional`; `@includeWhen`,
`@includeUnless`, and both `@each` views are `conditional`. Other supported
view references are `required` when their directive is reached. This field
describes native lookup behavior, not whether the surrounding template branch
will run.

Supported translation references are literal `@lang` and `@choice` arguments
and direct `__`, `trans`, and `trans_choice` function calls in Blade echos,
`@php` blocks or expressions, raw PHP tags, and the supported directives'
argument expressions. PHP functions are recognized syntactically; no runtime
helper identity or translation catalog is asserted. Blade comments, HTML
comments, `@verbatim` blocks, escaped directives, and escaped echos are skipped.

`complete: false` means a recognized reference position was dynamic, malformed,
or unsupported. A source over 256 KiB or containing a NUL byte returns `null`.
`complete: true` only describes the supported forms above; custom directives,
other Blade expressions, runtime registrations, and dynamic names are outside
the scan. In particular, literal candidate arrays in `@includeFirst`,
`@extendsFirst`, and `@componentFirst` are deferred because individual names
are alternatives. The scan supplies positive source metadata for consumers;
it does not register Blade diagnostics with Mago. Consumers must use a separate
explicitly complete catalog and native identity proof before reporting a
missing name, and must place any report on the original Blade span.
