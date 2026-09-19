# Declarative policy attributes

`PolicyAttributeDeclarations` reads a named class's direct native `UsePolicy` attribute from parsed PHP source. It accepts a literal class string, an imported `Policy::class`, `self::class`, or the native named argument `class:`. Literal spelling, including a leading backslash, is preserved. Reading does not instantiate the attribute, autoload the model, or execute its source.

The result is declaration metadata, not an effective Gate mapping. `null` means the selected known class has no direct declaration. Unreadable or ambiguous declarations, unresolved expressions, invalid arguments and duplicate attributes return `UnknownValue`. Conditional and anonymous classes are not selected. Parent attributes are not silently inherited.

Native Gate first checks its exact registered policy map, then a direct attribute, then guessed policies, then parent mappings, and finally inherited attributes. A future resolver must retain that ordering and account for custom Gate bindings, guessing callbacks and runtime registrations. This reader cannot prove that an ability or policy is missing and does not add diagnostics. Native Mago attribute and class-reference diagnostics remain authoritative.

Reference semantics: Laravel's `Illuminate\Auth\Access\Gate::getPolicyFor()` and `getPolicyFromAttribute()`, and `Illuminate\Database\Eloquent\Attributes\UsePolicy` (`public string $class`, class target). No Laravel LSP runtime collector is executed.
