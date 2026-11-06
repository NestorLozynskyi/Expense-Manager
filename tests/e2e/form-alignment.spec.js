// @ts-check
/*
 * TASK-004: fields in one form row stay aligned (label tops and control tops within 1px) when
 * an error or hint appears under one of them. On mobile (< 768px) rows stack into one column;
 * there the fields must keep their order without overlapping.
 */
const { test, expect } = require('@playwright/test');
const { signUp, startDemo } = require('./helpers');

/** Asserts that two fields of a row are aligned (side by side) or cleanly stacked (mobile). */
/** Bounding box once it stopped moving (dialogs/popovers animate in). */
async function stableBox(locator) {
  let prev = null;
  for (let i = 0; i < 40; i++) {
    const box = await locator.boundingBox();
    if (box && prev && box.x === prev.x && box.y === prev.y && box.height === prev.height) return box;
    prev = box;
    await locator.page().waitForTimeout(50);
  }
  return prev;
}

async function expectRowAligned(page, leftControl, rightControl, state) {
  const fieldOf = (sel) => page.locator(sel).locator('xpath=ancestor::div[contains(concat(" ", normalize-space(@class), " "), " field ")][1]');
  const lc = await stableBox(page.locator(leftControl));
  const rc = await stableBox(page.locator(rightControl));
  const ll = await stableBox(fieldOf(leftControl).locator('.field__label'));
  const rl = await stableBox(fieldOf(rightControl).locator('.field__label'));
  expect(lc && rc && ll && rl, `${state}: elements visible`).toBeTruthy();
  if (!lc || !rc || !ll || !rl) return;
  const sideBySide = Math.abs(lc.x - rc.x) > 20;
  if (sideBySide) {
    expect(Math.abs(lc.y - rc.y), `${state}: control tops ${leftControl}=${lc.y} ${rightControl}=${rc.y}`).toBeLessThanOrEqual(1);
    expect(Math.abs(ll.y - rl.y), `${state}: label tops`).toBeLessThanOrEqual(1);
  } else {
    // Stacked (mobile): the second field starts below the first field's control.
    expect(rl.y, `${state}: stacked without overlap`).toBeGreaterThan(lc.y + lc.height);
  }
  return sideBySide;
}

async function openAddTransaction(page) {
  await page.goto('/transactions');
  await page.locator('[data-add-transaction]:visible').first().click();
  await expect(page.locator('#tx-modal')).toBeVisible();
  return page.locator('#tx-modal');
}

test('transaction form: Category and Date stay aligned in every error state', async ({ page }) => {
  await signUp(page, { currency: 'EUR' });
  const row = ['#tf-category', '#tf-date'];

  let d = await openAddTransaction(page);
  const sideBySide = await expectRowAligned(page, ...row, 'no errors');
  expect(sideBySide).toBe((page.viewportSize()?.width ?? 0) >= 768); // two columns from tablet up

  // Category only (client-side validation).
  await d.locator('#tf-amount').fill('12');
  await d.locator('#tf-save').click();
  await expect(d.locator('#tf-category')).toHaveAttribute('aria-invalid', 'true');
  await expect(d.locator('#tf-date')).not.toHaveAttribute('aria-invalid', 'true');
  await expectRowAligned(page, ...row, 'category error');

  // Date only.
  await d.locator('#tf-category').selectOption({ label: 'Groceries' });
  await d.locator('#tf-date').fill('');
  await d.locator('#tf-save').click();
  await expect(d.locator('#tf-date')).toHaveAttribute('aria-invalid', 'true');
  await expect(d.locator('#tf-category')).not.toHaveAttribute('aria-invalid', 'true');
  await expectRowAligned(page, ...row, 'date error');

  // Both.
  await d.locator('#tf-category').selectOption({ index: 0 });
  await d.locator('#tf-save').click();
  await expect(d.locator('#tf-category')).toHaveAttribute('aria-invalid', 'true');
  await expect(d.locator('#tf-date')).toHaveAttribute('aria-invalid', 'true');
  await expectRowAligned(page, ...row, 'both errors');

  // Server-side errors (422 re-render): bypass client validation.
  await page.evaluate(() => document.querySelector('#tx-form, #tx-modal form')?.removeAttribute('data-validate'));
  await d.locator('#tf-save').click();
  await expect(d.locator('.field__error', { hasText: 'Choose a category' })).toBeVisible();
  await expectRowAligned(page, ...row, 'server errors');
});

test('custom date range: From and To stay aligned with an error under To', async ({ page }) => {
  await startDemo(page); // the filter bar is shown once there are transactions
  await page.goto('/transactions');
  await page.locator('.period-filter .select-trigger').click();
  await page.getByRole('menuitemradio', { name: /Custom range/ }).click();
  const pop = page.locator('#tx-period-range');
  await expect(pop).toBeVisible();
  await expectRowAligned(page, '#tx-period-from', '#tx-period-to', 'no error');
  await page.fill('#tx-period-from', '2026-11-10');
  await page.fill('#tx-period-to', '2026-11-01');
  await pop.getByRole('button', { name: 'Apply' }).click();
  await expect(page.locator('#tx-period-to')).toHaveAttribute('aria-invalid', 'true');
  await expectRowAligned(page, '#tx-period-from', '#tx-period-to', 'client error');

  // Server-side validation of the same rule (dashboard filter).
  await page.goto('/dashboard?period=custom&from=2026-11-10&to=2026-11-01');
  await expect(page.locator('#dash-period-range')).toBeVisible();
  await expect(page.locator('#dash-period-to')).toHaveAttribute('aria-invalid', 'true');
  await expectRowAligned(page, '#dash-period-from', '#dash-period-to', 'server error');
});

test('budget form: Monthly limit and Month stay aligned with a limit error', async ({ page }) => {
  await signUp(page, { currency: 'USD' });
  await page.goto('/budgets');
  await page.locator('#set-budget-btn').click();
  const d = page.locator('#budget-form');
  await expect(d).toBeVisible();
  await expectRowAligned(page, '#budget-form .input-group--amount', '#bf-month', 'no error');
  await d.locator('#bf-limit').fill('0');
  await d.locator('#bf-submit').click();
  await expect(d.locator('#bf-limit')).toHaveAttribute('aria-invalid', 'true');
  await expectRowAligned(page, '#budget-form .input-group--amount', '#bf-month', 'limit error');
});
