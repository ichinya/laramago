"""Instrument the pinned SDK dispatch outside read-only vendor; no private payloads exported."""
import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess
import textwrap

IMAGE = 'php:8.5.4-cli@sha256:cfa277c10f9f1859addbbed6739673a50a9c1b5777f64c0441118eca148b8056'


def instrument(source):
    pattern = re.compile(r'(\$(?:registered|hook)(?:->callback)?)->(getPropertyType|isPropertyInitialized|getClassInitializers|filterIssue|getAssertions|getCallableSignature|getReturnType|initialize|scan|beforeAnalysis|afterFileAnalysis|afterAnalysis|analyze)\(')
    matches = list(pattern.finditer(source))
    if len(matches) != 14:
        raise RuntimeError(f'Unexpected SDK dispatch shape: {len(matches)}')
    for m in reversed(matches):
        depth, pos, quote = 1, m.end(), None
        while depth:
            c = source[pos]
            if quote:
                if c == '\\':
                    pos += 2
                    continue
                if c == quote:
                    quote = None
            elif c in "\"'":
                quote = c
            elif c == '(':
                depth += 1
            elif c == ')':
                depth -= 1
            pos += 1
        call = source[m.start():pos]
        source = source[:m.start()] + f"\\BenchCpu::invoke({m[1]}, '{m[2]}', fn () => {call})" + source[pos:]
    if source.count('$this->cancelRequests($requests);') != 2:
        raise RuntimeError('Unexpected SDK shutdown shape')
    source = source.replace('$this->cancelRequests($requests);', '\\BenchCpu::flush(true);\n                    $this->cancelRequests($requests);')
    return source


