# Static metadata JSON export

The `environment-references` source mode additionally exports variable names and
interpolation locations from explicit dotenv templates; see
[environment template references](environment-template-references.md).

`vendor/bin/laramago-metadata` exports configuration, route name and translation key declarations from PHP
syntax without bootstrapping Laravel. It does not load the application's Composer
autoload file, execute configuration expressions, read `.env`, or connect to a
database. Only names and source provenance are exported; configuration values
and translated messages are never included.

```sh
vendor/bin/laramago-metadata --project-root /path/to/application
vendor/bin/laramago-metadata --project-root /path/to/application --config-key app.name
vendor/bin/laramago-metadata --project-root /path/to/application --config-array services --output metadata.json
vendor/bin/laramago-metadata --project-root /path/to/application --watch --interval-ms 500
vendor/bin/laramago-metadata --kind routes --source routes/web.php --source routes/api.php
vendor/bin/laramago-metadata --kind translations --source lang/en/messages.php
```

The project root defaults to the current directory. `--config-key` and
`--config-array` can be repeated. With no key or array filter, the command walks
conventional `config/*.php` arrays and their statically selected nested arrays.
Output goes to stdout unless `--output` explicitly names a file. The command
supports Composer's custom `vendor-dir` because it locates its parser beside the
installed package, without requiring the application's autoloader.

`--watch` writes one JSON object per line (JSONL), beginning with an initial
snapshot and then when watched source state or error status changes. It polls
`config/*.php`, `composer.json`, and `composer.lock`; `--interval-ms` accepts
50–60000 milliseconds and defaults to 500. Each event contains
`event: "snapshot"`, a revision and source hash, `status: "ready"` with full replacement
`metadata`, or `status: "error"` with `metadata: null` and generic `errors`.
Parse/read failures and truncated exports invalidate the prior ready snapshot.
Stop the command with Ctrl+C or the host process interrupt. `--output` is not
accepted in watch mode, and invalid options exit with status 2. This default mode
refreshes static configuration metadata; it does not run incremental Mago
analysis or reuse the analyzer worker.

Watch scans are limited to 512 configuration files, 4 MiB per file, and 32 MiB
of source bytes per poll. Exceeding a limit emits an error snapshot with no
metadata. Files are checked again after export to reject a snapshot changed
during the read.

The JSON object has `schemaVersion: 1`, an absolute `projectRoot`, a `scope`
of `{"kind":"configuration","evidence":"source-only"}`, `catalogs`,
`requests`, and `errors`. Each catalog has an `arrayKey`, nullable
`sourceComplete`, and declarations. A null `sourceComplete` means no static array
was available. Every declaration preserves `key` (nullable for a literal child
name containing a dot), raw `name`, parent `arrayKey`, absolute `file`, byte
offsets `start` and exclusive `end`, one-based `line`, SHA-256 `contentHash` of
the parsed file snapshot, `sourceSelected`, and `confidence`.

For requested keys, `confidence` is `known-positive`, `complete-absent`, or
`unknown`. These states describe literal source evidence only. Even
`complete-absent` does not prove a missing runtime configuration key: application
code can replace or mutate Laravel's repository. `sourceSelected: false` means a
later dynamic array entry may replace the literal declaration; the location is
still a useful source candidate. A complete catalog refers only to its selected
array expression. Parse/read failures appear in `errors` with a generic message
and no source content. Unavailable catalogs and requested keys remain unknown.
One-shot export exits with status 0 when it produces JSON, including partial
results with `errors`; consumers must inspect that field. Invalid arguments,
JSON encoding failures, and output write failures exit with status 2.

Unfiltered traversal is bounded to 4,096 catalogs, 100,000 declarations, and
32 nested array levels. `truncated` and `truncationReasons` disclose when a
limit is reached. Consumers should check `schemaVersion`, source hashes, and
`truncated` before reusing spans or treating the export as a complete snapshot.

## Route and translation source exports

`--kind` defaults to `configuration`. The `routes` and `translations` kinds
require explicit, repeatable `--source` paths relative to the project root.
Absolute paths, parent traversal and paths resolving outside the project are
rejected in the JSON `errors` list. No application route files, locale or loader
paths are discovered by running Laravel. Configuration filters cannot be combined
with these kinds; invalid option combinations exit with status 2. Route metadata
and translation metadata support selected-source watch as described below.

Both kinds return `schemaVersion: 1`, `projectRoot`, `scope`, `declarations`,
`errors`, `truncated` and `truncationReasons`. Each declaration identifies an
absolute `file`, original half-open byte range (`start`, `end`), one-based `line`,
and `contentHash`. `confidence: "known-positive"` means the literal declaration
exists in that source snapshot, not that it is registered or used at runtime.
There is no complete runtime catalog and no missing-name inference from this output.

