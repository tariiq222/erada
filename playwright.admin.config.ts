import { defineConfig, devices } from '@playwright/test';

const adminUrl = 'http://127.0.0.1:4174';
const adminDatabasePort = process.env.ADMIN_E2E_DB_PORT ?? '5433';
const adminDatabaseName = process.env.ADMIN_E2E_DB_DATABASE ?? 'iradah_pmo_admin_e2e_test';
const testDatabaseEnvironment = [
  'APP_ENV=testing',
  'APP_URL=http://127.0.0.1:8000',
  'DB_CONNECTION=pgsql',
  'DB_HOST=127.0.0.1',
  `DB_PORT=${adminDatabasePort}`,
  `DB_DATABASE=${adminDatabaseName}`,
  'DB_USERNAME=iradah',
  'DB_PASSWORD=secret',
  'SANCTUM_STATEFUL_DOMAINS=127.0.0.1:4174',
  // The E2E helper assigns an isolated forwarded IP per browser context so
  // Laravel's production login limiter stays enabled without coupling cases.
  'TRUSTED_PROXIES=*',
  'SESSION_DRIVER=file',
  'CACHE_STORE=file',
  'CACHE_PREFIX=iradah_admin_e2e',
  'QUEUE_CONNECTION=sync',
  'MAIL_MAILER=array',
  'BCRYPT_ROUNDS=4',
  'APP_MAINTENANCE_DRIVER=file',
  'SESSION_SECURE_COOKIE=false',
  'SESSION_DOMAIN=',
  'SESSION_SAME_SITE=lax',
].join(' ');

export default defineConfig({
  testDir: 'e2e/admin',
  fullyParallel: false,
  forbidOnly: Boolean(process.env.CI),
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: 'list',
  use: {
    baseURL: adminUrl,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  projects: [{ name: 'admin-chromium', use: { ...devices['Desktop Chrome'] } }],
  webServer: [
    {
      command: `mkdir -p storage/framework/sessions && ${testDatabaseEnvironment} php artisan serve --host=127.0.0.1 --port=8000`,
      url: 'http://127.0.0.1:8000/api/health',
      reuseExistingServer: false,
      timeout: 120_000,
    },
    {
      command: 'npm run admin:dev -- --host 127.0.0.1 --port 4174 --strictPort',
      url: `${adminUrl}/login`,
      reuseExistingServer: false,
      timeout: 120_000,
    },
  ],
});
