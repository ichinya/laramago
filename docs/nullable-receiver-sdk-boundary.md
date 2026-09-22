# Nullable receiver return inference

Mago reports a nullable receiver and then combines the successful method return
with `mixed` for the invalid null branch. A concrete extension return type does
not remove that `mixed` result. This can cause downstream mixed assignments,
arguments and method accesses after a single unguarded call.

```php
function dateStart(): DateValue
{
    return DateValue::make()->start(); // make(): ?DateValue
}
```

`tests/nullable-receiver-sdk-boundary.php` verifies this with the real engine,
both without an extension and with a probe that explicitly returns `DateValue`
(named `ProbeDate` in the fixture). The probe audit proves that Mago calls the
provider for the successful object branch. Both runs retain
`possible-method-access-on-null` and `mixed-return-statement`. Guarded, known
non-null, and nullsafe calls retain precise return types.

This explains one source of cascading diagnostics after nullable Carbon factory
calls; it does not mean every mixed Carbon expression has the same cause. A
package provider cannot assume the null branch succeeds or erase the initial
error. Application code can handle the nullable result explicitly; changing
engine recovery to retain the successful branch type requires a Mago change.

The test deliberately fails if engine behavior changes, so the boundary can be
re-evaluated rather than retained as a permanent assumption. It runs through
`composer check`, never bootstraps Laravel, and needs no database.

Verified with matching Mago and PHP SDK versions 1.48.1 and 1.50.0. Set
`MAGO_BINARY` and `MAGO_SDK_AUTOLOAD` to an isolated engine and SDK installation
when testing a newer release; the package's installed dependency need not change.
The existing aggregate and Pest context boundary tests also retain their results
with engine and SDK 1.50.0. Upgrading to that release alone does not resolve these
three integration limitations.
