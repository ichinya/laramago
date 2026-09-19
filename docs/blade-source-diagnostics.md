# Original Blade source diagnostics

`BladeSourceDiagnostics` is a standalone, source-only result API. It consumes
the existing reference scanner, directive checker and component contract APIs
and returns `BladeSourceCheckResult` objects containing `BladeSourceDiagnostic`
values. It does not compile Blade, bootstrap Laravel, execute application code,
register a Mago hook, or add a command-line checker.

Create one `BladeSourceDocument($path, $source)` per original Blade buffer. The
path is the caller's exact file or editor-buffer identity; it is not normalized,
reduced to a basename, read again or replaced with a compiled PHP path. This
supports unsaved editor contents. The caller is responsible for pairing the
identity with the correct buffer and for using consistent catalog snapshots.

Each diagnostic contains `path`, `code`, `message`, `start`, `end`, `line`,
`column`, `endLine` and `endColumn`. Offsets are zero-based bytes and the end is
exclusive. Lines and columns are one-based; columns count **bytes**, not Unicode
characters or LSP UTF-16 units. CRLF counts as one line break; LF and lone CR are
also supported. No tab expansion is applied. A consumer needing another encoding
must convert using the same original buffer. Escaped literal names retain their
raw original spans even when the decoded catalog key has a different length.

```php
$document = new BladeSourceDocument($absoluteBladePath, $originalBuffer);
$consumer = new BladeSourceDiagnostics;
$views = $consumer->references($document, new ReferenceCatalogs($projectRoot), true);
$directives = $consumer->directives($document, $effectiveDirectiveChecker);
```

The `true` argument above is an explicit assertion of native reference directive
and helper semantics, including no overriding custom directive, compiler extension
or precompiler. View checks additionally require the existing explicitly complete
`reference-catalogs.views` configuration. They report only required literal view
references, with code `blade-missing-view`. Optional and conditional references,
translations without proven locale, and class-capable `@component` references
are skipped. A missing catalog name describes the lookup if that expression is
reached; it does not prove that its surrounding branch runs.

Directive checks require an explicitly complete effective
`BladeDirectiveReferenceChecker` and emit only its `missing` references with
code `blade-missing-directive`. Unknown registry state returns `null`.

`livewire($document, $registrations, $conventionalNames,
$conventionalComplete, $effectiveResolverComplete, $nativeReferenceSemantics)`
checks literal `@livewire('name')` and `<livewire:name>` references. It emits
`blade-missing-livewire-component` only when explicit registrations,
conventional names and the entire effective resolver are independently
asserted complete, and native version-specific Blade syntax is asserted.
See [Livewire component references](livewire-components.md) for the exact
boundary. Dynamic names remain unknown.

`requiredClassProps($document, $start, $end, $resolver, $classes, $requiredNames,
$activeOpeningTag)` checks one original isolated opening-tag span. The caller
must establish that the selected tag is active Blade markup, outside comments,
verbatim text or PHP strings. It is not a document tag-discovery API. The consumer
parses the substring itself, resolves the tag through the complete resolver,
matches both class name and declaration path against the supplied class snapshot,
and applies `BladeClassRequiredProps::missingExplicit()`. Only explicit required
names produce `blade-missing-required-prop`, located over that opening tag.
Constructor candidates alone, unknown targets, anonymous targets, incomplete
attributes, stale contracts or an unproven active span produce no such finding.

`null` means the requested check is unknown or cannot be scanned. An empty
diagnostic list is not proof that the whole template is valid.
`sourceScanComplete` describes the bounded scanner's syntax coverage only; it
does not assert catalog, runtime or template completeness. Positive findings
from supported positions can survive an incomplete reference scan.

Mago 1.48.1 can serialize `Issue::at()` with a foreign `SourceLocation`, but the
host rejects lifecycle annotations naming an unregistered source file. The SDK
does not expose registration of arbitrary Blade buffers as analyzed sources.
These standalone results therefore have **no Mago Blade diagnostic hookup**.
No issue is relocated onto an unrelated PHP file. Such integration requires
host-supported Blade file registration and original-content spans first.
