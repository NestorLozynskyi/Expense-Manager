// @ts-check
const { test, expect } = require('@playwright/test');
const { signUp } = require('./helpers');

/** Waits until HTMX finished swapping/settling (new content is wired up before the next action). */
async function htmxIdle(page) {
  await page.waitForFunction(() => !document.querySelector('.htmx-request, .htmx-swapping, .htmx-settling'));
}

/** Opens the global "Add transaction" dialog (sidebar / top bar / FAB — whichever is visible). */
async function openAddTransaction(page) {
  await page.locator('[data-add-transaction]:visible').first().click();
  const dialog = page.locator('#tx-modal');
  await expect(dialog).toBeVisible();
  return dialog;
}

async function addTransaction(page, { type = 'expense', amount, category, note }) {
  const dialog = await openAddTransaction(page);
  if (type === 'income') await dialog.locator('label.segmented__item--income').click();
  await dialog.locator('#tf-amount').fill(amount);
  await dialog.locator('#tf-category').selectOption({ label: category });
  if (note) await dialog.locator('#tf-note').fill(note);
  await dialog.locator('#tf-save').click();
  await expect(dialog).toBeHidden();
  await expect(page.locator('.toast').last()).toContainText('Transaction added');
}

/** Row action on the transactions table: inline buttons on desktop/tablet, ⋯ menu on mobile. */
async function rowAction(page, note, action) {
  const row = page.locator('#tx-results tr', { hasText: note });
  const inline = row.locator(`.tx-actions a[aria-label^="${action} "]`);
  if (await inline.isVisible()) {
    await inline.click();
  } else {
    await row.getByRole('button', { name: /^Actions for / }).click();
    await row.getByRole('menuitem', { name: action }).click();
  }
}

async function setBudget(page, { category, limit }) {
  await page.locator('#set-budget-btn').click();
  const dialog = page.locator('#budget-form');
  await expect(dialog).toBeVisible();
  if (category) {
    await dialog.locator('label.segmented__item', { has: page.locator('input[name=scope][value=category]') }).click();
    await dialog.locator('#bf-category').selectOption({ label: category });
  } else {
    await dialog.locator('label.segmented__item', { has: page.locator('input[name=scope][value=overall]') }).click();
  }
  await dialog.locator('#bf-limit').fill(limit);
  await dialog.locator('#bf-submit').click();
  await expect(dialog).toBeHidden();
}

test('account → transactions → filters → budgets (ok / near / over) → dashboard', async ({ page }) => {
  await signUp(page, { currency: 'USD' });

  // Empty dashboard of a new account.
  await expect(page.locator('#dash-welcome')).toBeVisible();

  // Create transactions through the dialog (validation first).
  let dialog = await openAddTransaction(page);
  await dialog.locator('#tf-save').click();
  await expect(dialog.locator('#tf-errors')).toContainText('Enter an amount greater than 0');
  await expect(dialog.locator('#tf-errors')).toContainText('Choose a category');
  await dialog.locator('#tf-amount').fill('1.234');
  await dialog.locator('#tf-category').selectOption({ label: 'Dining out' });
  await dialog.locator('#tf-save').click();
  await expect(dialog.locator('#tf-amount-error, .field__error').first()).toContainText('Use no more than 2 decimal places');
  await dialog.getByRole('button', { name: 'Cancel' }).click();
  await expect(dialog).toBeHidden();

  await addTransaction(page, { type: 'income', amount: '1000', category: 'Salary', note: 'Salary — November' });
  await addTransaction(page, { amount: '25', category: 'Dining out', note: 'Dinner' });
  await addTransaction(page, { amount: '35', category: 'Transport', note: 'Transit pass' });
  await addTransaction(page, { amount: '30', category: 'Groceries', note: 'Groceries run' });
  await addTransaction(page, { amount: '9.99', category: 'Shopping', note: 'Mistake' });

  // Edit and delete on the transactions list.
  await page.goto('/transactions');
  await expect(page.locator('#tx-results tr[data-tx]')).toHaveCount(5);
  await rowAction(page, 'Dinner', 'Edit');
  dialog = page.locator('#tx-modal');
  await expect(dialog.locator('#tf-amount')).toHaveValue('25.00');
  await dialog.locator('#tf-note').fill('Dinner with Sam');
  await dialog.locator('#tf-save').click();
  await expect(dialog).toBeHidden();
  await expect(page.locator('#tx-results')).toContainText('Dinner with Sam');

  await rowAction(page, 'Mistake', 'Delete');
  const confirm = page.locator('#confirm-delete');
  await expect(confirm).toBeVisible();
  await confirm.locator('#cd-confirm').click();
  await expect(confirm).toBeHidden();
  await expect(page.locator('.toast').last()).toContainText('Transaction deleted');
  await expect(page.locator('#tx-results tr[data-tx]')).toHaveCount(4);

  // Filters + totals of the filtered set.
  await page.locator('#tx-filter-form label.segmented__item--expense').click();
  await expect(page).toHaveURL(/type=expense/);
  await expect(page.locator('#tx-results tr[data-tx]')).toHaveCount(3);
  await expect(page.locator('#tx-totals')).toContainText('−$90.00');
  await htmxIdle(page);
  await page.locator('#f-cat').selectOption({ label: 'Dining out' });
  await expect(page.locator('#tx-results tr[data-tx]')).toHaveCount(1);
  await htmxIdle(page);
  await page.locator('#clear-filters').first().click();
  await expect(page.locator('#tx-results tr[data-tx]')).toHaveCount(4);

  // Budgets in all three states + over-budget banner.
  await page.goto('/budgets');
  await setBudget(page, { limit: '500' });
  await setBudget(page, { category: 'Dining out', limit: '20' });   // 25 / 20  → over
  await setBudget(page, { category: 'Transport', limit: '40' });    // 35 / 40  → near limit
  await setBudget(page, { category: 'Groceries', limit: '100' });   // 30 / 100 → on track
  const dining = page.locator('article.budget[data-category]', { hasText: 'Dining out' });
  await expect(dining).toHaveClass(/budget--over/);
  await expect(dining).toContainText('Over budget');
  await expect(dining).toContainText('Over by $5.00');
  await expect(page.locator('article.budget', { hasText: 'Transport' })).toContainText('Near limit');
  await expect(page.locator('article.budget', { hasText: 'Groceries' })).toContainText('On track');
  await expect(page.locator('.alert').first()).toContainText(/over/i);

  // Dashboard: totals, charts and budgets reflect the data.
  await page.goto('/dashboard');
  await expect(page.locator('main')).toContainText('+$1,000.00');
  await expect(page.locator('main')).toContainText('−$90.00');
  await expect(page.locator('#donut-canvas')).toBeVisible();
  await expect(page.locator('#bar-canvas')).toBeVisible();
  await expect(page.locator('#dash-budgets')).toContainText('Over budget');

  // Period filter: last month has no data.
  await page.locator('.period-filter .select-trigger').click();
  await page.getByRole('menuitemradio', { name: /Last month/ }).click();
  await expect(page).toHaveURL(/period=last-month/);
});

test('dashboard quick add records an expense dated today', async ({ page }) => {
  await signUp(page, { currency: 'EUR' });
  const qa = page.locator('#dash-quick');
  await qa.locator('#qa-amount').fill('12.5');
  await qa.locator('#qa-category').selectOption({ label: 'Groceries' });
  await qa.locator('button[type=submit]').click();
  await expect(page.locator('.toast').last()).toBeVisible();
  await expect(page.locator('#dash-recent')).toContainText('−€12.50');
});
