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
