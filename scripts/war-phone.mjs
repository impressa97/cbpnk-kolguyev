// /reforger on an iPhone 13 mini (375 x 629 visible in Safari): first screen and full page,
// plus anything wider than the screen (horizontal overflow) with the offending elements.
//   node scripts/reforger/war-phone.mjs [base-url] [out-dir]
import { chromium, devices } from '@playwright/test';

const base = process.argv[2] ?? 'http://127.0.0.1:8000';
const out = process.argv[3] ?? '/tmp/war-shots';
const browser = await chromium.launch();
const context = await browser.newContext({ ...devices['iPhone 13 Mini'], viewport: { width: 375, height: 629 } });
const page = await context.newPage();
const problems = [];
page.on('pageerror', (e) => problems.push(`pageerror: ${e}`));
page.on('console', (m) => m.type() === 'error' && problems.push(`console.error: ${m.text().slice(0, 200)}`));

await page.goto(`${base}/login`);
await page.fill('input#username', 'test@example.com');
await page.fill('input[type="password"]', 'password');
await Promise.all([page.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 30000 }), page.click('button[type="submit"]')]);
await page.goto(`${base}/reforger`, { waitUntil: 'load' });
await page.waitForTimeout(3500);
await page.screenshot({ path: `${out}/mini-first.png` });
// The page scrolls inside the layout, not the document: walk that container screen by screen.
const screens = await page.evaluate(() => {
    const scroller =
        [...document.querySelectorAll('*')].find(
            (el) => el.scrollHeight > el.clientHeight + 50 && ['auto', 'scroll'].includes(getComputedStyle(el).overflowY),
        ) ?? document.scrollingElement;
    scroller.setAttribute('data-shots-scroller', '1');

    return Math.min(8, Math.ceil(scroller.scrollHeight / scroller.clientHeight));
});
for (let i = 1; i < screens; i++) {
    await page.evaluate((n) => {
        const scroller = document.querySelector('[data-shots-scroller]');
        scroller.scrollTop = n * (scroller.clientHeight - 60);
    }, i);
    await page.waitForTimeout(400);
    await page.screenshot({ path: `${out}/mini-${i}.png` });
}

const layout = await page.evaluate(() => {
    const width = document.documentElement.clientWidth;
    const wide = [...document.querySelectorAll('body *')]
        .filter((el) => el.getBoundingClientRect().right > width + 1)
        .slice(0, 8)
        .map((el) => `${el.tagName.toLowerCase()}.${String(el.className).slice(0, 60)} right=${Math.round(el.getBoundingClientRect().right)}`);
    const map = document.querySelector('.maplibregl-map')?.getBoundingClientRect();
    const main = document.querySelector('main')?.getBoundingClientRect();

    return {
        viewport: width,
        scrollWidth: document.documentElement.scrollWidth,
        map: map && { left: Math.round(map.left), width: Math.round(map.width), top: Math.round(map.top), height: Math.round(map.height) },
        main: main && { left: Math.round(main.left), width: Math.round(main.width) },
        wide,
    };
});
console.log(JSON.stringify({ layout, problems }, null, 2));
await browser.close();
