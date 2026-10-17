"""Compare release and isolated syntax-cache experiments; vendor remains read-only."""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import statistics
import subprocess
import sys
import uuid

IMAGE = 'php:8.5.4-cli@sha256:cfa277c10f9f1859addbbed6739673a50a9c1b5777f64c0441118eca148b8056'


def sha(data):
    return hashlib.sha256(data).hexdigest()


def prepare_overlays(worker, cache, reader):
    """Only exact-byte syntax reuse changes; freshness and native decisions stay outside."""
    needle = 'private const MAX_BYTES = 64 * 1024 * 1024;'
    if cache.count(needle) != 1:
        raise RuntimeError('Unexpected release cache')
    larger = cache.replace(needle, 'private const MAX_BYTES = 192 * 1024 * 1024;')
    needle = ('$nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($contents) ?? [];\n'
              '            $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes);')
    if reader.count(needle) != 1:
        raise RuntimeError('Unexpected release reader')
    shared = reader.replace(needle, '$nodes = \\Ichinya\\Laramago\\Analyzer\\StaticAnalysis\\CodebaseScanSyntax::parse($contents);')
    files = {}
    for name, changes in {'cache192': {'CodebaseScanSyntax': larger},
                          'cache192-reader': {'CodebaseScanSyntax': larger, 'GuardedStringCastContracts': shared}}.items():
        mapping = {}
        for short, source in changes.items():
            filename = name + '-' + short + '.php'
            files[filename] = source
            namespace = 'Ichinya\\Laramago\\Analyzer\\' + ('StaticAnalysis\\' if short == 'CodebaseScanSyntax' else '')
            mapping[namespace + short] = '/probe/' + filename
        pairs = ', '.join("'" + key + "' => '" + value + "'" for key, value in mapping.items())
        boot = "$benchmarkLoader = require $autoload;\n$benchmarkLoader->addClassMap([" + pairs + "]);\n"
        for cls, path in mapping.items():
            boot += ("if ((new \\ReflectionClass('" + cls + "'))->getFileName() !== '" + path
                     + "') { throw new \\RuntimeException('Experiment class override failed.'); }\n")
        if worker.count('$projectRoot = $argv[2]') != 1:
            raise RuntimeError('Unexpected release worker')
        files[name + '-worker.php'] = worker.replace('$projectRoot = $argv[2]', boot + '$projectRoot = $argv[2]')
        files[name + '-autoload.php'] = "<?php\n$autoload = '/case/vendor/autoload.php';\n" + boot
    return files


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--volume', required=True)
    ap.add_argument('--output-dir', required=True, type=Path)
    ap.add_argument('--timeout', type=int, default=1200)
    ap.add_argument('--pilot-only', action='store_true', help='Collect bounded independent pilots; never mark a comparative study complete')
    ap.add_argument('--variants', nargs='+', choices=['release', 'cache192', 'cache192-reader', 'phpstan10', 'native'])
    args = ap.parse_args()
    if args.variants and not args.pilot_only:
        ap.error('--variants requires --pilot-only; full studies use the balanced schedule')
    repo = Path(__file__).resolve().parents[1]
    output = args.output_dir.resolve()
    output.mkdir(parents=True, exist_ok=False)
    token = uuid.uuid4().hex[:12]
    report = {'schema_version': 1, 'complete': False, 'runs': [], 'depth': {},
              'protocol': {'image': IMAGE, 'cpu_quota': 2, 'memory_limit_mib': 6144,
                  'php_process_memory_limit': '2G', 'parallelism': 2, 'measured_rounds': 0 if args.pilot_only else 3,
                  'phpstan_level': 10, 'config_ignore_errors': 0, 'whole_command_timeout_seconds': args.timeout,
                  'vendor_read_only': True, 'network': 'none', 'pilot_samples_excluded': True,
                  'disk_cache': 'warm disk; no OS cache flush',
                  'candidate_selection': 'fastest valid pilot; independent balanced measurement rounds follow',
                  'diagnostic_reference': 'independent per-variant seals; no release equivalence claim' if args.pilot_only else 'release multiset',
                  'runner_sha256': sha((repo / 'scripts/benchmark-anonymous-linux.php').read_bytes()),
                  'experiment_sha256': sha(Path(__file__).read_bytes()),
                  'limitation': 'One private workload on a shared workstation; no general speed ranking.'}}

    def save():
        (output / 'results.json').write_text(json.dumps(report, indent=2) + '\n', encoding='utf-8')

    def docker(command, name, mounts, workdir='/case'):
        argv = ['docker', 'run', '--rm', '--name', name, '--network', 'none', '--cpus', '2', '--memory', '6g']
        for mount in mounts:
            argv += ['--mount', mount]
        argv += ['--workdir', workdir, IMAGE, *command]
        try:
            return subprocess.run(argv, capture_output=True, timeout=args.timeout + 120)
        except subprocess.TimeoutExpired:
            subprocess.run(['docker', 'rm', '-f', name], capture_output=True)
            raise RuntimeError('Experiment container deadline exceeded') from None

    readonly = [f'type=volume,source={args.volume},target=/case,readonly']
    stock = {}
    for short, relative in {'worker': 'bin/laramago-worker.php',
                            'cache': 'src/Analyzer/StaticAnalysis/CodebaseScanSyntax.php',
                            'reader': 'src/Analyzer/GuardedStringCastContracts.php'}.items():
        result = docker(['cat', '/case/vendor/ichinya/laramago/' + relative], 'bench-opt-' + token + '-read-' + short, readonly)
        if result.returncode:
            raise RuntimeError('Cannot read the pinned release sources')
        raw = result.stdout
        stock[short] = raw.decode().replace('\r\n', '\n')
        report['protocol']['release_' + short + '_sha256'] = sha(raw)
        (output / ('release-' + short + '.php')).write_bytes(raw)
    files = prepare_overlays(stock['worker'], stock['cache'], stock['reader'])
    report['protocol']['overlay_sha256'] = {}
    for name, source in files.items():
        raw = source.encode()
        (output / name).write_bytes(raw)
        report['protocol']['overlay_sha256'][name] = sha(raw)
    mounts = [f'type=volume,source={args.volume},target=/case',
              f'type=volume,source={args.volume},target=/case/vendor,volume-subpath=vendor,readonly',
              f'type=bind,source={repo.as_posix()},target=/runner,readonly',
              f'type=bind,source={output.as_posix()},target=/probe,readonly']
    save()

    def measure(variant, stage, round_number, position, create=False):
        profile = 'phpstan-unfiltered' if variant == 'phpstan10' else ('mago-native' if variant == 'native' else 'mago-laramago')
        seal_kind = 'phpstan' if variant == 'phpstan10' else ('native' if variant == 'native' else 'laramago')
        if args.pilot_only:
            seal_kind = variant
        command = ['php', '-d', 'memory_limit=2G', '-d', 'opcache.enable_cli=0', '/runner/scripts/benchmark-anonymous-linux.php',
                   '--project=/case', '--mago=/case/.benchmark-linux/mago-1.48.1-x86_64-unknown-linux-gnu/mago',
                   '--output=/case/.benchmark-opt-last.json', '--profile=' + profile,
                   '--threads=2', '--extension-workers=2', '--phpstan-processes=2', '--phpstan-level=10',
                   '--dependency-scope=composer-autoload', '--request-timeout-ms=600000', '--timeout=' + str(args.timeout),
                   '--source-seal=/case/.benchmark-opt-' + token + '-inputs',
                   '--diagnostic-seal=/case/.benchmark-opt-' + token + '-' + seal_kind]
        if variant.startswith('cache'):
            command += ['--worker=/probe/' + variant + '-worker.php']
        if create:
            command += ['--create-diagnostic-seal']
        print(json.dumps({'stage': stage, 'variant': variant, 'round': round_number, 'position': position}), flush=True)
        try:
            result = docker(command, f'bench-opt-{token}-{stage}-{round_number}-{variant}', mounts)
            if result.returncode:
                raise RuntimeError('Container infrastructure failure')
            row = json.loads(result.stdout)
            if not isinstance(row, dict):
                raise ValueError('Not an observation')
        except (RuntimeError, ValueError):
            report.setdefault('failures', []).append({'variant': variant, 'stage': stage, 'round': round_number,
                                                       'failure': 'incomplete-container-report'})
            save()
            raise RuntimeError('Experiment stopped; raw container output stays private') from None
        row.update(variant=variant, stage=stage, round=round_number, position=position)
        report['runs'].append(row)
        save()
        if not all(row.get(k) for k in ['valid', 'source_unchanged', 'sealed_workload', 'diagnostic_multiset_verified']):
            if args.pilot_only and row.get('source_unchanged'):
                print(json.dumps({'variant': variant, 'valid': False, 'timed_out': row.get('timed_out')}), flush=True)
                return row
            raise RuntimeError('Incomplete, changed-source or changed-diagnostic observation retained')
        if variant == 'phpstan10' and (row['phpstan_config_ignore_count'] != 0 or not all(row.get(k) for k in
                ['fresh_result_cache_confirmed', 'phpstan_tmp_dir_absent_before_start', 'phpstan_probe_cache_separate'])):
            raise RuntimeError('PHPStan cache/ignore control failed')
        print(json.dumps({k: row[k] for k in ['variant', 'wall_seconds', 'cpu_seconds', 'peak_container_memory_mib', 'diagnostics']}), flush=True)
        return row

    pilots = {}
    for position, variant in enumerate(args.variants or ['release', 'cache192', 'cache192-reader', 'phpstan10', 'native'], 1):
        pilots[variant] = measure(variant, 'pilot', 0, position, args.pilot_only or variant in ['release', 'phpstan10', 'native'])
    if args.pilot_only:
        report['pilot_collection_finished'] = True
        save()
        print(json.dumps({'pilot_collection_finished': True, 'comparative_study_complete': False}), flush=True)
        return
    chosen = min(['cache192', 'cache192-reader'], key=lambda v: pilots[v]['wall_seconds'])
    report['selected_candidate'] = chosen
    save()

    # Repeat the public matrix for both workers before accepting optimization timings.
    depth_original = (repo / 'scripts/benchmark-depth.php').read_text(encoding='utf-8')
    for variant in ['release', chosen]:
        harness = depth_original.replace("dirname(__DIR__).'/tests/fixtures/analyzer-depth/cases.php'",
                                         "'/runner/tests/fixtures/analyzer-depth/cases.php'")
        if variant != 'release':
            harness = harness.replace("$root.'/vendor/ichinya/laramago/bin/laramago-worker.php'", "'/probe/" + variant + "-worker.php'")
        harness_path = output / (variant + '-depth-harness.php')
        harness_path.write_bytes(harness.encode())
        depth_mounts = [f'type=volume,source={args.volume},target=/fixture/vendor,volume-subpath=vendor,readonly',
                        f'type=volume,source={args.volume},target=/tools,volume-subpath=.benchmark-linux,readonly',
                        f'type=bind,source={repo.as_posix()},target=/runner,readonly',
                        f'type=bind,source={output.as_posix()},target=/probe']
        print(json.dumps({'stage': 'depth', 'variant': variant}), flush=True)
        result = docker(['php', '-d', 'memory_limit=2G', '/probe/' + harness_path.name, '--dependencies=/fixture',
                         '--root=/fixture', '--mago=/tools/mago-1.48.1-x86_64-unknown-linux-gnu/mago',
                         '--output=/probe/' + variant + '-depth.json'], 'bench-opt-' + token + '-depth-' + variant, depth_mounts, '/fixture')
        if result.returncode:
            raise RuntimeError('Depth execution failed; private failure output was not exported')
        checked = subprocess.run([sys.executable, '-B', str(repo / 'tests/analyzer-depth-results.py'),
                     '--result', str(output / (variant + '-depth.json')), '--summary', str(output / (variant + '-depth-summary.json')),
                     '--harness', str(harness_path)], capture_output=True)
        if checked.returncode:
            raise RuntimeError('Depth oracle verification failed')
        report['depth'][variant] = json.loads((output / (variant + '-depth.json')).read_text(encoding='utf-8'))
        save()
    left = {k: v for k, v in report['depth']['release'].items() if k != 'harness_sha256'}
    right = {k: v for k, v in report['depth'][chosen].items() if k != 'harness_sha256'}
    if left != right:
        raise RuntimeError('Candidate changed the public depth matrix')
    report['depth_matrix_identical'] = True

    profiles = ['release', chosen, 'phpstan10']
    for round_number in range(3):
        order = profiles[round_number:] + profiles[:round_number]
        for position, variant in enumerate(order, 1):
            measure(variant, 'sample', round_number + 1, position)
    for round_number in range(3):
        measure('native', 'native-control', round_number + 1, 1)
    report['summaries'] = {}
    for variant in [*profiles, 'native']:
        rows = [r for r in report['runs'] if r['variant'] == variant and r['stage'] in ['sample', 'native-control']]
        summary = {'n': len(rows), 'diagnostics': rows[0]['diagnostics'], 'diagnostic_multisets_identical': True}
        for metric in ['wall_seconds', 'cpu_seconds', 'peak_container_memory_mib']:
            values = [r[metric] for r in rows]
            summary[metric] = {'median': statistics.median(values), 'min': min(values), 'max': max(values)}
        report['summaries'][variant] = summary
    report['complete'] = True
    save()
    print(json.dumps({'complete': True, 'selected_candidate': chosen, 'summaries': report['summaries']}), flush=True)


if __name__ == '__main__':
    main()
