# Middleware parameter metadata

`MiddlewareParameterMetadataExport` reads an explicit list of PHP files and
exports literal string pipes from directly constructed native
`Illuminate\Pipeline\Pipeline` chains. It recognizes literal arguments to
`through()` and `pipe()`, including literal lists and positional variadic calls.
It does not discover files or execute project PHP.

```php
use Ichinya\Laramago\Metadata\MiddlewareParameterMetadataExport;

$metadata = (new MiddlewareParameterMetadataExport)->export(
    root: '/srv/application',
    files: ['src/MessagePipeline.php'],
);
```

For a pipe such as `auth:a:b,c,,`, the export records `auth` as the name and
`['a:b', 'c', '', '']` as the parameters. It splits only the first colon and
then splits the suffix on every comma. A missing colon produces no parameters,
while an explicit empty suffix produces one empty parameter. Whitespace, extra
colons, zero strings, and empty fields are preserved without coercion.

Each reference includes the raw decoded literal, parsed name and parameters,
decoded byte offsets for every parameter token, the source literal's byte span,
line, absolute source path, and SHA-256 content hash. `through()` and `pipe()`
calls with dynamic, mixed, keyed, unpacked, named, or reference arguments are
listed as unresolved consumers instead of being partially interpreted. Calls
on variables, subclasses, custom classes, and unsupported fluent chains are
outside this bounded source contract.

The metadata is conditional by design. Native Pipeline dispatch applies
`is_callable($pipe)` before string parsing. A callable string can bypass the
parser entirely; for example, whether `ClassName::method` is callable depends
on runtime state. If parsing occurs, the name is still resolved through the
container and the configured dispatch method can differ from `handle`.
Accordingly, this export does not resolve aliases, middleware groups, container
bindings, callable status, target classes, or method signatures. It reports no
invalid-middleware or argument-count diagnostics. A consumer must verify the
native Pipeline contract and supply separate resolution evidence before using
these raw tokens for signature analysis.

Reads are limited to 256 explicitly selected files, 1 MiB per file, 8 MiB in
total, and 10,000 references or unresolved consumers. Invalid paths, unreadable
files, parse failures, and truncation are returned as metadata errors without
including source content.
