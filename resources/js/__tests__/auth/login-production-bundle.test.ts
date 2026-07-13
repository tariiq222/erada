import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, readdirSync, readFileSync, rmSync, statSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { env, execPath } from 'node:process';

const FIXTURE_ACCOUNTS = [
  'admin@admin.com',
  'manager@admin.com',
  'pmo.member@demo.com',
  'pmo.manager@demo.com',
] as const;

const REPO_ROOT = resolve(import.meta.dirname, '../../../..');
const TEXT_ARTIFACT_EXTENSIONS = ['.css', '.html', '.js', '.json', '.map'];
const BUILD_SCRIPT = `
  import { resolve } from 'node:path';
  import { build } from 'vite';

  const [root, outDir] = process.argv.slice(1);
  build({
    root,
    configFile: resolve(root, 'vite.config.js'),
    mode: 'production',
    publicDir: false,
    logLevel: 'silent',
    build: { outDir, emptyOutDir: true },
  }).catch((error) => {
    console.error(error);
    process.exitCode = 1;
  });
`;

function readTextArtifacts(directory: string): string {
  return readdirSync(directory)
    .flatMap((entry) => {
      const path = join(directory, entry);
      if (statSync(path).isDirectory()) {
        return readTextArtifacts(path);
      }

      return TEXT_ARTIFACT_EXTENSIONS.some((extension) => path.endsWith(extension))
        ? readFileSync(path, 'utf8')
        : '';
    })
    .join('\n');
}

describe('login production bundle', () => {
  let outputDirectory = '';
  let productionArtifacts = '';

  beforeAll(() => {
    outputDirectory = mkdtempSync(join(tmpdir(), 'erada-login-production-'));

    execFileSync(
      execPath,
      ['--input-type=module', '--eval', BUILD_SCRIPT, REPO_ROOT, outputDirectory],
      {
        cwd: REPO_ROOT,
        env: { ...env, NODE_ENV: 'production' },
        stdio: 'pipe',
      },
    );

    productionArtifacts = readTextArtifacts(outputDirectory);
  }, 120_000);

  afterAll(() => {
    if (outputDirectory) {
      rmSync(outputDirectory, { recursive: true, force: true });
    }
  });

  it('excludes every fixture account', () => {
    const shippedFixtureAccounts = FIXTURE_ACCOUNTS.filter((account) =>
      productionArtifacts.includes(account),
    );

    expect(shippedFixtureAccounts).toEqual([]);
  });
});
