// @ts-check
const { defineConfig, devices } = require('@playwright/test');

/**
 * E2E tests run against the Docker application (`docker compose up -d --wait`).
 * Override the target with E2E_BASE_URL.
 */
module.exports = defineConfig({
  testDir: './tests/e2e',
  timeout: 30_000,
  fullyParallel: false,
  workers: 1,
  reporter: [['list'], ['html', { open: 'never', outputFolder: 'playwright-report' }]],
  use: {
    baseURL: process.env.E2E_BASE_URL || `http://127.0.0.1:${process.env.APP_PORT || 8089}`,
    trace: 'retain-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
