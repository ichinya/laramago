# Policy model argument contract candidates

`PolicyModelArgumentContractExport` links literal model-to-policy entries from
explicitly selected native `AuthServiceProvider` sources to public methods
declared by the selected policy classes. It reads PHP syntax only. It does not
load the application autoloader, instantiate a policy, bootstrap Laravel, read
environment values, or connect to a database.

The export is intended for navigation, review, and later guarded analysis. A
contract records the policy method's second declared parameter because Laravel
passes the current user first when it invokes a policy method. The original
native type spelling, its resolved name, byte span, source hash, defaults,
reference and variadic flags are retained.

`declarationCompatibility` compares the mapped model object with that native
parameter declaration only:

- `compatible` means the declaration is untyped, accepts `mixed` or `object`,
  or contains the exact mapped model class in its native type.
- `incompatible` is emitted only when every native type member rejects objects,
  such as `int|float` or `array`.
- `unknown` covers other named classes and interfaces because their hierarchy
  is not proven by this bounded export. It also covers `string`, `callable`, and
  `iterable`: Laravel's framework call site uses weak scalar coercion, and
  `Stringable`, invokable, or `Traversable` objects may satisfy those types.
- `not-present` means the public method has no second parameter. The exporter
  does not invent one for a class-level ability.

These states are declaration facts, not authorization diagnostics. Every
contract has `runtimeDispatchValidated: false`. Global or policy `before`
callbacks, guest eligibility, model class-string removal, inherited and guessed
policy discovery, explicit Gate definitions, policy mutation, magic methods,
and container replacement can change whether and how a method is called. A
reported `incompatible` declaration can therefore be valid Laravel code when
the effective dispatch does not pass the mapped model object to that method.

The caller supplies every source path explicitly. Only project-contained PHP
files are accepted. Limits are 256 files, 1 MiB per file, 8 MiB total source,
10,000 literal mappings, and 20,000 contracts. Errors and truncation stay in the
result. Duplicate selected policy declarations are ambiguous and produce no
contracts; missing policy declarations remain unmatched. Unsupported dynamic
mapping entries are counted and never converted into inferred class names.

The class intentionally does not interpret PHPDoc or custom analyzer contracts.
It reports native syntax without changing Mago's native/PHPDoc/custom type
precedence. Consumers that add call-site diagnostics need a separate explicit
effective-dispatch contract and must preserve that precedence.

Until the shared CLI dispatcher exposes this mode, integrations can call the
exporter directly:

```php
use Ichinya\Laramago\Metadata\PolicyModelArgumentContractExport;

$metadata = (new PolicyModelArgumentContractExport)->export(
    $projectRoot,
    ['app/Providers/AuthServiceProvider.php', 'app/Policies/PostPolicy.php'],
);
```
