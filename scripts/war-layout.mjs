// The war desk's layout on /reforger, desktop and phone: the status line, the map with its
// console, and the squad manager under it. Saves screenshots, prints console errors.
//   node scripts/reforger/war-layout.mjs [base-url] [out-dir]
import { chromium } from '@playwright/test';

const base = process.argv[2] ?? 'http://127.0.0.1:8000';
const out = process.argv[3] ?? '/tmp/war-shots';
const browser = await chromium.launch();
const problems = [];

for (const [name, viewport, touch] of [
    ['desktop', { width: 1680, height: 1050 }, false],
    ['phone', { width: 390, height: 844 }, true],
]) {
    const page = await browser.newPage({ viewport, hasTouch: touch, isMobile: touch, deviceScaleFactor: touch ? 2 : 1 });
    page.on('pageerror', (e) => problems.push(`${name} pageerror: ${e}`));
    page.on('console', (m) => m.type() === 'error' && problems.push(`${name} console.error: ${m.text().slice(0, 200)}`));
    await page.goto(`${base}/login`);
    await page.fill('input#username', 'test@example.com');
    await page.fill('input[type="password"]', 'password');
    await Promise.all([page.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 30000 }), page.click('button[type="submit"]')]);
    await page.goto(`${base}/reforger`, { waitUntil: 'load' });
    await page.waitForTimeout(4000);
    await page.screenshot({ path: `${out}/${name}-top.png` });
    await page.getByTestId('squad-manager').scrollIntoViewIfNeeded();
    await page.waitForTimeout(400);
    await page.screenshot({ path: `${out}/${name}-below.png` });
    await page.close();
}

console.log(JSON.stringify({ problems }, null, 2));
await browser.close();
