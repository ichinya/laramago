# Static metadata JSON export

`vendor/bin/laramago-metadata` exports configuration key declarations from PHP
syntax without bootstrapping Laravel. It does not load the application's Composer
autoload file, execute configuration expressions, read `.env`, or connect to a
database. Only key names and source provenance are exported; configuration values
are never included.

```sh
vendor/bin/laramago-metadata --project-root /path/to/application
vendor/bin/laramago-metadata --project-root /path/to/application --config-key app.name
vendor/bin/laramago-metadata --project-root /path/to/application --config-array services --output metadata.json
vendor/bin/laramago-metadata --project-root /path/to/application --watch --interval-ms 500
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
accepted in watch mode, and invalid options exit with status 2. Watch mode only
refreshes static configuration metadata; it does not run incremental Mago
analysis or reuse the analyzer worker.

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

Unfiltered traversal is bounded to 4,096 catalogs, 100,000 declarations, and
32 nested array levels. `truncated` and `truncationReasons` disclose when a
limit is reached. Consumers should check `schemaVersion`, source hashes, and
`truncated` before reusing spans or treating the export as a complete snapshot.
