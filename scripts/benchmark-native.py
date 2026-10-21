"""Frozen baseline/candidate comparison on Linux, in fresh systemd user scopes."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import runpy
import statistics
import subprocess
import uuid


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def quote(value):
    return "'" + str(value).replace('\\', '\\\\').replace("'", "\\'") + "'"


def override_location(variant, contents, installed, frozen):
    if b'__DIR__' not in contents and b'__FILE__' not in contents:
        return frozen
    # A moved PHP file changes relative includes and source constants. Preserve
    # the baseline's real location only when its installed bytes are identical.
    # Dependency seals surround every observation, including this real file.
    if variant != 'baseline':
        raise RuntimeError('Candidate source constants require a package-preserving snapshot')
    if not installed.is_file() or installed.read_bytes() != contents:
        raise RuntimeError('Installed baseline source constants cannot be relocated or substituted')
    return installed


def baseline_overrides(manifest_path, baseline_commit):
    if manifest_path is None:
        return {}, None
    manifest = json.loads(manifest_path.read_text())
    report_path = Path(manifest['study']).resolve()
    report = json.loads(report_path.read_text())
    required = ['valid', 'source_unchanged', 'sealed_workload', 'diagnostic_multiset_verified',
                'dependency_inputs_unchanged', 'cpu_accounting_complete']
    if (not report.get('complete') or report.get('baseline_commit') != baseline_commit
            or not report.get('runs') or not all(all(r.get(k) for k in required) for r in report['runs'])):
        raise RuntimeError('Baseline overrides require a complete verified native study')
    sources = manifest['sources']
    if not sources or set(sources) != set(report['candidate_sources']):
        raise RuntimeError('Baseline manifest must identify the entire previous candidate')
    result = {}
    for name, value in sources.items():
        path = Path(value).resolve()
        if not name.startswith('src/') or not path.is_file():
            raise RuntimeError('A previous baseline source is absent or changed')
        contents = path.read_bytes()
        if sha(contents) != report['candidate_sources'][name]:
            raise RuntimeError('A previous baseline source is absent or changed')
        result[name] = contents
    return result, {'description': manifest['description'], 'study_sha256': sha(report_path.read_bytes())}


def snapshot_inventory(root):
    return {p.relative_to(root).as_posix(): sha(p.read_bytes())
            for p in root.rglob('*') if p.is_file()}


def nullable_profile_calls(snapshots):
    calls = 0
    for worker in snapshots:
        sections = worker.get('sections')
        # PHP encodes an empty associative array as [], which is valid for a
        # worker receiving no nullable issues in this scheduler distribution.
        if sections == []:
            sections = {}
        if not isinstance(sections, dict):
            raise RuntimeError('Malformed worker profile sections')
        calls += sum(row['calls'] for name, row in sections.items() if name.startswith('nullable-current /'))
    return calls


def package_snapshot(destination, installed, overrides):
    """Keep relative includes in one private tree; never rewrite PHP source constants."""
    sources = {}
    for directory in ['src', 'bin', 'presets']:
        for path in (installed / directory).rglob('*'):
            if path.is_file():
                sources[path.relative_to(installed).as_posix()] = path.read_bytes()
    if (installed / 'composer.json').is_file():
        sources['composer.json'] = (installed / 'composer.json').read_bytes()
    for name, contents in overrides.items():
        if not name.startswith('src/') or '..' in Path(name).parts or name not in sources:
            raise RuntimeError('Package override must identify an existing source within src/')
        sources[name] = contents
    destination.mkdir(parents=True, exist_ok=False)
    mapping = {}
    for name, contents in sources.items():
        path = destination / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(contents)
        if not name.startswith('src/') or not name.endswith('.php'):
            continue
        text = contents.decode()
        namespace = re.search(r'^namespace\s+([^;{]+);', text, re.M)
        if namespace is None:
            continue
        for cls in re.findall(r'^(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s+(\w+)', text, re.M):
            full = namespace[1] + '\\' + cls
            if not full.startswith('Ichinya\\Laramago\\') or full in mapping:
                raise RuntimeError('Ambiguous package snapshot declaration')
            mapping[full] = path
    return mapping, snapshot_inventory(destination)


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--project', required=True, type=Path)
    ap.add_argument('--php', required=True, type=Path)
    ap.add_argument('--mago', required=True, type=Path)
    ap.add_argument('--output-dir', required=True, type=Path)
    ap.add_argument('--gates', required=True, type=Path)
    ap.add_argument('--baseline-ref', default='0.1.6')
    ap.add_argument('--baseline-sources', type=Path, help='Verified source manifest from a previous complete native study')
    ap.add_argument('--expected-laramago-version', default='0.1.5')
    ap.add_argument('--candidate-files', required=True, nargs='+', type=Path)
    ap.add_argument('--package-snapshots', action='store_true', help='Preserve src/bin/presets layout for relative package includes')
    ap.add_argument('--diagnostic-reference', type=Path, help='Verify every observation against an existing full diagnostic seal without replacing it')
    ap.add_argument('--rounds', type=int, default=4)
    ap.add_argument('--focus-nullable', action='store_true', help='Skip SDK callback attribution; retain nullable sections only')
    ap.add_argument('--request-timeout-ms', type=int, default=1800000)
    ap.add_argument('--timeout', type=int, default=3600)
    args = ap.parse_args()
    if args.rounds < 2 or args.rounds % 2:
        ap.error('Use an even number of rounds for balanced baseline/candidate positions')
    repo = Path(__file__).resolve().parents[1]
    project, php, mago = (p.resolve() for p in (args.project, args.php, args.mago))
    vendor = project / 'vendor'
    if os.access(vendor, os.W_OK):
        raise RuntimeError('Use a disposable case with a non-writable vendor directory')
    gates = json.loads(args.gates.read_text())
    if not gates.get('complete') or not gates.get('tests') or not all(t.get('passed') for t in gates['tests']):
        raise RuntimeError('Complete native correctness gates are required')
    out = args.output_dir.resolve()
    out.mkdir(parents=True, exist_ok=False)
    token = uuid.uuid4().hex[:12]
    report = {'complete': False, 'runs': [], 'profiles': {}, 'rounds': args.rounds,
              'environment': 'native Linux; fresh systemd user scope for every observation',
              'quota': {'cpus': 2, 'memory_gib': 6}, 'vendor_read_only': True,
              'baseline_commit': subprocess.check_output(['git', 'rev-parse', args.baseline_ref + '^{commit}'], cwd=repo).decode().strip(),
              'baseline_sources': {}, 'candidate_sources': {}, 'native_gates': gates['tests'], 'installed_baseline_sources': [],
              'order': 'alternating pairs; each variant occupies each position equally',
              'callback_sites': 0 if args.focus_nullable else 14,
              'profile_note': 'Profiles and warmups are excluded from timing summaries. Nested CPU sections are not additive.'}
    def save():
        tmp = out / 'results.json.tmp'
        tmp.write_text(json.dumps(report, indent=2) + '\n')
        tmp.replace(out / 'results.json')
    previous, previous_metadata = baseline_overrides(args.baseline_sources, report['baseline_commit'])
    if not set(previous).issubset(p.as_posix() for p in args.candidate_files):
        raise RuntimeError('Every previous baseline override must also be present in candidate-files')
    if previous_metadata is not None:
        report['baseline_source_overrides'] = previous_metadata
    worker = (vendor / 'ichinya/laramago/bin/laramago-worker.php').read_text().replace('\r\n', '\n')
    marker = '$projectRoot = $argv[2]'
    if worker.count(marker) != 1:
        raise RuntimeError('Unexpected pinned worker bootstrap')
    mappings = {'baseline': {}, 'candidate': {}}
    for index, relative in enumerate(args.candidate_files):
        path = (repo / relative).resolve()
        name = path.relative_to(repo).as_posix()
        if not name.startswith('src/'):
            raise RuntimeError('Overrides must belong to package source')
        raw = path.read_bytes()
        if sha(raw) != gates['production_source_sha256'][name]:
            raise RuntimeError('Candidate differs from the native correctness snapshot')
        namespace = re.search(r'^namespace\s+([^;]+);', raw.decode(), re.M)
        cls = re.search(r'^final class\s+(\w+)', raw.decode(), re.M)
        if namespace is None or cls is None or not namespace[1].startswith('Ichinya\\Laramago\\'):
            raise RuntimeError('Unexpected package override class')
        full = namespace[1] + '\\' + cls[1]
        baseline = previous[name] if name in previous else subprocess.check_output(['git', 'show', report['baseline_commit'] + ':' + name], cwd=repo)
        for variant, contents in [('baseline', baseline), ('candidate', raw)]:
            frozen = out / (variant + '-' + str(index) + '.php')
            frozen.write_bytes(contents)
            mappings[variant][full] = frozen if args.package_snapshots else override_location(variant, contents, vendor / 'ichinya/laramago' / name, frozen)
            if mappings[variant][full] != frozen:
                report['installed_baseline_sources'].append(name)
            report[variant + '_sources'][name] = sha(contents)
    verified_classes = set(mappings['candidate'])
    packages = {}
    if args.package_snapshots:
        for variant, mapping in mappings.items():
            overrides = {name: (out / (variant + '-' + str(index) + '.php')).read_bytes()
                         for index, name in enumerate(report[variant + '_sources'])}
            directory = out / (variant + '-package')
            complete_mapping, inventory = package_snapshot(directory, vendor / 'ichinya/laramago', overrides)
            if not verified_classes.issubset(complete_mapping):
                raise RuntimeError('Selected override missing from the package class map')
            if variant == 'candidate':
                actual = {name: sha((directory / name).read_bytes().replace(b'\r\n', b'\n'))
                          for name in inventory if name.startswith('src/') and name.endswith('.php')}
                if actual != gates['production_source_sha256']:
                    raise RuntimeError('Entire candidate package differs from the native correctness snapshot')
            mappings[variant] = complete_mapping
            packages[variant] = (directory, inventory)
        report['package_snapshots'] = {variant: inventory for variant, (_, inventory) in packages.items()}
        report['package_snapshot_native_inventory_verified'] = True
    def boot(mapping):
        pairs = ', '.join(quote(k) + ' => ' + quote(v) for k, v in mapping.items())
        checks = [key for key in mapping if key in verified_classes or not key.startswith('Ichinya\\Laramago\\')]
        return ('$nativeLoader=require $autoload;\n$nativeMap=[' + pairs + '];\n$nativeLoader->addClassMap($nativeMap);\n'
                '$nativeChecks=[' + ', '.join(quote(key) for key in checks) + '];\n'
                'foreach($nativeChecks as $class){if((new ReflectionClass($class))->getFileName()!==$nativeMap[$class]){throw new RuntimeException("Native source override failed");}}\n')
    report['worker_sha256'] = {}
    for variant, mapping in mappings.items():
        wrapper = out / (variant + '-worker.php')
        wrapper.write_text(worker.replace(marker, boot(mapping) + marker))
        report['worker_sha256'][variant] = sha(wrapper.read_bytes())
    runner = repo / 'scripts/benchmark-anonymous-linux.php'
    (out / 'runner.php').write_bytes(runner.read_bytes())
    report['runner_sha256'] = sha(runner.read_bytes())
    report['experiment_sha256'] = sha(Path(__file__).read_bytes())
    report['php_sha256'] = sha(php.read_bytes())
    report['mago_sha256'] = sha(mago.read_bytes())
    frozen = {p: sha(p.read_bytes()) for p in out.glob('*.php')}
    def check_frozen():
        if any(snapshot_inventory(directory) != inventory for directory, inventory in packages.values()):
            raise RuntimeError('Package snapshot paths or bytes changed')
        if any(not p.is_file() or sha(p.read_bytes()) != digest for p, digest in frozen.items()):
            raise RuntimeError('Frozen package or harness source changed')
        if sha(php.read_bytes()) != report['php_sha256'] or sha(mago.read_bytes()) != report['mago_sha256']:
            raise RuntimeError('Pinned executable changed')
    def dependencies():
        return {p.relative_to(vendor).as_posix(): sha(p.read_bytes()) for p in vendor.rglob('*')
                if p.is_file() and p.suffix in ['.php', '.json', '.toml', '.lock', '.neon']}
    dependency_seal = dependencies()
    (project / ('.benchmark-native-' + token + '-dependencies.json')).write_text(json.dumps(dependency_seal))
    seal = args.diagnostic_reference.resolve() if args.diagnostic_reference else project / ('.benchmark-native-' + token + '-reference')
    if args.diagnostic_reference and not seal.is_file():
        raise RuntimeError('Existing diagnostic reference is absent')
    reference_bytes = seal.read_bytes() if args.diagnostic_reference else None
    save()
    sequence = 0
    def measure(variant, stage, index, override=None, create=False):
        nonlocal sequence
        sequence += 1
        unit = 'laramago-native-' + token + '-' + str(sequence)
        command = ['systemd-run', '--user', '--scope', '--quiet', '--unit=' + unit,
                   '-p', 'CPUQuota=200%', '-p', 'MemoryMax=6G', str(php), '-d', 'memory_limit=2G',
                   '-d', 'opcache.enable_cli=0', str(out / 'runner.php'), '--native-host',
                   '--project=' + str(project), '--mago=' + str(mago),
                   '--output=' + str(project / '.benchmark-native-last.json'), '--profile=mago-laramago',
                   '--threads=2', '--extension-workers=2', '--dependency-scope=composer-autoload',
                   '--expected-laramago-version=' + args.expected_laramago_version,
                   '--request-timeout-ms=' + str(args.request_timeout_ms), '--timeout=' + str(args.timeout),
                   '--source-seal=' + str(project / ('.benchmark-native-' + token + '-source')), '--diagnostic-seal=' + str(seal),
                   '--worker=' + str(override or out / (variant + '-worker.php'))]
        if create:
            command.append('--create-diagnostic-seal')
        print(json.dumps({'stage': stage, 'variant': variant, 'round': index}), flush=True)
        check_frozen()
        if dependencies() != dependency_seal:
            raise RuntimeError('Dependency input paths or bytes changed before measurement')
        try:
            result = subprocess.run(command, cwd=project, capture_output=True, timeout=args.timeout + 120)
        except subprocess.TimeoutExpired as error:
            subprocess.run(['systemctl', '--user', 'kill', '--kill-whom=all', unit + '.scope'], capture_output=True)
            (out / (str(sequence) + '.stdout')).write_bytes(error.stdout or b'')
            (out / (str(sequence) + '.stderr')).write_bytes(error.stderr or b'')
            report.setdefault('failures', []).append({'variant': variant, 'stage': stage, 'failure': 'scope-deadline'})
            save()
            raise RuntimeError('Native scope deadline; owned processes stopped') from None
        (out / (str(sequence) + '.stderr')).write_bytes(result.stderr)
        (out / (str(sequence) + '.stdout')).write_bytes(result.stdout)
        if result.returncode:
            report.setdefault('failures', []).append({'variant': variant, 'stage': stage, 'exit_code': result.returncode})
            save()
            raise RuntimeError('Native runner failed; local stdout and stderr retained')
        try:
            row = json.loads(result.stdout)
            if not isinstance(row, dict):
                raise ValueError('Expected an observation object')
        except (ValueError, UnicodeDecodeError):
            report.setdefault('failures', []).append({'variant': variant, 'stage': stage, 'failure': 'malformed-json'})
            save()
            raise RuntimeError('Malformed native output retained; observation rejected') from None
        check_frozen()
        row['dependency_inputs_unchanged'] = dependencies() == dependency_seal
        if not row['dependency_inputs_unchanged']:
            report['runs'].append(row)
            save()
            raise RuntimeError('Dependency input paths or bytes changed during measurement')
        row.update(variant=variant, stage=stage, round=index)
        report['runs'].append(row)
        save()
        if not all(row.get(k) for k in ['valid', 'source_unchanged', 'sealed_workload', 'diagnostic_multiset_verified', 'cpu_accounting_complete']):
            raise RuntimeError('Incomplete native observation retained')
        print(json.dumps({k: row[k] for k in ['variant', 'wall_seconds', 'cpu_seconds', 'diagnostics']}), flush=True)
        return row
    helper_path = out / 'profiler-helper.py'
    helper_path.write_bytes((repo / 'scripts/benchmark-cpu.py').read_bytes())
    frozen[helper_path] = sha(helper_path.read_bytes())
    report['profiler_helper_sha256'] = sha(helper_path.read_bytes())
    helpers = runpy.run_path(str(helper_path))
    nullable_class = 'Ichinya\\Laramago\\Analyzer\\NullableCollectionOffsetContract'
    observer = (repo / 'scripts/cpu-observer.php').read_bytes()
    def profile(variant, create=False):
        directory = out / (variant + '-profile')
        directory.mkdir()
        metrics = directory / 'metrics'
        metrics.mkdir()
        mapping = dict(mappings[variant])
        source = mapping[nullable_class].read_text().replace('\r\n', '\n')
        source = helpers['instrument_nullable'](source)
        target = directory / 'NullableCollectionOffsetContract.php'
        target.write_text(source)
        mapping[nullable_class] = target
        sdk = vendor / 'carthage-software/mago/composer/src/Sdk/Worker.php'
        target = directory / 'SdkWorker.php'
        target.write_text(helpers['instrument_shutdown' if args.focus_nullable else 'instrument'](sdk.read_text()))
        mapping['Mago\\Sdk\\Worker'] = target
        observer_path = directory / 'observer.php'
        observer_path.write_bytes(observer.replace(b'/metrics/', (str(metrics) + '/').encode()))
        wrapper = directory / 'worker.php'
        wrapper.write_text(worker.replace(marker, 'require ' + quote(observer_path) + ';\n' + boot(mapping) + marker))
        frozen.update({p: sha(p.read_bytes()) for p in directory.glob('*.php')})
        row = measure(variant, 'profile', 0, wrapper, create=create)
        snapshots = [json.loads(p.read_text()) for p in metrics.glob('cpu-*.json')]
        if not snapshots or nullable_profile_calls(snapshots) == 0:
            raise RuntimeError('No nullable CPU sections were captured')
        # Every completed current() flushes; a complete analyzer report proves all
        # filter invocations returned, even if the host force-stops workers later.
        report['profiles'][variant] = {'result': row, 'workers': snapshots,
            'observer_template_sha256': sha(observer),
            'instrumented_sources': {p.name: sha(p.read_bytes()) for p in directory.glob('*.php')},
            'completion': 'full analyzer multiset seal plus synchronous flush after every nullable current()'}
        save()
    profile('baseline', create=not args.diagnostic_reference)
    profile('candidate')
    for variant in ['baseline', 'candidate']:
        measure(variant, 'warmup', 0)
    for index in range(args.rounds):
        order = ['baseline', 'candidate'] if index % 2 == 0 else ['candidate', 'baseline']
        for variant in order:
            measure(variant, 'sample', index + 1)
    report['summaries'] = {}
    for variant in ['baseline', 'candidate']:
        rows = [r for r in report['runs'] if r['variant'] == variant and r['stage'] == 'sample']
        report['summaries'][variant] = {'n': len(rows), **{
            key: {'median': statistics.median(r[key] for r in rows), 'min': min(r[key] for r in rows), 'max': max(r[key] for r in rows)}
            for key in ['wall_seconds', 'cpu_seconds', 'peak_container_memory_mib']}}
    check_frozen()
    if reference_bytes is not None and seal.read_bytes() != reference_bytes:
        raise RuntimeError('Existing diagnostic reference changed')
    report['complete'] = True
    save()
    print(json.dumps({'complete': True, 'summaries': report['summaries']}), flush=True)


if __name__ == '__main__':
    main()
