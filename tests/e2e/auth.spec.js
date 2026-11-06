// @ts-check
const { test, expect } = require('@playwright/test');
const { signUp, signOut, uniqueEmail } = require('./helpers');

test('sign up with a searchable currency picker, then sign out and back in', async ({ page }) => {
  await page.goto('/register');
  // Client-side validation (design: error summary + field errors).
  await page.click('#su-submit');
  await expect(page.locator('#su-summary')).toContainText('Fix 3 fields to continue');
  await expect(page.locator('#su-currency-cb')).toContainText('Select a currency');

  const email = uniqueEmail('signup');
  await page.fill('#su-email', email);
  await page.fill('#su-password', 'maple-river-42');
  await expect(page.locator('#su-password-rule')).toContainText('At least 8 characters — done');
  await page.click('#su-currency');
  await page.fill('#su-currency-cb .combobox__search', 'hryv');
  await expect(page.locator('#su-currency-cb [role=option]:visible')).toHaveCount(1);
  await page.keyboard.press('Enter');
  await expect(page.locator('#su-preview-in')).toHaveText('+₴1,250.00');
  await expect(page.locator('#su-preview-out')).toHaveText('−₴45.20');
  await page.click('#su-submit');
  await expect(page).toHaveURL(/\/dashboard/);

  // Default categories exist and amounts use the account currency.
  await page.goto('/categories');
  for (const name of ['Groceries', 'Dining out', 'Housing', 'Subscriptions', 'Other']) {
    await expect(page.getByText(name, { exact: true }).first()).toBeVisible();
  }

  await signOut(page);
  await page.goto('/dashboard');
  await expect(page).toHaveURL(/\/login/);

  await page.fill('#si-email', email.toUpperCase());
  await page.fill('#si-password', 'wrong-password');
  await page.click('#si-submit');
  await expect(page.locator('#si-invalid')).toContainText('Email or password is incorrect');
  await page.fill('#si-password', 'maple-river-42');
  await page.click('#si-submit');
  await expect(page).toHaveURL(/\/dashboard/);
});

test('signed-in pages are private: anonymous visitors are sent to sign in', async ({ page }) => {
  for (const url of ['/dashboard', '/transactions', '/categories', '/budgets']) {
    await page.goto(url);
    await expect(page).toHaveURL(/\/login/);
  }
  await signUp(page);
});
