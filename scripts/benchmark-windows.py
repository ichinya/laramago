"""Native Windows process-tree measurements; suspended assignment prevents accounting races.

Use only a disposable application copy. Private stdout/stderr stay in output-dir.
PeakJobMemoryUsed is committed process memory, not Linux cgroup memory with file cache.
API reference: https://learn.microsoft.com/windows/win32/procthread/job-objects
"""
from __future__ import annotations
import argparse
from collections import Counter
import ctypes as C
from ctypes import wintypes as W
import json
import hashlib
import os
from pathlib import Path
import platform
import shutil
import statistics
import subprocess
import sys
import time
import traceback


class BasicLimits(C.Structure):
    _fields_ = [('process_time', C.c_longlong), ('job_time', C.c_longlong),
                ('flags', W.DWORD), ('minimum_ws', C.c_size_t), ('maximum_ws', C.c_size_t),
                ('process_limit', W.DWORD), ('affinity', C.c_size_t),
                ('priority', W.DWORD), ('scheduling', W.DWORD)]


class IOCounters(C.Structure):
    _fields_ = [(name, C.c_ulonglong) for name in
                ('read_ops', 'write_ops', 'other_ops', 'read_bytes', 'write_bytes', 'other_bytes')]


class ExtendedLimits(C.Structure):
    _fields_ = [('basic', BasicLimits), ('io', IOCounters),
                ('process_limit', C.c_size_t), ('job_limit', C.c_size_t),
                ('peak_process', C.c_size_t), ('peak_job', C.c_size_t)]


class Accounting(C.Structure):
    _fields_ = [('user_time', C.c_longlong), ('kernel_time', C.c_longlong),
                ('period_user', C.c_longlong), ('period_kernel', C.c_longlong),
                ('faults', W.DWORD), ('total_processes', W.DWORD),
                ('active_processes', W.DWORD), ('terminated_processes', W.DWORD)]


class Security(C.Structure):
    _fields_ = [('size', W.DWORD), ('descriptor', C.c_void_p), ('inherit', W.BOOL)]


class Startup(C.Structure):
    _fields_ = [('size', W.DWORD), ('reserved', W.LPWSTR), ('desktop', W.LPWSTR),
                ('title', W.LPWSTR), ('x', W.DWORD), ('y', W.DWORD),
                ('x_size', W.DWORD), ('y_size', W.DWORD), ('x_count', W.DWORD),
                ('y_count', W.DWORD), ('fill', W.DWORD), ('flags', W.DWORD),
                ('show', W.WORD), ('reserved_size', W.WORD), ('reserved_bytes', C.c_void_p),
                ('stdin', W.HANDLE), ('stdout', W.HANDLE), ('stderr', W.HANDLE)]


class Process(C.Structure):
    _fields_ = [('process', W.HANDLE), ('thread', W.HANDLE), ('pid', W.DWORD), ('tid', W.DWORD)]


class MemoryStatus(C.Structure):
    _fields_ = [('size', W.DWORD), ('load_percent', W.DWORD),
                *[(n, C.c_ulonglong) for n in ('total_physical', 'available_physical', 'total_page',
                  'available_page', 'total_virtual', 'available_virtual', 'available_extended')]]


