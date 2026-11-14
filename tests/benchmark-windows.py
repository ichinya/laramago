"""Measurement protocol checks; synthetic diagnostics are not analyzer acceptance."""
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('windows_measurement', Path(__file__).parents[1] / 'scripts/benchmark-windows.py')
study = importlib.util.module_from_spec(spec); spec.loader.exec_module(study)


class WindowsProtocol(unittest.TestCase):
    def test_capacity_requires_commit_and_physical_headroom_and_stability(self):
        states = [dict(available_commit_mib=512, available_physical_mib=8192),
                  dict(available_commit_mib=8192, available_physical_mib=512),
                  dict(available_commit_mib=8192, available_physical_mib=8192)]
        with tempfile.TemporaryDirectory() as folder, patch.object(study, 'host_state', side_effect=[*states, states[-1]]), \
                patch.object(study.time, 'monotonic', side_effect=[0, 0, 10, 20, 30]), patch.object(study.time, 'sleep') as sleep:
            result = study.wait_for_capacity(Path(folder) / 'capacity.json')
            self.assertTrue(result['complete']); self.assertEqual(result['samples'], 4)
            self.assertEqual(result['wait_seconds'], 30); self.assertEqual(sleep.call_count, 3)

    def test_capacity_deadline_retains_a_failed_receipt(self):
        with tempfile.TemporaryDirectory() as folder, patch.object(study, 'host_state', return_value=dict(available_commit_mib=100, available_physical_mib=8192)), \
                patch.object(study.time, 'monotonic', side_effect=[0, 28801]):
            path = Path(folder) / 'capacity.json'
            with self.assertRaisesRegex(RuntimeError, 'eight-hour'): study.wait_for_capacity(path)
            self.assertFalse(json.loads(path.read_text())['complete'])

    def test_full_diagnostic_identity_keeps_fields_duplicates_and_ignores_order(self):
        a = dict(code='example', level='Error', message='first', annotations=[])
        b = dict(a, message='second')
        identity = lambda rows: study.diagnostic_identity(json.dumps(dict(issues=rows)).encode(), b'', True)
        self.assertEqual(identity([a, b, a]), identity([b, a, a]))
        self.assertNotEqual(identity([a, b, a])[1], identity([a, b])[1])
        self.assertNotEqual(identity([a])[1], identity([b])[1])

    def test_worker_failure_and_commit_exhaustion_cannot_be_valid_timings(self):
        body = json.dumps(dict(issues=[])).encode()
        for text in [b'External analyzer provider failed; using native analysis fallback.',
                     b'VirtualAlloc() failed: [0x000005af]', b'invalid extension frame magic']:
            with self.subTest(text=text), self.assertRaisesRegex(RuntimeError, 'infrastructure'):
                study.diagnostic_identity(body, text, True)

    def test_phpstan_global_errors_or_missing_cache_confirmation_are_rejected(self):
        for body in [dict(totals=dict(errors=1), files={}), dict(totals=dict(errors=0), files={})]:
            with self.subTest(body=body), self.assertRaises(RuntimeError):
                study.diagnostic_identity(json.dumps(body).encode(), b'', False)


if __name__ == '__main__': unittest.main()
