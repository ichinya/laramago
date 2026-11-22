# Reproducing CODEBASE_SCAN cache pressure

This source-only experiment addresses [issue #9](https://github.com/ichinya/laramago/issues/9).
It needs existing Mago/SDK 1.59.0, PHP-Parser 5.9.0, Python 3 and Docker. It does
not install dependencies, evaluate a Laravel application, access a database or
use private application sources. Python is a benchmark tool, not a package runtime dependency.

Use an output directory outside the checkout. On Windows, use the existing Python
executable if `python` is not on PATH. Use the existing Linux Mago executable,
not the Windows executable; the runner checks its SHA256.

```sh
php scripts/generate-codebase-scan-corpus.php --seed=9 --file-counts=768,1152 --complexity=12 --output=/external/issue9/corpus
python scripts/benchmark-codebase-scan.py --create-protocol /external/issue9/protocol.json --corpus-root /external/issue9/corpus --mago-binary /external/tools/mago
python scripts/benchmark-codebase-scan.py --protocol /external/issue9/protocol.json --output /external/issue9/result.json
python tests/benchmark-codebase-scan.py
```

The generator refuses to overwrite a corpus. Each set includes one control file
with two genuine native errors and two safe functions. The remaining files use
JSON guards, integer validation and typed callbacks. The manifest records seed,
complexity, file count and every source hash. Calibrate complexity using baseline
observations only, then freeze it before measuring the candidate. A corpus is
not a pressure reproduction merely because it contains many files: demonstrate
AST/query eviction and repeated parsing, or the original request timeout.

The protocol generator creates a full two-corpus, three-payload, two-worker A/B
matrix. Each combination has one excluded warmup and three measured runs;
A/B order alternates. References are separate full analyses with a longer request
deadline. They supply diagnostic DTOs, never performance acceptance.

Variant A uses the current providers and the historical cache blob
`714c55a48ca7e2d06b031cc969af50a9ae65ee87`. Variant B uses the current cache.
The runner verifies that their only source difference is that file. This is a
cache comparison, not a complete 0.1.7-versus-current semantic comparison.

The candidate's pure-query disk tier retains evicted serialized results within
64 MiB and 65,536 entries, keeping the existing RAM budgets. Entries authenticate
both policy/source key and bytes with the process-private HMAC key. A restored
result is independently deserialized. Query files are removed at registration
reset because an extractor can change even when its policy name is reused.
Unavailable/full storage falls back to extraction; partial writes are removed.
Query snapshots use optional lossless compression in RAM and on disk. Eligibility
checks the original serialized size; RAM accounting includes the stored size and
length header within 8 MiB. Tests also use incompressible payloads to exercise the
real physical byte limit rather than assuming repeated padding still evicts.

Every run uses a fresh container, worker processes, result cache and spill tmpfs.
The pinned image is PHP 8.5.12 ZTS. Admission uses 2 CPU, 6 GiB container memory,
512 MiB worker heap, two native Mago threads, a 512 MiB `/tmp`, no network,
read-only sources/dependencies and request deadline 30000 ms. The outer command
limit is 900 seconds. OS filesystem caches are not flushed; host contention and
Docker Desktop filesystem costs can affect timings. These results describe the
declared environment and generated workloads, not every consumer application.

For profiles, copy a generated protocol, use stage `profile` and explicit runs
with `mode`/`phase` both `profile`. Include diagnostic reference runs first.
Instrumentation patches only disposable cache/SDK copies; hashes are recorded
separately. Profile timings and counters cannot be labeled unchanged controls.
Operation timings are inclusive and may overlap (in particular extraction that
requests a tree); do not add them as a partition of CPU. Hook records include
actual plugin identifiers and source hashes. Parse progress records preserve
partial evidence when the request is killed. A truncated profile is a lower
bound, not a complete-run total.

The report keeps failed runs and raw stdout/stderr. Exit 1 is permitted only
with a valid full issue report and intact positive/negative controls. Timeout,
OOM, transport failure, malformed reports, changed inputs or missing controls
are failures. Diagnostic comparison hashes complete DTOs, sorting only the issue
multiset, preserving all fields and duplicates. Containers always see `/corpus`,
so no path-field deletion is needed. Summary counts alone cannot prove parity.

`maximum_individual_child_rss_bytes` is RUSAGE_CHILDREN's largest individual
child RSS, not a sum of the process tree. `cgroup_memory_peak_bytes` includes
charged filesystem cache and tmpfs. Scratch usage is sampled every 100 ms and
can miss a short-lived peak. CPU uses completed child resource accounting.
Temporary storage sampling uses filesystem capacity/free-space counters for the
whole `/tmp` tmpfs, including allocated blocks. It does not walk every spill file
during analysis. The observer's own CPU time is recorded separately: a large
file inventory must not consume the CPU budget it is meant to observe.
Keep instrumentation cost separate from performance controls. Never compare
the partial duration of a timed-out baseline as if it were a completed analysis.

Store raw results, profiles and manifests outside the code tree. Publish a
receipt-backed summary in the repository Wiki separately. Protocol unit tests use
fabricated observations and are not real Mago acceptance. The runner's
`candidate_pass` covers its declared matrix and diagnostic checks; it does not
replace reviewing pressure evidence, package regressions or the issue criteria.
