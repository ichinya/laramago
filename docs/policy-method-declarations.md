# Policy method declaration advisory

Laramago can emit an optional declaration-quality note when a literal native
Gate call selects a model whose explicitly selected policy mapping has no
statically callable method for that literal ability. Enable it alongside
`policy-sources` in the application's `composer.json`:

```json
{
  "extra": {
    "laramago": {
      "policy-sources": [
        {"file": "app/Providers/AuthServiceProvider.php", "provider": "App\\Providers\\AuthServiceProvider"}
      ],
      "policy-method-declarations": {"diagnose": true}
    }
  }
}
```

The advisory covers literal abilities passed to the installed native Gate
facade or an exactly typed native `Illuminate\Auth\Access\Gate` object. The
authorization argument must statically identify the exact model from the
selected mapping: a model object, `Model::class`, or the first item in a literal
list. Dynamic abilities, dynamic arrays, inherited policy resolution, policy
attributes, guessed policies, and incomplete class metadata defer without a
note.

Method availability comes from Mago's merged class metadata. Public inherited
and static methods count. A public `__call` also defers because Laravel's
`is_callable([$policy, $method])` can use magic dispatch. For an ability
containing a hyphen, the method name follows Laravel's `Str::camel` conversion;
an ability without a hyphen is unchanged, including underscores and case.

Selected explicit Gate definitions and policy classes with a selected container
binding defer. The check also requires native Gate facade forwarding or an exact
native Gate receiver. It does not execute Laravel, resolve the current user, or
inspect runtime callback and container state.

`laramago-policy-method-declaration` is a note, not a missing-method error. It
means only that the selected policy class lacks a statically declared public
handler under the configured declaration policy. A global before callback can
allow the request; an after callback can replace a null result; an explicit
definition can handle the ability; the container can supply another policy;
magic dispatch can provide the method; and guest eligibility can prevent a
policy call. The note therefore never establishes an authorization failure or
an attempted missing-method invocation.
