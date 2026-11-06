// @ts-check
const { test, expect } = require('@playwright/test');
const { startDemo, consoleCmd } = require('./helpers');

test('demo: start, survives reload, 10-minute warning, then ends', async ({ page }) => {
  const email = await startDemo(page);
  expect(email).toMatch(/^demo-.+@demo\.expensemanager\.app$/);
  await expect(page.locator('#em-demo-banner')).toContainText(/2[34] h/);

  // Sample data is there and editable demo data survives a reload.
  await page.reload();
  await expect(page.locator('#em-demo-banner')).toBeVisible();
  await expect(page.locator('[data-account-email]').first()).toHaveText(email);

  // 9 minutes left → warning tone + one-time dialog.
  consoleCmd(`app:demo:expire ${email} --in=540`);
  await page.reload();
  await expect(page.locator('#em-demo-banner')).toHaveClass(/demo-banner--warning/);
  const dialog = page.getByRole('dialog', { name: 'Your demo ends in 10 minutes' });
  await expect(dialog).toBeVisible();
  await dialog.getByRole('button', { name: 'Continue demo' }).click();
  await expect(dialog).toBeHidden();
  await page.reload();
  await expect(dialog).toBeHidden(); // shown once

  // Time is up → session ends, account deleted, "demo ended" screen.
  consoleCmd(`app:demo:expire ${email} --in=0`);
  await page.reload();
  await expect(page).toHaveURL(/\/demo\/ended/);
  await expect(page.getByRole('heading', { name: 'Your demo session has ended' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Create a free account' })).toBeVisible();
  await page.goto('/dashboard');
  await expect(page).toHaveURL(/\/login/);

  // A new demo is a fresh, separate account.
  await page.goto('/demo/ended');
  await page.getByRole('button', { name: 'Start a new demo' }).click();
  await expect(page).toHaveURL(/\/dashboard/);
  const second = (await page.locator('[data-account-email]').first().textContent())?.trim();
  expect(second).not.toBe(email);
});

test('demo: the banner counts down and the warning opens without reloading', async ({ page }) => {
  const email = await startDemo(page);
  consoleCmd(`app:demo:expire ${email} --in=615`); // 10 min 15 s
  await page.reload();
  await expect(page.locator('#em-demo-banner')).not.toHaveClass(/demo-banner--warning/);
  await expect(page.getByRole('dialog', { name: 'Your demo ends in 10 minutes' })).toBeVisible({ timeout: 35_000 });
  await expect(page.locator('#em-demo-banner')).toHaveClass(/demo-banner--warning/);
});
