# Policy call contracts

An optional analyzer check connects native Gate calls to explicitly selected policy methods. It reports `laramago-policy-call-contract` during ordinary `mago analyze`; the metadata export command is not required.

```json
{
  "extra": {
    "laramago": {
      "policy-call-contracts": {
        "diagnose": true,
        "native-dispatch": true,
        "authenticated-user": true,
        "no-intercepting-callbacks": true,
        "policies": {
          "App\\Models\\Post": "App\\Policies\\PostPolicy"
        }
      }
    }
  }
}
```

These settings are application assertions, not discoveries. The exact model-to-policy map must describe the effective runtime selection for the checked calls, including discovery callbacks, attributes, registration order and container resolution. `native-dispatch` asserts standard Laravel Gate invocation without replacement; `authenticated-user` excludes guest short-circuiting; `no-intercepting-callbacks` asserts that Gate callbacks do not bypass policy invocation. Leave the check disabled when these assumptions do not hold. Mapping keys are exact class names; subclasses need their own entry. This does not fix the application's Auth model or environment.

For `Gate::allows('update', [new Post(), new Team()])`, the check skips the separately supplied user and compares literal object arguments with native policy parameter classes using Mago's type comparator. A wrong model or additional object emits a warning. Literal scalar/null values supplied to a required object parameter are checked too. For `Gate::allows('create', [Post::class, new Team()])`, the class selector is removed before aligning parameters. Missing required parameters are reported, while optional parameters may be omitted. Hyphenated abilities use the existing Laravel method-name normalization.

Visible policy `before` or magic methods, container replacements, explicit Gate definitions, changed native signatures, incomplete class hierarchies and unsupported receivers suppress the check. Chained instance calls such as `$gate->forUser(null)->allows(...)` defer. Existing declaration notes remain separately controlled by `policy-method-declarations`.

The bounded implementation accepts direct `new Model()` or `Model::class` selectors and positional literal arrays without explicit keys, references or unpacking. Native object-only unions and nullable object parameters are checked when every named class or interface has a complete indexed hierarchy. A null argument satisfies a nullable contract but does not make that parameter optional; omitted required nullable parameters still warn. For example, `Team|Other|null $team` accepts either object or null and rejects an unrelated literal object. Variables, dynamic abilities, intersections and unions containing scalar alternatives defer. Native scalar parameter checks are intentionally omitted because Laravel invokes policy methods with weak scalar coercion. PHPDoc narrowing does not become a runtime argument error. Variadic and reference policy signatures defer; excess arguments are not diagnosed. The user argument's type, authorization result, runtime registry completeness and automatic discovery remain outside this check.

All metadata is read statically. No application bootstrap, environment file, policy body or database is executed. Run `php tests/policy-call-contracts.php` for the real-Mago dispatch and negative regressions.
