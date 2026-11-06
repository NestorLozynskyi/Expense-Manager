// @ts-check
const { execSync } = require('child_process');
const { expect } = require('@playwright/test');

const CONSOLE = process.env.E2E_CONSOLE || 'docker compose exec -T app php bin/console';

/** Runs a Symfony console command in the app container (used to move a demo's expiry). */
function consoleCmd(args) {
  return execSync(`${CONSOLE} ${args}`, { cwd: __dirname + '/../..', encoding: 'utf8' });
}

let seq = 0;
function uniqueEmail(prefix = 'e2e') {
  return `${prefix}-${Date.now()}-${++seq}@example.com`;
}

/** Creates an account through the sign-up form and lands on the dashboard. */
async function signUp(page, { email = uniqueEmail(), password = 'maple-river-42', currency = 'USD' } = {}) {
  await page.goto('/register');
  await page.fill('#su-email', email);
  await page.fill('#su-password', password);
  await page.click('#su-currency');
  await page.fill('#su-currency-cb .combobox__search', currency);
  await page.keyboard.press('Enter');
  await expect(page.locator('#su-currency .combobox__value')).toContainText(currency);
  await page.click('#su-submit');
  await expect(page).toHaveURL(/\/dashboard/);
  return { email, password };
}

async function startDemo(page) {
  // "Try demo" is rate-limited per IP (5/hour); e2e runs start many demos from one IP.
  consoleCmd('cache:pool:clear cache.rate_limiter');
  await page.goto('/login');
  await page.getByRole('button', { name: 'Try demo' }).click();
  await expect(page).toHaveURL(/\/dashboard/);
  await expect(page.locator('#em-demo-banner')).toBeVisible();
  return (await page.locator('[data-account-email]').first().textContent())?.trim() || '';
}

/** Opens the account menu on tablet/mobile (sidebar on desktop has the button inline). */
async function signOut(page) {
  const sidebar = page.locator('.sidebar__footer button[type=submit]');
  if (await sidebar.isVisible()) {
    await sidebar.click();
  } else {
    await page.getByRole('button', { name: 'Account menu' }).click();
    await page.getByRole('menuitem', { name: 'Sign out' }).click();
  }
  await expect(page).toHaveURL(/\/login/);
}

module.exports = { consoleCmd, uniqueEmail, signUp, startDemo, signOut };
