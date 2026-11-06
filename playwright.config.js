// @ts-check
const { defineConfig, devices } = require('@playwright/test');

/**
 * E2E tests run against the Docker application (`docker compose up -d --wait`).
 * Override the target with E2E_BASE_URL; tests that need to move a demo account's expiry run
 * `bin/console app:demo:expire` through E2E_CONSOLE (default: docker compose exec -T app php bin/console).
 */
module.exports = defineConfig({
  testDir: './tests/e2e',
  timeout: 45_000,
  fullyParallel: false,
  workers: 1,
  reporter: [['list'], ['html', { open: 'never', outputFolder: 'playwright-report' }]],
  use: {
    baseURL: process.env.E2E_BASE_URL || `http://127.0.0.1:${process.env.APP_PORT || 8089}`,
    trace: 'retain-on-failure',
  },
  projects: [
    { name: 'desktop', use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } } },
    // Tablet layout (768–1279) only for the layout checks of form rows.
    { name: 'tablet', testMatch: /form-alignment\.spec\.js/, use: { ...devices['Desktop Chrome'], viewport: { width: 834, height: 1112 } } },
    { name: 'mobile', use: { ...devices['Desktop Chrome'], viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 3 } },
  ],
});
