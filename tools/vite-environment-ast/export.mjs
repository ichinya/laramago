import { createHash } from 'node:crypto';
import { closeSync, openSync, readSync, realpathSync, statSync } from 'node:fs';
import { extname, isAbsolute, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const limits = { files: 256, fileBytes: 1048576, totalBytes: 8388608, nodes: 200000, references: 20000 };

// This imports only the adapter's installed parser, never application modules or configuration.
export async function loadParser() {
  try {
    return (await import('@babel/parser')).parse;
  } catch {
    throw new Error('parser-unavailable: run npm ci in tools/vite-environment-ast');
  }
}

function property(node, name) {
  return node?.type === 'MemberExpression' && !node.computed
    && node.property?.type === 'Identifier' && node.property.name === name;
}

export function exportReferences(root, files, parse) {
  const projectRoot = realpathSync(root);
  if (!statSync(projectRoot).isDirectory()) throw new Error('invalid-project-root');
  const result = {
    schemaVersion: 1, projectRoot,
    scope: { kind: 'vite-environment-ast-references', evidence: 'babel-ast',
      completeness: 'non-exhaustive', runtimeValuesResolved: false,
      vitePrefixValidated: false, applicationExecuted: false },
    references: [], errors: [], uncertainties: [], truncated: false,
  };
  const seen = new Set();
  let total = 0;
  if (files.length > limits.files) result.truncated = true;
  for (const file of files.slice(0, limits.files)) {
    let path, source;
    try {
      if (!file || isAbsolute(file) || file.includes(':') || file.split(/[\\/]/).includes('..')) {
        throw new Error('invalid-source-path');
      }
      if (!/\.(?:[cm]?[jt]s|[jt]sx)$/i.test(file)) throw new Error('unsupported-source-format');
      path = realpathSync(resolve(projectRoot, file));
      const rel = relative(projectRoot, path);
      if (rel === '..' || rel.startsWith(`..${sep}`) || isAbsolute(rel)) throw new Error('invalid-source-path');
      if (!/\.(?:[cm]?[jt]s|[jt]sx)$/i.test(path)) throw new Error('unsupported-source-format');
      const identity = process.platform === 'win32' ? path.toLowerCase() : path;
      if (seen.has(identity)) continue;
      seen.add(identity);
      const stat = statSync(path);
      if (!stat.isFile()) throw new Error('unreadable-source');
      const size = stat.size;
      if (size > limits.fileBytes || total + size > limits.totalBytes) throw new Error('source-byte-limit');
      // Read only a bounded amount, including files that grow between stat and read.
      const descriptor = openSync(path, 'r');
      try {
        const buffer = Buffer.alloc(limits.fileBytes + 1);
        let offset = 0;
        while (offset < buffer.length) {
          const count = readSync(descriptor, buffer, offset, buffer.length - offset, null);
          if (!count) break;
          offset += count;
        }
        source = buffer.subarray(0, offset);
      } finally {
        closeSync(descriptor);
      }
      if (source.length > limits.fileBytes || total + source.length > limits.totalBytes) throw new Error('source-byte-limit');
      total += source.length;
    } catch (error) {
      const code = ['invalid-source-path', 'unsupported-source-format', 'source-byte-limit'].includes(error.message)
        ? error.message : 'unreadable-source';
      result.errors.push({ file, code });
      if (code === 'source-byte-limit') result.truncated = true;
      continue;
    }
    const extension = extname(path).toLowerCase();
    const plugins = [];
    if (/\.[cm]?tsx?$/.test(extension)) plugins.push('typescript');
    if (/\.[jt]sx$/.test(extension)) plugins.push('jsx');
    let ast;
    try {
      ast = parse(new TextDecoder('utf-8', { fatal: true, ignoreBOM: true }).decode(source), {
        sourceType: 'module', plugins, errorRecovery: false,
      });
    } catch {
      result.errors.push({ file, code: 'source-parse-error' });
      continue;
    }
    const sourceHash = createHash('sha256').update(source).digest('hex');
    const stack = [ast];
    let visited = 0;
    while (stack.length) {
      if (++visited > limits.nodes || result.references.length + result.uncertainties.length >= limits.references) {
        result.truncated = true;
        break;
      }
      const node = stack.pop();
      if (node.type === 'MemberExpression' || node.type === 'OptionalMemberExpression') {
        const env = node.object;
        if (property(env, 'env') && env.object?.type === 'MetaProperty'
          && env.object.meta?.name === 'import' && env.object.property?.name === 'meta') {
          const name = !node.computed && node.property.type === 'Identifier' ? node.property.name
            : node.computed && node.property.type === 'StringLiteral' ? node.property.value : null;
          const location = { file, sourceHash, start: node.start, end: node.end,
            line: node.loc.start.line, column: node.loc.start.column,
            offsetEncoding: 'utf16-code-units' };
          if (name !== null) {
            result.references.push({ name, computed: node.computed, optional: node.optional === true, ...location });
          } else {
            result.uncertainties.push({ code: 'dynamic-environment-name', ...location });
          }
        }
      }
      // Traverse syntax nodes only: comments, literal contents and attached metadata are not code.
      for (const value of Object.values(node)) {
        if (Array.isArray(value)) {
          for (let index = value.length - 1; index >= 0; index--) {
            if (value[index]?.type) stack.push(value[index]);
          }
        } else if (value?.type) stack.push(value);
      }
    }
  }
  result.references.sort((a, b) => a.file.localeCompare(b.file) || a.start - b.start);
  return result;
}

// The contract is an independent user assertion, never inferred from dotenv templates.
export function validateContract(contract) {
  const fields = ['schemaVersion', 'mode', 'prefixes', 'availableNames', 'complete', 'nativeEnvironment'];
  const validString = value => typeof value === 'string' && value.length > 0 && value.length <= 256
    && !/[\u0000-\u001f\u007f]/u.test(value);
  if (!contract || typeof contract !== 'object' || Array.isArray(contract)
    || Object.keys(contract).length !== fields.length || fields.some(key => !Object.hasOwn(contract, key))
    || contract.schemaVersion !== 1 || contract.complete !== true || contract.nativeEnvironment !== true
    || !validString(contract.mode) || !Array.isArray(contract.prefixes) || !contract.prefixes.length
    || contract.prefixes.length > 32 || !contract.prefixes.every(validString)
    || new Set(contract.prefixes).size !== contract.prefixes.length
    || !Array.isArray(contract.availableNames) || contract.availableNames.length > 10000
    || !contract.availableNames.every(validString)
    || new Set(contract.availableNames).size !== contract.availableNames.length
    || contract.availableNames.some(name => !contract.prefixes.some(prefix => name.startsWith(prefix)))) {
    throw new Error('invalid-environment-contract');
  }
  return contract;
}

export function diagnoseReferences(result, input) {
  const contract = validateContract(input);
  const available = new Set([...contract.availableNames, 'MODE', 'BASE_URL', 'DEV', 'PROD', 'SSR']);
  return {
    ...result,
    scope: { ...result.scope, kind: 'vite-environment-contract-diagnostics',
      evidence: 'babel-ast-and-explicit-contract', environmentInventoryInferred: false,
      runtimeFailureClaimed: false, contractMode: contract.mode },
    diagnostics: result.references.filter(reference => !available.has(reference.name)).map(reference => ({
      code: 'environment-name-outside-contract', severity: 'warning', ...reference,
      message: 'Environment name is absent from the explicitly asserted exposed-name inventory.',
      reason: contract.prefixes.some(prefix => reference.name.startsWith(prefix))
        ? 'absent-from-inventory' : 'outside-declared-prefixes',
      conditionalOnContract: true, runtimeFailure: false,
    })),
  };
}

function readContract(file) {
  if (!statSync(file).isFile()) throw new Error('invalid-environment-contract');
  const descriptor = openSync(file, 'r');
  try {
    const buffer = Buffer.alloc(1048577);
    let length = 0;
    while (length < buffer.length) {
      const count = readSync(descriptor, buffer, length, buffer.length - length, null);
      if (!count) break;
      length += count;
    }
    if (length > 1048576) throw new Error('invalid-environment-contract');
    return validateContract(JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(buffer.subarray(0, length))));
  } finally { closeSync(descriptor); }
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  try {
    const args = process.argv.slice(2);
    if (args.length < 3 || args[0] !== '--root') throw new Error('usage: node export.mjs --root PROJECT [--contract JSON] FILE...');
    let files = args.slice(2), contract = null;
    if (files[0] === '--contract') {
      try { contract = readContract(files[1]); } catch { throw new Error('invalid-environment-contract'); }
      files = files.slice(2);
    }
    if (!files.length || files.some(file => file.startsWith('--'))) {
      throw new Error('usage: node export.mjs --root PROJECT [--contract JSON] FILE...');
    }
    const parse = await loadParser();
    const references = exportReferences(args[1], files, parse);
    const result = contract ? diagnoseReferences(references, contract) : references;
    process.stdout.write(`${JSON.stringify(result, null, 2)}\n`);
    if (contract && (result.errors.length || result.truncated)) process.exitCode = 2;
    else if (result.diagnostics?.length) process.exitCode = 1;
  } catch (error) {
    process.stderr.write(`${error.message.startsWith('parser-unavailable:') || error.message.startsWith('usage:')
      || error.message === 'invalid-environment-contract'
      ? error.message : 'export-failed: check the project root and source paths'}\n`);
    process.exitCode = 1;
  }
}
