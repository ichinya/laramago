# MailMessage view reference candidates

`MailMessageViewReferenceExport` reads only explicitly selected project PHP
files and exports literal view names from a bounded native `MailMessage` syntax
subset. The result is suitable for navigation, completion, and indexing. It is
not a missing-view diagnostic.

```php
use Ichinya\Laramago\Metadata\MailMessageViewReferenceExport;

$metadata = (new MailMessageViewReferenceExport)->export(
    projectRoot: '/path/to/project',
    files: ['app/Notifications/InvoicePaid.php'],
);
```

The source selector accepts project-relative PHP files only. It never loads the
project Composer autoloader, executes a notification, boots Laravel, or reads
environment state.

## Supported references

The receiver must be a direct construction of
`Illuminate\Notifications\Messages\MailMessage`, with imports and aliases
resolved lexically. Chaining remains supported only through the audited native
`view()` and `markdown()` setters, which both return the same message instance.

```php
use Illuminate\Notifications\Messages\MailMessage;

(new MailMessage)->view('mail.invoice');
(new MailMessage)->markdown('mail.invoice-markdown');
(new MailMessage)->view(['mail.invoice-html', 'mail.invoice-text']);
(new MailMessage)->view([
    'html' => 'mail.invoice-html',
    'text' => 'mail.invoice-text',
    'raw' => 'literal mail content',
]);
(new MailMessage)->view($dynamic)->markdown('mail.final');
```

The exporter preserves decoded names, exact literal and call byte spans, line
numbers, the canonical source path, and a SHA-256 hash of the source snapshot.
`viewSlot` distinguishes single, HTML, text, and Markdown entries. A `raw`
entry is message content and is never exported as a view reference.

Calls on variables, subclasses, unknown fluent methods, or constructions with
arguments are not attributed to the native class. Dynamic strings, interpolated
strings, unpacking, ambiguous view arrays, and unsupported argument shapes do
not produce references. These omissions keep the metadata source-only and avoid
claiming type or dispatch facts that require Mago or runtime state.

## Rendering boundary

Every reference has `runtimeLookupProven`, `selectedForRenderProven`, and
`required` set to `false`. Native setters only mutate public message state: a
later setter may replace the value, the message may never be sent, and a
notifiable without a mail route may skip view construction.

Ordinary `view()` candidates use the mailer's view finder. A `markdown()`
candidate records two finder contexts because Laravel renders it separately:
the HTML pass replaces the `mail` namespace with HTML component paths, while
the text pass replaces it with text component paths. Effective paths, themes,
custom renderers, and final selected state remain unknown. Consumers must not
turn finder absence into a runtime-error diagnostic from this metadata alone.

The envelope is versioned with `schemaVersion: 1` and scope kind
`mail-message-view-reference-candidates`. It is explicitly non-exhaustive and
reports invalid selections, parse failures, bounded-read truncation, and
unsupported setter counts without returning source contents.
