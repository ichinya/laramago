import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { copyFileSync, mkdtempSync, mkdirSync, readFileSync, rmSync, symlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';
import { exportReferences, loadParser, diagnoseReferences, validateContract } from './export.mjs';

const parse = await loadParser();
function fixture(run) {
  const root = mkdtempSync(join(tmpdir(), 'laramago-vite-ast-'));
  try { run(root); } finally { rmSync(root, { recursive: true, force: true }); }
}

test('real parser finds syntax, not text, without executing application code', () => fixture(root => {
  writeFileSync(join(root, 'app.js'), [
    'throw new Error("APPLICATION EXECUTED");',
    '// import.meta.env.COMMENT',
    'const s = "import.meta.env.STRING";',
    'const r = /import.meta.env.REGEX/;',
    'const t = `import.meta.env.TEXT ${import.meta.env.TEMPLATE}`;',
    'const x = import.meta.env.DIRECT;',
    'const c = import.meta.env["COMPUTED"];',
    'const d = import.meta.env[key];',
    'const o = import.meta.env?.OPTIONAL;',
    'const fake = object.import.meta.env.SHADOW;',
    'const unicode = "😀"; const after = import.meta.env.AFTER;',
  ].join('\n'));
  const result = exportReferences(root, ['app.js', 'app.js'], parse);
  assert.deepEqual(result.references.map(item => item.name), ['TEMPLATE', 'DIRECT', 'COMPUTED', 'OPTIONAL', 'AFTER']);
  assert.equal(result.uncertainties.length, 1);
  assert.equal(result.errors.length, 0);
  assert.equal(result.references[2].computed, true);
  assert.equal(result.references[3].optional, true);
  assert.equal(result.references[4].offsetEncoding, 'utf16-code-units');
  const text = readFileSync(join(root, 'app.js'), 'utf8');
  assert.equal(text.slice(result.references[4].start, result.references[4].end), 'import.meta.env.AFTER');
  assert.notEqual(Buffer.byteLength(text.slice(0, result.references[4].start)), result.references[4].start);
  assert.equal(result.scope.applicationExecuted, false);
  assert.equal(result.truncated, false);
}));

test('resolved symlink targets cannot escape the selected project', () => fixture(root => {
  mkdirSync(join(root, 'project'));
  mkdirSync(join(root, 'outside'));
  writeFileSync(join(root, 'outside', 'app.js'), 'import.meta.env.OUTSIDE;');
  symlinkSync(join(root, 'outside'), join(root, 'project', 'linked'), process.platform === 'win32' ? 'junction' : 'dir');
  const result = exportReferences(join(root, 'project'), ['linked/app.js'], parse);
  assert.deepEqual(result.errors, [{ file: 'linked/app.js', code: 'invalid-source-path' }]);
  assert.deepEqual(result.references, []);
}));

test('invalid UTF-8 is not silently normalized into valid syntax', () => fixture(root => {
  writeFileSync(join(root, 'bad.js'), Buffer.from([47, 47, 255]));
  assert.equal(exportReferences(root, ['bad.js'], parse).errors[0].code, 'source-parse-error');
}));

test('BOM remains part of original UTF-16 source offsets', () => fixture(root => {
  const source = '\uFEFFimport.meta.env.BOM;';
  writeFileSync(join(root, 'bom.js'), source);
  const reference = exportReferences(root, ['bom.js'], parse).references[0];
  assert.equal(reference.start, 1);
  assert.equal(source.slice(reference.start, reference.end), 'import.meta.env.BOM');
}));

test('TypeScript, JSX and TSX syntax are parsed with explicit extension plugins', () => fixture(root => {
  writeFileSync(join(root, 'types.ts'), 'const x: string = import.meta.env.TYPES;');
  writeFileSync(join(root, 'view.jsx'), 'const x = <div title={import.meta.env.JSX}>import.meta.env.TEXT</div>;');
  writeFileSync(join(root, 'view.tsx'), 'const x: JSX.Element = <div>{import.meta.env.TSX}</div>;');
  const result = exportReferences(root, ['types.ts', 'view.jsx', 'view.tsx'], parse);
  assert.deepEqual(result.references.map(item => item.name), ['TYPES', 'JSX', 'TSX']);
  assert.deepEqual(result.errors, []);
}));

test('malformed files emit no partial candidates or source payload', () => fixture(root => {
  writeFileSync(join(root, 'broken.js'), 'const x = import.meta.env.BEFORE; const PRIVATE_SECRET = ;');
  const result = exportReferences(root, ['broken.js'], parse);
  assert.equal(result.references.length, 0);
  assert.equal(result.errors[0].code, 'source-parse-error');
  assert.ok(!JSON.stringify(result).includes('PRIVATE_SECRET'));
}));

test('paths and size limits are enforced', () => fixture(root => {
  writeFileSync(join(root, 'large.js'), ' '.repeat(1048577));
  writeFileSync(join(root, 'file.vue'), 'import.meta.env.HIDDEN');
  const result = exportReferences(root, ['../outside.js', 'C:/outside.js', 'missing.js', 'file.vue', 'large.js'], parse);
  assert.deepEqual(result.errors.map(item => item.code), ['invalid-source-path', 'invalid-source-path',
    'unreadable-source', 'unsupported-source-format', 'source-byte-limit']);
  assert.equal(result.truncated, true);
}));

test('dynamic and literal references share a bounded output budget across files', () => fixture(root => {
  writeFileSync(join(root, 'literal.js'), 'import.meta.env.ONE;');
  writeFileSync(join(root, 'dynamic.js'), 'import.meta.env[key];\n'.repeat(20005));
  writeFileSync(join(root, 'later.js'), 'import.meta.env.LATER;');
  const result = exportReferences(root, ['literal.js', 'dynamic.js', 'later.js'], parse);
  assert.equal(result.references.length, 1);
  assert.equal(result.uncertainties.length, 19999);
  assert.equal(result.truncated, true);
}));

test('requested extensions and nonregular sources are rejected before reading', () => fixture(root => {
  mkdirSync(join(root, 'directory.js'));
  // A directory symlink avoids Windows file-symlink privilege requirements.
  // Its resolved suffix is supported, but the selected .txt suffix is not.
  symlinkSync(join(root, 'directory.js'), join(root, 'alias.txt'), process.platform === 'win32' ? 'junction' : 'dir');
  const result = exportReferences(root, ['alias.txt', 'directory.js'], parse);
  assert.deepEqual(result.errors.map(item => item.code), ['unsupported-source-format', 'unreadable-source']);
  assert.deepEqual(result.references, []);
}));

test('CLI handles spaces and produces JSON without loading app configuration', () => fixture(root => {
  mkdirSync(join(root, 'with spaces'));
  writeFileSync(join(root, 'with spaces', 'app.js'), 'import.meta.env.CLI;');
  writeFileSync(join(root, 'babel.config.cjs'), 'throw new Error("CONFIG EXECUTED");');
  const run = spawnSync(process.execPath, [fileURLToPath(new URL('./export.mjs', import.meta.url)),
    '--root', root, 'with spaces/app.js'], { encoding: 'utf8' });
  assert.equal(run.status, 0, run.stderr);
  assert.equal(JSON.parse(run.stdout).references[0].name, 'CLI');
}));

test('missing parser fails explicitly without silently falling back', () => fixture(root => {
  copyFileSync(new URL('./export.mjs', import.meta.url), join(root, 'export.mjs'));
  const run = spawnSync(process.execPath, [join(root, 'export.mjs'), '--root', root, 'app.js'], { encoding: 'utf8' });
  assert.equal(run.status, 1);
  assert.equal(run.stdout, '');
  assert.match(run.stderr, /^parser-unavailable:/);
}));


const contract = () => ({ schemaVersion: 1, mode: 'production', prefixes: ['PUBLIC_'],
  availableNames: ['PUBLIC_PRESENT'], complete: true, nativeEnvironment: true });

test('explicit final inventory produces located diagnostics and preserves native builtins', () => fixture(root => {
  const source = 'const emoji = "😀"; import.meta.env.PUBLIC_PRESENT; import.meta.env.PUBLIC_MISSING; '
    + 'import.meta.env.VITE_OTHER; import.meta.env.MODE; import.meta.env.BASE_URL; '
    + 'import.meta.env.DEV; import.meta.env.PROD; import.meta.env.SSR; import.meta.env[key];';
  writeFileSync(join(root, 'app.js'), source);
  const result = diagnoseReferences(exportReferences(root, ['app.js'], parse), contract());
  assert.deepEqual(result.diagnostics.map(d => d.name), ['PUBLIC_MISSING', 'VITE_OTHER']);
  assert.deepEqual(result.diagnostics.map(d => d.reason), ['absent-from-inventory', 'outside-declared-prefixes']);
  assert.equal(result.uncertainties.length, 1);
  for (const d of result.diagnostics) {
    assert.equal(source.slice(d.start, d.end), `import.meta.env.${d.name}`);
    assert.equal(d.offsetEncoding, 'utf16-code-units');
    assert.equal(d.sourceHash, createHash('sha256').update(source).digest('hex'));
    assert.equal(d.runtimeFailure, false);
  }
  assert.equal(result.scope.applicationExecuted, false);
  assert.equal(result.scope.environmentInventoryInferred, false);
}));

test('contracts reject partial, dynamic, oversized, duplicate and value-bearing inventories', () => {
  for (const patch of [ { complete: false }, { nativeEnvironment: false }, { mode: '' },
    { prefixes: [''] }, { prefixes: ['PUBLIC_', 'PUBLIC_'] }, { prefixes: Array(33).fill('X') },
    { availableNames: ['OTHER'] }, { availableNames: ['PUBLIC_PRESENT', 'PUBLIC_PRESENT'] },
    { availableNames: Array(10001).fill('PUBLIC_A') }, { availableNames: ['PUBLIC_\nSECRET'] },
    { availableNames: [{ name: 'PUBLIC_A', value: 'SECRET' }] }, { values: {} },
    { mode: 'x'.repeat(257) } ]) {
    assert.throws(() => validateContract({ ...contract(), ...patch }), /invalid-environment-contract/);
  }
  assert.throws(() => validateContract(null), /invalid-environment-contract/);
  assert.throws(() => validateContract([]), /invalid-environment-contract/);
  assert.doesNotThrow(() => validateContract({ ...contract(), prefixes: ['PUBLIC_', 'APP_'], availableNames: [] }));
});

test('contract CLI reports findings, clean runs, incomplete sources, invalid contracts and caps', () => fixture(root => {
  const entry = fileURLToPath(new URL('./export.mjs', import.meta.url));
  const config = join(root, 'contract with spaces.json');
  const run = (...files) => spawnSync(process.execPath, [entry, '--root', root, '--contract', config, ...files], { encoding: 'utf8' });
  writeFileSync(config, JSON.stringify(contract()));
  writeFileSync(join(root, '.env'), 'PRIVATE_SECRET=DO_NOT_READ');
  writeFileSync(join(root, 'vite.config.js'), 'throw new Error("CONFIG EXECUTED")');
  writeFileSync(join(root, 'app.js'), 'import.meta.env.PUBLIC_MISSING;');
  let result = run('app.js');
  assert.equal(result.status, 1, result.stderr);
  assert.equal(JSON.parse(result.stdout).diagnostics.length, 1);
  assert.ok(!result.stdout.includes('DO_NOT_READ'));
  writeFileSync(join(root, 'app.js'), 'import.meta.env.PUBLIC_PRESENT;');
  result = run('app.js');
  assert.equal(result.status, 0, result.stderr);
  assert.deepEqual(JSON.parse(result.stdout).diagnostics, []);
  result = run('missing.js');
  assert.equal(result.status, 2);
  assert.equal(JSON.parse(result.stdout).errors[0].code, 'unreadable-source');
  for (const invalid of ['{"secret":"DO_NOT_LEAK"}', ' '.repeat(1048577), '{bad']) {
    writeFileSync(config, invalid);
    result = run('app.js');
    assert.equal(result.status, 1);
    assert.equal(result.stdout, '');
    assert.equal(result.stderr.trim(), 'invalid-environment-contract');
  }
}));
