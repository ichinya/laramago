"""Mocked orchestration checks; these are not analyzer or performance acceptance."""
import contextlib
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

REPO = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('native_study', REPO / 'scripts/benchmark-native.py')
study = importlib.util.module_from_spec(spec)
spec.loader.exec_module(study)
SOURCE = 'src/Analyzer/NullableCollectionOffsetContract.php'
BASELINE = b'<?php\nnamespace Ichinya\\Laramago\\Analyzer;\nfinal class NullableCollectionOffsetContract {}'
CANDIDATE = BASELINE + b'\n// candidate\n'


class NativeProtocol(unittest.TestCase):
    def test_empty_php_worker_sections_are_valid_but_malformed_sections_are_rejected(self):
        self.assertEqual(study.nullable_profile_calls([{'sections': []}, {'sections': {'nullable-current / caller': {'calls': 3}}}]), 3)
        self.assertEqual(study.nullable_profile_calls([{'sections': []}]), 0)
        for value in [None, ['bad entry'], 'bad entry']:
            with self.assertRaises(RuntimeError):study.nullable_profile_calls([{'sections': value}])

    def test_previous_baseline_requires_a_complete_study_and_exact_entire_snapshot(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory);source=root/'source.php';source.write_bytes(BASELINE)
            report=root/'study.json';manifest=root/'manifest.json';commit='a'*40
            flags=dict(valid=True,source_unchanged=True,sealed_workload=True,diagnostic_multiset_verified=True,dependency_inputs_unchanged=True,cpu_accounting_complete=True)
            body=dict(complete=True,baseline_commit=commit,candidate_sources={SOURCE:hashlib.sha256(BASELINE).hexdigest()},runs=[flags])
            report.write_text(json.dumps(body));data=dict(study=str(report),description='previous candidate',sources={SOURCE:str(source)});manifest.write_text(json.dumps(data))
            paths,metadata=study.baseline_overrides(manifest,commit)
            self.assertEqual(paths,{SOURCE:BASELINE});self.assertEqual(metadata['description'],'previous candidate')
            self.assertEqual(study.baseline_overrides(None,commit),({},None))
            for changes in [dict(complete=False),dict(baseline_commit='b'*40),dict(runs=[flags|{'source_unchanged':False}])]:
                report.write_text(json.dumps(body|changes))
                with self.assertRaises(RuntimeError):study.baseline_overrides(manifest,commit)
            report.write_text(json.dumps(body));source.write_bytes(BASELINE+b'// changed')
            with self.assertRaises(RuntimeError):study.baseline_overrides(manifest,commit)
            source.write_bytes(BASELINE);manifest.write_text(json.dumps(data|{'sources':{}}))
            with self.assertRaises(RuntimeError):study.baseline_overrides(manifest,commit)

    def test_relative_baseline_includes_keep_the_verified_installed_location(self):
        with tempfile.TemporaryDirectory() as directory:
            installed=Path(directory)/'installed.php';frozen=Path(directory)/'frozen.php'
            source=b"<?php require_once __DIR__.'/peer.php';"
            installed.write_bytes(source);frozen.write_bytes(source)
            self.assertEqual(study.override_location('baseline',source,installed,frozen),installed)
            installed.write_bytes(source+b'// changed')
            with self.assertRaises(RuntimeError):study.override_location('baseline',source,installed,frozen)
            installed.unlink()
            with self.assertRaises(RuntimeError):study.override_location('baseline',source,installed,frozen)

    def test_candidate_source_constants_cannot_silently_change_location(self):
        with tempfile.TemporaryDirectory() as directory:
            path=Path(directory)/'source.php'
            for token in [b'__DIR__',b'__FILE__']:
                with self.assertRaises(RuntimeError):study.override_location('candidate',b'<?php '+token,path,path)
            self.assertEqual(study.override_location('candidate',b'<?php // plain source',path,path),path)

    def test_package_snapshot_preserves_includes_assets_and_all_declaration_paths(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory);installed=root/'installed';target=root/'snapshot'
            files={
                'src/Analyzer/Owner.php': b"<?php\nnamespace Ichinya\\Laramago\\Analyzer;\nrequire_once __DIR__.'/Peer.php';\nfinal class Owner {}",
                'src/Analyzer/Peer.php': b'<?php\nnamespace Ichinya\\Laramago\\Analyzer;\nabstract class Peer {}\ninterface Contract {}',
                'bin/worker.php': b'<?php // worker', 'presets/laravel.toml': b'configuration',
                'composer.json': b'{}',
            }
            for name,contents in files.items():
                path=installed/name;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(contents)
            changed=files['src/Analyzer/Owner.php']+b'\n// candidate'
            mapping,inventory=study.package_snapshot(target,installed,{'src/Analyzer/Owner.php':changed})
            self.assertEqual(mapping['Ichinya\\Laramago\\Analyzer\\Owner'],target/'src/Analyzer/Owner.php')
            self.assertEqual(mapping['Ichinya\\Laramago\\Analyzer\\Contract'],target/'src/Analyzer/Peer.php')
            self.assertEqual((target/'src/Analyzer/Owner.php').read_bytes(),changed)
            self.assertEqual((installed/'src/Analyzer/Owner.php').read_bytes(),files['src/Analyzer/Owner.php'])
            self.assertEqual((target/'presets/laravel.toml').read_bytes(),b'configuration')
            self.assertEqual(study.snapshot_inventory(target),inventory)
            extra=target/'src/added.php';extra.write_bytes(b'<?php')
            self.assertNotEqual(study.snapshot_inventory(target),inventory)
            extra.unlink();self.assertEqual(study.snapshot_inventory(target),inventory)
            (target/'src/Analyzer/Peer.php').unlink()
            self.assertNotEqual(study.snapshot_inventory(target),inventory)
            for bad in ['src/../outside.php','outside.php','src/missing.php']:
                with self.assertRaises(RuntimeError):study.package_snapshot(root/'rejected',installed,{bad:b'<?php'})

    def exercise(self, directory, failure=None, previous=False, packages=False, reference=False):
        root = Path(directory)
        repo, project, out = root / 'repo', root / 'case', root / 'result'
        files = {
            repo / SOURCE: CANDIDATE,
            repo / 'scripts/benchmark-native.py': (REPO / 'scripts/benchmark-native.py').read_bytes(),
            repo / 'scripts/benchmark-anonymous-linux.php': b'<?php // runner',
            repo / 'scripts/benchmark-cpu.py': b'# frozen helper',
            repo / 'scripts/cpu-observer.php': b'<?php // /metrics/',
            project / 'vendor/ichinya/laramago/bin/laramago-worker.php': b'<?php\n$projectRoot = $argv[2];',
            project / 'vendor/carthage-software/mago/composer/src/Sdk/Worker.php': b'<?php // SDK',
            root / 'php': b'php executable', root / 'mago': b'mago executable',
        }
        if packages:
            files[project/'vendor/ichinya/laramago'/SOURCE]=BASELINE
        for path, contents in files.items():
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(contents)
        gates = root / 'gates.json'
        gates.write_text(json.dumps({'complete': True, 'tests': [{'passed': True}],
                                    'production_source_sha256': {SOURCE: hashlib.sha256(CANDIDATE).hexdigest()}}))
        argv = ['benchmark-native.py', '--project', str(project), '--php', str(root / 'php'),
                '--mago', str(root / 'mago'), '--output-dir', str(out), '--gates', str(gates),
                '--rounds', '2', '--candidate-files', SOURCE]
        commands = []
        if packages:argv.append('--package-snapshots')
        if reference:
            seal=root/'existing-reference';seal.write_bytes(b'unchanged full diagnostic digest')
            argv+=['--diagnostic-reference',str(seal)]
        flags = dict(valid=True, source_unchanged=True, sealed_workload=True,
                     diagnostic_multiset_verified=True, cpu_accounting_complete=True)
        if previous:
            prior=BASELINE+b'\n// previous accepted candidate\n';prior_path=root/'previous.php';prior_path.write_bytes(prior)
            receipt=root/'previous-study.json';receipt.write_text(json.dumps(dict(complete=True,baseline_commit='a'*40,candidate_sources={SOURCE:hashlib.sha256(prior).hexdigest()},runs=[flags|{'dependency_inputs_unchanged':True}])))
            manifest=root/'previous-manifest.json';manifest.write_text(json.dumps(dict(study=str(receipt),description='previous accepted candidate',sources={SOURCE:str(prior_path)})))
            argv+=['--baseline-sources',str(manifest)]

        def launch(command, **kwargs):
            commands.append(command)
            if failure == 'package-created':
                (out/'candidate-package/src/added.php').write_bytes(b'<?php')
            if failure == 'exit':
                return subprocess.CompletedProcess(command, 255, b'private fatal output', b'private error')
            if failure == 'malformed':
                return subprocess.CompletedProcess(command, 0, b'{private', b'')
            if failure == 'array':
                return subprocess.CompletedProcess(command, 0, b'[]', b'')
            worker = Path(next(a.split('=', 1)[1] for a in command if a.startswith('--worker=')))
            if worker.name == 'worker.php':
                (worker.parent / 'metrics/cpu-1.json').write_text(json.dumps({
                    'sections': {'nullable-current / caller': {'calls': 1, 'cpu_seconds': 1}}}))
            row = flags | dict(wall_seconds=5, cpu_seconds=7, peak_container_memory_mib=10, diagnostics=1)
            if failure == 'diagnostics':
                row['diagnostic_multiset_verified'] = False
            return subprocess.CompletedProcess(command, 0, json.dumps(row).encode(), b'')

        def git(command, **kwargs):
            return b'a' * 40 if command[1] == 'rev-parse' else BASELINE

        helpers = {name: lambda s: s for name in ['instrument_nullable', 'instrument_shutdown', 'instrument']}
        with patch.object(study, '__file__', str(repo / 'scripts/benchmark-native.py')), \
                patch.object(study.os, 'access', return_value=False), patch.object(sys, 'argv', argv), \
                patch.object(study.subprocess, 'check_output', side_effect=git), \
                patch.object(study.subprocess, 'run', side_effect=launch), \
                patch.object(study.runpy, 'run_path', return_value=helpers), contextlib.redirect_stdout(io.StringIO()):
            if failure:
                with self.assertRaises(RuntimeError):
                    study.main()
            else:
                study.main()
        return out, commands

    def test_balanced_fresh_scopes_and_frozen_sources(self):
        with tempfile.TemporaryDirectory() as directory:
            out, commands = self.exercise(directory)
            report = json.loads((out / 'results.json').read_text())
            self.assertTrue(report['complete'])
            self.assertEqual(len(commands), 8)
            self.assertEqual(len({next(a for a in c if a.startswith('--unit=')) for c in commands}), 8)
            for c in commands:
                self.assertIn('CPUQuota=200%', c)
                self.assertIn('MemoryMax=6G', c)
                self.assertIn('--native-host', c)
            rows = [(r['variant'], r['stage']) for r in report['runs']]
            self.assertEqual(rows[-4:], [('baseline', 'sample'), ('candidate', 'sample'),
                                         ('candidate', 'sample'), ('baseline', 'sample')])
            self.assertEqual((out / 'baseline-0.php').read_bytes(), BASELINE)
            self.assertEqual((out / 'candidate-0.php').read_bytes(), CANDIDATE)
            self.assertEqual(report['summaries']['candidate']['n'], 2)
            self.assertTrue(all(r['dependency_inputs_unchanged'] for r in report['runs']))

    def test_previous_candidate_is_frozen_instead_of_the_release_source(self):
        with tempfile.TemporaryDirectory() as directory:
            out,commands=self.exercise(directory,previous=True)
            report=json.loads((out/'results.json').read_text())
            expected=BASELINE+b'\n// previous accepted candidate\n'
            self.assertTrue(report['complete']);self.assertEqual((out/'baseline-0.php').read_bytes(),expected)
            self.assertEqual(report['baseline_sources'][SOURCE],hashlib.sha256(expected).hexdigest())
            self.assertEqual(report['baseline_source_overrides']['description'],'previous accepted candidate')

    def test_package_snapshots_match_complete_native_inventory(self):
        with tempfile.TemporaryDirectory() as directory:
            out,commands=self.exercise(directory,packages=True)
            report=json.loads((out/'results.json').read_text())
            self.assertTrue(report['complete'] and report['package_snapshot_native_inventory_verified'])
            self.assertEqual((out/'baseline-package'/SOURCE).read_bytes(),BASELINE)
            self.assertEqual((out/'candidate-package'/SOURCE).read_bytes(),CANDIDATE)
            self.assertIn(SOURCE,report['package_snapshots']['candidate'])
            self.assertEqual(len(commands),8)

    def test_package_snapshot_new_paths_invalidate_an_observation(self):
        with tempfile.TemporaryDirectory() as directory:
            out,commands=self.exercise(directory,'package-created',packages=True)
            report=json.loads((out/'results.json').read_text())
            self.assertFalse(report['complete']);self.assertEqual(len(commands),1)

    def test_existing_diagnostic_seal_is_reused_without_creation_or_replacement(self):
        with tempfile.TemporaryDirectory() as directory:
            out,commands=self.exercise(directory,packages=True,reference=True)
            seal=Path(directory)/'existing-reference'
            self.assertEqual(seal.read_bytes(),b'unchanged full diagnostic digest')
            self.assertTrue(json.loads((out/'results.json').read_text())['complete'])
            self.assertTrue(all('--create-diagnostic-seal' not in command for command in commands))
            self.assertTrue(all('--diagnostic-seal='+str(seal.resolve()) in command for command in commands))

    def test_failed_and_malformed_output_is_retained_privately(self):
        for mode, raw in [('exit', b'private fatal output'), ('malformed', b'{private'), ('array', b'[]')]:
            with self.subTest(mode=mode), tempfile.TemporaryDirectory() as directory:
                out, commands = self.exercise(directory, mode)
                report = json.loads((out / 'results.json').read_text())
                self.assertFalse(report['complete'])
                self.assertEqual(len(commands), 1)
                self.assertEqual((out / '1.stdout').read_bytes(), raw)
                self.assertTrue(report['failures'])
                self.assertNotIn('private', (out / 'results.json').read_text())

    def test_diagnostic_mismatch_stops_before_further_observations(self):
        with tempfile.TemporaryDirectory() as directory:
            out, commands = self.exercise(directory, 'diagnostics')
            report = json.loads((out / 'results.json').read_text())
            self.assertFalse(report['complete'])
            self.assertEqual(len(commands), 1)
            self.assertFalse(report['runs'][0]['diagnostic_multiset_verified'])


if __name__ == '__main__':
    unittest.main()