K = C.WinDLL('kernel32', use_last_error=True) if os.name == 'nt' else None
if K:
    declarations = {
        'CreateJobObjectW': ([C.c_void_p, W.LPCWSTR], W.HANDLE),
        'SetInformationJobObject': ([W.HANDLE, C.c_int, C.c_void_p, W.DWORD], W.BOOL),
        'QueryInformationJobObject': ([W.HANDLE, C.c_int, C.c_void_p, W.DWORD, C.c_void_p], W.BOOL),
        'AssignProcessToJobObject': ([W.HANDLE, W.HANDLE], W.BOOL),
        'IsProcessInJob': ([W.HANDLE, W.HANDLE, C.POINTER(W.BOOL)], W.BOOL),
        'TerminateJobObject': ([W.HANDLE, W.UINT], W.BOOL),
        'TerminateProcess': ([W.HANDLE, W.UINT], W.BOOL),
        'CloseHandle': ([W.HANDLE], W.BOOL),
        'GetCurrentProcess': ([], W.HANDLE),
        'GetProcessAffinityMask': ([W.HANDLE, C.POINTER(C.c_size_t), C.POINTER(C.c_size_t)], W.BOOL),
        'CreateFileW': ([W.LPCWSTR, W.DWORD, W.DWORD, C.POINTER(Security), W.DWORD, W.DWORD, W.HANDLE], W.HANDLE),
        'CreateProcessW': ([W.LPCWSTR, W.LPWSTR, C.c_void_p, C.c_void_p, W.BOOL, W.DWORD,
                           C.c_void_p, W.LPCWSTR, C.POINTER(Startup), C.POINTER(Process)], W.BOOL),
        'ResumeThread': ([W.HANDLE], W.DWORD),
        'WaitForSingleObject': ([W.HANDLE, W.DWORD], W.DWORD),
        'GetExitCodeProcess': ([W.HANDLE, C.POINTER(W.DWORD)], W.BOOL),
        'GetSystemTimes': ([C.POINTER(C.c_ulonglong), C.POINTER(C.c_ulonglong), C.POINTER(C.c_ulonglong)], W.BOOL),
        'GlobalMemoryStatusEx': ([C.POINTER(MemoryStatus)], W.BOOL),
        'OpenProcess': ([W.DWORD, W.BOOL, W.DWORD], W.HANDLE),
        'QueryFullProcessImageNameW': ([W.HANDLE, W.DWORD, W.LPWSTR, C.POINTER(W.DWORD)], W.BOOL),
    }
    for name, (arguments, result) in declarations.items():
        function = getattr(K, name); function.argtypes = arguments; function.restype = result


def checked(value, operation):
    if not value:
        raise OSError(C.get_last_error(), operation)
    return value


def affinity(cpus=2):
    process_mask = C.c_size_t(); system_mask = C.c_size_t()
    checked(K.GetProcessAffinityMask(K.GetCurrentProcess(), C.byref(process_mask), C.byref(system_mask)), 'Read affinity')
    bits = [1 << i for i in range(C.sizeof(C.c_size_t) * 8) if process_mask.value & (1 << i)]
    if len(bits) < cpus:
        raise RuntimeError('Not enough allowed logical processors')
    return sum(bits[:cpus])


def host_state():
    idle = C.c_ulonglong(); kernel = C.c_ulonglong(); user = C.c_ulonglong()
    checked(K.GetSystemTimes(C.byref(idle), C.byref(kernel), C.byref(user)), 'Read host CPU')
    memory = MemoryStatus(); memory.size = C.sizeof(memory)
    checked(K.GlobalMemoryStatusEx(C.byref(memory)), 'Read host memory')
    return {'idle': idle.value, 'total': kernel.value + user.value,
            'available_physical_mib': memory.available_physical / 1048576,
            'commit_limit_mib': memory.total_page / 1048576,
            'available_commit_mib': memory.available_page / 1048576,
            'memory_load_percent': memory.load_percent}


def wait_for_capacity(path, minimum_commit_mib=4096, minimum_physical_mib=4096, stable_seconds=10):
    path = Path(path); start = time.monotonic(); stable = None; count = 0
    while True:
        state = host_state(); now = time.monotonic(); count += 1
        ready = state['available_commit_mib'] >= minimum_commit_mib and state['available_physical_mib'] >= minimum_physical_mib
        stable = (stable if stable is not None else now) if ready else None
        passed = stable is not None and now - stable >= stable_seconds
        data = {'complete': passed, 'samples': count, 'wait_seconds': now - start,
                'minimum_commit_mib': minimum_commit_mib, 'minimum_physical_mib': minimum_physical_mib,
                'required_stable_seconds': stable_seconds, 'checked_at_unix': time.time(), 'state': state}
        temporary = path.with_suffix('.tmp'); temporary.write_text(json.dumps(data, indent=2) + '\n', encoding='utf-8'); temporary.replace(path)
        if passed: return data
        if now - start > 8 * 3600: raise RuntimeError('Windows capacity guard exceeded its eight-hour safety deadline')
        time.sleep(1 if ready else 10)


