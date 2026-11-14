"""Sequential, counterbalanced full-analysis samples; no private diagnostics leave Docker."""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import statistics
import subprocess

IMAGE = 'php:8.5.4-cli@sha256:cfa277c10f9f1859addbbed6739673a50a9c1b5777f64c0441118eca148b8056'
PROFILES = ['mago-laramago', 'phpstan-unfiltered', 'mago-native']


def summarize(rows):
    summaries = {}
    for profile in PROFILES:
        samples = [r for r in rows if r['stage'] == 'sample' and r['profile'] == profile and r.get('valid')]
        if not samples:
            continue
        counts = [(r['diagnostics'], r['codes'], r['levels']) for r in samples]
        if any(value != counts[0] for value in counts):
            raise RuntimeError('Diagnostics differ between repeated samples')
        summaries[profile] = {'n': len(samples), 'diagnostics_stable': True,
                              'diagnostics': samples[0]['diagnostics']}
        for metric in ['wall_seconds', 'cpu_seconds', 'peak_container_memory_mib']:
            values = [r[metric] for r in samples]
            summaries[profile][metric] = {'median': statistics.median(values),
                                         'min': min(values), 'max': max(values)}
    return summaries


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--volume', required=True)
    ap.add_argument('--output', type=Path, required=True)
    ap.add_argument('--mago', default='/case/.benchmark-linux/mago-1.48.1-x86_64-unknown-linux-gnu/mago')
    ap.add_argument('--runs', type=int, default=3)
    ap.add_argument('--timeout', type=int, default=3600)
    ap.add_argument('--resume', action='store_true')
    args = ap.parse_args()
    if args.runs < 3 or args.runs % 3 or args.timeout <= 0:
        ap.error('Use at least three samples, in complete three-round Latin-square blocks')
    scripts = Path(__file__).resolve().parent
    runner_sha = hashlib.sha256((scripts / 'benchmark-anonymous-linux.php').read_bytes()).hexdigest()
    protocol = {'image': IMAGE, 'volume': args.volume, 'mago': args.mago,
                'runs': args.runs, 'warmup_rounds': 1, 'cpu_quota': 2,
                'memory_limit_mib': 6144, 'parallelism': 2,
                'phpstan_level': 10, 'config_ignore_errors': 0,
                'whole_command_timeout_seconds': args.timeout,
                'extension_request_timeout_ms': 600000,
                'runner_sha256': runner_sha,
                'disk_cache': 'OS cache not flushed; one full warmup per tool excluded',
                'phpstan_cache': 'new tmpDir per invocation; cache miss confirmed from verbose output',
                'configuration_probe_cache': 'separate tmpDir, never the measured analyzer cache',
                'sealed_inputs': 'selected sources, config, migrations, composer files, tool configs and bootstrap entrypoints; no private hashes exported',
                'order': 'one excluded warmup round, then cyclic three-tool Latin-square rounds'}
    report = {'schema_version': 1, 'protocol': protocol, 'runs': [], 'complete': False}
    if args.resume and args.output.exists():
        report = json.loads(args.output.read_text(encoding='utf-8'))
        if report['protocol'] != protocol:
            raise RuntimeError('Cannot resume a changed protocol or runner')
        if report.get('failures') or any(not r.get('valid') for r in report['runs']):
            raise RuntimeError('Use a new output after a failed observation; preserve the original receipt')
    elif args.output.exists():
        raise FileExistsError('Output already exists; use --resume or a new file')
    args.output.parent.mkdir(parents=True, exist_ok=True)
    for stage, rounds in [('warmup', 1), ('sample', args.runs)]:
        for iteration in range(rounds):
            order = PROFILES[iteration % 3:] + PROFILES[:iteration % 3]
            if stage == 'warmup':
                order = PROFILES[1:] + PROFILES[:1]
            for position, profile in enumerate(order):
                key = (stage, iteration + 1, profile)
                if any((r['stage'], r['round'], r['profile']) == key for r in report['runs']):
                    continue
                name = f'laramago-bench-v2-{stage}-{iteration + 1}-{profile}'
                command = ['docker', 'run', '--rm', '--name', name, '--network', 'none',
                           '--cpus', '2', '--memory', '6g',
                           '--mount', f'type=volume,source={args.volume},target=/case',
                           '--mount', f'type=volume,source={args.volume},target=/case/vendor,volume-subpath=vendor,readonly',
                           '--mount', f'type=bind,source={scripts.as_posix()},target=/runner,readonly',
                           '--workdir', '/case', IMAGE, 'php', '-d', 'memory_limit=2G',
                           '-d', 'opcache.enable_cli=0', '/runner/benchmark-anonymous-linux.php',
                           '--project=/case', '--mago=' + args.mago,
                           '--output=/case/.benchmark-v2-last.json', '--profile=' + profile,
                           '--threads=2', '--extension-workers=2', '--phpstan-processes=2',
                           '--phpstan-level=10', '--dependency-scope=composer-autoload',
                           '--source-seal=/case/.benchmark-v2-input-seal',
                           '--request-timeout-ms=600000', '--timeout=' + str(args.timeout)]
                print(json.dumps({'stage': stage, 'round': iteration + 1, 'profile': profile,
                                  'position': position + 1}), flush=True)
                try:
                    result = subprocess.run(command, capture_output=True, timeout=args.timeout + 120)
                except subprocess.TimeoutExpired:
                    subprocess.run(['docker', 'rm', '-f', name], capture_output=True)
                    report.setdefault('failures', []).append({'stage': stage, 'round': iteration + 1,
                        'profile': profile, 'valid': False, 'failure': 'container-safety-deadline'})
                    args.output.write_text(json.dumps(report, indent=2) + '\n', encoding='utf-8')
                    raise RuntimeError('Container safety deadline exceeded') from None
                if result.returncode:
                    report.setdefault('failures', []).append({'stage': stage, 'round': iteration + 1,
                        'profile': profile, 'valid': False, 'container_exit': result.returncode})
                    args.output.write_text(json.dumps(report, indent=2) + '\n', encoding='utf-8')
                    raise RuntimeError(f'Container failed with exit {result.returncode}; raw streams are private')
                try:
                    row = json.loads(result.stdout)
                    if not isinstance(row, dict):
                        raise ValueError('Expected an observation object')
                except ValueError:
                    report.setdefault('failures', []).append({'stage': stage, 'round': iteration + 1,
                        'profile': profile, 'valid': False, 'failure': 'invalid-analyzer-json'})
                    args.output.write_text(json.dumps(report, indent=2) + '\n', encoding='utf-8')
                    raise RuntimeError('Malformed analyzer report retained as failure; raw streams are private') from None
                row.update(stage=stage, round=iteration + 1, position=position + 1)
                if row.get('valid') and (not row.get('source_unchanged') or not row.get('sealed_workload')):
                    row.update(valid=False, failure='source-control-failed')
                if row.get('valid') and profile.startswith('phpstan') and (
                        not row.get('fresh_result_cache_confirmed') or row.get('phpstan_config_ignore_count') != 0
                        or not row.get('phpstan_tmp_dir_absent_before_start') or not row.get('phpstan_probe_cache_separate')):
                    row.update(valid=False, failure='cache-or-ignore-control-failed')
                report['runs'].append(row)
                args.output.write_text(json.dumps(report, indent=2) + '\n', encoding='utf-8')
                if not row['valid'] or not row['source_unchanged'] or not row['sealed_workload']:
                    raise RuntimeError('Incomplete or changed-source observation retained; suite stopped')
                if profile.startswith('phpstan') and (not row['fresh_result_cache_confirmed']
                                                     or row['phpstan_config_ignore_count'] != 0
                                                     or not row['phpstan_tmp_dir_absent_before_start']
                                                     or not row['phpstan_probe_cache_separate']):
                    raise RuntimeError('PHPStan cache or ignore control failed')
                try:
                    report['summaries'] = summarize(report['runs'])
                except RuntimeError:
                    report.setdefault('failures', []).append({'stage': stage, 'round': iteration + 1,
                        'profile': profile, 'valid': False, 'failure': 'diagnostic-aggregate-instability'})
                    args.output.write_text(json.dumps(report, indent=2) + '\n', encoding='utf-8')
                    raise
                args.output.write_text(json.dumps(report, indent=2) + '\n', encoding='utf-8')
                print(json.dumps({k: row[k] for k in ['profile', 'wall_seconds', 'diagnostics', 'valid']}), flush=True)
    report['complete'] = True
    args.output.write_text(json.dumps(report, indent=2) + '\n', encoding='utf-8')
    print(json.dumps({'complete': True, 'summaries': report['summaries']}), flush=True)


if __name__ == '__main__':
    main()
