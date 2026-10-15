"""Aggregate-only Windows benchmark. Source and diagnostic messages stay private."""
from __future__ import annotations

import argparse
from collections import Counter
import ctypes as C
from ctypes import wintypes as W
from datetime import datetime, timezone
import hashlib
import json
import os
from pathlib import Path
import platform
import re
import statistics
import subprocess
import time


class BasicLimits(C.Structure):
    _fields_ = [('process_time', C.c_longlong), ('job_time', C.c_longlong),
                ('flags', W.DWORD), ('minimum_ws', C.c_size_t), ('maximum_ws', C.c_size_t),
                ('process_limit', W.DWORD), ('affinity', C.c_size_t),
                ('priority', W.DWORD), ('scheduling', W.DWORD)]


class IOCounters(C.Structure):
    _fields_ = [(name, C.c_ulonglong) for name in ('read_ops', 'write_ops', 'other_ops', 'read_bytes', 'write_bytes', 'other_bytes')]


class ExtendedLimits(C.Structure):
    _fields_ = [('basic', BasicLimits), ('io', IOCounters),
                ('process_limit', C.c_size_t), ('job_limit', C.c_size_t),
                ('peak_process', C.c_size_t), ('peak_job', C.c_size_t)]


class Accounting(C.Structure):
    _fields_ = [('user_time', C.c_longlong), ('kernel_time', C.c_longlong),
                ('period_user', C.c_longlong), ('period_kernel', C.c_longlong),
                ('faults', W.DWORD), ('total_processes', W.DWORD),
                ('active_processes', W.DWORD), ('terminated_processes', W.DWORD)]


K = C.WinDLL('kernel32', use_last_error=True) if os.name == 'nt' else None
if K:
    K.CreateJobObjectW.argtypes = [C.c_void_p, W.LPCWSTR]
    K.CreateJobObjectW.restype = W.HANDLE
    K.AssignProcessToJobObject.argtypes = [W.HANDLE, W.HANDLE]
    K.AssignProcessToJobObject.restype = W.BOOL
    K.QueryInformationJobObject.argtypes = [W.HANDLE, C.c_int, C.c_void_p, W.DWORD, C.c_void_p]
    K.QueryInformationJobObject.restype = W.BOOL
    K.TerminateJobObject.argtypes = [W.HANDLE, W.UINT]
    K.CloseHandle.argtypes = [W.HANDLE]


