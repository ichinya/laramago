# Contextual binding declaration metadata

`ContextualBindingMetadataExport` reads only explicitly selected project-relative
PHP files and returns literal contextual-binding declaration candidates. It does
not load the application, execute a service provider, or resolve the container.

```php
use Ichinya\Laramago\Metadata\ContextualBindingMetadataExport;

$metadata = (new ContextualBindingMetadataExport)->export(
    $projectRoot,
    ['app/Providers/AppServiceProvider.php'],
);
```

The bounded source subset recognizes container-shaped chains on global `app()`
helper syntax and fully qualified `Illuminate\Container\Container::getInstance()`
syntax:

```php
app()->when(ReportController::class)
    ->needs(Clock::class)
    ->give(SystemClock::class);

app()->when([CsvReport::class, JsonReport::class])
    ->needs('$timezone')
    ->giveConfig('reports.timezone', 'UTC');
```

`when` accepts one literal string or class string, or a nonempty flat list of
them. `needs` accepts one literal string or class string; dollar-prefixed strings
are identified as primitive parameter keys. `give` accepts one literal token or
a nonempty flat list. Literal `giveTagged` tags and `giveConfig` keys are also
recorded. For `giveConfig`, metadata reports only whether a default argument is
present, never its value. Positional arguments and exact native named-argument
names are supported.

Each declaration includes the resolved class spelling where syntax proves it,
the original token bytes, byte offsets, line, selected file, and SHA-256 content
hash. Unsupported container-shaped chains are returned as uncertainties rather
than silently treated as declarations. Invalid, unreadable, oversized, and
unparseable selected files produce generic errors without source fragments.
Reads are limited by file count, per-file bytes, aggregate bytes, and candidate
count.

The export deliberately labels receiver, runtime registration, and effective
resolution as unvalidated. Recognized receiver syntax alone does not prove that
the analyzed application uses Laravel's native helper or container declaration.
A chain inside a conditional is still only a source candidate.

Laravel canonicalizes aliases while registering contextual bindings and selects
them from the current runtime build stack. Later registrations, factory frames,
parameter overrides, contextual attributes and their custom handlers, ordinary
bindings, callbacks, tags, and configuration values can change the result. The
export does not model those states, join declarations to construction sites,
check implementation compatibility or class existence, apply registration
order, or narrow constructor parameter types. `$this->app`, arbitrary receiver
variables, closures, dynamic expressions, keyed or unpacked arrays, and lexical
`self`, `static`, or `parent` class strings remain uncertainty.

This metadata is independent of the analyzer's opt-in global binding catalog.
It does not make `when`, `needs`, or `give` declarations effective for `app()`,
`resolve()`, facade, or constructor type inference.
