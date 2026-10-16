"""Validate genuine analyzer output against target identifiers and corrected controls."""
import argparse
import hashlib
import json
from pathlib import Path


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--result', required=True, type=Path)
    ap.add_argument('--summary', required=True, type=Path)
    args = ap.parse_args()
    fixtures = Path(__file__).parent / 'fixtures/analyzer-depth'
    report = json.loads(args.result.read_text(encoding='utf-8'))
    expected = json.loads((fixtures / 'expectations.json').read_text(encoding='utf-8'))
    assert report['complete']
    assert report['model_autoload_control']
    assert report['harness_sha256'] == hashlib.sha256((Path(__file__).parents[1] / 'scripts/benchmark-depth.php').read_bytes()).hexdigest(), 'Changed harness'
    assert report['fixture_sha256'] == hashlib.sha256((fixtures / 'cases.php').read_bytes()).hexdigest(), 'Stale corpus'
    assert report['expectations_sha256'] == hashlib.sha256((fixtures / 'expectations.json').read_bytes()).hexdigest(), 'Changed oracle'
    levels = report['phpstan_levels']
    if isinstance(levels, dict):
        levels = [levels[str(i)] for i in range(11)]
    assert len(levels) == 11
    assert report['primary_matrix_checkConfigTypes'] is True
    for run in [*levels, report['phpstan_default_config_types_level10'], report['mago_native'], report['mago_laramago']]:
        assert set(run) == set(expected)
        assert all(set(pair) == {'bad', 'good'} for pair in run.values())
    rows = []
    for name, oracle in expected.items():
        selected_levels = [i for i, level in enumerate(levels)
                           if set(level[name]['bad']) & set(oracle['phpstan_identifiers'])]
        first = min(selected_levels) if selected_levels else None
        core_level = oracle['expected_phpstan_first_level']
        if core_level is not None:
            assert first == core_level, f'{name}: expected level {core_level}, observed {first}'
            assert selected_levels == list(range(first, 11)), f'{name}: unexpected non-cumulative witness'
            assert all(not level[name]['good'] for level in levels), f'{name}: corrected PHPStan control has a finding'
        row = {'case': name, 'core_level_oracle': core_level, 'phpstan_first_level': first,
               'phpstan_flagged_control_levels': [i for i, level in enumerate(levels) if level[name]['good']],
               'phpstan_default_config_types_level10': report['phpstan_default_config_types_level10'][name]}
        for mode in ['native', 'laramago']:
            pair = report['mago_' + mode][name]
            row['mago_' + mode] = pair
            row['mago_' + mode + '_expected_fault'] = [finding for finding in pair['bad']
                if finding.split('/')[0] in oracle['mago_codes']]
        rows.append(row)
    summary = {'complete': True, 'witness_pairs': len(rows), 'target_observations': len(rows) * 2 * 14,
               'core_oracles_passed': sum(r['core_level_oracle'] is not None for r in rows), 'cases': rows,
               'limitation': 'Finite public witnesses in analyze mode. Findings at marked positions; not exhaustive false-positive rates or a universal level equivalence.'}
    args.summary.write_text(json.dumps(summary, indent=2) + '\n', encoding='utf-8')
    print(json.dumps({k: summary[k] for k in ['complete', 'witness_pairs', 'target_observations', 'core_oracles_passed']}))


if __name__ == '__main__':
    main()
