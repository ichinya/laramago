# Template reference policy diagnostics

The optional `template-reference-policy` connects the existing route, MailMessage,
Mailables Content and pagination reference extractors to real Mago notes. It checks
literal source references against a project-maintained permitted-name policy. It
does **not** discover an effective view finder or diagnose missing runtime files.

```json
{
  "extra": {
    "laramago": {
      "template-reference-policy": {
        "enabled": true,
        "files": ["routes/web.php", "app/Mail/Receipt.php", "app/Providers/AppServiceProvider.php"],
        "permitted": {
          "route": ["welcome", "dashboard"],
          "mail-html": ["mail.receipt"],
          "mail-text": ["mail.receipt-text"],
          "markdown-html": ["mail.summary"],
          "markdown-text": ["mail.summary"],
          "pagination": ["pagination::bootstrap-5"]
        }
      }
    }
  }
}
```

Run `vendor/bin/mago analyze` as usual. An unlisted literal produces
`laramago-template-reference-outside-policy` with level `Note` at the exact source
literal. Names are exact and case-sensitive. An omitted context is **unasserted**
and produces no notes. An empty list for a context explicitly permits **no** literal
references in that context; it is not an unknown catalog.

| Context | Selected source syntax |
| --- | --- |
| `route` | Literal `Route::view()` view arguments |
| `mail-html` | Fresh `MailMessage::view()` HTML/single-view literals; Content `view` and `html` constructor arguments |
| `mail-text` | Fresh MailMessage text slots and Content `text` constructor arguments |
| `markdown-html`, `markdown-text` | MailMessage and Content markdown references, checked independently in both contexts |
| `pagination` | Exact static `Paginator::defaultView()` and `defaultSimpleView()` declarations |

`htmlString` and raw mail payloads are not view references. Dynamic arguments,
unsupported expressions and falsey Content references retain the existing
extractors' conservative behavior. Instance `links()` and `render()` references
are not checked: source syntax alone cannot establish a paginator receiver.

These are declaration-policy notes even for syntactically selected framework
classes. They do not establish native method bodies, active routes, effective
MailMessage/Content state, namespace replacement, configured template paths or
whether rendering occurs. Markdown HTML and text can resolve through different
mail namespace paths, so permitting a name in one context does not permit it in
the other. Mutable pagination defaults are checked as declarations, never as proof
of the eventual selected view. Existing Mago argument/PHPDoc/custom-method
diagnostics are not suppressed or replaced.

Only explicitly selected project-contained PHP files are read. A note additionally
requires the same file to be analyzed by Mago with a matching SHA-256 source hash
and valid original byte span; selected but unindexed files cannot receive notes.
The hook is disabled by default and does not run the exporters when disabled or
when its configuration is malformed. Up to 256 sources and 20,000 names per
context are accepted, with the existing exporters' independent byte/reference
budgets. Truncated or unresolved extraction is never evidence of absence. No
application bootstrap, application Composer autoloader, `.env`, database or PHP
source execution is needed.

Validation: `php tests/template-reference-policy.php` runs the real worker with
eight exact diagnostics across these contexts, disabled/malformed/unasserted
policies, native argument errors, raw/dynamic/custom-method exclusions, source
selection and stale-span guards.
