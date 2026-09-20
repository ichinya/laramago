# Pest test file class and trait mapping

`PestTestFileMapping` groups the ordered positive records from
`PestUsesCatalog` by selected test file. A classifier identifies each named
symbol as a class, trait, or unknown. `fromCodebase()` supplies that classifier
from Mago's frozen source metadata; neither path loads Pest, test files, or
application classes.

For each matched file, the mapping retains ordered `classCandidates`, `traits`,
`unresolvedNames`, and declaration source/line provenance. `declaredBaseClass`
is present only when exactly one class was identified and every observed name
was classified. It records an unambiguous **parsed declaration candidate**,
not a promise that Pest will bind that class at runtime. Repeated class
declarations remain separate candidates, including repeated use of the same
class; the mapping does not choose one or issue a diagnostic. Traits likewise
retain their declared order and multiplicity.

An optional declared-context warning flags selected files with two or more
statically identified, non-default test classes. Enable it with
`"diagnose-declared-conflicts": true` in the `extra.laramago.pest-uses` object.
Mago reports `laramago-pest-declared-test-context-conflict` at the later class
literal. Repeating the same custom class counts as an overlap because Pest checks
whether any custom class is already selected. `PHPUnit\Framework\TestCase` does
not occupy that slot. A class plus traits, or only traits, does not warn. Unknown
class/trait kinds stop diagnosis for that file. This policy warns about the
selected **declarations**: it does not assert that both registrations execute or
that Pest will fail at runtime. Conditional declarations and dynamic
registrations remain outside the static catalog. It conservatively omits a
custom class followed by an explicit PHPUnit default class: Pest may reject
that order at runtime, but effective registration order can differ from source
order when directory and file targets overlap.

`files() === null` means the underlying declaration catalog was unavailable.
An unmatched selected file has no mapping entry. Omitted dynamic or unsupported
Pest calls, conditional execution, other source files, and runtime plugins may
change the effective context. A caller must independently establish those
conditions before using this mapping to type a closure's `$this` or diagnose a
runtime conflict. No default PHPUnit test class is inferred from absent declarations.

This ordering and conflict boundary follows Pest 4.x at commit
[`5b2293f`](https://github.com/pestphp/pest/tree/5b2293f67adcf1b2320b33f521b94a692d18f360):
[`UsesCall`](https://github.com/pestphp/pest/blob/5b2293f67adcf1b2320b33f521b94a692d18f360/src/PendingCalls/UsesCall.php)
registers ordered classes/traits at destruction; the
[`TestRepository`](https://github.com/pestphp/pest/blob/5b2293f67adcf1b2320b33f521b94a692d18f360/src/Repositories/TestRepository.php)
appends registrations per path, adds traits, and rejects a second test class
while building a test case. Its
[`TestCaseFactory`](https://github.com/pestphp/pest/blob/5b2293f67adcf1b2320b33f521b94a692d18f360/src/Factories/TestCaseFactory.php)
starts from PHPUnit's `TestCase` and adds Pest's own traits at runtime; that
generated class is outside this source-only mapping.
