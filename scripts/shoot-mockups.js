// Captures reference screenshots of the approved HTML mockups (all states, 3 widths).
const { chromium } = require('@playwright/test');
const path = require('path');
const D = process.argv[2];
const OUT = process.argv[3] || 'artifacts/mockups';
const SCREENS = {
  'auth': ['sign-in','sign-in-errors','sign-in-invalid','sign-up','sign-up-errors','sign-up-currency'],
  'dashboard': ['default','empty','loading','custom-range','error'],
  'transactions': ['default','filtered','no-results','empty','loading','delete-confirm','deleted-toast'],
  'transaction-form': ['create','create-income','edit','errors','saving','success'],
  'categories': ['default','income','create','rename','delete-empty','delete-reassign','empty'],
  'budgets': ['default','previous-month','set-budget','edit-budget','delete-budget','empty','loading'],
  'demo-ended': ['expired','expiring'],
};
const W = [[1440, 900], [834, 1112], [390, 844]];
(async () => {
  const browser = await chromium.launch();
  for (const [w, h] of W) {
    const ctx = await browser.newContext({ viewport: { width: w, height: h } });
    const page = await ctx.newPage();
    for (const [file, states] of Object.entries(SCREENS)) {
      for (const st of states) {
        await page.goto('file://' + path.join(D, 'screens', file + '.html') + '#' + st + '~bare');
        await page.waitForTimeout(500);
        await page.screenshot({ path: `${OUT}/${file}--${st}--${w}.png`, fullPage: true });
      }
    }
    await ctx.close();
  }
  await browser.close();
})();
