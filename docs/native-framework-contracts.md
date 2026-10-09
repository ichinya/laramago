# Native framework contracts

These contracts and the focused test command are part of release `0.1.0`.

Laramago corrects specific native analyzer contracts using installed declarations and analyzed types. Run `composer test:framework-native-contracts` for the focused regression suite; `composer test` also includes it. Fixtures contain intentional errors, so individual native analyzer processes exit with status 1 while the regression wrappers require the exact expected diagnostics and exit with status 0.

| Contract | Required evidence | Limits retained |
| --- | --- | --- |
| RedirectResponse `onlyInput` / `exceptInput`, Facade `shouldReceive` / `expects` | Complete installed method tokens, declaring owner, canonical framework source path | Positional arguments only; named extras, unpacking, and overrides keep native checks |
| Application `environment(patterns)` | Installed method and imported `Str::is` implementation | No-argument getter remains its native union; custom methods and changed dependencies decline refinement |
| Migrator `usingConnection(null, callback)` | Exact native Migrator receiver and installed `usingConnection`, `setConnection`, and `directConnectionName` bodies | Subclass receivers defer; native callback generics, required arguments, and return substitution remain authoritative |
| `array_filter` string predicates | Builtin functions and a physically bound arrow with an untyped or `mixed` parameter and a pure `is_string` guard, optionally followed by `trim` or a two-argument `preg_match` test | Keys preserved; no nonempty promise; coercing typed parameters, key mode, writes, disjunctions, shadowed functions, and extra regex output parameters decline refinement |
| Backed enum `array_column(..., 'value')` | Builtin function and analyzed backed enum metadata for every input element | Backing scalar preserved; index columns and unknown element types keep native results |
| DOMXPath `query` | Native declaring method, generic DOMNodeList metadata and a literal structural selector without a namespace axis or callable expression | Returns `DOMNodeList<DOMNode>\|false`; namespace, dynamic and callable queries keep the native broad result, while false and incompatible node consumers remain diagnosed |
| Documented `Collection\|Item[]` shorthand | Existing model, Traversable, generic and physical PHPDoc evidence, with LF/CRLF line endings | Nullable access, native/child declarations, explicit array unions, unknown elements and invalid writes keep their original controls |
| Returning a native `never` call | Direct function or `$this` method call, exact diagnostic spans, parsed enclosing return, and native declared `never` result | Other call forms defer; PHPDoc-only claims, outer `void`/`never` returns, and other argument/return errors remain diagnosed |
| `proc_open` standard descriptor redirection | Exact native descriptor diagnostic, builtin metadata, and validated literal descriptor syntax | Accepts only standard slots with literal pipe/file/redirect descriptors and redirect targets 0/1/2; native signature, param-out pipes, other arguments, and resource/false return remain intact |

Method spans use raw file bytes before whitespace-insensitive token comparison. LF and CRLF fixtures exercise the same contracts. The providers do not execute framework applications or migrations. The `proc_open` regression executes only a fixed local PHP child program to verify merged stdout/stderr draining; analyzed fixture command expressions are never executed. The native redirect tuple is supported by [PHP's implementation](https://github.com/php/php-src/blob/PHP-8.2/ext/standard/proc_open.c).

An internal `array_filter` callback can receive a coerced string while the resulting array retains the original integer element. Its regression checks that PHP behavior directly and keeps both native callback-argument and invalid string-consumer diagnostics. Typed callbacks over original string values retain their valid native result.

## Schema and model state

`SchemaIndex::uncertainties()` records the source file, line, and affected scope when a statement first invalidates inferred schema knowledge. This inspection API exposes the reasons separately from unreadable-source warnings. Unknown helper calls, raw SQL, dynamic tables, and unsupported table callbacks keep their conservative invalidation behavior.

A complete SQL schema does not prove that an Eloquent instance is hydrated or has all columns selected. New instances, partial projections, unknown migrations, nullable casts, and custom accessors still require a valid read contract or a runtime guard. Timestamp casts also need source evidence; these corrections do not grant global non-null model properties.

## Source forms for unresolved state

For an HTTP factory, `createPendingRequest()` establishes the installed PendingRequest contract before chaining configuration and a request; explicit `async()` remains asynchronous. A factory's magic dispatch can invoke macros or mixins, so it does not receive a global synchronous return promise.

For recursive iterators, check `instanceof SplFileInfo` before calling file methods. Filesystem iterator flags can return path strings or the iterator object. For list contracts, `array_values($collection->all())` explicitly reindexes keys. For translations or an environment getter, an `is_string` branch establishes the value actually returned. For query deletion, verify an integer result where a custom `onDelete` callback can change the result.

A facade's magic `shouldHaveReceived` call depends on its current root. Capture the actual Mockery object and check `instanceof Mockery\MockInterface` before relying on its verification API. `Facade::spy()` can fall through when already mocked, despite its PHPDoc; neither the facade root nor its return receives an unconditional Mockery guarantee.

## Testing an unpublished local package

Keep the application's portable `composer.json` and committed lock unchanged. Use an isolated consumer, or an ignored copy of its manifest with a path repository for `ichinya/laramago`, `options.symlink: false`, and a development requirement in that copy only. An explicit `options.versions` entry can align the local repository version with that development requirement. Select the copy using the `COMPOSER` environment variable; Composer writes its corresponding alternate lock file. Restore the prior environment variable after the local install/update operation.

Without the explicit mirroring option, path repositories may use a symlink or Windows junction, making library edits immediately visible and resolving package fixtures outside the consumer's vendor exclusion. With mirroring, refresh the installed copy after source changes. Freeze the library before the final application gate and record the active manifest, package path/version, source revision, and dirty diff. A gate against the alternate local manifest does not establish success with the portable root lock or a published release. See [Composer path repositories](https://getcomposer.org/doc/05-repositories.md#path) and [the COMPOSER variable](https://getcomposer.org/doc/03-cli.md#composer).

If the consumer sets its own `source.excludes` array, retain the preset's existing `**/ichinya/laramago/tests/**` and `**/ichinya/laramago/var/**` entries in the effective configuration. Those package directories contain analyzer fixture doubles and local research snapshots; scanning them as dependencies can introduce duplicate framework/model metadata. Application source and tests still belong in the analysis gate.

Most portable test catalogues record LF fixture bytes. Test harnesses stage that form before native scanning and compare the expected hashes and spans against the staged source. Catalogues that deliberately pin CRLF or mixed line endings reconstruct only the form matching their recorded hash. Production file spans and source identity checks continue to use raw bytes; request-query and framework contract matrices explicitly exercise CRLF source.
