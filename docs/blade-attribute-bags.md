# Blade attribute bag partition metadata

`BladeAttributeBagPartitioner` accepts an already parsed, complete list of final
attribute keys. For an anonymous component with one source-proven literal
`@props([...])` directive, `anonymous()` maps exact prop names and their
`Str::kebab` aliases to declarations. For a proven class component,
`componentClass()` maps keys through Laravel's `Str::camel` rule to declared
constructor parameters. Each method returns `BladeAttributePartition`, with
`propAttributes` (input key to declaration name) and `bagAttributes` (ordered
remaining keys). This is reusable names-only metadata, not a rendered bag.
When two declarations share a kebab alias and that alias is supplied, the
partition is unknown; Laravel removes the key by list membership, but a unique
declaration association cannot be proven. The mapping is not a claim about
runtime variable assignment: `compileProps` uses the raw input key for
`$$__key`.

Pass every post-parse key, including `class`, `style`, `disabled`, `x-data`,
`@click`, and escaped `:class`. The shorter name list used for required-prop
checks is not suitable here because it may omit keys that remain in the bag.
The caller must establish the resolved component and complete final key list;
both APIs return null when that proof is absent. The class API also returns null
for an unknown constructor, and the anonymous API requires a parsed `@props`
directive. Uncertain Unicode class attribute conversion is left unknown.

Laravel's `compileProps` removes matching incoming keys from the anonymous
bag and applies keyed defaults to variables with null coalescing. A default does
not create an attribute or make a prop required. Class tags partition constructor
data before creating the component. Attribute values, `@class`/`@style` and
`$attributes->merge()` results are outside this names-only snapshot: native bag
merges append `class`/`style` and preserve ordinary incoming attributes over
defaults. Boolean attributes are preserved as keys here; rendering decides
their HTML output. Arbitrary extra HTML, Alpine and framework attributes are
not reported as invalid.

This API does not parse Blade tags, evaluate PHP or Blade, infer values, add
diagnostics, or prove custom resolver behavior. See
[anonymous components](blade-anonymous-components.md),
[class components](blade-class-components.md), and
[component tag resolution](blade-component-tags.md) for the input contracts.