def information(job):
    memory = ExtendedLimits(); accounting = Accounting()
    checked(K.QueryInformationJobObject(job, 9, C.byref(memory), C.sizeof(memory), None), 'Read job memory')
    checked(K.QueryInformationJobObject(job, 1, C.byref(accounting), C.sizeof(accounting), None), 'Read job accounting')
    return memory, accounting


def measure(command, root, env, output, timeout=3600, affinity_mask=None, memory_gib=6, trace_processes=False):
    if K is None:
        raise RuntimeError('Native Windows is required')
    output = Path(output); output.mkdir(parents=True, exist_ok=False)
    job = checked(K.CreateJobObjectW(None, None), 'Create measurement job')
    limits = ExtendedLimits(); limits.basic.flags = 0x2000 | 0x200 | 0x10
    limits.basic.affinity = affinity_mask if affinity_mask is not None else affinity()
    limits.job_limit = memory_gib * 1024 ** 3
    handles = []; process = Process(); assigned = False; completed = False
    checked(K.SetInformationJobObject(job, 9, C.byref(limits), C.sizeof(limits)), 'Set isolated job limits')
    before = host_state(); start = time.perf_counter(); timed_out = False; observed_processes = {}
    minimum_commit = before['available_commit_mib']; minimum_physical = before['available_physical_mib']; pressure_abort = False
    try:
        security = Security(C.sizeof(Security), None, True)
        for name, access, disposition in [('NUL', 0x80000000, 3),
                (str(output / 'stdout'), 0x40000000, 2), (str(output / 'stderr'), 0x40000000, 2)]:
            handle = K.CreateFileW(name, access, 3, C.byref(security), disposition, 0x80, None)
            if handle == C.c_void_p(-1).value:
                raise OSError(C.get_last_error(), 'Open private measurement stream')
            handles.append(handle)
        startup = Startup(); startup.size = C.sizeof(startup); startup.flags = 0x100 | 1
        startup.stdin, startup.stdout, startup.stderr = handles
        environment = C.create_unicode_buffer('\0'.join(k + '=' + str(v) for k, v in sorted(env.items(), key=lambda x: x[0].upper())) + '\0\0')
        command_line = C.create_unicode_buffer(subprocess.list2cmdline([str(v) for v in command]))
        checked(K.CreateProcessW(str(command[0]), command_line, None, None, True,
            0x4 | 0x400 | 0x8000000, C.cast(environment, C.c_void_p), str(root),
            C.byref(startup), C.byref(process)), 'Create suspended analyzer')
        checked(K.AssignProcessToJobObject(job, process.process), 'Assign suspended analyzer')
        assigned = True
        member = W.BOOL()
        checked(K.IsProcessInJob(process.process, job, C.byref(member)), 'Verify pre-execution job membership')
        if not member.value:
            raise RuntimeError('Analyzer is outside its measurement job')
        if K.ResumeThread(process.thread) != 1:
            raise RuntimeError('Unexpected initial thread suspension count')
        while K.WaitForSingleObject(process.process, 250) == 0x102:
            pressure = host_state()
            minimum_commit = min(minimum_commit, pressure['available_commit_mib'])
            minimum_physical = min(minimum_physical, pressure['available_physical_mib'])
            if pressure['available_commit_mib'] < 512 or pressure['available_physical_mib'] < 512:
                pressure_abort = True; checked(K.TerminateJobObject(job, 125), 'Stop owned job under host memory exhaustion'); break
            if trace_processes:
                buffer = C.create_string_buffer(8192)
                checked(K.QueryInformationJobObject(job, 3, buffer, C.sizeof(buffer), None), 'Read owned process identities')
                count = C.cast(buffer, C.POINTER(W.DWORD))[1]
                ids = (C.c_size_t * count).from_buffer(buffer, 8)
                for pid in ids:
                    if pid in observed_processes: continue
                    handle = K.OpenProcess(0x1000, False, pid)
                    if not handle: continue
                    try:
                        name = C.create_unicode_buffer(32768); size = W.DWORD(len(name))
                        if K.QueryFullProcessImageNameW(handle, 0, name, C.byref(size)):
                            observed_processes[pid] = Path(name.value).name
                    finally: K.CloseHandle(handle)
            if time.perf_counter() - start > timeout:
                timed_out = True; checked(K.TerminateJobObject(job, 124), 'Stop owned timed-out job'); break
        checked(K.WaitForSingleObject(process.process, 10000) == 0, 'Wait for analyzer exit')
        root_elapsed = time.perf_counter() - start
        drain_start = time.perf_counter()
        while information(job)[1].active_processes:
            if time.perf_counter() - drain_start > 15:
                raise RuntimeError('Analyzer left live descendants in its measurement job')
            time.sleep(0.02)
        memory, accounting = information(job); after = host_state(); code = W.DWORD()
        checked(K.GetExitCodeProcess(process.process, C.byref(code)), 'Read analyzer exit')
        completed = True
        total = after['total'] - before['total']; idle = after['idle'] - before['idle']
        result = {'wall_seconds': root_elapsed, 'tree_drain_seconds': time.perf_counter() - start - root_elapsed,
            'cpu_seconds': (accounting.user_time + accounting.kernel_time) / 10000000,
            'peak_job_commit_mib': memory.peak_job / 1048576, 'process_count': accounting.total_processes,
            'active_processes_at_completion': accounting.active_processes, 'exit_code': code.value, 'root_pid': process.pid,
            'timed_out': timed_out, 'assigned_while_suspended': True, 'affinity_mask': limits.basic.affinity,
            'host_memory_pressure_abort': pressure_abort, 'minimum_available_commit_mib': minimum_commit,
            'minimum_available_physical_mib': minimum_physical,
            'job_memory_limit_gib': memory_gib, 'host_cpu_busy_percent': 100 * (1 - idle / total) if total else None,
            'host_before': before, 'host_after': after}
        (output / 'measurement.json').write_text(json.dumps(result, indent=2) + '\n', encoding='utf-8')
        if trace_processes:
            (output / 'owned-processes.json').write_text(json.dumps(observed_processes, indent=2) + '\n', encoding='utf-8')
        return result
    finally:
        if process.process and not completed:
            if assigned: K.TerminateJobObject(job, 125)
            else: K.TerminateProcess(process.process, 125)
            K.WaitForSingleObject(process.process, 10000)
        for handle in [process.thread, process.process, *handles, job]:
            if handle: K.CloseHandle(handle)


