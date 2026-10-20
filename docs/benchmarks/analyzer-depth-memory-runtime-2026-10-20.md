# Final-source analyzer depth verification

The public 33 defect/control pairs were executed with the exact locally admitted source package, native Mago, PHPStan levels 0 through 10 and a separate default-config-type L10 control. All 14 configurations complete, all 924 marked observations are checked, and all 23 identifier-specific PHPStan threshold oracles pass. Both marked Mago identifier/severity matrices equal the archived reference, including existing gaps.

| Witness | First PHPStan level | Laramago expected fault at bad marker | Laramago corrected-control findings |
| --- | ---: | --- | --- |
| unknown-function | 0 | non-existent-function/Error | - |
| unknown-class | 0 | non-existent-class/Error | - |
| undefined-variable | 0 | undefined-variable/Error | - |
| missing-argument | 0 | too-few-arguments/Error | - |
| maybe-undefined | 1 | possibly-undefined-variable/Warning | - |
| magic-property | 2 | non-documented-property/Warning | - |
| unknown-method | 2 | non-existent-method/Error | - |
| incompatible-phpdoc | 2 | docblock-type-mismatch/Error | - |
| return-type | 3 | invalid-return-statement/Error | - |
| property-type | 3 | invalid-property-assignment-value/Error | - |
| unreachable | 4 | unevaluated-code/Help | - |
| impossible-type | 4 | - | - |
| argument-type | 5 | invalid-argument/Error | - |
| missing-parameter | 6 | - | - |
| missing-return | 6 | - | - |
| iterable-value | 6 | - | - |
| union-argument | 7 | possibly-invalid-argument/Error | - |
| union-method | 7 | non-existent-method/Error | - |
| nullable-method | 8 | possible-method-access-on-null/Error | - |
| nullable-property | 8 | possibly-null-property-access/Error | - |
| explicit-mixed-argument | 9 | mixed-argument/Error | - |
| explicit-mixed-method | 9 | mixed-method-access/Error | - |
| implicit-mixed-argument | 10 | mixed-argument/Error | - |
| implicit-mixed-method | 10 | mixed-method-access/Error | - |
| array-shape | 3 | invalid-return-statement/Error | - |
| callable-argument | 5 | invalid-argument/Error | - |
| generic-identity | 3 | invalid-return-statement/Error | - |
| model-property | 3 | invalid-return-statement/Error | - |
| collection-first | 3 | invalid-return-statement/Error | - |
| config-value | 3 | invalid-return-statement/Error | - |
| query-nullable | 8 | possibly-null-property-access/Error | - |
| migrated-property | 3 | invalid-return-statement/Error | - |
| config-default | 3 | invalid-return-statement/Error | - |

These witnesses show overlapping coverage across levels; they do not assign Laramago a numeric PHPStan level. For example, Help remains advisory, and the current level-4 impossible-type and level-6 annotation witnesses remain coverage gaps. Finite corrected controls do not establish an overall false-positive rate.

All original dependency paths/bytes, both measured package snapshots, the copied read-only fixture vendor tree and its separate selected worker are checked. The raw public fixture, the executed fixture and the archived fingerprint are exactly identical; no line-ending conversion is applied. Twelve PHPStan caches are removed only after the full depth oracle succeeds, with path-ownership and source-proof checks recorded. No ordinary timing observation is changed.

The selected source remains local and unpublished. Focused native controls and the ordinary full-workload study are separate evidence; the exact admitted source additionally passes strict Composer validation and the complete 502-command script, including the public replay regression. Hosted CI is NOTRUN.

[Complete matrix](analyzer-depth-memory-runtime-2026-10-20.json). [Archived protocol](analyzer-depth-and-timing-2026-10-16.md).
[Matching performance study](cpu-memory-runtime-combination-and-larastan-2026-10-20.md).

See [final-source native Composer verification](native-full-check-final-2026-10-20.md).
