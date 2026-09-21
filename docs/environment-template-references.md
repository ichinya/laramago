# Environment template references

```sh
vendor/bin/laramago-metadata --kind environment-references --source .env.example
```

The CLI mode requires explicit sources and does not support watch. See the
[metadata CLI](static-metadata-export.md) for JSON error and exit-code handling.

`EnvironmentTemplateReferences::export(string $root, array $files)` produces
positive source locations for dotenv interpolation names without loading dotenv
or evaluating any value. Callers must pass each project-relative source
explicitly. The exporter accepts only files whose requested and resolved
basenames are exactly `.env.example` or `.env.template`; it does not scan the
project and will not read `.env`.

The output scope is `environment-references` with `source-only` evidence. Each
declaration and reference contains the variable name, absolute canonical file,
half-open byte span covering only the name, one-based line, SHA-256 hash of the
exact source bytes, and `known-positive` confidence. The envelope never includes
assignment values or source snippets.

## Supported syntax

The bounded grammar follows the parsing and interpolation states in official
`vlucas/phpdotenv` v5.6.2 source at commit
[`24ac4c7`](https://github.com/vlucas/phpdotenv/tree/24ac4c74f91ee2c193fa1aaa5c249cb0822809af):

- assignments may use an optional `export` prefix and ASCII names matching
  `[A-Za-z0-9_.]+`;
- literal `${NAME}` references are exported from unquoted and double-quoted
  values, including multiple references and multiline double-quoted values;
- single-quoted values are literal, and `\$` suppresses interpolation inside a
  double-quoted value;
- a backslash is literal in an unquoted value in v5.6.2, so
  `VALUE=\${NAME}` still contains a `NAME` reference;
- bare `$NAME`, comments, and interpolation-like forms such as
  `${NAME:-fallback}` are not references in this contract.

Valid interpolation-like forms outside this literal-name subset are returned as
generic `unsupported-interpolation` uncertainties. Unsupported or invalid
entries receive generic errors, without including their source text. A supported
literal name inside a nested form can still be exported as positive evidence;
the dynamic outer name remains uncertain.

Declarations are source locations only. The exporter does not diagnose
duplicates, prove assignment order, resolve references, or report a missing
declaration. phpdotenv resolves against the repository being loaded, so a name
absent from the selected templates may still be supplied by the process or a
different source. Consumers must not treat this metadata as a complete runtime
environment catalog.

Reads are limited to 256 explicit files, 1 MiB per file, 8 MiB total, and 20,000
declarations or references. Limits and rejected inputs are reported in the
envelope. The class never executes project PHP, loads the project's Composer
autoload file, or mutates process environment state.