The route exporter recognizes top-level or namespace-level expression statements
of the form `Route::get('/path', $action)->name('example')`, resolving imports of
`Illuminate\Support\Facades\Route`. It also accepts `post`, `put`, `patch`,
`delete`, `options` and `any`. URI and name must be literal strings; arguments
must be positional. Literal `Route::name('admin.')->group(function () { ... })`
and exact `Route::group(['as' => 'admin.'], function () { ... })` groups compose
with supported declarations, including nested literal name groups. Attribute-array
groups accept only the literal `as` entry; group closures have no parameters or
captures. The magic `name` group attribute is case-sensitive.

Ungrouped declarations retain their original shape. A grouped declaration's
`name` is a composed source candidate; `rawName` is the leaf literal. Its primary
`start`, `end` and `line` still identify that leaf. `nameProvenance` records
`kind: literal-concatenation` and ordered `tokens`, each with its decoded `value`,
`group-prefix` or `route-name` role, and original source span. No synthetic source
span is claimed for the composed name.

Dynamic prefixes, non-closure callbacks, captured or parameterized closures,
extra group attributes, conditions, functions, unsupported fluent calls, repeated
route naming and handler bodies are not traversed. Unsupported groups defer their
subtrees; safe siblings remain eligible. Duplicate names remain separate candidates.
An earlier `throw`, a macro or a replaced router can prevent any candidate from
becoming an effective runtime route.

The translation exporter reads PHP files that directly return one literal array.
Nested declarations preserve raw `segments` so a dotted key is distinguishable
from nested keys. `name` retains the final literal key, while `phpKey` reflects
PHP's numeric-string key normalization. Overwritten duplicates remain visible;
`sourceSelected: false` marks declarations that are shadowed or may be replaced
by a later dynamic entry. This describes only the array expression, not locale
selection, namespace resolution or fallback. Implicit numeric keys, computed keys,
JSON translation files and executable top-level setup are not inferred. Unsupported
file forms produce errors; dynamic array entries can coexist with positive literals.

Each source exporter accepts at most 256 input files, 1 MiB per file and 8 MiB
total source bytes. Route exports stop at 10,000 declarations, 32 literal name
group levels and 4,096 bytes per composed name. Translation exports
stop at 20,000 declarations or 32 nested key levels. Limits are disclosed through
`truncated` and `truncationReasons`; consumers must also inspect `errors` on an
otherwise successful one-shot invocation.

## Translation placeholder candidates

```sh
vendor/bin/laramago-metadata --kind translation-placeholders --source lang/en/messages.php
```

This source-only mode exports ASCII colon-word completion candidates (`:name`,
`:NAME`, `:count`) from literal PHP translation messages. Its `candidates` list
does not contain translated values. `scope.semantics` is `completion-candidates`
and `scope.exhaustive` is false: Laravel also accepts partial dictionaries,
prefix substitutions, arbitrary keys and closure-based tag replacements.
These candidates do not establish mandatory replacement arguments.

Each candidate retains raw message-key `segments`, `messageName`, normalized
`messagePhpKey`, source `file`, `contentHash` and `sourceSelected`. Key-token
coordinates use `keyStart`, `keyEnd`, `keyLine`; message coordinates use
`messageStart`, `messageEnd`, `messageLine`. `spanKind: message-literal` explicitly
identifies the enclosing original string token, including when PHP escapes
change its decoded contents. The export does not invent a source offset for
the decoded placeholder. Candidate confidence is `completion-candidate`.

Dynamic messages, JSON translations and runtime locale/fallback selection are
not inferred. The mode requires explicit PHP `--source` paths and does not
support watch. Limits are 256 files, 1 MiB per file, 8 MiB source bytes, depth 32,
20,000 candidates, 1,024 bytes per name and an 8 MiB estimated candidate-output
budget. Repeated long parent-key provenance counts against the output budget.
Reached limits are disclosed through `truncated` and `truncationReasons`.

## JSON translation declarations

Run `laramago-metadata --kind translations-json --source lang/en.json` to export
root object keys from explicitly selected JSON translation files. The exporter
validates the document and retains each original key literal's byte span, line,
source hash, decoded name, and PHP associative-array key. Duplicate declarations
remain visible; `sourceSelected` identifies the last occurrence within that file.
Nested message content and translated values are omitted.

This is source evidence for navigation and completion. It does not infer active
locales, fallback, loader path precedence, or a complete runtime translation
catalog. Requested and resolved sources must be project-contained JSON files.
Malformed input produces generic errors without source excerpts. Limits are
256 files, 1 MiB per file, 8 MiB total source bytes, 20,000 declarations, and JSON
decode depth 512. Errors and truncation remain visible in the envelope.

## Selected route source watch

