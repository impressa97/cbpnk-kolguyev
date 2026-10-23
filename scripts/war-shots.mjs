// Local look at /reforger: sign in as the dev user and save desktop and phone screenshots,
// with every console error and warning, failed request, and the live Reverb feed counted.
//   node scripts/reforger/war-shots.mjs [base-url] [out-dir]
//   WAIT_PUSH=ms  extra wait for live desk pushes before closing in on the squad
import { chromium } from '@playwright/test';

const base = process.argv[2] ?? 'http://127.0.0.1:8000';
const out = process.argv[3] ?? '/tmp/war-shots';
const browser = await chromium.launch();
const problems = [];
const live = { light: null, frames: 0, desks: 0, bytes: 0 };

for (const [name, viewport] of [
    ['desktop', { width: 1680, height: 1050 }],
    ['phone', { width: 390, height: 844 }],
]) {
    const page = await browser.newPage({ viewport, colorScheme: 'light', deviceScaleFactor: name === 'phone' ? 2 : 1 });
    page.on('pageerror', (e) => problems.push(`${name} pageerror: ${e}`));
    page.on('console', (m) => ['error', 'warning'].includes(m.type()) && problems.push(`${name} console.${m.type()}: ${m.text().slice(0, 300)}`));
    // Tiles cancelled mid-zoom or at page close are not failures.
    page.on(
        'requestfailed',
        (r) =>
            (r.failure()?.errorText !== 'net::ERR_ABORTED' || r.method() !== 'GET') &&
            problems.push(`${name} failed ${r.method()} ${r.url()} ${r.failure()?.errorText}`),
    );
    page.on('response', (r) => r.status() >= 400 && problems.push(`${name} HTTP ${r.status()} ${r.url()}`));
    page.on('websocket', (ws) => {
        if (!ws.url().includes('/app/')) {
            return;
        }
        ws.on('framereceived', ({ payload }) => {
            live.frames++;
            if (String(payload).includes('war.desk')) {
                live.desks++;
                live.bytes = Math.max(live.bytes, String(payload).length);
            }
        });
    });
    page.on('request', (r) => {
        const url = new URL(r.url());
        if (!['127.0.0.1', 'localhost'].includes(url.hostname) && !['data:', 'blob:'].includes(url.protocol)) {
            problems.push(`${name} external request ${r.url()}`);
        }
    });

    await page.goto(`${base}/login`);
    await page.fill('input#username', 'test@example.com');
    await page.fill('input[type="password"]', 'password');
    await Promise.all([page.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 30000 }), page.click('button[type="submit"]')]);
    await page.goto(`${base}/reforger`, { waitUntil: 'load' });
    await page.waitForTimeout(3000);
    await page.screenshot({ path: `${out}/${name}-full.png`, fullPage: true });
    const map = page.locator('.maplibregl-map').first();
    if (await map.count()) {
        await map.screenshot({ path: `${out}/${name}-map.png` });
        if (name === 'desktop') {
            // The map light: what the feed is (Live / No feed / Linking / Link lost).
            live.light = await page
                .locator('[title*="game server"], [title*="live feed"]')
                .first()
                .textContent()
                .catch(() => null);
            await page.waitForTimeout(Number(process.env.WAIT_PUSH ?? 0));
            // Close in on the picked squad: centre button, then a little more.
            await page
                .getByRole('button', { name: 'Centre on the squad' })
                .click()
                .catch(() => {});
            await page.waitForTimeout(900);
            const box = await map.boundingBox();
            await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
            for (let i = 0; i < 6; i++) {
                await page.mouse.wheel(0, -300);
                await page.waitForTimeout(120);
            }
            await page.waitForTimeout(2500);
            await map.screenshot({ path: `${out}/${name}-zoom.png` });
            // The same close-up from above (the terrain's own colour map), then back to the map.
            const ground = async (label) => {
                await page.getByRole('button', { name: 'Map layers' }).click();
                await page.getByRole('menuitemradio', { name: label }).click();
                await page.keyboard.press('Escape');
            };
            await ground('From above').catch(() => {});
            await page.waitForTimeout(2500);
            await map.screenshot({ path: `${out}/${name}-above.png` });
            await ground('Map').catch(() => {});
            const army = page.locator('[data-slot="card"]').filter({ hasText: 'Your army' }).first();
            if (await army.count()) {
                await army.screenshot({ path: `${out}/${name}-army.png` });
            }

            // The order console on the map: a patrol's two ends, then a move sent to the game.
            const dock = page.getByTestId('command-panel');
            if (await dock.count()) {
                await dock.screenshot({ path: `${out}/${name}-dock.png` });
                const cx = box.x + box.width / 2;
                const cy = box.y + box.height / 3;
                await dock.getByRole('radio', { name: 'Patrol', exact: true }).click();
                await page.mouse.click(cx - 120, cy);
                await page.mouse.click(cx + 120, cy - 60);
                await page.waitForTimeout(400);
                await map.screenshot({ path: `${out}/${name}-patrol.png` });

                await dock.getByRole('radio', { name: 'Move', exact: true }).click();
                await page.mouse.click(cx + 90, cy + 40);
                await page.waitForTimeout(300);
                await dock.getByRole('button', { name: /^Send / }).click();
                // A bridge tick or two: the game picks the order up and reports it under way.
                await page.waitForTimeout(Number(process.env.WAIT_ORDER ?? 7000));
                await map.screenshot({ path: `${out}/${name}-sent.png` });
                live.order = await dock
                    .locator('[title="What the game has the squad doing"]')
                    .textContent()
                    .catch(() => null);

                // Aiming a raid rings every harbour.
                await dock.getByRole('radio', { name: 'Raid', exact: true }).click();
                await page.getByRole('button', { name: 'Whole island' }).click();
                await page.waitForTimeout(900);
                await map.screenshot({ path: `${out}/${name}-raid.png` });
                await dock.getByRole('radio', { name: 'Move', exact: true }).click();
            }

            // Pick the first soldier in the roster: the console shows his own orders and his drill.
            await page
                .getByRole('button', { name: 'Unfold all squads' })
                .click()
                .catch(() => {});
            await page.waitForTimeout(400);
            const man = page.locator('[title*="stance, pace, the medic"]').first();
            if (await man.count()) {
                await man.click();
                await page.waitForTimeout(800);
                await dock.screenshot({ path: `${out}/${name}-soldier.png` });
            }
        }
    }
    await page.close();
}

console.log(JSON.stringify({ live, problems: [...new Set(problems)].slice(0, 40) }, null, 2));
await browser.close();
