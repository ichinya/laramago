# Native translation choice references

`Lang::choice()` and calls on an exact `Illuminate\Translation\Translator` receiver can report `laramago-missing-translation` for literal keys and nonempty literal requested locales. This is catalog absence, not proof that a runtime callback fails or that plural syntax is invalid.

Enable this only when the application can assert every possible effective locale selected by native `localeForChoice()` for the requested locale:

```json
{
  "extra": {
    "laramago": {
      "reference-catalogs": {
        "translations": {
          "complete": true,
          "path": "lang",
          "locales": {
            "en": ["en", "fr"],
            "fr": ["fr", "en"]
          },
          "choice-locales": {
            "en": ["en", "fr"]
          }
        }
      }
    }
  }
}
```

The `choice-locales.en` list is an exhaustive application assertion, not inferred from `.env`, configuration defaults, or the PHP fallback chain. Each listed effective locale must have a valid `locales` entry. Empty, malformed, unknown, or absent associations disable the choice check for that requested locale. A key must be provably absent for **every** listed effective locale before a warning is emitted. Each effective locale checks its own JSON catalog and configured PHP fallback chain; a key present only in the fallback locale's JSON file prevents a warning.

The installed native Translator class, including `choice()` and `localeForChoice()`, must match the audited source contract. Existing facade forwarding, native source location, class documentation and configured translator/loader binding guards apply. Subclasses, custom facades, modified translator bodies, unknown keys/locales, unpacked arguments and first-class callable creation retain native analysis. No application code, translation PHP, bootstrap, environment file or database is executed.

This stage deliberately does not enforce replacement completeness: Laravel permits omitted placeholders and inserts `count` during choice. It does not select plural branches, validate custom selectors, analyze `trans_choice()` helper dispatch, or establish runtime translator state. Missing catalog contracts continue to produce no extension warning.

Validation: `php -d opcache.enable_cli=0 tests/translation-choice-references.php` uses genuine Mago with native and modified source fixtures, both facade and concrete receiver calls, PHP and JSON fallback presence, namespaced catalogs, malformed JSON, explicit contract absence/malformation and dynamic calls. Existing `tests/translation-references.php` covers regression of `get()`.
