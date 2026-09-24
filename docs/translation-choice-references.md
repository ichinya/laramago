# Translation choice reference candidates

`TranslationChoiceReferenceExport` reads explicitly selected project PHP files
and exports literal keys from calls that PHP-Parser resolves lexically to the
global `trans_choice` function. The result supports navigation, completion, and
external indexing. It is not a missing-translation diagnostic.

A separate [native helper diagnostic](native-translation-choice-references.md)
can check explicit complete effective-locale catalogs after proving installed
helper and translator dispatch through Mago metadata. That stronger analysis
does not change this source-only exporter's uncertainty fields.

```php
use Ichinya\Laramago\Metadata\TranslationChoiceReferenceExport;

$metadata = (new TranslationChoiceReferenceExport)->export(
    projectRoot: '/path/to/project',
    files: ['app/Services/CartSummary.php'],
);
```

The source selector accepts project-relative PHP files only. It never loads the
project Composer autoloader, executes project code, boots Laravel, reads the
environment, or resolves a runtime translation loader.

## Supported references

Direct global calls, explicitly fully qualified calls, and imported aliases are
supported. Named arguments follow the native helper's `key`, `number`,
`replace`, and `locale` parameter order.

```php
trans_choice('cart.items', $items);
\trans_choice('cart.apples', 2, locale: 'fr');

use function trans_choice as pluralize;
pluralize(key: 'cart.pears', number: 3);
```

The exporter preserves the decoded key, key and call byte spans, line numbers,
canonical source path, source SHA-256 hash, bounded count evidence, and the
requested locale argument. Dynamic locales remain explicit uncertainty.
Unqualified calls inside a namespace are excluded because a namespaced function
can take precedence. Dynamic keys, unpacked arguments, invalid named arguments,
methods, and facade calls do not produce references.

Lexical resolution to the global name does not prove that Laravel owns the
function. Laravel declares its helper behind `function_exists`, so another
global implementation can already exist. Every row therefore has
`nativeHelperProven: false`.

## Locale and catalog boundary

Native `Translator::choice()` first calls `localeForChoice()`. That method tests
`hasForLocale()` and selects either the requested/default locale or the fallback
locale. Only then does `choice()` call `get()`. `get()` checks the JSON catalog
at that selected locale before parsing the key and traversing PHP translation
fallbacks.

The metadata records both possible lookup channels and always leaves
`actualLocale.state` as `unknown`. A literal requested locale does not prove
that Laravel selects it. Consequently `runtimeLookupProven` and
`missingNameDiagnostic` remain `false`; consumers must not compare these rows
to an ordinary requested-locale `get()` catalog and report a missing key.

The envelope uses `schemaVersion: 1` and scope kind
`translation-choice-reference-candidates`. It is intentionally non-exhaustive
and reports invalid selections, parse failures, unsupported calls, and bounded
read truncation without returning source contents.
