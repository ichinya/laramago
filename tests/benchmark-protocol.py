"""Protocol guards only. Mocked observations are never analyzer acceptance evidence."""
import contextlib
import importlib.util
import io
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('benchmark_suite', Path(__file__).parents[1] / 'scripts/benchmark-suite.py')
suite = importlib.util.module_from_spec(spec)
spec.loader.exec_module(suite)


def observation(profile, valid=True):
    return {'profile': profile, 'valid': valid, 'source_unchanged': True, 'sealed_workload': True,
            'fresh_result_cache_confirmed': True, 'phpstan_config_ignore_count': 0,
            'phpstan_tmp_dir_absent_before_start': True, 'phpstan_probe_cache_separate': True,
            'diagnostics': 1, 'codes': {'example': 1}, 'levels': {'Error': 1},
            'wall_seconds': 2.0, 'cpu_seconds': 3.0, 'peak_container_memory_mib': 10.0}


class ProtocolTests(unittest.TestCase):
    def run_suite(self, path, fake_run, extra=()):
        argv = ['benchmark-suite.py', '--volume', 'invented-volume', '--output', str(path), *extra]
        with patch.object(sys, 'argv', argv), patch.object(suite.subprocess, 'run', side_effect=fake_run), contextlib.redirect_stdout(io.StringIO()):
            suite.main()

    def test_full_schedule_balances_position_and_uses_fresh_readonly_containers(self):
        commands = []
        def fake(command, **kwargs):
            commands.append(command)
            profile = next(a.split('=', 1)[1] for a in command if a.startswith('--profile='))
            return subprocess.CompletedProcess(command, 0, json.dumps(observation(profile)).encode(), b'')
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory) / 'report.json'
            self.run_suite(output, fake)
            report = json.loads(output.read_text())
            self.assertTrue(report['complete'])
            self.assertEqual(len(commands), 12)
            self.assertEqual(len({c[c.index('--name') + 1] for c in commands}), 12)
            for command in commands:
                self.assertIn('--rm', command)
                self.assertTrue(any('target=/case/vendor,volume-subpath=vendor,readonly' in arg for arg in command))
                self.assertIn('--source-seal=/case/.benchmark-v2-input-seal', command)
            for profile in suite.PROFILES:
                rows = [r for r in report['runs'] if r['stage'] == 'sample' and r['profile'] == profile]
                self.assertEqual(sorted(r['position'] for r in rows), [1, 2, 3])
                self.assertEqual(report['summaries'][profile]['n'], 3)
            with self.assertRaises(FileExistsError):
                self.run_suite(output, fake)

    def test_container_failure_is_saved_and_cannot_be_resumed_as_success(self):
        def fake(command, **kwargs):
            return subprocess.CompletedProcess(command, 125, b'', b'private container details')
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory) / 'report.json'
            with self.assertRaises(RuntimeError):
                self.run_suite(output, fake)
            report = json.loads(output.read_text())
            self.assertFalse(report['complete'])
            self.assertEqual(report['failures'][0]['container_exit'], 125)
            self.assertNotIn('private container details', output.read_text())
            with self.assertRaises(RuntimeError):
                self.run_suite(output, fake, ['--resume'])

    def test_incomplete_analyzer_row_without_counts_survives(self):
        def fake(command, **kwargs):
            return subprocess.CompletedProcess(command, 0, b'{"valid":false,"timed_out":true}', b'')
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory) / 'report.json'
            with self.assertRaises(RuntimeError):
                self.run_suite(output, fake)
            report = json.loads(output.read_text())
            self.assertFalse(report['complete'])
            self.assertTrue(report['runs'][0]['timed_out'])

    def test_warmup_is_excluded_and_changed_diagnostic_codes_rejected(self):
        warmup = observation('mago-native') | {'stage': 'warmup', 'wall_seconds': 999}
        sample = observation('mago-native') | {'stage': 'sample'}
        self.assertEqual(suite.summarize([warmup, sample])['mago-native']['wall_seconds']['median'], 2)
        changed = sample | {'codes': {'different-error': 1}}
        with self.assertRaises(RuntimeError):
            suite.summarize([sample, changed])

    def test_polluted_cache_control_is_saved_as_invalid(self):
        def fake(command, **kwargs):
            profile = next(a.split('=', 1)[1] for a in command if a.startswith('--profile='))
            row = observation(profile) | {'phpstan_tmp_dir_absent_before_start': False}
            return subprocess.CompletedProcess(command, 0, json.dumps(row).encode(), b'')
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory) / 'report.json'
            with self.assertRaises(RuntimeError):
                self.run_suite(output, fake)
            report = json.loads(output.read_text())
            self.assertFalse(report['runs'][0]['valid'])
            self.assertFalse(report['complete'])
            with self.assertRaises(RuntimeError):
                self.run_suite(output, fake, ['--resume'])

    def test_changed_diagnostics_preserve_failed_series(self):
        seen = {}
        def fake(command, **kwargs):
            profile = next(a.split('=', 1)[1] for a in command if a.startswith('--profile='))
            row = observation(profile)
            if 'sample' in command[command.index('--name') + 1]:
                seen[profile] = seen.get(profile, 0) + 1
                if profile == 'phpstan-unfiltered' and seen[profile] == 2:
                    row['codes'] = {'changed-diagnostic': 1}
            return subprocess.CompletedProcess(command, 0, json.dumps(row).encode(), b'')
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory) / 'report.json'
            with self.assertRaises(RuntimeError):
                self.run_suite(output, fake)
            report = json.loads(output.read_text())
            self.assertFalse(report['complete'])
            self.assertEqual(report['failures'][0]['failure'], 'diagnostic-aggregate-instability')
            self.assertEqual(report['runs'][-1]['codes'], {'changed-diagnostic': 1})


if __name__ == '__main__':
    unittest.main()
