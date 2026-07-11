#!/usr/bin/env node

import assert from 'node:assert/strict';
import { promises as fs } from 'node:fs';
import path from 'node:path';
import process from 'node:process';

const ROOT = process.cwd();
const ADMIN_ROOT = path.join(ROOT, 'resources/admin');
const SOURCE_EXTENSIONS = new Set(['.js', '.jsx', '.ts', '.tsx']);
const FORBIDDEN_ALIASES = ['@app', '@pages', '@widgets', '@features'];
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

function extractImports(source) {
  const imports = [];
  const pattern = /(?:import|export)\s+(?:[^'";]*?\s+from\s+)?["']([^"']+)["']|import\s*\(\s*["']([^"']+)["']\s*\)/g;
  let match;

  while ((match = pattern.exec(source)) !== null) {
    imports.push(match[1] ?? match[2]);
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
