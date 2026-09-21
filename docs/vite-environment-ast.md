# Optional Vite environment AST export

The PHP metadata CLI retains its dependency-free lexical export. For JavaScript,
TypeScript and JSX syntax, an optional adapter uses the maintained
[`@babel/parser`](https://babeljs.io/docs/babel-parser) package. It has a separate
locked dependency tree and requires Node.js 20 or later. Node and npm are not
required by Composer installation, the PHP metadata CLI or `vendor/bin/mago`.

From the Laramago package directory:

```sh
npm ci --ignore-scripts --prefix tools/vite-environment-ast
node tools/vite-environment-ast/export.mjs --root /path/to/application resources/js/app.ts resources/js/Page.tsx
npm test --prefix tools/vite-environment-ast
```

After Composer installation, the same commands can target the package directory:

```sh
npm ci --ignore-scripts --prefix vendor/ichinya/laramago/tools/vite-environment-ast
node vendor/ichinya/laramago/tools/vite-environment-ast/export.mjs --root "/path/with spaces/application" resources/js/app.ts
```

Quote project roots and file arguments containing spaces. Select source files
explicitly; directories are not recursively scanned. The parser is resolved from
the adapter's own installation, not from application dependencies. Missing parser
support exits with status 1 and an actionable message; it never silently switches
to lexical extraction. Tests require the optional dependencies and are deliberately
separate from `composer check`.

The JSON export recognizes direct `import.meta.env.NAME`, literal computed
`import.meta.env['NAME']`, and optional terminal members. Template interpolations
and JSX expressions are traversed as syntax, while strings, comments, regex bodies
and JSX text cannot produce references. Dynamic computed names are reported as
uncertainties. Syntax errors discard the entire file's candidates. Invalid UTF-8
also produces `source-parse-error`. No parser error messages or source contents are
included in output. Names themselves are exported intentionally.

Each reference contains a SHA-256 source hash and an explicit UTF-16 offset
encoding. Offsets are Babel character positions, not PHP byte positions; consumers
must not use them as Mago byte spans without conversion. Lines are one-based and
columns are zero-based.

Bounds are 256 files, 1 MiB per file, 8 MiB total source bytes, 200,000 visited
syntax nodes per file, and 20,000 references and uncertainties combined. Both the
requested and resolved paths must have a supported extension and resolve to a
regular file. Paths must resolve inside the selected
project, including symlink targets. Bounds and parse/read failures remain visible
in `truncated` or `errors`; no exhaustive environment inventory is promised.

This is source metadata, not a missing-environment-variable diagnostic. It does
not evaluate JavaScript, Vite or Babel configuration, import application modules,
read dotenv files, resolve aliases/destructuring, infer build mode, validate
`envPrefix`, or infer whether a reference executes. `.vue` and other embedded
languages are unsupported. JSX uses `.jsx`/`.tsx` extensions. TypeScript uses
`.ts`/`.mts`/`.cts`/`.tsx`; decorators and other experimental parser plugins are
not enabled. Runtime value types and existence remain unknown.

## Diagnostics under an explicit environment contract

The optional `--contract` mode compares literal references with an independently
asserted final inventory. It never constructs that inventory from `.env`, examples,
process variables, Vite configuration, or application execution.

```json
{
  "schemaVersion": 1,
  "mode": "production",
  "prefixes": ["VITE_", "PUBLIC_"],
  "availableNames": ["VITE_API_URL", "PUBLIC_TITLE"],
  "complete": true,
  "nativeEnvironment": true
}
```

`complete` asserts that the names are the entire final set of custom exposed names
for the selected build mode. `nativeEnvironment` asserts native `import.meta.env`
behavior without plugins, `define` substitutions, mutation, or other overrides.
These assertions must be established independently by the caller. The adapter
cannot validate them; a configuration with overrides must not supply this contract.
`mode` is an identifying label, not a configuration loader. Prefixes must be
nonempty and every custom available name must match a prefix. Native builtins
`MODE`, `BASE_URL`, `DEV`, `PROD`, and `SSR` are always accepted independently of
prefixes and should not be listed. No values belong in the contract.

```sh
node tools/vite-environment-ast/export.mjs --root /path/to/application --contract /path/to/environment-contract.json resources/js/app.ts
```

`--contract` must appear directly after the root, before sources. Without it the
existing metadata export is unchanged. Contract mode adds `diagnostics` containing
`environment-name-outside-contract` warnings for literal names absent from the
asserted inventory. Each warning retains the original source hash, UTF-16 span,
name, and line/column. `reason` distinguishes unmatched prefixes from inventory
omissions. Warnings describe a disagreement with the explicit contract; they do
not claim that code executes or raises a runtime exception. Dynamic computed
names remain uncertainties, and aliases/destructuring remain unsupported. A clean
result does not establish exhaustive source coverage.

Exit status is 0 for no findings, 1 for findings or invalid input, and 2 when source
read/parse errors or bounds make a contract-mode scan incomplete. Incomplete scans
still produce JSON with available diagnostics and errors. Invalid contract input
produces only a generic error, with no contract payload. Contracts are limited to
1 MiB, 10,000 unique names, 32 unique prefixes, and 256 characters per name/prefix
or mode. Unknown fields, duplicate names/prefixes, control characters, non-string
entries, and missing or false assertions are rejected. The optional adapter remains
independent of PHP and is not automatically run by `vendor/bin/mago`.
