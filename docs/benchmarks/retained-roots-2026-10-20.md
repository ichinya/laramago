# Retained roots after complete analysis

An excluded CPU/heap run preserves all 13,100 diagnostics and unchanged sealed inputs. The observer runs after the AFTER_ANALYSIS handler returns and before its response is encoded. Normal terminal CPU/heap values are copied before the retained-root probe. The widened observer completes 4,709,043 visits without truncation inside its declared coverage; 208 callback objects are still alive. Nonowned object bodies remain opaque.

PHP live memory at the pre-probe boundary is 381.82 MiB, with 418.50 MiB allocated by PHP. These are instrumented phase observations, not whole-command scope peaks or causal memory savings.

| First-visit root | Objects | Arrays | Array entries |
| --- | ---: | ---: | ---: |
| worker:Mago\Sdk\Worker::$metadataCache | 204,409 | 246,905 | 224,718 |
| callback:Ichinya\Laramago\Analyzer\LiteralForeachSelectionReturnFilter::$cache | 42,490 | 50,459 | 278,471 |
| callback:Ichinya\Laramago\Analyzer\StructuralArrayContractFilter::$parsed | 36,430 | 43,031 | 238,306 |
| callback:Ichinya\Laramago\Analyzer\StaticAnalysis\DirectCallbackReferenceEffects::$files | 34,463 | 42,262 | 228,218 |
| callback:Ichinya\Laramago\Analyzer\ValidatedForeachReturnFilter::$cache | 12,553 | 15,461 | 81,268 |
| callback:Ichinya\Laramago\Analyzer\EloquentAggregateProjectionProvider::$calls | 11,912 | 14,350 | 114,826 |
| callback:Ichinya\Laramago\Analyzer\QueryAggregateProvider::$contract | 11,459 | 14,837 | 72,315 |
| callback:Ichinya\Laramago\Analyzer\EloquentChunkCallbackProvider::$source | 7,253 | 9,719 | 45,712 |
| callback:Ichinya\Laramago\Analyzer\ForwardedTransactionTemplateProvider::$contract | 6,887 | 8,208 | 43,905 |
| callback:Ichinya\Laramago\Analyzer\EloquentKeyProvider::$source | 5,797 | 7,656 | 35,760 |
| callback:Ichinya\Laramago\Analyzer\CollectionHelperProvider::$source | 5,452 | 7,316 | 34,440 |
| callback:Ichinya\Laramago\Analyzer\TransactionProvider::$facades | 3,527 | 4,562 | 22,061 |

These counts describe logical reachability. Repeated objects are credited to the first visited root, and the observer cannot deduplicate copy-on-write arrays or strings. They do not measure allocation size, exclusive ownership or the percentage of RAM used by a handler. Opaque object bodies remain explicit; untruncated does not imply inspection of every process object.

StructuralArrayContractFilter and LiteralForeachSelectionReturnFilter each retain expanded ASTs for up to 16 files. Private prototypes replace that retention with bounded source facts while preserving source order, spans, declaration headers, ambiguity and live native checks. Their independent source oracles and real-host tests are queued after ordinary timing work. Neither prototype is admitted and neither has a measured whole-command memory gain. ValidatedForeachReturnFilter is a further investigation target.

The observer does not call getters or serialize inspected objects. It limits object traversal to package/SDK/PhpParser objects and exact stdClass objects; controls verify weak observation does not extend object lifetimes. The earlier shutdown-only capture attempt lacked durable root artifacts and remains incomplete. Awaiting the before-response phase repairs artifact capture; this phase is explicitly distinct from SDK shutdown. Probe CPU and heap overhead are excluded from ordinary timing observations.

[Structured counts and limits](retained-roots-2026-10-20.json). [Completed baseline test suite](native-full-check-2026-10-20.md). [Ordinary performance study](cpu-scan-facts-and-larastan-2026-10-20.md).