Run `laramago-metadata --kind routes --source routes/web.php --watch` to poll an
explicit fixed selection of PHP route sources. Each JSONL event replaces the
previous snapshot. Source edits, removal, read/parse errors, racing edits, and
truncated exports invalidate stale metadata; recovery publishes fresh metadata.
Unchanged ready or error states do not emit repeated events.

The watcher checks requested and resolved extensions and project containment
before reading, hashes exact bytes and canonical paths, and rescans after export.
It does not discover files, execute application PHP, or perform incremental Mago
analysis. Limits are 256 selected files, 1 MiB per file and 8 MiB total per scan.
The existing interval and interrupt options apply. Restart to change the selection.

PHP and JSON translation declarations use the same replacement protocol:

```sh
laramago-metadata --kind translations --source lang/en/messages.php --watch
laramago-metadata --kind translations-json --source lang/en.json --watch
```

Each mode accepts only its own source format, including after symlink resolution.
Malformed translations invalidate the previous snapshot and recovery publishes a
fresh one. Source selection stays explicit; watching does not infer runtime locale,
fallback, or loader precedence. Placeholder and environment modes do not support watch.

## Duplicate route name advice

`laramago-metadata --kind route-name-duplicates --source routes/web.php` exports
[duplicate literal name candidates](route-name-duplicate-candidates.md) with both
source locations and group-name provenance. These are review candidates only:
`activeRouteConflict` remains `unknown`, including when Laravel may replace an
earlier route with the same method, domain and URI. The mode is non-exhaustive,
retains source errors and truncation, and does not support watch.

## Vite environment name candidates

`laramago-metadata --kind vite-environment-references --source resources/js/app.js`
exports lexical candidates for direct `import.meta.env.NAME` access. Each entry
contains the name, original byte span, line, source hash and
`completion-candidate` confidence. This is explicitly non-exhaustive lexical
evidence, not a JavaScript AST index or proof that an environment name exists.

Only explicit project-contained `.js`, `.mjs`, `.cjs`, `.ts`, `.mts` and `.cts`
files are accepted. Strings, comments and property-chain lookalikes are excluded.
Scanning stops with uncertainty at templates, ambiguous slash or angle syntax,
escaped/non-ASCII identifiers and token limits. Computed and optional access
remain unsupported. An unsupported identifier suffix cannot yield a truncated
name. JSX/TSX and Vue files require a maintained frontend parser for future support.

The exporter reads no dotenv values and executes no application code. Limits are
256 files, 1 MiB per file, 8 MiB total, 200,000 tokens per file and 20,000 references.
Consumers must retain errors, uncertainties and truncation; candidates must not
drive missing-name diagnostics. This mode does not support watch.

## Route parameter declarations

`laramago-metadata --kind route-parameters --source routes/web.php` exports
[bounded URI/domain parameter declarations](route-parameter-metadata.md), binding
fields, source provenance and declaration-default names without their values.
It preserves native method order and distinguishes URI optional syntax from the
question-mark marker in a domain. Runtime route survival, actual URL defaults and
whether a caller may omit a parameter remain unknown. This mode has no watch.

## Controller declaration candidates

`laramago-metadata --kind controller-route-contract-candidates --source routes/web.php --source app/Http/Controllers/ReportController.php`
links literal route actions to unique selected public controller declarations.
[Candidate metadata](controller-route-contract-candidates.md) retains parameter
positions, native type syntax, defaults and lexical placeholder matches. Runtime
dispatch and dependency injection remain unvalidated; missing bindings and scalar
name differences are not errors. Duplicate class declarations defer. No application
autoloading or implicit source discovery occurs, and this mode has no watch.

The `middleware-parameters` kind reads explicit PHP sources and exports [conditional native Pipeline parameter tokens](middleware-parameter-metadata.md). It does not resolve middleware targets or validate their signatures.

The `policy-model-argument-contract-candidates` kind exports [selected mapping and policy parameter declarations](policy-model-argument-contract-candidates.md), with explicit uncertainty about effective Gate dispatch.

The `policy-class-selector-call-candidates` kind preserves [literal class-selector transformations](policy-class-selector-call-candidates.md) without claiming that the policy call executes.

The `policy-additional-argument-contract-candidates` kind exports [additional parameter declarations and separate Gate argument syntax](policy-additional-argument-contract-candidates.md). It composes with model parameter metadata without asserting effective dispatch or compatibility.

The `policy-discovery-boundaries` kind describes [selected declarations and native resolver boundaries](policy-discovery-boundaries.md), retaining effective policy resolution as unknown.

The `binding-compatibility-candidates` kind compares [selected binding declarations and type hierarchies](binding-compatibility-export.md). It does not prove receiver identity, effective container state or runtime failure.

The `container-injection-attribute-candidates` kind preserves [contextual attribute declarations](container-injection-attribute-candidates.md), including source types and arguments, without inferring runtime injected values.
