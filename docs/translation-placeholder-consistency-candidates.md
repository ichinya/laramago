# Translation placeholder consistency candidates

`TranslationPlaceholderConsistencyExport` provides an optional source-review
policy for placeholder differences between explicitly associated locale files.
It never reports a Laravel runtime error. Laravel can intentionally rename or
omit replacements between locales, select different plural branches, use JSON
overrides, and resolve missing messages through fallback.

Call `export($root, $files)` with a list of exact source associations:

```php
[
    ['locale' => 'en', 'catalog' => 'messages', 'file' => 'lang/en/messages.php'],
    ['locale' => 'fr', 'catalog' => 'messages', 'file' => 'lang/fr/messages.php'],
]
```

`locale` and `catalog` are caller-owned non-empty labels. `file` is a contained
project-relative PHP or JSON translation source. The exporter does not infer any
label from directories or filenames. At most one file may be selected for each
locale and catalog pair; a collision is retained as uncertainty and excluded
from comparison because loader precedence has not been asserted.

The version 1 result uses kind
`translation-placeholder-consistency-candidates`. `candidates` contain exact-case
colon-word set differences and source byte spans. PHP messages use literal key
segments for identity. JSON messages use a SHA-256 key identity so phrase text is
not exported. Translation values are never returned. The colon-word subset is
shared with `TranslationPlaceholderExport`; it is not Laravel's complete
replacement grammar.

`uncertainties` separately retain missing locale messages, dynamic values and
array entries, shadowed declarations, plural branches, and association
collisions. A missing message does not become a difference candidate because
fallback and intentional omission remain unknown. Scope flags explicitly deny
inferred locale association, fallback resolution, loader precedence, plural
branch alignment, exhaustiveness, and runtime-failure claims.

The metadata CLI integration uses repeated explicit associations:

```shell
php vendor/bin/laramago-metadata \
  --kind translation-placeholder-consistency-candidates \
  --translation-source en:messages:lang/en/messages.php \
  --translation-source fr:messages:lang/fr/messages.php
```

This kind requires `--translation-source` and rejects ordinary `--source`.
Because `:` separates the three fields, locale and catalog labels containing a
colon must be rejected by the CLI before export. Source paths remain subject to
the exporter's containment and extension checks.
