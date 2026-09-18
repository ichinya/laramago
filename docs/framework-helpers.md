# Framework helper contracts

## String helper proxy

`str()` without arguments retains Laravel's actual anonymous proxy class. Known
public static `Illuminate\Support\Str` methods supply parameter and return types
for calls such as `str()->uuid()`. Explicit strings and `str(null)` return
`Illuminate\Support\Stringable`; an explicit null argument is not a proxy request.

The extension verifies the installed helper's syntax and declaration, then locates
the anonymous class in Mago metadata. It does not invent a runtime class or claim
that the proxy is a Str instance. Unknown methods, reference signatures, unresolved
method templates and contextual types defer. Native methods and compatible native
return declarations retain priority, and argument/return errors remain visible.

Changed helper implementations, custom helper locations and overridden declarations
use native analysis. A future framework rewrite may require updating the bounded
syntax matcher. The implementation never executes the helper or application code.

`tests/string-helper.php` runs enabled, disabled and altered-helper controls in
temporary workspaces outside the package tree.

## Standard container services

Laravel `app('cache')`, `resolve('session')`, and native Application
`make('router')` calls can use the installed framework's literal core container
alias table. The extension reads source declarations without bootstrapping Laravel
or executing provider code.

Explicit binding catalogs take precedence, including blocked or uncertain entries.
Native helper PHPDoc and method overrides remain authoritative. Unknown service
strings, plain containers, container interface receivers, and overridden Application
alias registration or resolution defer to Mago.

Helper inference assumes the standard application services, as existing framework
facade inference does. Unlisted runtime provider mutations are not discovered.
Custom application bindings should use the existing explicit binding contracts.

`tests/framework-container-helpers.php` verifies positive and negative cases;
the `--disabled` run checks the native analyzer comparison.

## Transaction callback results

The standard `DB::transaction()` facade call propagates an analyzed Closure result
through Laravel's broad `mixed` facade contract. The installed connection declaration
must retain the matching callback/result template. Native methods, custom PHPDoc,
custom facade roots and cataloged connection replacements retain priority.
Unknown or unresolved callback contracts defer to Mago.
Arrays and generic objects keep their known outer structure when their elements
or type arguments are `mixed`. Accessing those elements still produces the native
mixed diagnostics. Top-level mixed results and unresolved template, conditional,
alias, variable, or contextual object types continue to defer.

The default and provably positive retry counts retain the callback result type.
Zero or negative attempts return null; an unknown integer count includes null.
A void callback produces null, while a never-returning callback remains never
when attempts are positive. Invalid arguments remain diagnostics.

Direct connection transactions keep native Mago generic analysis. Runtime driver
extensions and uncataloged connection replacements are not discovered. No callback,
transaction, database connection, or application bootstrap is executed.

`tests/transactions.php` includes retry and return-type cases. Its `--custom-doc`,
`--custom-binding`, and `--disabled` modes verify contract priority and native behavior.
