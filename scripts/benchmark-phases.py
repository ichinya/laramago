"""Separate observer run after benchmark samples; installed vendor stays read-only."""
import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import time

IMAGE = 'php:8.5.4-cli@sha256:cfa277c10f9f1859addbbed6739673a50a9c1b5777f64c0441118eca148b8056'


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--volume', required=True)
    ap.add_argument('--output-dir', required=True, type=Path)
    args = ap.parse_args()
    args.output_dir.mkdir(parents=True, exist_ok=True)
    if (args.output_dir / 'phase-events.jsonl').exists():
        raise FileExistsError('Use a new directory to preserve previous phase observations')
    common = ['docker', 'run', '--rm', '--network', 'none', '--mount',
              f'type=volume,source={args.volume},target=/case,readonly']
    stock = subprocess.check_output([*common, IMAGE, 'cat', '/case/vendor/ichinya/laramago/bin/laramago-worker.php']).decode()
    cache_stock = subprocess.check_output([*common, IMAGE, 'cat', '/case/vendor/ichinya/laramago/src/Analyzer/StaticAnalysis/CodebaseScanSyntax.php']).decode()
    config = json.loads(subprocess.check_output([*common, IMAGE, 'cat', '/case/benchmark-linux-mago.json']))
    if config['source']['paths'] != ['app', 'tests', 'scripts/testing', 'testo.php']:
        raise RuntimeError('Unexpected source roots')
    if config['extends'] != ['vendor/ichinya/laramago/presets/laravel.toml']:
        raise RuntimeError('Unexpected inherited configuration')
    before_parse, parse_body = cache_stock.split('    public static function parse(', 1)
    before_parse = before_parse.replace('        if (isset(self::$queries[$key])) {', "        \\BenchSyntaxCacheProbe::lookup('queries', $key, isset(self::$queries[$key]), count(self::$queries), self::$queryBytes);\n        if (isset(self::$queries[$key])) {", 1)
    before_parse = before_parse.replace('$size = strlen($serialized);', "$size = strlen($serialized);\n        \\BenchSyntaxCacheProbe::payload('queries', $key, $size);", 1)
    before_parse = before_parse.replace('self::$queryBytes -= strlen(self::$queries[$oldest]);', "\\BenchSyntaxCacheProbe::eviction('queries');\n                self::$queryBytes -= strlen(self::$queries[$oldest]);", 1)
    parse_body = parse_body.replace('        if (isset(self::$trees[$key])) {', "        \\BenchSyntaxCacheProbe::lookup('trees', $key, isset(self::$trees[$key]), count(self::$trees), self::$bytes);\n        if (isset(self::$trees[$key])) {", 1)
    parse_body = parse_body.replace('$size = strlen($serialized);', "$size = strlen($serialized);\n        \\BenchSyntaxCacheProbe::payload('trees', $key, $size);", 1)
    parse_body = parse_body.replace('self::$bytes -= strlen(self::$trees[$oldest]);', "\\BenchSyntaxCacheProbe::eviction('trees');\n                self::$bytes -= strlen(self::$trees[$oldest]);", 1)
    cache_instrumented = before_parse + '    public static function parse(' + parse_body
    if cache_instrumented.count('BenchSyntaxCacheProbe::') != 6:
        raise RuntimeError('Syntax cache source shape changed')
    (args.output_dir / 'CodebaseScanSyntax.php').write_text(cache_instrumented, encoding='utf-8')
    boot = """require '/bench/scripts/phase-observer.php';
$cacheClass = 'Ichinya\\\\Laramago\\\\Analyzer\\\\StaticAnalysis\\\\CodebaseScanSyntax';
if (class_exists($cacheClass, false)) { throw new RuntimeException('Syntax cache loaded before instrumentation.'); }
$benchLoader = require '/case/vendor/autoload.php';
$benchLoader->addClassMap([$cacheClass => '/probe/CodebaseScanSyntax.php']);
if ((new ReflectionClass($cacheClass))->getFileName() !== '/probe/CodebaseScanSyntax.php') { throw new RuntimeException('Syntax cache override failed.'); }
"""
    worker = stock.replace('$projectRoot = $argv[2]', boot + '$projectRoot = $argv[2]', 1)
    worker = worker.replace('analyzerPlugins: [', "analyzerPlugins: [\n        new \\BenchPhaseObserver('first'),", 1)
    worker = worker.replace('    ],\n)))->run();', "        new \\BenchPhaseObserver('last'),\n    ],\n)))->run();", 1)
    if worker.count('new \\BenchPhaseObserver') != 2:
        raise RuntimeError('Stock worker shape changed')
    (args.output_dir / 'worker.php').write_text(worker, encoding='utf-8')
    host = config['extension-hosts']['laramago']
    config['extends'] = ['/case/vendor/ichinya/laramago/presets/laravel.toml']
    host['command'] = ['/usr/local/bin/php', '-d', 'memory_limit=2G', '-d', 'opcache.enable_cli=0',
                       '/probe/worker.php', '/case/vendor/autoload.php', '/case']
    if host['workers'] != 2 or config['threads'] != 2:
        raise RuntimeError('Unexpected parallelism')
    (args.output_dir / 'mago.json').write_text(json.dumps(config), encoding='utf-8')
    repo = Path(__file__).resolve().parents[1]
    name = 'laramago-phase-control-v2'
    command = [*common, '--name', name, '--cpus', '2', '--memory', '6g',
               '--mount', f'type=bind,source={repo.as_posix()},target=/bench,readonly',
               '--mount', f'type=bind,source={args.output_dir.resolve().as_posix()},target=/probe',
               '--env', 'PHP_INI_SCAN_DIR=/case/.benchmark-linux-ini-on', '--workdir', '/case', IMAGE,
               '/case/.benchmark-linux/mago-1.48.1-x86_64-unknown-linux-gnu/mago',
               '--workspace', '/case', '--config', '/probe/mago.json', '--threads', '2', 'analyze', 'app', 'tests', 'scripts/testing', 'testo.php', '--reporting-format=json']
    start = time.monotonic()
    try:
        result = subprocess.run(command, capture_output=True, timeout=3600)
    except subprocess.TimeoutExpired:
        subprocess.run(['docker', 'rm', '-f', name], capture_output=True)
        raise RuntimeError('Observer run did not complete') from None
    if result.returncode not in (0, 1):
        raise RuntimeError('Observer infrastructure failure; no benchmark success')
    body = json.loads(result.stdout)
    if any(marker in result.stderr.lower() for marker in [b'did not answer request', b'protocol i/o failed',
            b'external plugin error', b'violated the protocol', b'extension worker failed']):
        raise RuntimeError('Observer extension failure')
    issues = body['issues']
    if any(i['code'] == 'configuration-source-changed' for i in issues):
        raise RuntimeError('Observer source changed')
    events = [json.loads(line) for line in (args.output_dir / 'phase-events.jsonl').read_text().splitlines()]
    boundaries = []
    for pid in sorted({e['pid'] for e in events}):
        for phase in ['scan', 'before-analysis', 'after-analysis']:
            first = [e for e in events if e['pid'] == pid and e['phase'] == phase and e['slot'] == 'first']
            last = [e for e in events if e['pid'] == pid and e['phase'] == phase and e['slot'] == 'last']
            if len(first) == len(last) == 1:
                boundaries.append({'pid': pid, 'phase': phase,
                    'wall_seconds': last[0]['monotonic_seconds'] - first[0]['monotonic_seconds'],
                    'php_cpu_seconds': last[0]['php_cpu_seconds'] - first[0]['php_cpu_seconds']})
        start_events = [e for e in events if e['pid'] == pid and e['phase'] == 'before-analysis' and e['slot'] == 'last']
        end_events = [e for e in events if e['pid'] == pid and e['phase'] == 'after-analysis' and e['slot'] == 'first']
        if len(start_events) == len(end_events) == 1:
            boundaries.append({'pid': pid, 'phase': 'analysis-and-callback-window',
                'wall_seconds': end_events[0]['monotonic_seconds'] - start_events[0]['monotonic_seconds'],
                'php_cpu_seconds': end_events[0]['php_cpu_seconds'] - start_events[0]['php_cpu_seconds']})
    report = {'kind': 'separate-instrumented-phase-control', 'valid': True,
              'wall_seconds': time.monotonic() - start, 'diagnostics': len(issues),
              'stock_worker_sha256': hashlib.sha256(stock.encode()).hexdigest(),
              'vendor_read_only': True, 'boundaries': boundaries,
              'syntax_cache_metrics': [json.loads(line) for line in (args.output_dir / 'cache-events.jsonl').read_text().splitlines()],
              'cache_policy_changed': False, 'metrics_source_copy_outside_vendor': True,
              'stock_cache_sha256': hashlib.sha256(cache_stock.encode()).hexdigest(),
              'note': 'PHP CPU is per-worker CPU within paired phase boundaries. Wall intervals across workers overlap; do not sum them.'}
    report['initialized_workers'] = sorted({e['pid'] for e in events if e['phase'] == 'initialize'})
    report['cache_workers_observed'] = sorted({row['pid'] for row in report['syntax_cache_metrics'] if row['stats']})
    report['cache_telemetry_complete'] = set(report['initialized_workers']) == set(report['cache_workers_observed'])
    report['cache_telemetry_note'] = 'Only workers that emitted terminal counters are represented; missing workers are not extrapolated.'
    (args.output_dir / 'aggregate.json').write_text(json.dumps(report, indent=2) + '\n', encoding='utf-8')
    print(json.dumps(report), flush=True)


if __name__ == '__main__':
    main()
