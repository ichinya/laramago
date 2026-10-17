"""Protocol guards only. Mocked observations are never analyzer acceptance evidence."""
import contextlib
import importlib.util
import io
import json
import os
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

    def test_malformed_json_is_saved_privately_and_cannot_be_resumed(self):
        for payload in [b'', b'{"private-source":', b'\xff', b'[]', b'null', b'42']:
            with self.subTest(payload=payload), tempfile.TemporaryDirectory() as directory:
                output = Path(directory) / 'report.json'
                def fake(command, **kwargs):
                    return subprocess.CompletedProcess(command, 0, payload, b'private stderr')
                with self.assertRaises(RuntimeError):
                    self.run_suite(output, fake)
                report = json.loads(output.read_text())
                self.assertFalse(report['complete'])
                self.assertEqual(report['failures'][0]['failure'], 'invalid-analyzer-json')
                self.assertEqual(report['failures'][0]['profile'], 'phpstan-unfiltered')
                self.assertNotIn('private-source', output.read_text())
                self.assertNotIn('private stderr', output.read_text())
                def resumed(command, **kwargs):
                    self.fail('A failed series must be rejected before launching a container')
                with self.assertRaises(RuntimeError):
                    self.run_suite(output, resumed, ['--resume'])

    def test_malformed_json_preserves_previous_observations(self):
        calls = 0
        def fake(command, **kwargs):
            nonlocal calls
            calls += 1
            if calls == 2:
                return subprocess.CompletedProcess(command, 0, b'{', b'')
            profile = next(a.split('=', 1)[1] for a in command if a.startswith('--profile='))
            return subprocess.CompletedProcess(command, 0, json.dumps(observation(profile)).encode(), b'')
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory) / 'report.json'
            with self.assertRaises(RuntimeError):
                self.run_suite(output, fake)
            report = json.loads(output.read_text())
            self.assertFalse(report['complete'])
            self.assertEqual(len(report['runs']), 1)
            self.assertTrue(report['runs'][0]['valid'])
            self.assertEqual(report['failures'][0]['profile'], 'mago-native')

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


@unittest.skipUnless(os.environ.get('LARAMAGO_BENCHMARK_LINUX_TESTS') == '1',
                     'Set LARAMAGO_BENCHMARK_LINUX_TESTS=1 to exercise the Linux runner in Docker')
class LinuxInputInventoryTests(unittest.TestCase):
    """Real Linux runner with a synthetic analyzer; not analyzer acceptance evidence."""

    def run_linux_runner(self, mutation='', extra_arguments=(), initial_files=None):
        with tempfile.TemporaryDirectory() as directory:
            base = Path(directory)
            project = base / 'project'
            vendor = base / 'vendor'
            for relative in ['app', 'tests', 'scripts/testing', 'bootstrap']:
                (project / relative).mkdir(parents=True)
            (vendor / 'composer').mkdir(parents=True)
            (vendor / 'composer/installed.json').write_text(json.dumps({
                'packages': [{'name': 'ichinya/laramago', 'version': '0.1.5'}]}), encoding='utf-8')
            for relative in ['app/existing.php', 'testo.php', 'bootstrap/app.php']:
                (project / relative).write_text('<?php', encoding='utf-8')
            (project / 'composer.json').write_text('{}', encoding='utf-8')
            for relative, contents in (initial_files or {}).items():
                (project / relative).write_text(contents, encoding='utf-8')
            fake = '''#!/usr/local/bin/php
<?php
if (in_array('config', $argv, true)) {
    echo json_encode(['includes' => [], 'paths' => ['app', 'tests', 'scripts/testing', 'testo.php']]);
    exit;
}
''' + mutation + "\necho '{\"issues\":[]}';\n"
            (project / 'fake-mago').write_bytes(fake.encode('utf-8'))
            command = ['docker', 'run', '--rm', '--network', 'none',
                       '--mount', f'type=bind,source={project.as_posix()},target=/case',
                       '--mount', f'type=bind,source={vendor.as_posix()},target=/case/vendor,readonly',
                       '--mount', f'type=bind,source={Path(__file__).resolve().parents[1].as_posix()},target=/runner,readonly',
                       '--workdir', '/case', suite.IMAGE, 'php', '-r',
                       "chmod('/case/fake-mago', 0755); require '/runner/scripts/benchmark-anonymous-linux.php';",
                       '--', '--project=/case', '--mago=/case/fake-mago', '--profile=mago-native',
                       '--output=/case/result.json', '--source-seal=/case/seal', '--timeout=10', *extra_arguments]
            result = subprocess.run(command, capture_output=True, timeout=45)
            self.assertEqual(result.returncode, 0, result.stderr.decode(errors='replace'))
            return json.loads(result.stdout)

    def test_unchanged_inputs_are_valid(self):
        report = self.run_linux_runner()
        self.assertTrue(report['valid'])
        self.assertTrue(report['source_unchanged'])
        self.assertTrue(report['sealed_workload'])

    def test_input_additions_removals_renames_and_edits_are_rejected(self):
        mutations = {
            'source-added': "file_put_contents('/case/app/added.php', '<?php');",
            'source-removed': "unlink('/case/app/existing.php');",
            'source-renamed': "rename('/case/app/existing.php', '/case/app/renamed.php');",
            'source-edited': "file_put_contents('/case/app/existing.php', '<?php // changed');",
            'new-config-directory': "mkdir('/case/config'); file_put_contents('/case/config/new.php', '<?php');",
            'new-migration-directory': "mkdir('/case/database/migrations', recursive: true); file_put_contents('/case/database/migrations/new.php', '<?php');",
            'tool-config-added': "file_put_contents('/case/phpstan.neon', 'parameters:');",
            'composer-file-removed': "unlink('/case/composer.json');",
            'composer-lock-added': "file_put_contents('/case/composer.lock', '{}');",
            'bootstrap-added': "file_put_contents('/case/bootstrap/providers.php', '<?php return [];');",
        }
        for name, mutation in mutations.items():
            with self.subTest(mutation=name):
                report = self.run_linux_runner(mutation)
                self.assertFalse(report['valid'])
                self.assertFalse(report['source_unchanged'])

    def test_generated_non_inputs_do_not_invalidate_a_run(self):
        report = self.run_linux_runner("file_put_contents('/case/.benchmark-cache.json', '{}');")
        self.assertTrue(report['valid'])
        self.assertTrue(report['source_unchanged'])

    def test_diagnostic_seal_cannot_be_recreated_during_verification(self):
        for initial in [{}, {'diagnostic.seal': 'different-multiset'}]:
            with self.subTest(initial=initial):
                report = self.run_linux_runner(extra_arguments=['--diagnostic-seal=/case/diagnostic.seal'],
                                               initial_files=initial)
                self.assertFalse(report['valid'])
                self.assertFalse(report['diagnostic_multiset_verified'])

    def test_diagnostic_baseline_is_created_explicitly(self):
        report = self.run_linux_runner(extra_arguments=['--diagnostic-seal=/case/diagnostic.seal',
                                                       '--create-diagnostic-seal'])
        self.assertTrue(report['valid'])
        self.assertTrue(report['diagnostic_multiset_verified'])


if __name__ == '__main__':
    unittest.main()
