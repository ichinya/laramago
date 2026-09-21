# Policy discovery boundary metadata

`PolicyDiscoveryBoundaryExport` records source evidence around Laravel's native
policy discovery resolver without choosing an effective policy. It reads only
explicit project-relative PHP sources and exports each unique top-level named
class as a possible resolver subject. Conditional and anonymous classes are not
selected.

The schema preserves the resolver order audited from Laravel framework snapshot
`7c75fbf`:

1. exact entries in Gate's runtime policy map;
2. a direct native `UsePolicy` attribute;
3. policy-name guessing;
4. mappings registered for a runtime parent class;
5. native `UsePolicy` attributes found while walking the parent hierarchy.

This order is context, not a static resolution result. Exact and parent policy
maps can be registered or replaced at runtime. `guessPolicyNamesUsing()` replaces
the default naming algorithm, and the default algorithm uses runtime
`class_exists()` probes. The policy object is then created through the container.
A replaced Gate binding can change all of these rules. Every subject therefore
has `effectivePolicy.status: "unknown"`, even when a selected direct attribute is
fully parsed.

## Exported source evidence

Each declaration retains its resolved class and parent names, original class-name
byte span, one-based line, absolute file, and SHA-256 hash. A direct native
`UsePolicy` attribute is `declared`, `absent-in-selected-declaration`, or
`uncertain`. A declared attribute retains the policy class plus the original
attribute and argument spans. Literal strings, imported or fully qualified
`Policy::class`, the native named `class:` argument, and `self::class` are
supported. Dynamic expressions, `parent::class`, `static::class`, invalid
arguments, and repeated direct attributes remain uncertain.

The default guess boundary contains the native probe order and the name returned
when no probe succeeds. These are `conditional-candidates`: the export does not
call `class_exists()`, load Composer application classes, or claim that the
native default guesser is active. Duplicate names remain in the probe order when
the native algorithm would probe them repeatedly.

Selected parent declarations are followed only while every parent has one unique
top-level declaration in the input. The first unselected, ambiguous, or cyclic
parent stops the chain and marks it incomplete. Direct attribute candidates from
selected parents remain separate from the earlier direct-attribute channel.
Selected-source completeness does not prove the runtime hierarchy.

Duplicate selected class declarations remain in `declarations` but do not receive
a `subjects` entry. Parse and read failures remain in `errors`. The exporter never
reads provider mappings; [explicit policy mapping metadata](policy-mappings.md)
and [policy model argument candidates](policy-model-argument-contract-candidates.md)
are independent contracts.

## Limits and use

The export accepts at most 256 files, 1 MiB per file, 8 MiB total source,
20,000 class declarations, and 50,000 default guess candidates. Reached limits
set `truncated` and name the reason. Consumers must retain source errors,
truncation, ambiguous declarations, and incomplete parent chains.

The metadata is suitable for navigation, review, and explaining which resolver
channel may apply. It is not evidence that a policy class exists at runtime, that
a selected mapping is active, or that authorization succeeds or fails. It must
not drive missing-policy diagnostics or replace native class, attribute, or
method diagnostics.
