# Declarative mail content references

`MailContentReferenceExport` reads explicitly selected PHP files and exports
literal template names from construction of
`Illuminate\Mail\Mailables\Content`. It recognizes imports, aliases, fully
qualified names, and the native constructor's named or stable positional
arguments for `view`, `html`, `text`, and `markdown`.

The constructor's `html` argument is a Blade view alias. It is distinct from
`htmlString`, which contains raw pre-rendered HTML and is never exported as a
view reference. Empty strings and `"0"` are also omitted because Laravel's
truthy hydration checks skip them. Dynamic arguments and positional arguments
after an unpack are reported as deferred counts rather than guessed.

Each candidate carries the exact literal byte span, source line, source hash,
constructor argument role, and reference kind. The export never executes a
selected file or loads the application's Composer autoloader. Reads are limited
to 256 files, 1 MiB per file, 8 MiB total, and 20,000 references.

This metadata is intended for completion, navigation, and hover features. It
does not justify a missing-view diagnostic. `Content` is a mutable data object:
fluent setters can replace constructor fields, hydration applies the `html`
alias after `view`, raw HTML and Markdown have later rendering precedence, and
existing Mailable state can survive falsey declarations. Custom constructors,
`build()` methods, renderers, container bindings, and terminal send or preview
state remain unknown. Every candidate therefore has `runtimeLookup = unknown`,
and the scope explicitly marks missing-view diagnostics as ineligible.
