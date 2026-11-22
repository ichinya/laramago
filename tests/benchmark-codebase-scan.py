#!/usr/bin/env python3
"""Protocol regression tests; these fabricated DTOs are NOT real Mago acceptance."""
import copy
import importlib.util
import pathlib
import sys
import tempfile
import unittest
from unittest import mock

sys.dont_write_bytecode = True

path = pathlib.Path(__file__).resolve().parents[1] / "scripts/benchmark-codebase-scan.py"
spec = importlib.util.spec_from_file_location("benchmark", path)
b = importlib.util.module_from_spec(spec)
spec.loader.exec_module(b)


class ProtocolTests(unittest.TestCase):
    def test_baseline_timeout_does_not_whitelist_oom_or_transport_failure(self):
        report = dict(mode='control', deadline_ms=30000, exit_code=2, cgroup_memory_events='oom 0\noom_kill 0')
        timeout = 'extension worker 0 did not answer request 3 within 30000 ms'
        self.assertTrue(b.request_timeout_only(report, timeout))
        for extra in ['Allowed memory size exhausted', 'protocol I/O failed', 'panicked']:
            with self.subTest(extra=extra): self.assertFalse(b.request_timeout_only(report, timeout + extra))
        self.assertFalse(b.request_timeout_only(dict(report, cgroup_memory_events='oom_kill 1'), timeout))
        self.assertFalse(b.request_timeout_only(dict(report, deadline_ms=600000), timeout))
        self.assertFalse(b.request_timeout_only(dict(report, mode='reference'), timeout))

    def observation(self):
        report = {'mode': 'control', 'exit_code': 1, 'deadline_ms': 30000,
                  'environment': {'php': '8.5.12', 'zts': True, 'memory_max': str(6 * 1024**3)},
                  'cgroup_memory_events': 'oom 0\noom_kill 0'}
        body = {'issues': [{'level': 'Error', 'code': code, 'annotations': [{'span': {
            'file_id': {'path': '/corpus/app/Controls.php'}, 'start': {'line': line}}}]}
            for code, line in [('invalid-return-statement', 8), ('invalid-argument', 10)]]}
        return report, body

    def test_positive_and_negative_controls_and_failures(self):
        report, body = self.observation()
        expected = b.validate_observation(report, body, '', 'control')
        for stderr in ['worker failed', 'did not answer request 3 within 30000 ms', 'Allowed memory size exhausted']:
            with self.subTest(stderr=stderr), self.assertRaises(ValueError):
                b.validate_observation(report, body, stderr, 'control', expected)
        for key, value in [('exit_code', 2), ('deadline_ms', 600000), ('cgroup_memory_events', 'oom_kill 1')]:
            changed = dict(report, **{key: value})
            with self.subTest(key=key), self.assertRaises(ValueError):
                b.validate_observation(changed, body, '', 'control', expected)
        with self.assertRaises(ValueError):
            b.validate_observation(report, {'issues': body['issues'][:1]}, '', 'control')
        extra = copy.deepcopy(body['issues'][0])
        extra['annotations'][0]['span']['start']['line'] = 7
        with self.assertRaises(ValueError):
            b.validate_observation(report, {'issues': body['issues'] + [extra]}, '', 'control')
        with self.assertRaises(ValueError):
            b.validate_observation(report, {'issues': body['issues'] + [body['issues'][0]]}, '', 'control', expected)

    def test_multiset_preserves_duplicates_and_every_field(self):
        a = {"level": "Error", "code": "return", "message": "bad", "nested": {"line": 9}}
        c = {"level": "Error", "code": "argument", "message": "bad"}
        expected = b.diagnostics({"issues": [a, a, c]})
        self.assertEqual(expected, b.diagnostics({"issues": [c, a, a]}))
        self.assertNotEqual(expected, b.diagnostics({"issues": [a, c]}))
        changed = copy.deepcopy(a)
        changed["nested"]["line"] = 10
        self.assertNotEqual(expected, b.diagnostics({"issues": [a, changed, c]}))

    def test_malformed_reports(self):
        for value in (None, [], {}, {"issues": {}}, {"issues": "[]"}, {"issues": [1]}, {"issues": [{}]}):
            with self.subTest(value=value), self.assertRaises(ValueError): b.diagnostics(value)

    def test_protocol_rejects_changed_versions_limits_and_roles(self):
        p = {"format": 1, "stage": "calibration", "image": b.IMAGE, "baseline_blob": b.BLOB,
             "mago": "1.59.0", "parser": "5.9.0", "corpora": {"small": "/tmp/corpus"},
             "resources": {"cpus": 2, "memory_bytes": 6 * 1024**3, "tmpfs_bytes": 512 * 1024**2,
                           "php_heap_mib": 512, "threads": 2, "request_deadline_ms": 30000, "command_timeout_seconds": 900},
             "runs": [{"variant": "A", "corpus": "small", "payload": 64, "workers": 1, "mode": "profile", "phase": "profile"}]}
        b.validate_protocol(p)
        for key, value in (("image", "latest"), ("mago", "1.48.1"), ("parser", "5.8.0"), ("baseline_blob", "HEAD")):
            changed = copy.deepcopy(p)
            changed[key] = value
            with self.subTest(key=key), self.assertRaises(ValueError): b.validate_protocol(changed)
        for key in p["resources"]:
            changed = copy.deepcopy(p)
            changed["resources"][key] += 1
            with self.subTest(key=key), self.assertRaises(ValueError): b.validate_protocol(changed)
        for field, value in (("payload", 4), ("workers", 3), ("phase", "measured"), ("variant", "C")):
            changed = copy.deepcopy(p)
            changed["runs"][0][field] = value
            with self.subTest(field=field), self.assertRaises(ValueError): b.validate_protocol(changed)
        changed = copy.deepcopy(p)
        changed["stage"] = "acceptance"
        with self.assertRaises(ValueError): b.validate_protocol(changed)

    def test_provider_and_timeout_signatures_are_failures(self):
        for text in ("did not answer request", "provider failed", "worker failed", "protocol I/O failed", "Allowed memory size exhausted"):
            with self.subTest(text=text): self.assertRegex(text, b.FAILURE)

    def test_source_seal_changes(self):
        self.assertNotEqual(b.digest({"a.php": "sha1"}), b.digest({"a.php": "sha2"}))
        self.assertNotEqual(b.digest({"a.php": "sha1"}), b.digest({"a.php": "sha1", "b.php": "sha2"}))

    def test_dependency_metadata_files_are_sealed_by_content(self):
        with tempfile.TemporaryDirectory() as temporary:
            source = pathlib.Path(temporary) / 'composer.lock'
            source.write_text('{"packages": []}')
            sealed = b.digest(b.inventory(source))
            source.write_text('{"packages": ["changed"]}')
            self.assertNotEqual(sealed, b.digest(b.inventory(source)))

    def test_changed_real_input_stops_before_variant_preparation_or_docker(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = pathlib.Path(temporary)
            inputs = root / 'inputs'
            inputs.mkdir()
            source = inputs / 'case.php'
            source.write_text('<?php return 9;')
            sealed = b.digest(b.inventory(inputs))
            source.write_text('<?php return 10;')
            protocol = {'format': 1, 'stage': 'calibration', 'image': b.IMAGE, 'baseline_blob': b.BLOB,
                        'mago': '1.59.0', 'parser': '5.9.0', 'package': str(root), 'corpora': {}, 'runs': [],
                        'resources': dict(cpus=2, memory_bytes=6*1024**3, tmpfs_bytes=512*1024**2,
                                          php_heap_mib=512, threads=2, request_deadline_ms=30000, command_timeout_seconds=900),
                        'seals': {str(inputs): sealed}}
            with mock.patch.object(b, 'prepare', side_effect=AssertionError('Must reject before preparation')):
                with self.assertRaisesRegex(ValueError, 'Input seal differs'):
                    b.run(protocol, root / 'result.json')
            self.assertFalse((root / 'result.json').exists())

    def test_invalid_json_is_not_an_empty_report(self):
        with tempfile.TemporaryDirectory() as temporary:
            path = pathlib.Path(temporary) / 'broken.json'
            path.write_text('{"issues": [')
            with self.assertRaises(ValueError): b.read_json(path)

    def test_observation_rejects_profile_substitution_before_dto(self):
        with self.assertRaises(ValueError):
            b.validate_observation({"mode": "profile", "exit_code": 0}, {"issues": []}, "", "control")


if __name__ == "__main__": unittest.main()