def section(source, signature, label):
    if source.count(signature) != 1:
        raise RuntimeError('Unexpected section source shape')
    start = source.index('\n    {', source.index(signature)) + len('\n    {')
    end = source.index('\n    }\n', start)
    body = source[start:end]
    if '$cpuSection' in body:
        raise RuntimeError('Section variable collision')
    wrapped = ('\n        $cpuSection = \\BenchCpu::startSection();\n        try {'
               + textwrap.indent(body, '    ')
               + '\n        } finally {\n            \\BenchCpu::endSection(\'' + label + '\', $cpuSection);\n        }')
    return source[:start] + wrapped + source[end:]


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--volume', required=True)
    ap.add_argument('--output-dir', required=True, type=Path)
    ap.add_argument('--timeout', type=int, default=1800)
    ap.add_argument('--request-timeout-ms', type=int, default=600000)
    ap.add_argument('--cache-mib', type=int, default=64)
    ap.add_argument('--cache-source', type=Path)
    ap.add_argument('--candidate-dir', type=Path)
    ap.add_argument('--verify-diagnostics', action='store_true')
    args = ap.parse_args()
    repo = Path(__file__).resolve().parents[1]
    out = args.output_dir.resolve()
    out.mkdir(parents=True, exist_ok=False)
    metrics = out / 'metrics'
    metrics.mkdir()
    observer = (repo / 'scripts/cpu-observer.php').read_bytes()
    (out / 'observer-source.php').write_bytes(observer)
    runner = (repo / 'scripts/benchmark-anonymous-linux.php').read_bytes()
    (out / 'runner-source.php').write_bytes(runner)
    common = ['docker', 'run', '--rm', '--network', 'none', '--mount', f'type=volume,source={args.volume},target=/case,readonly', IMAGE]
    sdk = subprocess.check_output([*common, 'cat', '/case/vendor/carthage-software/mago/composer/src/Sdk/Worker.php']).decode()
    worker = subprocess.check_output([*common, 'cat', '/case/vendor/ichinya/laramago/bin/laramago-worker.php']).decode().replace('\r\n', '\n')
    cache = subprocess.check_output([*common, 'cat', '/case/vendor/ichinya/laramago/src/Analyzer/StaticAnalysis/CodebaseScanSyntax.php']).decode().replace('\r\n', '\n')
    reader = subprocess.check_output([*common, 'cat', '/case/vendor/ichinya/laramago/src/Analyzer/GuardedStringCastContracts.php']).decode().replace('\r\n', '\n')
    candidate_map = {}
    candidate_hashes = {}
    if args.candidate_dir:
        supplied = json.loads((args.candidate_dir / 'candidate-map.json').read_text(encoding='utf-8'))
        for cls, filename in supplied.items():
            if not re.fullmatch(r'Ichinya\\Laramago\\[A-Za-z0-9_\\]+', cls) or Path(filename).name != filename:
                raise RuntimeError('Unexpected package override')
            raw = (args.candidate_dir / filename).read_bytes()
            candidate_hashes[cls] = hashlib.sha256(raw).hexdigest()
            if cls.endswith('\\StaticAnalysis\\CodebaseScanSyntax'):
                cache = raw.decode().replace('\r\n', '\n')
            elif cls.endswith('\\GuardedStringCastContracts'):
                reader = raw.decode().replace('\r\n', '\n')
            else:
                (out / filename).write_bytes(raw)
                candidate_map[cls] = '/probe/' + filename
    if args.cache_source:
        cache = args.cache_source.read_text(encoding='utf-8').replace('\r\n', '\n')
    (out / 'SdkWorker.php').write_bytes(instrument(sdk).encode())
    needle = 'private const MAX_BYTES = 64 * 1024 * 1024;'
    if cache.count(needle) != 1:
        raise RuntimeError('Unexpected cache shape')
    replacements = {
        '        if (isset(self::$trees[$key])) {': '        \\BenchCpu::cacheLookup(isset(self::$trees[$key]));\n        if (isset(self::$trees[$key])) {',
        'self::$bytes -= strlen(self::$trees[$oldest]);': '\\BenchCpu::cacheEvict();\n                self::$bytes -= strlen(self::$trees[$oldest]);',
        'self::$bytes += $size;': 'self::$bytes += $size;\n            \\BenchCpu::cacheSize(self::$bytes, count(self::$trees));',
    }
    for old, new in replacements.items():
        if cache.count(old) != 1:
            raise RuntimeError('Unexpected syntax-cache telemetry shape')
        cache = cache.replace(old, new)
    if 'self::encodeTree(serialize($nodes))' in cache:
        cache = cache.replace('$serialized = self::encodeTree(serialize($nodes));',
            '$cpuSerialized = serialize($nodes);\n        \\BenchCpu::cachePayload($key, strlen($cpuSerialized));\n'
            '        $serialized = self::encodeTree($cpuSerialized);\n        unset($cpuSerialized);')
    else:
        cache = cache.replace('$serialized = serialize($nodes);',
            '$serialized = serialize($nodes);\n        \\BenchCpu::cachePayload($key, strlen($serialized));')
    cache = section(cache, 'public static function parse(', 'tree-syntax')
    reader = section(reader, 'public function read(', 'guarded-source-read')
    (out / 'CodebaseScanSyntax.php').write_bytes(cache.replace(needle, f'private const MAX_BYTES = {args.cache_mib} * 1024 * 1024;').encode())
    (out / 'GuardedStringCastContracts.php').write_bytes(reader.encode())
    boot = """require '/probe/observer-source.php';
$cpuLoader = require $autoload;
$cpuLoader->addClassMap(['Mago\\Sdk\\Worker' => '/probe/SdkWorker.php',
    'Ichinya\\Laramago\\Analyzer\\GuardedStringCastContracts' => '/probe/GuardedStringCastContracts.php',
    'Ichinya\\Laramago\\Analyzer\\StaticAnalysis\\CodebaseScanSyntax' => '/probe/CodebaseScanSyntax.php']);
foreach (['Mago\\Sdk\\Worker' => '/probe/SdkWorker.php',
    'Ichinya\\Laramago\\Analyzer\\GuardedStringCastContracts' => '/probe/GuardedStringCastContracts.php',
    'Ichinya\\Laramago\\Analyzer\\StaticAnalysis\\CodebaseScanSyntax' => '/probe/CodebaseScanSyntax.php'] as $class => $path) {
    if ((new ReflectionClass($class))->getFileName() !== $path) { throw new RuntimeException('CPU override failed'); }
}
"""
    extra = ', '.join("'" + cls.replace('\\', '\\\\') + "' => '" + path + "'" for cls, path in candidate_map.items())
    boot += '$cpuExtraMap = [' + extra + '];\n$cpuLoader->addClassMap($cpuExtraMap);\n'
    boot += "foreach ($cpuExtraMap as $class => $path) { if ((new ReflectionClass($class))->getFileName() !== $path) { throw new RuntimeException('Candidate CPU override failed'); } }\n"
    (out / 'worker.php').write_bytes(worker.replace('$projectRoot = $argv[2]', boot + '$projectRoot = $argv[2]').encode())
    protocol = {'cache_mib': args.cache_mib, 'sdk_sha256': hashlib.sha256(sdk.encode()).hexdigest(),
                'callback_sites': 14, 'vendor_read_only': True,
                'observer_sha256': hashlib.sha256(observer).hexdigest(),
                'runner_sha256': hashlib.sha256(runner).hexdigest(),
                'candidate_source_sha256': candidate_hashes,
                'instrumented_sources': {name: hashlib.sha256((out / name).read_bytes()).hexdigest()
                    for name in ['SdkWorker.php', 'CodebaseScanSyntax.php', 'GuardedStringCastContracts.php', 'worker.php']},
                'cpu_note': 'Inclusive process CPU can overlap across suspended hooks. Isolated CPU excludes every overlapping invocation; do not sum inclusive rows.'}
    (out / 'protocol.json').write_text(json.dumps(protocol, indent=2), encoding='utf-8')
    command = ['docker', 'run', '--rm', '--name', 'laramago-cpu-profile', '--network', 'none', '--cpus', '2', '--memory', '6g',
               '--mount', f'type=volume,source={args.volume},target=/case',
               '--mount', f'type=volume,source={args.volume},target=/case/vendor,volume-subpath=vendor,readonly',
               '--mount', f'type=bind,source={repo.as_posix()},target=/runner,readonly',
               '--mount', f'type=bind,source={out.as_posix()},target=/probe,readonly',
               '--mount', f'type=bind,source={metrics.as_posix()},target=/metrics',
               '--workdir', '/case', IMAGE, 'php', '-d', 'memory_limit=2G', '/probe/runner-source.php',
               '--project=/case', '--mago=/case/.benchmark-linux/mago-1.48.1-x86_64-unknown-linux-gnu/mago',
               '--output=/case/.benchmark-cpu-last.json', '--profile=mago-laramago', '--threads=2', '--extension-workers=2',
               '--dependency-scope=composer-autoload', '--request-timeout-ms=' + str(args.request_timeout_ms), '--timeout=' + str(args.timeout),
               '--worker=/probe/worker.php', '--source-seal=/case/.benchmark-v2-input-seal',
               '--diagnostic-seal=/case/.benchmark-cpu-reference']
    if not args.verify_diagnostics:
        command += ['--create-diagnostic-seal']
    try:
        result = subprocess.run(command, capture_output=True, timeout=args.timeout + 120)
    except subprocess.TimeoutExpired:
        subprocess.run(['docker', 'rm', '-f', 'laramago-cpu-profile'], capture_output=True)
        raise RuntimeError('CPU control deadline exceeded; output withheld') from None
    if result.returncode:
        raise RuntimeError('CPU control infrastructure failure; output withheld')
    row = json.loads(result.stdout)
    (out / 'result.json').write_text(json.dumps(row, indent=2), encoding='utf-8')
    if not all(row.get(k) for k in ['valid', 'source_unchanged', 'sealed_workload', 'diagnostic_multiset_verified']):
        raise RuntimeError('CPU control incomplete or diagnostics changed; observation retained')
    print(json.dumps(row), flush=True)


if __name__ == '__main__':
    main()