def self_test(output):
    output = Path(output); output.mkdir(parents=True, exist_ok=False)
    wait_for_capacity(output / 'capacity.json')
    child = "import time; x=bytearray(48*1024*1024); t=time.process_time();\nwhile time.process_time()-t<0.15: pass\ntime.sleep(0.5)"
    parent = "import ctypes as c,json,os,subprocess,sys; x=bytearray(32*1024*1024); p=subprocess.Popen([sys.executable,'-c'," + repr(child) + "],creationflags=subprocess.CREATE_NO_WINDOW); print(json.dumps({'parent':os.getpid(),'child':p.pid}),flush=True); assert p.wait()==0"
    positive = measure([sys.executable, '-c', parent], output, os.environ.copy(), output / 'tree', timeout=20, trace_processes=True)
    identities = json.loads((output / 'tree/owned-processes.json').read_text())
    expected = json.loads((output / 'tree/stdout').read_text())
    assert expected['parent'] == positive['root_pid']
    assert all(identities.get(str(pid)) == Path(sys.executable).name for pid in expected.values())
    assert set(identities.values()) <= {Path(sys.executable).name, 'conhost.exe'}
    assert 2 <= len(identities) <= positive['process_count'] <= 4
    assert positive['exit_code'] == 0
    assert positive['peak_job_commit_mib'] > 80 and positive['cpu_seconds'] >= 0.15
    assert positive['assigned_while_suspended'] and positive['active_processes_at_completion'] == 0
    negative = measure([sys.executable, '-c', "import json,os,subprocess,sys,time;p=subprocess.Popen([sys.executable,'-c','import time;time.sleep(10)'],creationflags=subprocess.CREATE_NO_WINDOW);print(json.dumps({'parent':os.getpid(),'child':p.pid}),flush=True);time.sleep(10)"],
        output, os.environ.copy(), output / 'timeout', timeout=0.8, trace_processes=True)
    negative_ids = json.loads((output / 'timeout/owned-processes.json').read_text())
    negative_expected = json.loads((output / 'timeout/stdout').read_text())
    assert all(negative_ids.get(str(pid)) == Path(sys.executable).name for pid in negative_expected.values())
    assert set(negative_ids.values()) <= {Path(sys.executable).name, 'conhost.exe'}
    assert negative['timed_out'] and 2 <= negative['process_count'] <= 4 and negative['active_processes_at_completion'] == 0
    receipt = {'complete': True, 'real_parent_child_accounting': positive, 'owned_tree_timeout': negative,
        'both_real_python_identities_verified_inside_job': True,
        'auxiliary_console_hosts_included': True}
    (output / 'results.json').write_text(json.dumps(receipt, indent=2) + '\n', encoding='utf-8')
    return receipt


