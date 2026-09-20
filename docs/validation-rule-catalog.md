# Installed validation rule metadata

`BuiltinValidationRuleCatalog($projectRoot)` reads the installed Laravel
framework or standalone `illuminate/validation` package without loading PHP.
It honors Composer's `vendor-dir` and reports the installed package version
when `composer/installed.json` supplies it.

`rules()` maps canonical snake names to declared validator methods.
`methodFor($name)` supplies positive matches, including the installed parser's
literal aliases and PHP's case-insensitive method names. `frameworkVersion()`
and `sourcePath()` describe the selected source snapshot. Missing, malformed,
ambiguous or structurally incompatible sources leave the catalog unknown.

The catalog checks declaration namespaces, the validation trait, parser and
dispatch structure, and rule-shaped method parameters. These are structural
checks, not proof of the entire native implementation or runtime behavior.
It does not load custom extensions, prove that a validator is active, or
establish complete rule names. A null result from `methodFor()` must never
alone become an invalid-rule warning. Create a new catalog after source changes.

The method and alias conventions follow Laravel's
[validation parser](https://github.com/laravel/framework/blob/7c75fbf93f91fa077d3df1c820cc14f4e59a9774/src/Illuminate/Validation/ValidationRuleParser.php)
and [attribute validation methods](https://github.com/laravel/framework/blob/7c75fbf93f91fa077d3df1c820cc14f4e59a9774/src/Illuminate/Validation/Concerns/ValidatesAttributes.php).
