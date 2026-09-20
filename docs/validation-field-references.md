# Cross-field validation references

`ValidationFieldReferences::from($rules)` returns positive source hints from a
literal rules array. `fromValue($field, $value)` accepts a source-proven rule
value for one field, including `Validator::sometimes()`. Each hint contains the
declared field, lowercase source rule name, referenced field, whether the reference
was implicit, and the source string node. A bare `confirmed` rule implicitly
refers to `<field>_confirmation`.

The helper extracts field arguments from selected native rules such as `same`,
`different`, `required_if`, `required_with`, `exclude_if`, and `prohibits`. It
preserves quoted CSV parameters, escaped literal dots, and `*` in the source
reference. Laravel substitutes wildcard keys from the current expanded
attribute at validation time; the helper does not invent an index or flatten a
literal-dot key. Rule arrays keep each string whole; scalar strings split on
pipes. Rule objects, dynamic keys, unpacks, and unsupported rule names remain
unknown. Callers must establish native rule dispatch independently.

A referenced field can exist in raw input without a validation rule of its own.
These hints therefore do not assert a complete input catalog and never report
a missing field. They can power navigation or suggestions from known source
names; absence from the rules map alone is no evidence of a mistake.

The parameter roles follow the [installed Laravel validator](https://github.com/laravel/framework/blob/7c75fbf93f91fa077d3df1c820cc14f4e59a9774/src/Illuminate/Validation/Validator.php)
and [validation methods](https://github.com/laravel/framework/blob/7c75fbf93f91fa077d3df1c820cc14f4e59a9774/src/Illuminate/Validation/Concerns/ValidatesAttributes.php).
