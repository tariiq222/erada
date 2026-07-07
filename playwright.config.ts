import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright E2E configuration for Erada PMO
 * @see https://playwright.dev/docs/test-configuration
 */
export default defineConfig({
    testDir: 'e2e',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    workers: process.env.CI ? 1 : undefined,
    reporter: 'list',
    use: {
        baseURL: 'http://localhost:8000',
        trace: 'on-first-retry',
        screenshot: 'only-on-failure',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
    webServer: {
        // In CI this is the freshly-built/migrated/seeded app (see .github/workflows/ci.yml).
        // Locally, reuseExistingServer picks up `composer dev` / `php artisan serve` if already running.
        command: 'php artisan serve --host=0.0.0.0 --port=8000',
        url: 'http://localhost:8000/login',
        reuseExistingServer: !process.env.CI,
        timeout: 120_000,
    },
});
