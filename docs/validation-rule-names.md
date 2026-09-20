# Validation rule-name diagnostics

The `laramago-unknown-validation-rule` warning checks literal rule names passed to native `Illuminate\Validation\Factory::make` / `validate` and `Illuminate\Validation\Validator::setRules` / `addRules` / `sometimes`. It is disabled unless the application explicitly asserts a complete effective rule-name catalog in `composer.json`:

```json
{
  "extra": {
    "laramago": {
      "validation-rule-names": {
        "complete": true,
        "names": ["required", "string", "email", "company_code"]
      }
    }
  }
}
```

`names` must include every effective built-in and custom rule name that application validation can use, including rules supplied by extensions or a custom validator resolver. If that assertion cannot be made, omit the setting or use `"complete": false`; missing-name warnings are then suppressed. The installed Laravel source supplies positive evidence for built-in names, but absence from that source catalog does not prove a name invalid.

The checker reads only literal array declarations and literal strings. It handles pipe-separated strings, string array elements, named `rules` arguments, and the last value for duplicate field keys. Dynamic field keys, top-level unpacking, unsupported rule spelling, rule objects, and rule lists with keyed or unpacked elements are left to Laravel and Mago. The warning highlights the whole PHP string literal that contains an absent rule name.

The method forwarding checks currently recognize the pinned Laravel framework source revision `7c75fbf`. If an installed Laravel method changes, this diagnostic defers until its new declaration has been verified.
