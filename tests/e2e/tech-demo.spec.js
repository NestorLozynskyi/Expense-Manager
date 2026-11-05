// @ts-check
const { test, expect } = require('@playwright/test');

const SCREENSHOT_DIR = process.env.E2E_SCREENSHOT_DIR || 'artifacts/screenshots';

test('stack demo page: SQLite data, HTMX fragment update, Alpine, Chart.js, Tailwind', async ({ page }) => {
  const fullPageLoads = [];
  page.on('load', () => fullPageLoads.push(page.url()));

  await page.goto('/');
  await expect(page).toHaveURL(/\/tech\/demo$/);

  // Data from SQLite through Doctrine.
  await expect(page.getByTestId('sqlite-version')).toHaveText(/^3\.\d+\.\d+$/);
  const countBefore = Number(await page.getByTestId('ping-count').innerText());

  // Tailwind compiled CSS is applied (text-2xl => 24px, font-bold => 700).
  const heading = page.getByTestId('tailwind-probe');
  await expect(heading).toHaveCSS('font-size', '24px');
  await expect(heading).toHaveCSS('font-weight', '700');

  // Chart.js rendered a chart on the canvas.
  const chart = page.getByTestId('ping-chart');
  await expect(chart).toHaveAttribute('data-chart-ready', 'true');
  const canvasHasPixels = await chart.evaluate((canvas) => {
    const c = /** @type {HTMLCanvasElement} */ (canvas);
    const data = c.getContext('2d')?.getImageData(0, 0, c.width, c.height).data ?? [];
    return c.width > 0 && Array.prototype.some.call(data, (v, i) => i % 4 === 3 && v !== 0);
  });
  expect(canvasHasPixels).toBe(true);

  // Alpine.js component reacts to clicks.
  await expect(page.getByTestId('alpine-panel')).toBeHidden();
  await page.getByTestId('alpine-toggle').click();
  await expect(page.getByTestId('alpine-panel')).toBeVisible();
  await expect(page.getByTestId('alpine-clicks')).toHaveText('1');

  await page.screenshot({ path: `${SCREENSHOT_DIR}/tech-demo-before-htmx.png`, fullPage: true });

  // HTMX: POST stores a row and only the #ping-panel fragment is swapped.
  const loadsBefore = fullPageLoads.length;
  const renderedAtBefore = await page.getByTestId('ping-rendered-at').innerText();
  await page.evaluate(() => { /** @type {any} */ (window).__noReloadMarker = 'still-here'; });

  const [response] = await Promise.all([
    page.waitForResponse((r) => r.url().endsWith('/tech/demo/ping') && r.request().method() === 'POST'),
    page.getByTestId('ping-button').click(),
  ]);
  expect(response.status()).toBe(200);
  expect(response.request().headers()['hx-request']).toBe('true');

  await expect(page.getByTestId('ping-count')).toHaveText(String(countBefore + 1));
  await expect(page.getByTestId('ping-rendered-at')).not.toHaveText(renderedAtBefore);
  await expect(page.getByTestId('ping-list')).toContainText('#');

  // No full page reload: JS state and Alpine state survived, no new "load" event.
  expect(await page.evaluate(() => /** @type {any} */ (window).__noReloadMarker)).toBe('still-here');
  expect(fullPageLoads.length).toBe(loadsBefore);
  await expect(page.getByTestId('alpine-clicks')).toHaveText('1');

  // Chart updated from the HTMX fragment data (the new ping is in the current minute).
  await expect(chart).toHaveAttribute('data-chart-total', /^[1-9]\d*$/);

  await page.screenshot({ path: `${SCREENSHOT_DIR}/tech-demo-after-htmx.png`, fullPage: true });
});

test('protected route is not available to an anonymous visitor', async ({ request }) => {
  const response = await request.get('/app/tech/secure-check');
  expect(response.status()).toBe(401);
});
