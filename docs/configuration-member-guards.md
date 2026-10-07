# Defensive configuration member checks

Laramago keeps explicit validation of a dependent configuration field when a physically checked configuration reader supplies the record and an earlier literal discriminator selects the validator. The supported field read uses a null fallback, `is_array()` and literal presence keys only to append a validation diagnostic.

This compatibility policy covers otherwise impossible member checks, including an impossible `is_array()` Error for the null fallback. It does not change the inferred value type or accept a business operation with an invalid argument. Unknown producers, dynamic keys, aliases, references, changed records and effectful validation bodies retain Mago's diagnostics.

The native matrix requires six genuine selected diagnostics, all independent Errors and complete residual diagnostic preservation across twenty-one negative owners. Actual SDK predicate and producer contract mutations must veto each correction and restore it when the original contract returns. One and three extension workers produce the same report. No application or fixture body is executed.

Run `php -d memory_limit=512M -d opcache.enable_cli=0 tests/configuration-member-guards.php` to verify the policy.