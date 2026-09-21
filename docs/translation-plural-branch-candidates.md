# Translation plural branch candidates

The `translation-plural-branches` metadata kind describes the branch structure
of literal messages in explicitly selected PHP translation sources. It exports
no translated message or condition text. Each candidate retains its message key,
the enclosing literal and key byte spans, source hash, static selection state,
and a list of branch-shape records.

Branch records contain only their index, whether the pinned Laravel
`MessageSelector` prefix pattern recognizes a condition, the single/range
shape, delimiter pair, comma count, and whether the remaining payload is empty,
whitespace-only, or present. A pipe creates a branch exactly as native
`explode('|', $line)` does. Recognizable condition prefixes in a single-branch
message are also included.

This is non-exhaustive, advisory source evidence. Empty branches, mismatched
delimiter pairs, overlapping ranges, incomplete locale forms, extra forms, and
unrecognized prefixes are retained as metadata and are not errors. The export
does not know the effective locale, fallback-selected message, selector object,
or runtime-selected branch. A consumer may apply a separately configured
translation-quality policy, but must not present these candidates as Laravel
runtime failures.

Dynamic messages and JSON translations are outside this mode. Duplicate and
uncertain PHP array entries remain visible through `sourceSelected`. Reads are
bounded to 256 files, 1 MiB per file, 8 MiB total, 20,000 candidates, 20,000
branch records, 32 array levels, and an 8 MiB estimated output budget. Errors
and reached limits are explicit, and source files are parsed without execution.

The direct exporter contract is:

```php
$metadata = (new TranslationPluralBranchExport)->export($projectRoot, [
    'lang/en/messages.php',
]);
```

Its envelope uses `schemaVersion: 1`,
`scope.kind: translation-plural-branches`, and
`scope.semantics: plural-branch-structure-candidates`. The scope explicitly
sets `effectiveLocale`, `selectorIdentity`, and `runtimeSelection` to `unknown`.
