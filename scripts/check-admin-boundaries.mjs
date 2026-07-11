#!/usr/bin/env node

import assert from 'node:assert/strict';
import { promises as fs } from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import ts from 'typescript';

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
  assert.deepEqual(
    extractImports("export const route = '@pages/example';"),
    [],
    'exported string values are not module specifiers',
  );
  const [escapedOperationalImport] = extractImports(
    "import '\\u002e\\u002e/\\u002e\\u002e/js/pages/admin/Overview';",
  );
  assert.equal(
    escapedOperationalImport,
    '../../js/pages/admin/Overview',
    'escaped import specifiers must be standards-decoded',
  );
  assert.equal(
    isForbiddenImport(escapedOperationalImport, 'resources/admin/app/AdminApp.tsx'),
    true,
    'decoded operational imports must be rejected',
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
  const sourceFile = ts.createSourceFile(
    'admin-boundary.tsx',
    source,
    ts.ScriptTarget.Latest,
    true,
    ts.ScriptKind.TSX,
  );
  const imports = [];

  function collectModuleSpecifier(moduleSpecifier) {
    if (moduleSpecifier && ts.isStringLiteralLike(moduleSpecifier)) {
      imports.push(moduleSpecifier.text);
    }
  }

  function visit(node) {
    if (ts.isImportDeclaration(node) || ts.isExportDeclaration(node)) {
      collectModuleSpecifier(node.moduleSpecifier);
    } else if (
      ts.isCallExpression(node)
      && node.expression.kind === ts.SyntaxKind.ImportKeyword
      && node.arguments.length === 1
    ) {
      collectModuleSpecifier(node.arguments[0]);
    }

    ts.forEachChild(node, visit);
  }

  visit(sourceFile);
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
