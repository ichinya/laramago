# Native translation choice references

Native `trans_choice()`, `Lang::choice()` and calls on an exact `Illuminate\Translation\Translator` receiver can report `laramago-missing-translation` for literal keys and nonempty literal requested locales. This is catalog absence, not proof that a runtime callback fails or that plural syntax is invalid.

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

Helper calls additionally require the actual `trans_choice` and `app` declarations
to come from Laravel's Foundation helpers and match their audited signatures,
PHPDoc and forwarding bodies. A replacement helper or changed forwarding disables
the helper check. Native, fully qualified and imported function calls are supported;
unqualified namespaced calls use Mago metadata to check for a local replacement
before applying global fallback. Positional and named arguments share the native
choice parameter mapping. Custom dependency directories are supported.

```php
trans_choice('cart.items', 2, locale: 'en');

use function trans_choice as pluralize;
pluralize(key: 'cart.items', number: 2, locale: 'en');
```

Omitted, null, empty, dynamic or unasserted locales do not produce this warning.
The helper uses the same exhaustive `choice-locales` assertion as native methods;
an ordinary `locales` fallback list alone is insufficient.

This stage deliberately does not enforce replacement completeness: Laravel permits omitted placeholders and inserts `count` during choice. It does not select plural branches, validate custom selectors, or establish runtime translator state. Missing catalog contracts continue to produce no extension warning.

Validation: `php -d opcache.enable_cli=0 tests/translation-choice-references.php` uses genuine Mago with native and modified source fixtures, helper/facade/concrete receiver calls, native argument diagnostics, PHP and JSON fallback presence, namespaced catalogs, malformed JSON, explicit contract absence/malformation, helper shadowing and custom dependency directories. Existing `tests/translation-references.php` covers regression of `get()`.
