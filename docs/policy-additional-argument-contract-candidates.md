# Policy additional argument contract candidates

`PolicyAdditionalArgumentContractExport` provides bounded source provenance for
parameters after a mapped policy model and arguments after a Gate call subject.
It composes with `PolicyModelArgumentContractExport`; it does not parse or copy
the policy mapping contract itself.

The caller explicitly selects project-relative PHP provider, policy and call-site
files. The exporter never loads the project Composer autoloader, executes selected
PHP, bootstraps Laravel, evaluates environment values or accesses a database.
Reads are contained beneath the project root, deduplicated and bounded by file,
byte, declaration, call and argument limits. Every retained declaration or call
has byte spans and an exact source hash.

For a mapped policy method with item 087's model-parameter candidate,
`policyContracts` contains only parameters after the user and candidate model
parameters. Each parameter records its absolute position, positions after the
user and model, source and resolved native type, default presence, variadic state
and reference state. Methods without that second parameter are omitted. A second
parameter is still only a declaration candidate; this exporter does not
reinterpret class-level methods because class selector transformation is a
separate contract.

`gateCalls` recognizes direct, positional calls to the native
`Illuminate\Support\Facades\Gate` for `allows`, `denies`, `check`, `authorize`,
`inspect` and `raw` when the ability is a literal string. A positional list array
retains the subject and every following expression by position and span. Omitted
arguments and a single expression are distinct shapes. Keyed, unpacked or sparse
arrays remain incomplete and expose no invented positional arguments. Aggregate
ability arrays, dynamic ability names, helper methods, contracts, injected Gate
objects and custom facades remain outside this source subset.

The two lists are deliberately unjoined. A matching ability and policy method
name does not prove that Laravel will invoke that policy. Effective dispatch may
instead use an explicit Gate definition, class-level policy path, policy discovery,
container replacement, global or policy `before` callback, magic method, or guest
eligibility rule. The export therefore sets `runtimeDispatchValidated` and
`exhaustive` to `false` and emits no type, arity or authorization diagnostics.
An analyzer may join these candidates only after independently proving the
effective Gate receiver, mapping, subject kind, callback precedence and reachable
policy method. Native PHP/PHPDoc/custom declarations remain authoritative.

Laravel's pinned Gate implementation wraps the supplied argument value, passes
the original array through global and policy callbacks, removes an initial class
string only immediately before a policy method, and invokes policy code from a
weakly typed framework file. Those details make ordinary direct-call type checks
unsound here. This export preserves syntax needed by a future flow-aware consumer
without claiming those runtime conditions.
