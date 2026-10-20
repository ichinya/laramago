# Refreshed analyzer depth: admitted source snapshot compression

The existing public 33-pair corpus was executed again with PHPStan levels 0 through 10, its separate default-config-type L10 control, native Mago and Mago with the exact locally admitted Laramago source package: 14 configurations and 924 marked observations. All 23 identifier-specific PHPStan threshold oracles pass, including their corrected controls. The source variant is uncommitted and unpublished; its complete source and worker fingerprints are recorded in JSON.

Both Mago marked identifier/severity matrices match the archived reference. This preserves overlapping coverage across PHPStan levels, including the existing gaps; it does not assign a numeric PHPStan level to Laramago. Help remains advisory. These finite examples do not estimate whole-program false-positive rates.

| Witness | First PHPStan level | Laramago expected fault at bad marker | Laramago corrected-control findings |
| --- | ---: | --- | --- |
| unknown-function | 0 | non-existent-function/Error | — |
| unknown-class | 0 | non-existent-class/Error | — |
| undefined-variable | 0 | undefined-variable/Error | — |
| missing-argument | 0 | too-few-arguments/Error | — |
| maybe-undefined | 1 | possibly-undefined-variable/Warning | — |
| magic-property | 2 | non-documented-property/Warning | — |
| unknown-method | 2 | non-existent-method/Error | — |
| incompatible-phpdoc | 2 | docblock-type-mismatch/Error | — |
| return-type | 3 | invalid-return-statement/Error | — |
| property-type | 3 | invalid-property-assignment-value/Error | — |
| unreachable | 4 | unevaluated-code/Help | — |
| impossible-type | 4 | — | — |
| argument-type | 5 | invalid-argument/Error | — |
| missing-parameter | 6 | — | — |
| missing-return | 6 | — | — |
| iterable-value | 6 | — | — |
| union-argument | 7 | possibly-invalid-argument/Error | — |
| union-method | 7 | non-existent-method/Error | — |
| nullable-method | 8 | possible-method-access-on-null/Error | — |
| nullable-property | 8 | possibly-null-property-access/Error | — |
| explicit-mixed-argument | 9 | mixed-argument/Error | — |
| explicit-mixed-method | 9 | mixed-method-access/Error | — |
| implicit-mixed-argument | 10 | mixed-argument/Error | — |
| implicit-mixed-method | 10 | mixed-method-access/Error | — |
| array-shape | 3 | invalid-return-statement/Error | — |
| callable-argument | 5 | invalid-argument/Error | — |
| generic-identity | 3 | invalid-return-statement/Error | — |
| model-property | 3 | invalid-return-statement/Error | — |
| collection-first | 3 | invalid-return-statement/Error | — |
| config-value | 3 | invalid-return-statement/Error | — |
| query-nullable | 8 | possibly-null-property-access/Error | — |
| migrated-property | 3 | invalid-return-statement/Error | — |
| config-default | 3 | invalid-return-statement/Error | — |

The original controller reached its final historical fingerprint check after all analyzers, the depth oracle and the 12-cache cleanup assertion completed. That check compared archived raw bytes with the LF-normalized WSL fixture and failed. Its incomplete receipt is retained. Separate offline verification proves that the current raw public fixture exactly matches the archived raw fingerprint and that its CRLF-to-LF normalization exactly matches the executed fixture. The verified findings reuse the completed outputs; zero analyzers were repeated.

Original dependencies, both measured source packages and the fixture vendor snapshot are verified unchanged; the fixture worker is the separately copied measured candidate. Individual cache-deletion receipts had not been persisted before the failed historical check; JSON records that limit. Full Composer checks and hosted CI remain unrun.

[Fresh matrix and verification](analyzer-depth-refresh-2026-10-20.json), [historical protocol and matrix](analyzer-depth-and-timing-2026-10-16.md), [completed performance study](cpu-compressed-syntax-and-larastan-2026-10-19.md).