def measure(command, root, env, deadline):
    job = K.CreateJobObjectW(None, None)
    if not job:
        raise RuntimeError('Cannot create a Windows measurement job')
    start = time.perf_counter()
    process = subprocess.Popen(command, cwd=root, env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if not K.AssignProcessToJobObject(job, W.HANDLE(int(process._handle))):
        process.kill()
        K.CloseHandle(job)
        raise RuntimeError('Cannot attach analyzer to measurement job')
    timed_out = False
    try:
        try:
            out, err = process.communicate(timeout=deadline)
        except subprocess.TimeoutExpired:
            timed_out = True
            K.TerminateJobObject(job, 124)
            out, err = process.communicate()
        elapsed = time.perf_counter() - start
        memory = ExtendedLimits()
        accounting = Accounting()
        if not K.QueryInformationJobObject(job, 9, C.byref(memory), C.sizeof(memory), None):
            raise RuntimeError('Cannot read job memory')
        if not K.QueryInformationJobObject(job, 1, C.byref(accounting), C.sizeof(accounting), None):
            raise RuntimeError('Cannot read job accounting')
        return {'wall_seconds': round(elapsed, 4),
                'peak_job_commit_mib': round(memory.peak_job / 1048576, 2),
                'cpu_seconds': round((accounting.user_time + accounting.kernel_time) / 10000000, 4),
                'process_count': accounting.total_processes, 'exit_code': process.returncode,
                'timed_out': timed_out}, out.decode('utf-8-sig', errors='replace'), err.decode('utf-8', errors='replace')
    finally:
        K.CloseHandle(job)


def aggregate(body, stderr, is_mago):
    data = json.loads(body)
    if is_mago:
        if not isinstance(data, dict) or 'issues' not in data:
            raise RuntimeError('Analyzer did not return a complete issue report')
        lower = (body + '\n' + stderr).lower()
        if 'parsing errors' in lower and 'incomplete' in lower:
            raise RuntimeError('Dependency parsing was incomplete')
        failed = any(s in lower for s in ('external analyzer provider failed', 'rejected request',
                                         'extension worker failed', 'extension host failed',
                                         'provider timed out', 'worker timed out'))
        if failed:
            raise RuntimeError('Extension failed; fallback is not a valid extension benchmark')
        issues = data['issues']
        codes = Counter(i.get('code') or 'uncoded' for i in issues)
        levels = Counter(i['level'] for i in issues)
        files = {a.get('span', {}).get('file_id', {}).get('name') for i in issues for a in i.get('annotations', [])}
        files.discard(None)
    else:
        if 'totals' not in data or data['totals'].get('errors', 0):
            raise RuntimeError('PHPStan returned global errors; report is incomplete')
        issues = [i for f in data.get('files', {}).values() for i in f.get('messages', [])]
        codes = Counter(i.get('identifier') or 'uncoded' for i in issues)
        levels = Counter({'Error': len(issues)})
        files = set(data.get('files', {}))
    return {'diagnostics': len(issues), 'files_with_diagnostics': len(files),
            'levels': dict(sorted(levels.items())), 'codes': dict(sorted(codes.items()))}


def save(path, report, forbidden):
    raw = json.dumps(report, ensure_ascii=False, indent=2) + '\n'
    if any(token.casefold() in raw.casefold() for token in forbidden):
        raise RuntimeError('Privacy check rejected generated aggregate')
    path.write_text(raw, encoding='utf-8')


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--project', type=Path, required=True)
    ap.add_argument('--php', type=Path, required=True)
    ap.add_argument('--output', type=Path, required=True)
    ap.add_argument('--runs', type=int, default=1)
    ap.add_argument('--control-runs', type=int, default=1)
    ap.add_argument('--warm-runs', type=int, default=3)
    ap.add_argument('--timeout', type=int, default=900)
    ap.add_argument('--resume', action='store_true')
    ap.add_argument('--forbid-token', action='append', default=[])
    args = ap.parse_args()
    if not K:
        ap.error('This benchmark measures Windows Job Object memory; Windows is required')
    root = args.project.resolve()
    vendor = root / 'vendor'
    args.output.parent.mkdir(parents=True, exist_ok=True)
    installed = json.loads((vendor / 'composer/installed.json').read_text(encoding='utf-8'))['packages']
    names = ('phpstan/phpstan', 'larastan/larastan', 'laravel/framework', 'ichinya/laramago', 'carthage-software/mago')
    versions = {p['name']: p['version'] for p in installed if p['name'] in names}
    assert versions['ichinya/laramago'] == '0.1.5', 'Expected the pinned published release'
    mago = next((vendor / 'carthage-software/mago/composer/bin').glob('**/mago.exe'))
    env = os.environ.copy()
    env['PATH'] = str(args.php.parent) + os.pathsep + env.get('PATH', '')
    for key in ('LARAMAGO_EVALUATE_RUNTIME', 'LARAMAGO_RUNTIME_METADATA', 'MAGO_THREADS'):
        env.pop(key, None)
    env.update(APP_ENV='testing', APP_DEBUG='false', APP_URL='http://example.invalid',
               DB_CONNECTION='sqlite', DB_DATABASE=':memory:', CACHE_STORE='array',
               SESSION_DRIVER='array', QUEUE_CONNECTION='sync', MAIL_MAILER='array')
    ini_dir = root / '.benchmark-php-ini'
    ini_dir.mkdir(exist_ok=True)
    (ini_dir / '99-benchmark.ini').write_text('opcache.enable_cli=0\nopcache.jit=disable\nopcache.jit_buffer_size=0\nmemory_limit=2G\n', encoding='utf-8')
    # PHPStan starts another PHP process. CLI -d flags alone do not propagate.
    # The inherited scan directory applies these settings to every child too.
    env['PHP_INI_SCAN_DIR'] = str(ini_dir)
    php = [str(args.php), '-d', 'opcache.enable_cli=0', '-d', 'memory_limit=2G']
    probe = json.loads(subprocess.check_output([str(args.php), '-r', 'echo json_encode([ini_get("opcache.enable_cli"), ini_get("memory_limit")]);'], env=env))
    assert probe == ['0', '2G'], 'PHP child environment did not apply benchmark INI settings'
    php_version = subprocess.check_output([*php, '-r', 'echo PHP_VERSION;'], env=env).decode()
    mago_version = subprocess.check_output([str(mago), '--version'], env=env).decode().strip()
    roots = ['app', 'tests', 'scripts/testing', 'testo.php']
    files = []
    for relative in roots:
        path = root / relative
        files.extend(sorted(path.rglob('*.php')) if path.is_dir() else [path])
    counts = {'php_files': len(files), 'physical_lines': sum(len(p.read_bytes().splitlines()) for p in files),
              'bytes': sum(p.stat().st_size for p in files)}
    worker = [str(args.php), '-d', 'opcache.enable_cli=0', '-d', 'memory_limit=2G',
              str(vendor / 'ichinya/laramago/bin/laramago-worker.php'), str(vendor / 'autoload.php'), str(root)]
    mago_config = {'extends': ['mago.toml', 'vendor/ichinya/laramago/presets/laravel.toml'],
                   'version': '1.48.1', 'php-version': '8.5', 'threads': 1,
                   'source': {'paths': roots, 'includes': ['vendor'],
                              'excludes': ['vendor/laravel/framework/tests/Foundation/Fixtures/bad-syntax-strategy.php']},
                   'extension-hosts': {'laramago': {'command': worker, 'workers': 1, 'request-timeout-ms': 120000}}}
    mago_path = root / 'benchmark-mago.json'
    mago_path.write_text(json.dumps(mago_config, indent=2), encoding='utf-8')
    base_phpstan = [*php, str(vendor / 'bin/phpstan'), 'analyse', '--memory-limit=2G', '--error-format=json', '--no-progress', '--no-ansi']
    base_mago = [str(mago), '--config', str(mago_path), '--threads', '1', 'analyze', *roots, '--reporting-format=json']
    profiles = ['phpstan-level5-configured', 'mago-laramago', 'phpstan-level5-unfiltered', 'phpstan-max-unfiltered', 'mago-native']
    primary_profiles = {'phpstan-level5-configured', 'mago-laramago'}
    report = {'case': 'anonymous-laravel-case-01', 'date': datetime.now(timezone.utc).date().isoformat(),
              'environment': {'os': platform.system(), 'os_release': platform.release(), 'architecture': platform.machine(),
                              'logical_cpus': os.cpu_count(), 'php': php_version, 'php_cli_opcache': False,
                              'analysis_workers': 1, 'versions': versions, 'mago_binary_version': mago_version,
                              'mago_binary_sha256': hashlib.sha256(mago.read_bytes()).hexdigest()},
              'scope': {'roots': roots, **counts},
              'method': {'primary_full_runs_per_profile': args.runs, 'control_full_runs_per_profile': args.control_runs,
                         'incremental_runs': args.warm_runs,
                         'php_child_settings': 'PHP_INI_SCAN_DIR propagates OPcache off and 2G memory limit to PHPStan child and Laramago worker',
                         'php_child_ini_probe': {'opcache_enable_cli': False, 'memory_limit': '2G'},
                         'extension_request_timeout_ms': 120000,
                         'dependency_fixture_exclusions': ['vendor/laravel/framework/tests/Foundation/Fixtures/bad-syntax-strategy.php'],
                         'full_run_cache': 'fresh PHPStan tmpDir for each full run; OS file cache is not flushed',
                         'memory': 'Windows Job Object PeakJobMemoryUsed (process-tree peak committed memory), MiB',
                         'memory_assignment': 'root process assigned immediately after spawn; descendants inherit job membership',
                         'timeout_seconds': args.timeout, 'runtime_schema_collection': False,
                         'privacy': 'No source names, repository identifiers, source excerpts, diagnostic messages or file paths are exported'},
              'runs': [], 'summaries': {}}
    if args.resume and args.output.exists():
        previous = json.loads(args.output.read_text(encoding='utf-8'))
        assert previous['environment'] == report['environment'] and previous['scope'] == report['scope'], 'Cannot mix benchmark environments or workloads'
        report['runs'] = previous['runs']
        report['setup_probes'] = previous.get('setup_probes', [])
    configs = {}
    run_id = datetime.now(timezone.utc).strftime('%Y%m%d-%H%M%S')
    for iteration in range(max(args.runs, args.control_runs)):
        order = profiles[iteration % len(profiles):] + profiles[:iteration % len(profiles)]
        for profile in order:
            if iteration >= (args.runs if profile in primary_profiles else args.control_runs):
                continue
            if any(r['profile'] == profile and r['run'] == iteration + 1 and r['cache'] == 'full' for r in report['runs']):
                continue
            print(f'Start {profile} full run {iteration + 1}', flush=True)
            if profile.startswith('phpstan'):
                config = root / f'benchmark-{profile}-{iteration + 1}.neon'
                cache_path = root / '.benchmark-cache' / run_id / profile / str(iteration + 1)
                assert not cache_path.exists(), 'Full-run cache must be fresh'
                temp = cache_path.as_posix()
                value = 'includes:\n    - phpstan.neon\nparameters:\n    tmpDir: ' + json.dumps(temp) + '\n    parallel:\n        maximumNumberOfProcesses: 1\n'
                if profile != 'phpstan-level5-configured':
                    value += '    ignoreErrors!: []\n'
                config.write_text(value, encoding='utf-8')
                command = [*base_phpstan, '-c', str(config)]
                command.append('--level=max' if profile == 'phpstan-max-unfiltered' else '--level=5')
                configs[profile] = command
            else:
                command = base_mago + (['--no-extensions'] if profile == 'mago-native' else [])
            metrics, out, err = measure(command, str(root), env, args.timeout)
            record = {'profile': profile, 'run': iteration + 1, 'cache': 'full', **metrics}
            if metrics['timed_out']:
                record['valid'] = False
                record['failure'] = 'deadline exceeded'
            else:
                try:
                    if 'did not answer request' in err and 'within' in err:
                        raise RuntimeError('extension-request-timeout')
                    assert metrics['exit_code'] in (0, 1), 'Analyzer returned an infrastructure exit status'
                    record.update(aggregate(out, err, profile.startswith('mago')))
                    record['valid'] = True
                except (ValueError, AssertionError, RuntimeError) as error:
                    record['valid'] = False
                    record['failure'] = str(error)
            report['runs'].append(record)
            save(args.output, report, args.forbid_token)
            print(json.dumps({k: record[k] for k in ('profile', 'run', 'wall_seconds', 'peak_job_commit_mib', 'exit_code', 'valid')}), flush=True)
    for iteration in range(args.warm_runs):
        profile = 'phpstan-level5-configured-warm'
        if any(r['profile'] == profile and r['run'] == iteration + 1 for r in report['runs']):
            continue
        print(f'Start {profile} run {iteration + 1}', flush=True)
        warm_command = configs.get('phpstan-level5-configured') or [*base_phpstan, '-c', str(root / f'benchmark-phpstan-level5-configured-{args.runs}.neon'), '--level=5']
        metrics, out, err = measure([*warm_command, '-vv'], str(root), env, args.timeout)
        record = {'profile': profile, 'run': iteration + 1, 'cache': 'result-cache', **metrics,
                  **aggregate(out, err, False), 'valid': True}
        restored = re.search(r'Result cache restored\.\s*(\d+) files will be reanalysed', err, re.I)
        record['result_cache_reanalysed_files'] = int(restored.group(1)) if restored else None
        record['result_cache_used'] = bool(restored and int(restored.group(1)) == 0) or 'result cache is used' in err.lower()
        report['runs'].append(record)
        save(args.output, report, args.forbid_token)
    for profile in profiles + ['phpstan-level5-configured-warm']:
        rows = [r for r in report['runs'] if r['profile'] == profile]
        valid_rows = [r for r in rows if r['valid']]
        summary = {'runs': len(rows), 'successful_runs': len(valid_rows),
                   'complete': len(valid_rows) == len(rows),
                   'failures': sorted({r.get('failure', 'unknown') for r in rows if not r['valid']}),
                   'diagnostic_counts': sorted({r['diagnostics'] for r in valid_rows}),
                   'aggregate_diagnostics_stable': (len({json.dumps([r['levels'], r['codes']], sort_keys=True) for r in valid_rows}) == 1) if len(valid_rows) > 1 else None,
                   'levels': valid_rows[0]['levels'] if valid_rows else {}, 'codes': valid_rows[0]['codes'] if valid_rows else {}}
        for metric in ('wall_seconds', 'peak_job_commit_mib', 'cpu_seconds'):
            values = [r[metric] for r in rows]
            summary[metric] = {'min': min(values), 'median': round(statistics.median(values), 4), 'max': max(values)}
        report['summaries'][profile] = summary
    report['completed_at'] = datetime.now(timezone.utc).isoformat()
    save(args.output, report, args.forbid_token)
    print('Aggregate benchmark collection completed; failures remain explicitly marked', flush=True)


if __name__ == '__main__':
    main()
