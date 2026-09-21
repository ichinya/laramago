# Translation source-quality diagnostics

Two optional checks now run inside `vendor/bin/mago analyze`, using the existing bounded translation extractors. They emit Notes, not runtime errors. They are disabled by default and do not execute PHP, load the application, read `.env`, or query a database.

```json
{
  "extra": {
    "laramago": {
      "translation-source-quality": {
        "diagnose": true,
        "equal-placeholder-sources": [
          {"locale": "en", "catalog": "messages", "file": "lang/en/messages.php"},
          {"locale": "fr", "catalog": "messages", "file": "lang/fr/messages.php"}
        ],
        "ascii-replacement-name-files": ["app/Http/Controllers/GreetingController.php"]
      }
    }
  }
}
```

`equal-placeholder-sources` opts into a project convention: corresponding literal messages in the explicitly associated locale/catalog sources should contain the same exact-case placeholder names. A difference produces `laramago-translation-placeholder-parity`, with annotations on both message keys. Identical sets do not produce a note. Associations do not assert active Laravel loader precedence, fallback, or effective application locale. Message values and replacement values are omitted from diagnostic prose.

Both PHP locale files must be included in Mago's analyzed paths, not merely dependency includes. The hook validates source hashes and byte spans against Mago's analyzed snapshots. A missing analyzed source, stale source, dynamic message, or ambiguous locale/catalog association defers the comparison. JSON sources can be exported by the existing CLI, but cannot receive these PHP analysis annotations. Literal PHP duplicate keys follow the extractor's last-write semantics; unknown writes remain uncertain.

`ascii-replacement-name-files` selects PHP call-site sources for the optional `laramago-translation-replacement-name-convention` Note. Literal selected replacement keys outside `[A-Za-z0-9_]+` are highlighted. Laravel accepts arbitrary scalar keys, including names outside this convention. This is a syntactic style convention for translation-shaped calls, not proof of native helper dispatch or ineffective replacement; custom functions with those spellings are subject to the same explicitly selected convention. Numeric keys remain valid. Unknown array writes that can override a key defer that finding. Named and positional call shapes are recognized by the existing extractor.

The options are independent. List sizes are capped at 256; malformed option shapes defer. The exporters retain their file, byte, depth, and output limits. Disabled configuration returns before source extraction. A fresh worker reads the current sources; no persistent catalog cache is introduced.

## Deliberately unresolved behavior

Plural messages are excluded from placeholder parity diagnostics: their branches may legitimately use different names. `trans_choice()` locale selection, fallback chains, loader extensions, custom selectors, plural branch selection, replacement capitalization, and runtime replacement completeness remain unresolved. Missing keys or missing replacement arguments are not inferred from this source-quality policy. Existing explicit complete-catalog missing-key diagnostics remain separate. The `translation-choice` and plural-branch CLI exports continue to supply review metadata without claiming runtime validity.

These checks advance the placeholder consistency and replacement naming items. They do not complete runtime translation or pluralization analysis.

## Validation

`php tests/translation-source-quality-diagnostics.php` invokes real Mago with the complete plugin and checks positive Notes, key spans, both source annotations, equal sets, dynamic values, plural deferral, partial analysis, ambiguous associations, catalog separation, disabled and malformed policies, stale snapshots, and bootstrap non-execution.
