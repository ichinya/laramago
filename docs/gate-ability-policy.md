# Permitted Gate ability reference policy

Laravel does not require an ability to appear in `Gate::abilities()` before it
can be authorized. Policies, policy discovery, global callbacks and custom Gate
implementations can handle names that have no explicit `Gate::define`
registration. Consequently, `GateDefinitionCatalog` is not a missing-name
contract.

Applications may opt into a separate source-reference policy in composer.json:

```json
{
  "extra": {
    "laramago": {
      "gate-ability-policy": {
        "enabled": true,
        "abilities": ["view-dashboard", "publish-post"]
      }
    }
  }
}
```

With this policy enabled, `laramago-gate-ability-outside-policy` advises when an
exact string literal passed to a supported native Gate entry point is absent
from `abilities`. This means only that the reference is outside the configured
permitted list. It does not claim that Laravel will reject the authorization
request, that the ability lacks a callback or policy method, or that any listed
ability will be granted.

The initial subset covers native concrete `Illuminate\Auth\Access\Gate` calls
and the native `Illuminate\Support\Facades\Gate` facade for `allows`, `denies`,
`check`, `any`, `none`, `authorize`, `inspect` and `raw`. Exact key identity is
preserved, including case, whitespace and empty strings. Only an actual boolean
`enabled: true` with a list of strings activates advice; the default, disabled
and malformed configurations produce none.

Native method bodies and facade PHPDoc are checked against Laravel framework
commit `7c75fbf`. Changed framework contracts, custom classes and facades,
configured Gate rebinding, interface-typed receivers, dynamic expressions,
enums, concatenations, first-class callables and unpacked arguments defer to
Mago. Literal ability arrays also defer because aggregate order and
short-circuit behavior need a separate per-element policy. Native PHPDoc and
argument diagnostics retain priority, and the policy never changes inferred
types.
