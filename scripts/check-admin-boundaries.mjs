#!/usr/bin/env node

import assert from 'node:assert/strict';
import { promises as fs } from 'node:fs';
import path from 'node:path';
import process from 'node:process';

const ROOT = process.cwd();
const ADMIN_ROOT = path.join(ROOT, 'resources/admin');
const SOURCE_EXTENSIONS = new Set(['.js', '.jsx', '.ts', '.tsx']);
const FORBIDDEN_ALIASES = ['@app', '@pages', '@widgets', '@features'];
const ALLOWED_ALIAS_ROOTS = new Map([
  ['@admin', path.join(ROOT, 'resources/admin')],
  ['@shared', path.join(ROOT, 'resources/js/shared')],
  ['@entities', path.join(ROOT, 'resources/js/entities')],
]);
const FORBIDDEN_OPERATIONAL_ROOTS = [
  path.join(ROOT, 'resources/js/app'),
  path.join(ROOT, 'resources/js/pages'),
  path.join(ROOT, 'resources/js/widgets'),
  path.join(ROOT, 'resources/js/features'),
];
const OPERATIONAL_APP_ENTRY = path.join(ROOT, 'resources/js/app.tsx');

function isWithin(candidate, directory) {
  const relative = path.relative(directory, candidate);
  return relative === '' || (!relative.startsWith('..') && !path.isAbsolute(relative));
}

function matchesAlias(specifier, alias) {
  return specifier === alias || specifier.startsWith(`${alias}/`);
}

function isForbiddenImport(specifier, importer) {
  if (FORBIDDEN_ALIASES.some((alias) => matchesAlias(specifier, alias))) {
    return true;
  }

  for (const [alias, aliasRoot] of ALLOWED_ALIAS_ROOTS) {
    if (matchesAlias(specifier, alias)) {
      const suffix = specifier.slice(alias.length).replace(/^\//, '');
      return !isWithin(path.resolve(aliasRoot, suffix), aliasRoot);
    }
  }

  if (!specifier.startsWith('.')) {
    return false;
  }

  const resolved = path.resolve(ROOT, path.dirname(importer), specifier);
  return resolved === OPERATIONAL_APP_ENTRY
    || FORBIDDEN_OPERATIONAL_ROOTS.some((directory) => isWithin(resolved, directory));
}

function runSelfTest() {
  assert.equal(
    isForbiddenImport('@shared/ui/Button', 'resources/admin/app/AdminApp.tsx'),
    false,
    'shared imports must remain allowed',
  );
  assert.equal(
    isForbiddenImport('@pages/admin/overview/Overview', 'resources/admin/app/AdminApp.tsx'),
    true,
    'operational page imports must be rejected',
  );
  assert.equal(
    isForbiddenImport('@shared/../pages/admin/Overview', 'resources/admin/app/AdminApp.tsx'),
    true,
    'shared alias traversal into operational pages must be rejected',
  );
  assert.equal(
    isForbiddenImport('@entities/../features/access-control', 'resources/admin/app/AdminApp.tsx'),
    true,
    'entities alias traversal into operational features must be rejected',
  );
  assert.equal(
    isForbiddenImport('@admin/../../js/widgets/admin-shell', 'resources/admin/app/AdminApp.tsx'),
    true,
    'admin alias traversal into operational widgets must be rejected',
  );
  assert.deepEqual(
    extractImports("const page = import(/* chunk */ '../../js/pages/admin/Overview');"),
    ['../../js/pages/admin/Overview'],
    'commented dynamic imports must be extracted',
  );
  console.log('admin-boundaries:self-test — PASS');
}

async function walk(directory) {
  const entries = await fs.readdir(directory, { withFileTypes: true });
  const files = [];

  for (const entry of entries) {
    const absolutePath = path.join(directory, entry.name);
    if (entry.isDirectory()) {
      files.push(...await walk(absolutePath));
    } else if (SOURCE_EXTENSIONS.has(path.extname(entry.name))) {
      files.push(absolutePath);
    }
  }

  return files;
}

function tokenize(source) {
  const tokens = [];
  let index = 0;

  while (index < source.length) {
    const char = source[index];
    const next = source[index + 1];

    if (/\s/.test(char)) {
      index += 1;
      continue;
    }

    if (char === '/' && next === '/') {
      index = source.indexOf('\n', index + 2);
      if (index === -1) break;
      continue;
    }

    if (char === '/' && next === '*') {
      const commentEnd = source.indexOf('*/', index + 2);
      index = commentEnd === -1 ? source.length : commentEnd + 2;
      continue;
    }

    if (char === "'" || char === '"') {
      const quote = char;
      let value = '';
      index += 1;
      while (index < source.length && source[index] !== quote) {
        if (source[index] === '\\' && index + 1 < source.length) {
          value += source[index + 1];
          index += 2;
        } else {
          value += source[index];
          index += 1;
        }
      }
      index += 1;
      tokens.push({ type: 'string', value });
      continue;
    }

    if (/[A-Za-z_$]/.test(char)) {
      let end = index + 1;
      while (end < source.length && /[A-Za-z0-9_$]/.test(source[end])) {
        end += 1;
      }
      tokens.push({ type: 'identifier', value: source.slice(index, end) });
      index = end;
      continue;
    }

    tokens.push({ type: 'punctuation', value: char });
    index += 1;
  }

  return tokens;
}

function extractImports(source) {
  const tokens = tokenize(source);
  const imports = [];

  for (let index = 0; index < tokens.length; index += 1) {
    const token = tokens[index];
    if (token.type !== 'identifier' || (token.value !== 'import' && token.value !== 'export')) {
      continue;
    }

    if (token.value === 'import' && tokens[index + 1]?.value === '(') {
      const specifier = tokens[index + 2];
      if (specifier?.type === 'string') {
        imports.push(specifier.value);
      }
      continue;
    }

    for (let cursor = index + 1; cursor < tokens.length; cursor += 1) {
      const candidate = tokens[cursor];
      if (candidate.value === ';') break;
      if (candidate.type === 'string') {
        imports.push(candidate.value);
        break;
      }
    }
  }

  return imports;
}

async function checkAdminBoundaries() {
  const files = await walk(ADMIN_ROOT);
  const violations = [];

  for (const absoluteFile of files) {
    const importer = path.relative(ROOT, absoluteFile);
    const source = await fs.readFile(absoluteFile, 'utf8');
    for (const specifier of extractImports(source)) {
      if (isForbiddenImport(specifier, importer)) {
        violations.push(`${importer}: forbidden import ${specifier}`);
      }
    }
  }

  if (violations.length > 0) {
    console.error(`admin-boundaries — FAIL (${violations.length} violation${violations.length === 1 ? '' : 's'})`);
    for (const violation of violations) {
      console.error(`  ${violation}`);
    }
    process.exitCode = 1;
    return;
  }

  console.log(`admin-boundaries — PASS (${files.length} files scanned)`);
}

if (process.argv.includes('--self-test')) {
  runSelfTest();
} else {
  checkAdminBoundaries().catch((error) => {
    console.error('admin-boundaries crashed:', error);
    process.exitCode = 2;
  });
}
