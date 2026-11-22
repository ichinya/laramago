#!/usr/bin/env python3
"""Source-only issue #9 experiment. Raw observations never belong in the checkout."""
from __future__ import annotations

import argparse
import hashlib
import itertools
import json
import pathlib
import re
import shutil
import subprocess
import sys

IMAGE = "sha256:5e84875c17523ae2e1b72d9aa6d9e40f09b942fff9d6ceede7ea1483d98b443e"
BLOB = "714c55a48ca7e2d06b031cc969af50a9ae65ee87"
CACHE = "src/Analyzer/StaticAnalysis/CodebaseScanSyntax.php"
SDK_WORKER = "carthage-software/mago/composer/src/Sdk/Worker.php"
PROFILE = r"\ScanBenchmark\Profile"
FAILURE = re.compile(r"did not answer request|provider failed|worker failed|host failed|protocol.{0,20}failed|Allowed memory size|incomplete.*pars|panicked", re.I)


def request_timeout_only(report, stderr):
    """A failed baseline is useful only when the isolated failure is the deadline."""
    return (report.get('mode') == 'control' and report.get('deadline_ms') == 30000
            and report.get('exit_code') == 2
            and bool(re.search(r'did not answer request .* within 30000 ms', stderr))
            and not re.search(r'(?:oom|oom_kill) [1-9]', report.get('cgroup_memory_events', ''))
            and not re.search(r'Allowed memory size|out of memory|memory exhaust|protocol I/O failed|violated the protocol|rejected request|panicked|segmentation fault', stderr, re.I))


def read_json(path):
    return json.loads(pathlib.Path(path).read_text(encoding="utf-8-sig"))