def inventory(root):
    return {p.relative_to(root).as_posix(): hashlib.sha256(p.read_bytes()).hexdigest()
            for p in root.rglob('*') if p.is_file()}


def application_inventory(root):
    result = {}
    for name in ['app', 'bootstrap', 'config', 'database', 'lang', 'resources', 'routes', 'scripts',
                 'tests', 'public', 'artisan', 'composer.json', 'composer.lock', 'mago.toml', 'phpstan.neon', 'testo.php']:
        path = root / name
        for p in (path.rglob('*') if path.is_dir() else [path]):
            if not p.is_file(): continue
            relative = p.relative_to(root).as_posix()
            if relative.startswith(('bootstrap/cache/', 'public/storage/')): continue
            if relative.startswith('database/') and '.sqlite' in p.name: continue
            result[relative] = hashlib.sha256(p.read_bytes()).hexdigest()
    return result


def diagnostic_identity(stdout, stderr, mago):
    body = json.loads(stdout.decode('utf-8-sig'))
    text = (stdout + b'\n' + stderr).decode('utf-8', errors='replace').lower()
    failures = ['external analyzer provider failed', 'rejected request', 'extension worker failed',
                'extension host failed', 'external plugin error', 'violated the protocol',
                'protocol i/o failed', 'did not answer request', 'allowed memory size',
                'provider timed out', 'worker timed out', 'configuration-source-changed',
                'virtualalloc() failed', 'invalid extension frame magic']
    if any(value in text for value in failures) or ('parsing errors' in text and 'incomplete' in text):
        raise RuntimeError('Analyzer infrastructure or source-freshness failure')
    if mago:
        if not isinstance(body.get('issues'), list): raise RuntimeError('Incomplete Mago report')
        issues = body['issues']
    else:
        if body.get('totals', {}).get('errors') != 0: raise RuntimeError('Incomplete PHPStan report')
        issues = [{'file': name, **issue} for name, row in body['files'].items() for issue in row['messages']]
        if 'result cache not used because the cache file does not exist.' not in text:
            raise RuntimeError('Fresh PHPStan result cache not confirmed')
    hashes = sorted(hashlib.sha256(json.dumps(i, sort_keys=True, ensure_ascii=False, separators=(',', ':')).encode()).hexdigest() for i in issues)
    codes = Counter(i.get('code') or i.get('identifier') or 'uncoded' for i in issues)
    levels = Counter(i.get('level') or 'Error' for i in issues)
    return {'diagnostics': len(issues), 'codes': dict(sorted(codes.items())), 'levels': dict(sorted(levels.items()))}, hashlib.sha256('\n'.join(hashes).encode()).hexdigest()


