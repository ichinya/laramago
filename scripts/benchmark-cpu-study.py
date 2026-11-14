"""Profile-compatible, frozen-source comparison of a package candidate and its release."""
import argparse
import hashlib
import json
from pathlib import Path
import re
import statistics
import subprocess
import sys
import time
import uuid

IMAGE = 'php:8.5.4-cli@sha256:cfa277c10f9f1859addbbed6739673a50a9c1b5777f64c0441118eca148b8056'


def sha(data):
    return hashlib.sha256(data).hexdigest()


def quote(value):
    return "'" + value.replace('\\', '\\\\').replace("'", "\\'") + "'"


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--volume', required=True)
    ap.add_argument('--output-dir', required=True, type=Path)
    ap.add_argument('--reference-profile-dir', required=True, type=Path)
    ap.add_argument('--candidate-files', required=True, nargs='+', type=Path)
    ap.add_argument('--variant-name', default='optimized')
    ap.add_argument('--rounds', type=int, default=3)
    ap.add_argument('--request-timeout-ms', type=int, default=600000)
    ap.add_argument('--reuse-release-warmup', type=Path)
    ap.add_argument('--test-script', action='append', default=[])
    ap.add_argument('--profile-before-samples', action='store_true')
    ap.add_argument('--wait-for-native-gates', type=Path)
    args = ap.parse_args()
    if args.rounds < 1 or not re.fullmatch('[a-z0-9-]{1,32}', args.variant_name):
        ap.error('Positive rounds and a short lowercase variant name are required')
    repo = Path(__file__).resolve().parents[1]
    out = args.output_dir.resolve()
    out.mkdir(parents=True, exist_ok=False)
    token = uuid.uuid4().hex[:12]
    report = {'complete': False, 'runs': [], 'rounds': args.rounds, 'vendor_read_only': True,
              'timing_instrumented': False, 'candidate_variant': args.variant_name,
              'quota': {'cpus': 2, 'memory_gib': 6}, 'image': IMAGE,
              'timing_window': 'proc_open through proc_close; reporting and fingerprints excluded',
              'diagnostic_comparison': 'Exact canonical full issue multiset; private digests are not exported'}

    def save():
        temp = out / 'results.json.tmp'
        temp.write_text(json.dumps(report, indent=2) + '\n', encoding='utf-8')
        temp.replace(out / 'results.json')

    def docker(command, purpose, readonly=False, timeout=3720):
        name = 'cpu-study-' + token + '-' + purpose
        mounts = [f'type=volume,source={args.volume},target=/case' + (',readonly' if readonly else ''),
                  f'type=volume,source={args.volume},target=/case/vendor,volume-subpath=vendor,readonly',
                  f'type=bind,source={repo.as_posix()},target=/runner,readonly',
                  f'type=bind,source={out.as_posix()},target=/probe,readonly']
        commandline = ['docker', 'run', '--rm', '--name', name, '--network', 'none', '--cpus', '2', '--memory', '6g']
        for mount in mounts:
            commandline += ['--mount', mount]
        try:
            result = subprocess.run([*commandline, '--workdir', '/case', IMAGE, *command], capture_output=True, timeout=timeout)
        except subprocess.TimeoutExpired:
            subprocess.run(['docker', 'rm', '-f', name], capture_output=True)
            raise RuntimeError('Container deadline; private output withheld') from None
        if result.returncode:
            raise RuntimeError('Container failure; private output withheld')
        return result.stdout

    mapping = {}
    report['candidate_sources'] = {}
    for index, path in enumerate(args.candidate_files):
        path = (repo / path).resolve()
        relative = path.relative_to(repo).as_posix()
        if not relative.startswith('src/'):
            raise RuntimeError('Candidate source must belong to this package')
        raw = path.read_bytes()
        source = raw.decode('utf-8')
        namespace = re.search(r'^namespace\s+([^;]+);', source, re.M)
        cls = re.search(r'^final class\s+(\w+)', source, re.M)
        if namespace is None or cls is None or not namespace[1].startswith('Ichinya\\Laramago\\'):
            raise RuntimeError('Unexpected public package class')
        name = namespace[1] + '\\' + cls[1]
        filename = 'candidate-' + str(index) + '.php'
        (out / filename).write_bytes(raw)
        mapping[name] = filename
        report['candidate_sources'][relative] = sha(raw)
    report['candidate_sha256'] = sha(json.dumps(report['candidate_sources'], sort_keys=True).encode())
    (out / 'candidate-map.json').write_text(json.dumps(mapping, indent=2), encoding='utf-8')
    for source, filename, field in [(repo / 'scripts/benchmark-anonymous-linux.php', 'runner-source.php', 'runner_sha256'),
                                    (Path(__file__), 'experiment-source.py', 'experiment_sha256')]:
        raw = source.read_bytes()
        (out / filename).write_bytes(raw)
        report[field] = sha(raw)
    worker = docker(['cat', '/case/vendor/ichinya/laramago/bin/laramago-worker.php'], 'read-worker', readonly=True).decode().replace('\r\n', '\n')
    needle = '$projectRoot = $argv[2]'
    if worker.count(needle) != 1:
        raise RuntimeError('Release worker shape changed')
    pairs = ', '.join(quote(k) + ' => ' + quote('/probe/' + v) for k, v in mapping.items())
    boot = '$studyLoader = require $autoload;\n$studyMap = [' + pairs + '];\n$studyLoader->addClassMap($studyMap);\n'
    boot += "foreach ($studyMap as $class => $path) { if ((new ReflectionClass($class))->getFileName() !== $path) { throw new RuntimeException('Candidate override failed'); } }\n"
    (out / 'candidate-worker.php').write_bytes(worker.replace(needle, boot + needle).encode())
    (out / 'candidate-autoload.php').write_bytes(("<?php\n$autoload = '/case/vendor/autoload.php';\n" + boot).encode())
    baseline = json.loads((args.reference_profile_dir / 'result.json').read_text(encoding='utf-8'))
    if not all(baseline.get(k) for k in ['valid', 'source_unchanged', 'sealed_workload', 'diagnostic_multiset_verified']):
        raise RuntimeError('Complete release reference required')
    save()
    for test in args.test_script:
        command = ['php', '-d', 'memory_limit=256M', '-r',
                   "putenv('LARAMAGO_TEST_AUTOLOAD=/probe/candidate-autoload.php'); require " + quote('/runner/' + test) + ';']
        output = docker(command, 'test', readonly=True, timeout=300).decode()
        report.setdefault('tests', []).append({'script': test, 'passed': True, 'output': output.strip()})
        save()
    if args.wait_for_native_gates:
        print(json.dumps({'stage': 'waiting-for-native-contract-gates'}), flush=True)
        while True:
            try:
                gates = json.loads(args.wait_for_native_gates.read_text(encoding='utf-8'))
            except (FileNotFoundError, ValueError):
                time.sleep(1)
                continue
            if any(not test.get('passed') for test in gates.get('tests', [])):
                raise RuntimeError('A native contract gate failed; no performance samples started')
            if gates.get('complete'):
                report['native_gates'] = gates
                save()
                break
            time.sleep(1)

    def measure(variant, stage, index):
        profile = 'phpstan-unfiltered' if variant == 'larastan' else ('mago-native' if variant == 'native' else 'mago-laramago')
        seal = '/case/.benchmark-cpu-' + token + '-' + variant if variant in ['larastan', 'native'] else '/case/.benchmark-cpu-reference'
        command = ['php', '-d', 'memory_limit=2G', '/probe/runner-source.php', '--project=/case',
                   '--mago=/case/.benchmark-linux/mago-1.48.1-x86_64-unknown-linux-gnu/mago',
                   '--output=/case/.benchmark-cpu-last.json', '--profile=' + profile, '--threads=2', '--extension-workers=2',
                   '--phpstan-processes=2', '--phpstan-level=10', '--dependency-scope=composer-autoload',
                   '--request-timeout-ms=' + str(args.request_timeout_ms), '--timeout=3600', '--source-seal=/case/.benchmark-v2-input-seal',
                   '--diagnostic-seal=' + seal]
        if variant == args.variant_name:
            command += ['--worker=/probe/candidate-worker.php']
        if variant in ['larastan', 'native'] and stage == 'warmup':
            command += ['--create-diagnostic-seal']
        print(json.dumps({'stage': stage, 'variant': variant, 'round': index}), flush=True)
        try:
            row = json.loads(docker(command, 'measure'))
            if not isinstance(row, dict):
                raise ValueError('Not an observation')
        except (RuntimeError, ValueError):
            report.setdefault('failures', []).append({'variant': variant, 'stage': stage, 'round': index,
                                                      'failure': 'incomplete-container-report'})
            save()
            raise RuntimeError('Previous observations retained; raw output withheld') from None
        row.update(variant=variant, stage=stage, round=index)
        report['runs'].append(row)
        save()
        if not all(row.get(k) for k in ['valid', 'source_unchanged', 'sealed_workload', 'diagnostic_multiset_verified', 'cpu_accounting_complete']):
            raise RuntimeError('Incomplete report, changed input or changed diagnostics retained')
        print(json.dumps({k: row[k] for k in ['variant', 'wall_seconds', 'cpu_seconds', 'diagnostics']}), flush=True)

    warmups = [args.variant_name, 'release', 'larastan']
    if args.reuse_release_warmup:
        old = json.loads(args.reuse_release_warmup.read_text(encoding='utf-8'))
        row = next((r for r in old['runs'] if r['variant'] == 'release' and r['stage'] == 'warmup'), None)
        if row is None or not all(row.get(k) for k in ['valid', 'source_unchanged', 'sealed_workload', 'diagnostic_multiset_verified']):
            raise RuntimeError('Incomplete release warmup cannot be reused')
        if row['scope'] != baseline['scope'] or row['versions'] != baseline['versions']:
            raise RuntimeError('Warmup workload differs')
        report['reused_release_warmup'] = row
        warmups.remove('release')
        save()
    if args.profile_before_samples:
        for profiled in ['release', args.variant_name]:
            print(json.dumps({'stage': 'cpu-profile', 'variant': profiled}), flush=True)
            command = [sys.executable, '-B', str(repo / 'scripts/benchmark-cpu.py'),
                '--volume', args.volume, '--output-dir', str(out / (profiled + '-profile')),
                '--verify-diagnostics', '--timeout', '3600', '--request-timeout-ms', str(args.request_timeout_ms)]
            if profiled == args.variant_name:
                command += ['--candidate-dir', str(out)]
            result = subprocess.run(command, capture_output=True, timeout=3750)
            if result.returncode:
                raise RuntimeError('CPU profile failed; raw output withheld')
            report.setdefault('profiles', {})[profiled] = {'valid': True}
            save()
    for variant in warmups:
        measure(variant, 'warmup', 0)
    for index in range(args.rounds):
        variants = ['release', args.variant_name, 'larastan']
        for variant in variants[index % 3:] + variants[:index % 3]:
            measure(variant, 'sample', index + 1)
    measure('native', 'warmup', 0)
    for index in range(args.rounds):
        measure('native', 'native-control', index + 1)
    report['summaries'] = {}
    for variant in ['release', args.variant_name, 'larastan', 'native']:
        rows = [r for r in report['runs'] if r['variant'] == variant and r['stage'] in ['sample', 'native-control']]
        report['summaries'][variant] = {'n': len(rows), 'diagnostic_multiset_verified': True,
            **{k: {'median': statistics.median(r[k] for r in rows), 'min': min(r[k] for r in rows), 'max': max(r[k] for r in rows)}
               for k in ['wall_seconds', 'cpu_seconds', 'peak_container_memory_mib']}}
    report['complete'] = True
    save()
    print(json.dumps({'complete': True, 'summaries': report['summaries']}), flush=True)


if __name__ == '__main__':
    main()
