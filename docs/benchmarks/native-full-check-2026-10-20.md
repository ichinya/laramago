# Native full Composer check after source-scan optimization

The exact 587 production source files of the admitted source-scan candidate pass strict Composer validation and the full Composer test script in an isolated Linux package snapshot. The test script expands 490 entries and aliases to 501 PHP commands, including 6 syntax checks. Every emitted command is matched against the frozen script in exact order; both controlling Composer commands exit zero.

Dependencies are installed offline from byte-identical local mirrors: Mago 1.48.1, PhpParser 5.8.0, Doctrine Inflector 2.1.0 and Revolt 1.0.9. The 612 mirrored PHP/config files retain their original hashes. The package test loader excludes application autoload files. PHP 8.5.4 and the pinned Mago binary match the performance study. Each command group uses a two-CPU/six-GiB scope; individual test heaps and request deadlines are unchanged.

Two stopped attempts remain in the evidence:

- Attempt 01 completed 76 PHP commands, then the manually assembled external CLI fixture lacked CodebaseScanSyntax. Its copy list now includes that dependency. The missing-dependency failure, successful isolated repair and complete proxy test are verified.
- Attempt 02 completed 282 PHP commands, then the reused application autoloader conflicted with the deliberately isolated runtime fixture by defining env() beforehand. A clean loader for the four pinned package dependencies fixes the environment. The identical runtime test passes all eight reported branches without changes to the analyzer or that test.
- Attempt 03 completes all 501 commands with frozen source/dependency checks. These are verified test failures followed by corrections, not WSL interruptions.

Explicit SKIP/NOTRUN notices: 0. Incidental keyword notices are retained as a count and do not imply skipped commands.

No ordinary timing observation was repeated or altered. The only public test-source correction is the external fixture dependency list; production analyzer sources remain the exact measured candidate. Hosted CI is NOTRUN. Changes remain local, uncommitted and unpublished.

[Performance study](cpu-scan-facts-and-larastan-2026-10-20.md). [Audited check summary](native-full-check-2026-10-20.json).