def run_study(root, php, mago, output, rounds=4):
    root, php, mago, output = (Path(p).resolve() for p in (root, php, mago, output))
    marker = json.loads((root / '.laramago-benchmark-case.json').read_text())
    if marker['case'] != str(root) or (root / '.git').exists():
        raise RuntimeError('Use a marked disposable copy, not a source checkout')
    if rounds <= 0 or rounds % 4: raise ValueError('Use a positive multiple of four balanced rounds')
    output.mkdir(parents=True, exist_ok=False)
    setup = output / 'setup'; setup.mkdir()
    ini = setup / 'ini'; ini.mkdir()
    (ini / '99-benchmark.ini').write_text('opcache.enable_cli=0\nopcache.jit=disable\nopcache.jit_buffer_size=0\nmemory_limit=2G\n', encoding='utf-8')
    env = os.environ.copy(); env['PATH'] = str(php.parent) + os.pathsep + env.get('PATH', '')
    env['PHP_INI_SCAN_DIR'] = str(ini)
    for key in ['LARAMAGO_EVALUATE_RUNTIME', 'LARAMAGO_RUNTIME_METADATA', 'MAGO_THREADS']: env.pop(key, None)
    env.update(APP_ENV='testing', APP_DEBUG='false', APP_URL='http://example.invalid', DB_CONNECTION='sqlite',
               DB_DATABASE=':memory:', DB_URL='null', CACHE_STORE='array', SESSION_DRIVER='array',
               SESSION_CONNECTION='sqlite', SESSION_STORE='array', QUEUE_CONNECTION='sync', MAIL_MAILER='array')
    helper = Path(__file__).with_name('benchmark-windows-config.php')
    command = [str(php), '-d', 'opcache.enable_cli=0', '-d', 'memory_limit=2G']
    configuration = subprocess.run([*command, str(helper), str(root), str(setup)], cwd=root, env=env, capture_output=True)
    (setup / 'config.stdout').write_bytes(configuration.stdout); (setup / 'config.stderr').write_bytes(configuration.stderr)
    if configuration.returncode: raise RuntimeError('Windows configuration preparation failed; private streams retained')
    config = json.loads(configuration.stdout); assert config['opcache'] == '0' and config['heap'] == '2G'
    effective = subprocess.run([str(mago), '--config', config['mago_config'], 'config', '--show', 'source', '--no-extensions'], cwd=root, env=env, capture_output=True, check=True)
    (setup / 'effective-source.json').write_bytes(effective.stdout)
    scope = json.loads(effective.stdout)
    assert scope['paths'] == config['roots'] and not any(p.replace('\\', '/').rstrip('/').lower() in ['vendor', str(root / 'vendor').replace('\\', '/').lower()] for p in scope['includes'])
    sources = application_inventory(root); dependencies = inventory(root / 'vendor')
    (output / 'source-inputs.json').write_text(json.dumps(sources, indent=2) + '\n')
    (output / 'dependency-inputs.json').write_text(json.dumps(dependencies, indent=2) + '\n')
    frozen = {p: hashlib.sha256(p.read_bytes()).hexdigest() for p in [php, mago, Path(__file__), helper, setup / 'mago.json', ini / '99-benchmark.ini']}
    mask = affinity(2)
    names = ['mago-native', 'mago-laramago', 'larastan-level5', 'larastan-level10']
    williams = [[0, 1, 3, 2], [1, 2, 0, 3], [2, 3, 1, 0], [3, 0, 2, 1]]
    assert all(sorted(row[position] for row in williams) == list(range(4)) for position in range(4))
    assert all(sum(row.index(a) < row.index(b) for row in williams) == 2 for a in range(4) for b in range(a + 1, 4))
    orders = [list(reversed(row)) if cycle % 2 else row for cycle in range(rounds // 4) for row in williams]
    php_files = [p for name in config['roots'] for p in ((root / name).rglob('*.php') if (root / name).is_dir() else [root / name])]
    report = {'complete': False, 'phase': 'prepared', 'started_at_unix': time.time(), 'pid': os.getpid(),
        'case': 'anonymous-laravel-case-01', 'source_commit': marker['source_commit'], 'rounds': rounds,
        'design': 'Four excluded full warmups, then balanced Williams orders; every configuration occupies every position equally, and pairwise order is balanced. No trimming, retries or result-cache samples.',
        'profiles': names, 'orders': [[names[i] for i in order] for order in orders], 'runs': [],
        'environment': {'os': platform.platform(), 'architecture': platform.machine(), 'logical_cpus': os.cpu_count(),
            'php': config['php'], 'php_sha256': hashlib.sha256(php.read_bytes()).hexdigest(), 'extensions': config['extensions'],
            'mago': subprocess.check_output([str(mago), '--version'], env=env).decode().strip(),
            'mago_sha256': hashlib.sha256(mago.read_bytes()).hexdigest(), 'versions': config['versions'],
            'affinity_mask': mask, 'allowed_logical_processors': 2, 'job_memory_limit_gib': 6,
            'mago_threads': 2, 'extension_workers': 1, 'phpstan_maximum_processes': 2, 'php_heap_gib': 2,
            'opcache_and_jit': 'off', 'memory_metric': 'Windows Job Object PeakJobMemoryUsed: process-tree committed memory; includes auxiliary console hosts; not Linux cgroup file-cache accounting'},
        'scope': {'analyzed_php_files': len(php_files), 'selected_application_files': len(sources), 'vendor_files': len(dependencies),
                  'php_lines': sum(len(p.read_bytes().splitlines()) for p in php_files), 'php_bytes': sum(p.stat().st_size for p in php_files)},
        'input_sealing': 'All selected application and dependency paths and bytes are rediscovered before and after each command. Vendor is a private sealed copy, not an ACL-read-only mount.',
        'load_limit': 'Host load, CPU frequency, antivirus and OS file-cache state are uncontrolled and recorded where observable.',
        'capacity_guard': 'Before each timed command, at least4GiB physical and commit headroom must remain available for10seconds. Only the owned analyzer tree is aborted if either headroom drops below512MiB; such an observation is invalid, never a fallback timing.',
        'diagnostics': 'All fields and duplicate occurrences are compared to that configuration\'s excluded Windows warmup; no rule-set equivalence or cross-OS DTO identity is assumed.',
        'package_source_override_verified': config['package_source_override_verified'], 'cache_cleanup': []}
    def save():
        p = output / 'results.json.tmp'; p.write_text(json.dumps(report, indent=2) + '\n', encoding='utf-8'); p.replace(output / 'results.json')
    def seal():
        assert application_inventory(root) == sources, 'Application source paths/bytes changed'
        assert inventory(root / 'vendor') == dependencies, 'Dependency paths/bytes changed'
        assert all(hashlib.sha256(p.read_bytes()).hexdigest() == h for p, h in frozen.items()), 'Frozen runtime/controller changed'
    references = {}; save()
    try:
        stages = [('warmup', 0, list(range(4)))] + [('sample', i + 1, order) for i, order in enumerate(orders)]
        for stage, round_index, order in stages:
            for index in order:
                profile = names[index]; sequence = len(report['runs']) + 1
                seal(); report.update(phase='running-' + stage, current_profile=profile, current_round=round_index); save()
                cache = None
                if profile.startswith('larastan'):
                    level = 5 if profile.endswith('5') else 10
                    cache = output / 'cache' / ('run-' + str(sequence)); assert not cache.exists()
                    path = setup / ('phpstan-' + str(sequence) + '.neon')
                    value = 'includes:\n    - ' + json.dumps((root / 'phpstan.neon').as_posix()) + '\nparameters:\n    tmpDir: ' + json.dumps(cache.as_posix()) + '\n    parallel:\n        maximumNumberOfProcesses: 2\n'
                    if level == 10: value += '    ignoreErrors!: []\n'
                    path.write_text(value, encoding='utf-8', newline='\n')
                    probe = setup / ('probe-' + str(sequence) + '.neon')
                    probe.write_text(value.replace(cache.as_posix(), cache.as_posix() + '-probe'), encoding='utf-8', newline='\n')
                    parameters = subprocess.run([*command, str(root / 'vendor/bin/phpstan'), 'dump-parameters', '-c', str(probe), '--level=' + str(level), '--json'], cwd=root, env=env, capture_output=True, check=True)
                    (setup / ('parameters-' + str(sequence) + '.json')).write_bytes(parameters.stdout)
                    params = json.loads(parameters.stdout); ignore_count = len(params['ignoreErrors'])
                    assert ignore_count == (7 if level == 5 else 0) and params['parallel']['maximumNumberOfProcesses'] == 2
                    assert not cache.exists(), 'Configuration probe polluted measured cache'
                    analyzer = [*command, str(root / 'vendor/bin/phpstan'), 'analyse', '-c', str(path), '--level=' + str(level), '--memory-limit=2G', '--error-format=json', '--no-progress', '--no-ansi', '-vv']
                else:
                    ignore_count = None
                    analyzer = [str(mago), '--config', config['mago_config'], '--threads', '2', 'analyze', *config['roots'], '--reporting-format=json']
                    if profile == 'mago-native': analyzer.append('--no-extensions')
                report['phase'] = 'waiting-for-capacity'; save()
                wait_for_capacity(output / ('capacity-' + str(sequence) + '.json'))
                report['phase'] = 'running-' + stage; save()
                print(json.dumps({'sequence': sequence, 'stage': stage, 'round': round_index, 'profile': profile}), flush=True)
                metrics = measure(analyzer, root, env, output / ('run-' + str(sequence)), timeout=3600, affinity_mask=mask)
                row = {'configuration': profile, 'stage': stage, 'round': round_index, 'sequence': sequence, **metrics, 'valid': False}
                report['runs'].append(row); save()
                assert not metrics['timed_out'] and metrics['exit_code'] in [0, 1], 'Analyzer infrastructure exit; raw streams retained'
                aggregate, identity = diagnostic_identity((output / ('run-' + str(sequence)) / 'stdout').read_bytes(), (output / ('run-' + str(sequence)) / 'stderr').read_bytes(), profile.startswith('mago'))
                row.update(aggregate, phpstan_ignore_count=ignore_count)
                if stage == 'warmup': references[profile] = identity
                assert references[profile] == identity, 'Full diagnostic multiset differs from Windows warmup'
                seal(); row.update(valid=True, all_source_and_dependency_inputs_unchanged=True, full_diagnostic_multiset_equal=True); save()
                if cache is not None:
                    for p in [cache, Path(str(cache) + '-probe')]:
                        assert p.resolve().parent == (output / 'cache').resolve() and not p.is_symlink()
                        if p.exists(): shutil.rmtree(p); report['cache_cleanup'].append(p.name)
                save(); print(json.dumps({k: row[k] for k in ['configuration', 'wall_seconds', 'cpu_seconds', 'peak_job_commit_mib', 'diagnostics', 'valid']}), flush=True)
        report['summaries'] = {}
        for name in names:
            rows = [r for r in report['runs'] if r['configuration'] == name and r['stage'] == 'sample']
            assert len(rows) == rounds and all(r['valid'] for r in rows)
            report['summaries'][name] = {'n': len(rows), 'diagnostics': rows[0]['diagnostics'],
                **{key: {'median': statistics.median(r[key] for r in rows), 'min': min(r[key] for r in rows), 'max': max(r[key] for r in rows)} for key in ['wall_seconds', 'cpu_seconds', 'peak_job_commit_mib']}}
        seal(); report.update(complete=True, phase='complete', completed_at_unix=time.time(), all_inputs_unchanged=True); save()
        return report
    except BaseException as error:
        report.update(error=str(error), traceback=traceback.format_exc(), failed_at_unix=time.time()); save(); raise


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument('--self-test', type=Path, help='Run real process-tree accounting and timeout controls')
    group.add_argument('--project', type=Path, help='Marked disposable case')
    parser.add_argument('--php', type=Path)
    parser.add_argument('--mago', type=Path)
    parser.add_argument('--output-dir', type=Path)
    parser.add_argument('--rounds', type=int, default=4)
    arguments = parser.parse_args()
    if arguments.self_test:
        result = self_test(arguments.self_test)
        print(json.dumps({'complete': result['complete'], 'real_parent_child_accounting': True, 'owned_tree_timeout': True}))
    else:
        if not all([arguments.php, arguments.mago, arguments.output_dir]): parser.error('--php, --mago and --output-dir are required')
        result = run_study(arguments.project, arguments.php, arguments.mago, arguments.output_dir, arguments.rounds)
        print(json.dumps({'complete': result['complete'], 'observations': len(result['runs']), 'summaries': result['summaries']}))
