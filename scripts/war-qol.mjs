// The command desk on /reforger, driven like a player: keys pick a squad, a right click gives
// the order at once, Shift + right click chains one, HQ fire is aimed from the palette, and the
// phone layout. Saves screenshots and prints every POST and console error.
//   node scripts/reforger/war-qol.mjs [base-url] [out-dir]
import { chromium } from '@playwright/test';

const base = process.argv[2] ?? 'http://127.0.0.1:8000';
const out = process.argv[3] ?? '/tmp/war-shots';
const browser = await chromium.launch();
const problems = [];
const posts = [];

async function open(viewport, touch = false) {
    const page = await browser.newPage({ viewport, hasTouch: touch, isMobile: touch, deviceScaleFactor: touch ? 2 : 1 });
    page.on('pageerror', (e) => problems.push(`pageerror: ${e}`));
    page.on('console', (m) => m.type() === 'error' && problems.push(`console.error: ${m.text().slice(0, 200)}`));
    page.on(
        'request',
        (r) => r.method() === 'POST' && r.url().includes('/reforger/war/') && posts.push(`${r.url().split('/reforger/war/')[1]} ${r.postData()}`),
    );
    await page.goto(`${base}/login`);
    await page.fill('input#username', 'test@example.com');
    await page.fill('input[type="password"]', 'password');
    await Promise.all([page.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 30000 }), page.click('button[type="submit"]')]);
    await page.goto(`${base}/reforger`, { waitUntil: 'load' });
    await page.waitForTimeout(3500);

    return page;
}

const page = await open({ width: 1680, height: 1050 });
await page.screenshot({ path: `${out}/desk.png` });
const panel = page.getByTestId('command-panel');
const map = page.locator('.maplibregl-map').first();
const box = await map.boundingBox();
const at = { x: box.x + box.width * 0.55, y: box.y + box.height * 0.4 };

await page.keyboard.press('1');
await page.waitForTimeout(900);

// Nothing on the map sends: a right click does nothing, a click marks, the button sends.
await panel.getByRole('button', { name: 'Move', exact: true }).click();
const before = posts.length;
await page.mouse.click(at.x, at.y, { button: 'right' });
await page.mouse.click(at.x, at.y);
await page.waitForTimeout(500);
const mapSent = posts.length !== before;
await panel.getByRole('button', { name: /^Send / }).click();
await page.waitForTimeout(1500);
// Then: a defend chained behind the move.
await panel.getByRole('button', { name: 'Defend', exact: true }).click();
await page.mouse.click(at.x + 50, at.y + 30);
await page.waitForTimeout(300);
await panel.getByRole('button', { name: /^Then / }).click();
await page.waitForTimeout(1500);
await panel.screenshot({ path: `${out}/panel.png` });
await map.screenshot({ path: `${out}/map-chain.png` });

// HQ fire from the palette: aim with a click, the danger ring follows; Esc stops aiming.
await panel.getByRole('radio', { name: 'HQ fire' }).click();
await panel.getByRole('button', { name: 'Mortar barrage', exact: true }).click();
await page.mouse.move(at.x - 100, at.y + 60);
await page.mouse.click(at.x - 100, at.y + 60);
await page.waitForTimeout(400);
const fireReady = await panel.getByRole('button', { name: /^Call / }).isEnabled();
await map.screenshot({ path: `${out}/map-fire.png` });
await panel.screenshot({ path: `${out}/panel-fire.png` });
await page.keyboard.press('Escape');
await panel.getByRole('radio', { name: 'Squad orders' }).click();

// Unfold the squad: its men.
const unfold = panel.getByRole('button', { name: /^Unfold / }).first();
if (await unfold.count()) {
    await unfold.click();
    await page.waitForTimeout(300);
}
await panel.screenshot({ path: `${out}/panel-men.png` });

// Phone, with a finger: HQ fire with no squad involved, a tap on the map only marks, the
// squad's settings fold behind one button.
const phone = await open({ width: 390, height: 844 }, true);
await phone.screenshot({ path: `${out}/phone.png` });
const dock = phone.getByTestId('command-panel');
const pmap = phone.locator('.maplibregl-map').first();
const pbox = await pmap.boundingBox();
await dock.getByRole('button', { name: 'Squad', exact: true }).tap();
await phone.waitForTimeout(300);
await phone.screenshot({ path: `${out}/phone-squad.png` });
await dock.getByRole('radio', { name: 'HQ fire' }).tap();
await dock.getByRole('button', { name: 'Illumination', exact: true }).tap();
const postsBefore = posts.length;
await phone.touchscreen.tap(pbox.x + pbox.width * 0.7, pbox.y + pbox.height * 0.2);
await phone.waitForTimeout(500);
const phoneFire = { tapPosted: posts.length !== postsBefore, call: await dock.getByRole('button', { name: /^Call / }).isEnabled() };
await phone.screenshot({ path: `${out}/phone-fire.png` });
await phone.getByRole('tab', { name: 'Squads' }).tap();
await phone.waitForTimeout(300);
await phone.screenshot({ path: `${out}/phone-squads.png`, fullPage: true });

// Men: a role tap only picks it; the purchase is the second button.
await dock.getByRole('radio', { name: 'Squad orders' }).tap();
await dock.getByRole('button', { name: 'Squad', exact: true }).tap();
await dock.getByRole('button', { name: 'Men', exact: true }).tap();
const beforeMen = posts.length;
await phone
    .getByRole('button', { name: /^Rifleman/ })
    .first()
    .tap();
await phone.waitForTimeout(400);
const menPostedOnPick = posts.length !== beforeMen;
await phone.screenshot({ path: `${out}/phone-men.png` });

console.log(JSON.stringify({ mapSent, fireReady, phoneFire, menPostedOnPick, posts, problems }, null, 2));
await browser.close();
