// Optional UI test: npm install --no-save playwright; npx playwright install chromium
// Run via BOOKMARK_BROWSER_TEST=1 python3 tests/bookmarks_http_test.py.
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
(async () => {
    const browser = await chromium.launch({ headless: true, args: ['--no-sandbox'] });
    try {
        const page = await browser.newPage({ viewport: { width: 1280, height: 1000 } });
        const errors = [];
        page.on('pageerror', e => errors.push(e.message));
        // The fixture contains no movie files: supply media metadata, exercise real UI/API.
        await page.addInitScript(() => {
            const positions = new WeakMap();
            Object.defineProperty(HTMLMediaElement.prototype, 'duration', { get: () => 120 });
            Object.defineProperty(HTMLMediaElement.prototype, 'currentTime', {
                get() { return positions.get(this) || 0; }, set(v) { positions.set(this, v); },
            });
        });
        const url = process.env.BOOKMARK_TEST_URL + '/watch.php?id=1';
        await page.goto(url);
        await page.evaluate(() => document.getElementById('video').dispatchEvent(new Event('loadedmetadata')));
        await page.waitForFunction(() => !document.getElementById('bookmarkAdd').disabled);
        assert.equal(await page.locator('#bookmarkList img').count(), 0);
        assert.ok(await page.locator('#bookmarkList').textContent().then(t => t.includes('<img src=x onerror=alert(1)>')));
        await page.evaluate(() => { document.getElementById('video').currentTime = 23; });
        await page.locator('#bookmarkName').fill('Important explanation');
        await page.locator('#bookmarkAdd').click();
        await page.waitForFunction(() => document.getElementById('bookmarkStatus').textContent === 'Saved moment at 0:23.');
        await page.reload();
        await page.evaluate(() => document.getElementById('video').dispatchEvent(new Event('loadedmetadata')));
        const row = page.locator('.bookmark-item').filter({ hasText: 'Important explanation' });
        await row.locator('.bookmark-jump').waitFor();
        await row.locator('.bookmark-jump').click();
        assert.equal(await page.evaluate(() => document.getElementById('video').currentTime), 23);
        assert.equal(await page.locator('#resumeToast').evaluate(el => el.classList.contains('show')), false);
        await row.locator('.bookmark-actions summary').click();
        await row.getByRole('button', { name: 'Rename Important explanation', exact: true }).click();
        await row.locator('input').fill('Review this derivation');
        await row.getByRole('button', { name: 'Save', exact: true }).click();
        await page.waitForFunction(() => document.getElementById('bookmarkStatus').textContent === 'Bookmark renamed.');
        await page.setViewportSize({ width: 390, height: 844 });
        await page.locator('#bookmarks').scrollIntoViewIfNeeded();
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
        if (process.env.BOOKMARK_SCREENSHOT) await page.locator('#bookmarks').screenshot({ path: process.env.BOOKMARK_SCREENSHOT });
        page.once('dialog', dialog => dialog.accept());
        await page.locator('.bookmark-item').filter({hasText:'Review this derivation'}).locator('.bookmark-actions summary').click();
        await page.getByRole('button', { name: 'Delete Review this derivation', exact: true }).click();
        await page.waitForFunction(() => document.getElementById('bookmarkStatus').textContent === 'Bookmark deleted.');
        assert.equal(await page.getByRole('button', { name: 'Delete Review this derivation', exact: true }).count(), 0);
        assert.deepEqual(errors, []);
        console.log('PASS: UI create/reload/jump/rename/delete, safe text, resume dismissal, mobile width.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
