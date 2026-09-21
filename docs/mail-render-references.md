# Native mail rendering view references

`MailRenderReferencesHook` adds the existing `laramago-missing-view` **Warning**
for a literal passed to the concrete native `Illuminate\Mail\Mailer::render()`.
This is a catalog-backed existence check, separate from source permitted-name
policy Notes. Enable it only when the effective mailer view factory is the native
Laravel factory and its finder uses exactly the asserted complete catalog:

```json
{
  "extra": {
    "laramago": {
      "mail-render-contract": { "native-view-factory": true },
      "reference-catalogs": {
        "views": {
          "complete": true,
          "paths": ["resources/views"],
          "namespaces": { "billing": ["resources/views/vendor/billing"] }
        }
      }
    }
  }
}
```

The contract asserts the effective factory at the lookup, including any instance
construction, provider changes, callbacks, custom filesystem behavior and runtime
finder/path changes. Laramago does not discover those facts. Do not enable it for
custom factories/finders or catalogs omitting package views. All configured
catalog directories must exist; namespace paths describe effective ordered hints.
The existing catalog implementation conservatively defers unreadable directories,
symlinks, ambiguous case and unknown namespaces.

```php
function preview(\Illuminate\Mail\Mailer $mailer): string
{
    return $mailer->render(view: 'mail.reciept', data: []);
}
```

A missing literal produces a warning at that literal, with existing view name
suggestions where applicable. The check requires exact concrete receiver metadata,
audited native `render`, `parseView`, `renderView`, global `value` helper, and view
factory normalization/lookup methods. Changed source or PHPDoc contracts, subclasses,
helper shadowing and explicit custom view/finder bindings defer. The complete
catalog alone does not opt into mail rendering checks.

Only nonempty, non-`"0"` string literals are checked. Native `render` uses a falsey
HTML-to-plain fallback; arrays, closures, raw HTML/raw payloads, dynamic names,
unpacked arguments and first-class callable references are not interpreted.
`MailMessage::view`, `Mailables\Content`, routes, pagination defaults, `Mail`
facades and mail manager forwarding are not covered by this hook. Setter calls
alone do not establish which template will eventually render.

The hook is not registered by default. When enabled it parses only analyzed
files containing selected calls and reuses the existing complete view catalog.
Native contract validation and per-file syntax are cached for the analysis run.
It never executes application sources, bootstrap, Composer application autoload,
`.env`, a database connection or a render operation.

`php tests/mail-render-references.php` runs 17 real Mago cases. Repeat with
`--changed-forwarding`, `--changed-parse`, `--changed-render-view`, `--class-doc`,
`--changed-doc`, `--changed-helper`, `--shadow-helper`, `--finder-binding`,
`--no-contract` and `--incomplete-catalog` to verify native/PHPDoc precedence and
contract exclusions. Native invalid arguments and missing methods remain visible.
Native Laravel method excerpts use the existing fixture license.

`php tests/mail-render-references.php --all` runs all eleven configurations and
all 187 checks through argument-array subprocesses. Use this command in the
regular package check; the default invocation remains a focused 17-case run.