def write_json(path, value):
    pathlib.Path(path).write_text(json.dumps(value, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")


def inventory(root):
    root = pathlib.Path(root)
    if root.is_file():
        return {root.name: hashlib.sha256(root.read_bytes()).hexdigest()}
    if not root.is_dir(): raise ValueError("Missing input directory: " + str(root))
    return {p.relative_to(root).as_posix(): hashlib.sha256(p.read_bytes()).hexdigest()
            for p in sorted(root.rglob("*")) if p.is_file() and '__pycache__' not in p.parts}


def digest(value):
    return hashlib.sha256(json.dumps(value, sort_keys=True, separators=(",", ":")).encode()).hexdigest()


def diagnostics(body):
    if not isinstance(body, dict) or not isinstance(body.get("issues"), list):
        raise ValueError("Malformed or missing issue report")
    # Sort whole DTO hashes, preserving duplicates and every field. All runs use /corpus.
    if any(not isinstance(issue, dict) or not isinstance(issue.get('level'), str)
           or not isinstance(issue.get('annotations', []), list) for issue in body['issues']):
        raise ValueError('Malformed issue DTO')
    return sorted(digest(issue) for issue in body["issues"])


def validate_observation(report, body, stderr, mode, expected=None):
    if not isinstance(report, dict) or not {'mode', 'exit_code', 'deadline_ms', 'environment', 'cgroup_memory_events'} <= report.keys():
        raise ValueError('Malformed resource receipt')
    if report["mode"] != mode or report["exit_code"] not in (0, 1) or FAILURE.search(stderr):
        raise ValueError("Analyzer infrastructure failure or mislabeled instrumentation")
    if mode != "reference" and report["deadline_ms"] != 30000:
        raise ValueError("Admission deadline changed")
    if report["environment"]["php"] != "8.5.12" or report["environment"]["zts"] != 1:
        raise ValueError("PHP version differs")
    if int(report["environment"]["memory_max"]) != 6 * 1024**3:
        raise ValueError("Memory limit differs")
    if re.search(r"(?:oom|oom_kill) [1-9]", report["cgroup_memory_events"]):
        raise ValueError("Cgroup OOM")
    hashes = diagnostics(body)
    if expected is not None and hashes != expected:
        raise ValueError("Full diagnostic multiset differs (including duplicates)")
    issues = body["issues"]
    # Independent known bad and known good functions: source spans identify controls.
    control_text = pathlib.Path(__file__).resolve().parents[1].joinpath("tests/fixtures/codebase-scan-benchmark/controls.php.stub").read_text()
    for name, required in [("unsafeReturn", True), ("unsafeArgument", True), ("safeReturn", False), ("safeArgument", False)]:
        # Mago DTO source positions use zero-based lines (unlike rendered diagnostics).
        line = next(i for i, text in enumerate(control_text.splitlines()) if "function " + name + "(" in text)
        found = any(issue.get("level", "").lower() == "error" and "Controls.php" in json.dumps(issue)
                    and any(a.get("span", {}).get("start", {}).get("line") == line
                            for a in issue.get("annotations", [])) for issue in issues)
        if found != required:
            raise ValueError("Native control missing or false positive: " + name)
    return hashes


def validate_protocol(p):
    if p.get("format") != 1 or p.get("image") != IMAGE or p.get("baseline_blob") != BLOB:
        raise ValueError("Unpinned protocol/image/baseline")
    if p.get("stage") not in ("calibration", "profile", "acceptance"):
        raise ValueError("Unknown experiment stage")
    if p.get("resources") != {"cpus": 2, "memory_bytes": 6 * 1024**3, "tmpfs_bytes": 512 * 1024**2,
                              "php_heap_mib": 512, "threads": 2, "request_deadline_ms": 30000, "command_timeout_seconds": 900}:
        raise ValueError("Resource protocol differs")
    if p.get("mago") != "1.59.0" or p.get("parser") != "5.9.0":
        raise ValueError("Dependency versions differ")
    runs = p["runs"]
    for r in runs:
        if r["variant"] not in ("A", "B") or r["corpus"] not in p["corpora"] or r["payload"] not in (8, 16, 64) or r["workers"] not in (1, 2):
            raise ValueError("Invalid run setting")
        if r["mode"] not in ("control", "profile", "reference") or r["phase"] not in ("warmup", "measured", "profile", "reference"):
            raise ValueError("Invalid run role")
        if r["mode"] == "profile" and r["phase"] != "profile":
            raise ValueError("Instrumented run cannot be a timing control")
        if r["phase"] in ("warmup", "measured") and r["mode"] != "control":
            raise ValueError("Admission requires unchanged controls")
    if p["stage"] == "acceptance":
        if len(p["corpora"]) < 2:
            raise ValueError("Two stress corpora required")
        for key in itertools.product(p["corpora"], (8, 16, 64), (1, 2), ("A", "B")):
            matching = [r for r in runs if (r["corpus"], r["payload"], r["workers"], r["variant"]) == key]
            if sum(r["phase"] == "warmup" for r in matching) != 1 or sum(r["phase"] == "measured" for r in matching) < 3:
                raise ValueError("Incomplete warmup / three-repeat matrix")


def create_protocol(package, corpus_root, binary, docker):
    package, corpus_root, binary = map(lambda x: pathlib.Path(x).resolve(), (package, corpus_root, binary))
    corpora = {p.name: str(p) for p in sorted(corpus_root.iterdir()) if p.is_dir() and (p / 'manifest.json').is_file()}
    if len(corpora) != 2: raise ValueError("Require exactly two frozen stress corpora")
    runs = []
    for corpus in corpora:
        runs.append(dict(corpus=corpus, variant='A', payload=64, workers=1, mode='reference', phase='reference'))
        for payload, workers in itertools.product((8, 16, 64), (1, 2)):
            for repeat in range(4):
                for variant in (('A', 'B') if repeat % 2 == 0 else ('B', 'A')):
                    runs.append(dict(corpus=corpus, variant=variant, payload=payload, workers=workers,
                                     mode='control', phase='warmup' if repeat == 0 else 'measured'))
    roots = [package / name for name in ('src', 'bin', 'presets', 'vendor', 'scripts', 'tests/fixtures/codebase-scan-benchmark')]
    roots += [package / name for name in ('composer.json', 'composer.lock')]
    roots += [binary.parent, *map(pathlib.Path, corpora.values())]
    return dict(format=1, stage='acceptance', image=IMAGE, baseline_blob=BLOB, package=str(package),
                mago_binary=str(binary), docker=docker, mago='1.59.0', parser='5.9.0', corpora=corpora,
                resources=dict(cpus=2, memory_bytes=6*1024**3, tmpfs_bytes=512*1024**2, php_heap_mib=512,
                               threads=2, request_deadline_ms=30000, command_timeout_seconds=900),
                seals={str(root): digest(inventory(root)) for root in roots}, runs=runs,
                os_cache_policy='Not flushed; fresh container, workers, result cache and spill tmpfs per run.',
                source_head=subprocess.check_output(['git', '-c', 'safe.directory=' + package.as_posix(), 'rev-parse', 'HEAD'], cwd=package, text=True).strip())


def replace_once(text, old, new):
    if text.count(old) != 1:
        raise ValueError("Instrumentation anchor changed: " + old[:100])
    return text.replace(old, new)


def instrument(cache, sdk, helper):
    """Only disposable copies. All replaced operations keep their original values/exceptions."""
    cache = replace_once(cache, "final class CodebaseScanSyntax", "require_once '/package/Profile.php';\n\nfinal class CodebaseScanSyntax")
    for name, parameters, returned, arguments in [
        ('retainQuery', 'string $key, string $serialized', 'void', '$key, $serialized'),
        ('spilledQuery', 'string $key', '?string', '$key'),
        ('project', 'string $contents, string $requested', 'mixed', '$contents, $requested'),
    ]:
        signature = f'private static function {name}({parameters}): {returned}'
        if name == 'spilledQuery' and signature not in cache:
            continue  # Historical cache has no second tier.
        action = f"{PROFILE}::call('{name}', static fn() => self::profile_{name}({arguments}));"
        wrapper = signature + '\n    {\n        ' + ('' if returned == 'void' else 'return ') + action + '\n    }\n\n    '
        cache = replace_once(cache, signature, wrapper + f'private static function profile_{name}({parameters}): {returned}')
    for function in ("serialize", "unserialize", "gzdeflate", "gzinflate", "file_get_contents", "file_put_contents"):
        cache = re.sub(r"(?<![\w:])\\?" + function + r"\(", lambda _: PROFILE + "::" + function + "(", cache)
    cache = replace_once(cache, "$nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($contents) ?? [];",
                         f"$nodes = {PROFILE}::call('parse', static fn() => (new ParserFactory)->createForNewestSupportedVersion()->parse($contents) ?? []);")
    cache = replace_once(cache, "$nodes = (new NodeTraverser(new NameResolver))->traverse($nodes);",
                         f"$nodes = {PROFILE}::call('name_resolution', static fn() => (new NodeTraverser(new NameResolver))->traverse($nodes));")
    cache = cache.replace("$extractor($nodes)", f"{PROFILE}::call('extract:' . $policyId, static fn() => $extractor($nodes))")
    cache = cache.replace("$extractor(self::parse($contents))", f"{PROFILE}::call('extract_with_tree', static fn() => $extractor(self::parse($contents)))")
    for anchor, counter in [("if (isset(self::$trees[$key])) {", "tree_hit"), ("if (isset(self::$queries[$key])) {", "query_hit"),
                            ("$oldest = array_key_first(self::$queries);", "query_evict")]:
        cache = cache.replace(anchor, anchor + f"\n {PROFILE}::$counts['{counter}'] = ({PROFILE}::$counts['{counter}'] ?? 0) + 1;", 1)
    cache = replace_once(cache, "private static function evictTree(): void\n    {",
                         f"private static function evictTree(): void\n    {{\n {PROFILE}::$counts['tree_evict'] = ({PROFILE}::$counts['tree_evict'] ?? 0) + 1;")
    start = "        [$firstBatch, $lastBatch, $activeHooks, $filesByHook] = AnalyzerProtocol::readCodebaseScanRequest("
    sdk = replace_once(sdk, start, f"        require_once '/package/Profile.php';\n        $__start = hrtime(true);\n" + start)
    end = "        foreach ($activeHooks as $hookIndex) {\n            $registered = $this->codebaseScanHooks[$hookIndex] ?? null;"
    sdk = replace_once(sdk, end, f"        $__decodeNs = hrtime(true) - $__start;\n        $__inventory = [];\n" + end)
    sdk = replace_once(sdk, "            $cancellation->throwIfCancelled();\n            try {\n                $registered->callback->scan(",
        "            $__files = [];\n            foreach ($filesByHook[$registered->index] ?? [] as $__file) { $__files[] = ['path' => $__file->path, 'sha256' => hash('sha256', $__file->contents)]; }\n"
        "            $__inventory[] = ['index' => $hookIndex, 'plugin' => $registered->plugin, 'files' => $__files];\n"
        f"            {PROFILE}::event(['kind' => 'hook-start', 'index' => $hookIndex, 'plugin' => $registered->plugin, 'payload_bytes' => (new \\ReflectionProperty($reader, 'length'))->getValue($reader), 'decode_ns' => $__decodeNs, 'files' => $__files]);\n"
        "            $cancellation->throwIfCancelled();\n            try {\n                $registered->callback->scan(")
    sdk = replace_once(sdk, "                        $lastBatch,\n                    ),\n                );",
        "                        $lastBatch,\n                    ),\n                );\n" +
        f"                {PROFILE}::event(['kind' => 'hook-finish', 'index' => $hookIndex, 'elapsed_request_ns' => hrtime(true) - $__start]);")
    sdk = replace_once(sdk, "        return AnalyzerProtocol::writeCodebaseScanResponse();",
        f"        {PROFILE}::event(['kind' => 'scan', 'first' => $firstBatch, 'last' => $lastBatch, 'payload_bytes' => (new \\ReflectionProperty($reader, 'length'))->getValue($reader), 'decode_ns' => $__decodeNs, 'duration_ns' => hrtime(true) - $__start, 'hooks' => $__inventory]);\n"
        "        return AnalyzerProtocol::writeCodebaseScanResponse();")
    return cache, sdk, helper


def prepare(p, directory):
    package = pathlib.Path(p["package"]).resolve()
    required_roots = [package / name for name in ('src', 'bin', 'presets', 'vendor', 'composer.json', 'composer.lock')]
    required_roots += [pathlib.Path(p['mago_binary']).resolve().parent, *map(pathlib.Path, p['corpora'].values())]
    sealed_roots = {pathlib.Path(root).resolve() for root in p['seals']}
    if any(root.resolve() not in sealed_roots for root in required_roots):
        raise ValueError('Required source, dependency, binary or corpus seal missing')
    baseline = subprocess.check_output(["git", "-c", "safe.directory=" + package.as_posix(), "show", BLOB], cwd=package).decode()
    candidate = (package / CACHE).read_text(encoding="utf-8-sig").replace("\r\n", "\n")
    if set(re.findall(r"public static function (\w+)", baseline)) != set(re.findall(r"public static function (\w+)", candidate)) - {"stats"}:
        # stats is a property on current releases, so the normal case compares equal.
        raise ValueError("Baseline/candidate public cache API differs")
    common = {}
    for name in ("src", "bin", "presets"):
        for rel, sha in inventory(package / name).items(): common[name + "/" + rel] = sha
    for name in ("composer.json", "composer.lock"): common[name] = hashlib.sha256((package / name).read_bytes()).hexdigest()
    for variant, contents in (("A", baseline), ("B", candidate)):
        dest = directory / variant
        dest.mkdir()
        (dest / "vendor").mkdir()
        for name in ("src", "bin", "presets"): shutil.copytree(package / name, dest / name)
        for name in ("composer.json", "composer.lock"): shutil.copy2(package / name, dest / name)
        (dest / CACHE).write_text(contents, encoding="utf-8", newline="\n")
        profile = directory / (variant + "-profile")
        shutil.copytree(dest, profile)
        c, s, helper = instrument(contents, (package / "vendor" / SDK_WORKER).read_text(encoding="utf-8-sig").replace("\r\n", "\n"),
                                  (package / "tests/fixtures/codebase-scan-benchmark/Profile.php").read_text())
        (profile / CACHE).write_text(c, encoding="utf-8", newline="\n")
        (profile / "SdkWorker.php").write_text(s, encoding="utf-8", newline="\n")
        (profile / "Profile.php").write_text(helper, encoding="utf-8", newline="\n")
    a, b = inventory(directory / "A"), inventory(directory / "B")
    if {key for key in a.keys() | b.keys() if a.get(key) != b.get(key)} != {CACHE}:
        raise ValueError("Performance variants differ outside cache file")
    return {"checkout_sources": common, "variants": {v: inventory(directory / v) for v in ("A", "B", "A-profile", "B-profile")}}


def run(p, output):
    validate_protocol(p)
    output = pathlib.Path(output).resolve()
    if output.exists(): raise ValueError("Output must not exist (preserve previous evidence)")
    output.parent.mkdir(parents=True, exist_ok=True)
    work = output.with_suffix(".data")
    work.mkdir()
    package = pathlib.Path(p["package"]).resolve()
    for root, expected in p["seals"].items():
        if digest(inventory(root)) != expected: raise ValueError("Input seal differs: " + root)
    variants = work / "variants"
    variants.mkdir()
    manifest = prepare(p, variants)
    write_json(work / "variants.json", manifest)
    write_json(work / "protocol.json", p)
    observations, references = [], {}
    docker = p.get("docker", "docker")
    inspected = subprocess.check_output([docker, "image", "inspect", IMAGE, "--format", "{{.Id}}"], text=True).strip()
    if inspected != IMAGE: raise ValueError("Docker image identity differs")
    for index, spec in enumerate(p["runs"]):
        label = f"{index:03d}-{spec['corpus']}-{spec['variant']}-{spec['payload']}-{spec['workers']}-{spec['phase']}"
        dest = work / label
        dest.mkdir()
        mode = spec["mode"]
        variant = variants / (spec["variant"] + ("-profile" if mode == "profile" else ""))
        before = {root: digest(inventory(root)) for root in p["seals"]}
        # Docker atomically reserves this name: simultaneous benchmark containers are rejected.
        container_name = 'laramago-issue9-benchmark'
        command = [docker, "run", "--rm", "--name", container_name, "--label", "ichinya.laramago.issue9=true", "--pull=never", "--network", "none", "--read-only", "--cap-drop", "ALL", "--security-opt", "no-new-privileges",
                   "--cpus=2", "--memory=6442450944", "--memory-swap=6442450944", "--tmpfs", "/tmp:rw,size=536870912,mode=1777"]
        mounts = [(variant, "/package", "ro"), (package / "vendor", "/package/vendor", "ro"),
                  (pathlib.Path(p["mago_binary"]), "/mago", "ro"),
                  (pathlib.Path(p["corpora"][spec["corpus"]]), "/corpus", "ro"), (dest, "/output", "rw"),
                  (package / "scripts/benchmark-codebase-scan-linux.php", "/runner.php", "ro")]
        if mode == "profile": mounts.append((variant / "SdkWorker.php", "/package/vendor/" + SDK_WORKER, "ro"))
        for src, target, access in mounts: command += ["--mount", f"type=bind,source={src},target={target},readonly" if access == "ro" else f"type=bind,source={src},target={target}"]
        command += ["--entrypoint", "php", IMAGE, "-d", "opcache.enable_cli=0", "/runner.php", f"--payload={spec['payload']}",
                    f"--workers={spec['workers']}", "--deadline=" + str(600000 if mode == "reference" else 30000), "--mode=" + mode]
        print(label, flush=True)
        row = {"spec": spec, "directory": str(dest), "command": command, "valid": False}
        try:
            process = subprocess.run(command, capture_output=True, timeout=930)
            (dest / "container.stdout").write_bytes(process.stdout)
            (dest / "container.stderr").write_bytes(process.stderr)
            row["container_exit"] = process.returncode
            if process.returncode != 0: raise ValueError("Container runner failed")
            report = read_json(dest / "run.json")
            row['metrics'] = report
            stderr = (dest / 'stderr.log').read_text()
            row['request_timeout'] = request_timeout_only(report, stderr)
            if report.get('exit_code') not in (0, 1) or FAILURE.search(stderr):
                raise ValueError('Request timeout' if row['request_timeout'] else 'Analyzer infrastructure failure')
            row["report"] = report
            if report["payload_mib"] != spec["payload"] or report["workers"] != spec["workers"]:
                raise ValueError("Effective settings differ")
            hashes = validate_observation(report, read_json(dest / "stdout.json"), (dest / "stderr.log").read_text(), mode, references.get(spec["corpus"]))
            if mode == "reference": references[spec["corpus"]] = hashes
            elif spec["corpus"] not in references: raise ValueError("Diagnostic reference required before controls")
            row.update(valid=True, diagnostic_count=len(hashes), diagnostic_sha256=digest(hashes))
        except (ValueError, OSError, subprocess.SubprocessError) as error:
            row["failure"] = str(error)
            if isinstance(error, subprocess.TimeoutExpired):
                subprocess.run([docker, 'kill', container_name], capture_output=True, timeout=30)
        after = {root: digest(inventory(root)) for root in p["seals"]}
        row["inputs_unchanged"] = before == after == p["seals"]
        if not row["inputs_unchanged"]: row.update(valid=False, failure="Input changed")
        row["variant_unchanged"] = inventory(variant) == manifest["variants"][variant.name]
        if not row["variant_unchanged"]: row.update(valid=False, failure="Variant changed")
        observations.append(row)
        result = {"protocol_sha256": digest(p), "stage": p["stage"], "observations": observations,
                  "complete": len(observations) == len(p["runs"]), "candidate_pass": False}
        result["candidate_pass"] = p["stage"] == "acceptance" and result["complete"] and all(
            o['valid'] or (o['spec']['variant'] == 'A' and o['spec']['mode'] == 'control' and o.get('request_timeout')
                           and o.get('inputs_unchanged') and o.get('variant_unchanged')) for o in observations)
        write_json(output, result)
        print(json.dumps({k: row.get(k) for k in ("valid", "failure", "diagnostic_count")}), flush=True)
    return result


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--protocol")
    parser.add_argument("--output")
    parser.add_argument('--create-protocol')
    parser.add_argument('--package', default=str(pathlib.Path(__file__).resolve().parents[1]))
    parser.add_argument('--corpus-root')
    parser.add_argument('--mago-binary')
    parser.add_argument('--docker', default='docker')
    args = parser.parse_args()
    try:
        if args.create_protocol:
            if not args.corpus_root or not args.mago_binary: parser.error('Require --corpus-root and --mago-binary')
            if pathlib.Path(args.create_protocol).exists(): raise ValueError('Protocol already exists')
            p = create_protocol(args.package, args.corpus_root, args.mago_binary, args.docker)
            validate_protocol(p)
            write_json(args.create_protocol, p)
            sys.exit(0)
        if not args.protocol or not args.output: parser.error('Require --protocol and --output')
        result = run(read_json(args.protocol), args.output)
        sys.exit(0 if result["stage"] != "acceptance" or result["candidate_pass"] else 1)
    except (ValueError, OSError, subprocess.SubprocessError) as error:
        print(str(error), file=sys.stderr)
        sys.exit(2)
