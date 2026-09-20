# Literal validation rule parameters

Set `extra.laramago.validation-rule-parameters.native` to `true` in the analyzed project's `composer.json` only when its validation entry points use the installed native Laravel validator methods and its `requireParameterCount` implementation. This is an assertion about the effective validator, including any custom resolver or subclass. Without it, Laramago leaves parameter diagnostics to native Mago and application tests.

```json
{
  "extra": {
    "laramago": {
      "validation-rule-parameters": { "native": true }
    }
  }
}
```

With this assertion, direct literal rule maps in native `Factory::make()` / `validate()` and `Validator::setRules()` / `addRules()` / `sometimes()` receive a warning when a built-in rule has fewer parameters than its installed native method requires. The minimum comes only from an unconditional first-statement `requireParameterCount()` call in the installed `ValidatesAttributes` trait. The parser's CSV form is checked and used, so quoted commas count as one parameter. Examples include `min`, `max`, `size`, `between`, and `required_if`. `in` has no native minimum and is accepted without parameters.

The installed source may come from `laravel/framework` or standalone `illuminate/validation`, including a custom Composer `vendor-dir`. Composer's reported package version is metadata; the native method body, parser, and trait contracts determine whether this capability is available.

The warning says what the native method requires **when run**. Laravel can skip a rule for absent or otherwise non-validatable input, so this is not a claim that validation will throw on every request. Unknown, dynamic and custom rules remain unknown; regex rules and rule objects are handled separately. An unfamiliar or changed native source contract produces no parameter warning.
