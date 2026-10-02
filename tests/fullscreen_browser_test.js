// Requires Playwright/Chromium and ffmpeg. Exercises real media and Fullscreen API.
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { execFileSync } = require('node:child_process');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
(async () => {
    const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'qplayer-fullscreen-'));
    let browser;
    try {
        for (const [name, size] of [['small', '320x180'], ['portrait', '180x320']]) {
            execFileSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', `testsrc2=size=${size}:rate=10`, '-t', '8', '-c:v', 'libvpx', '-b:v', '150k', '-an', path.join(temp, name + '.webm')]);
        }
        browser = await chromium.launch({ headless: true, args: ['--no-sandbox'] });
        const page = await browser.newPage({ viewport: { width: 1280, height: 800 } });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        let html = fs.readFileSync(path.join(root, 'watch.php'), 'utf8')
            .replace(/<\?(?:php|=)[\s\S]*?\?>/g, '')
            .replace(/<script>[\s\S]*?<\/script>/g, '<script>window.__videoId=1;window.__lastPosition=0;</script>')
            .replace(/src="stream.php[^"]*"/, 'src="small.webm" muted loop')
            .replace('<script src="assets/js/bookmarks.js"></script>', '');
        await page.route('**/*', route => {
            const name = new URL(route.request().url()).pathname.slice(1);
            if (name === 'watch.php') return route.fulfill({ contentType: 'text/html', body: html });
            if (name === 'save_progress.php') return route.fulfill({ contentType: 'application/json', body: '{"success":true}' });
            if (['small.webm', 'portrait.webm'].includes(name)) return route.fulfill({ contentType: 'video/webm', body: fs.readFileSync(path.join(temp, name)) });
            if (['assets/css/style.css', 'assets/js/player.js', 'assets/js/fullscreen.js'].includes(name)) return route.fulfill({ contentType: name.endsWith('.css') ? 'text/css' : 'application/javascript', body: fs.readFileSync(path.join(root, name)) });
            return route.fulfill({ status: 404, body: '' });
        });
        await page.goto('http://player.test/watch.php');
        await page.waitForFunction(() => document.getElementById('video').readyState >= 2);
        assert.equal(await page.locator('video').evaluate(v => v.videoWidth), 320);
        await page.locator('#playPause').click();
        await page.locator('#fullscreenBtn').click();
        await page.waitForFunction(() => document.fullscreenElement?.id === 'videoShell');
        await page.mouse.move(100, 100);
        const fits = () => page.locator('video').evaluate(v => {
            const rect = v.getBoundingClientRect();
            return Math.abs(rect.width - innerWidth) <= 1 && Math.abs(rect.height - innerHeight) <= 1 && getComputedStyle(v).objectFit === 'contain';
        });
        assert.ok(await fits(), 'Low-resolution video element should fill the fullscreen viewport.');
        await page.waitForFunction(() => document.getElementById('videoShell').classList.contains('controls-hidden'));
        await page.waitForFunction(() => getComputedStyle(document.querySelector('.controls')).visibility === 'hidden');
        await page.mouse.move(200, 150);
        assert.equal(await page.locator('#videoShell').evaluate(el => el.classList.contains('controls-hidden')), false);
        await page.keyboard.press('k');
        await page.waitForTimeout(2800);
        assert.equal(await page.locator('.controls').evaluate(el => getComputedStyle(el).visibility), 'visible');
        await page.keyboard.press('k');
        await page.locator('#shortcutsBtn').click();
        await page.mouse.move(100, 100);
        await page.waitForTimeout(2800);
        assert.equal(await page.locator('.controls').evaluate(el => getComputedStyle(el).visibility), 'visible');
        await page.keyboard.press('Escape');
        // Escape may also leave fullscreen depending on browser; re-enter if needed.
        if (!await page.evaluate(() => !!document.fullscreenElement)) await page.locator('#fullscreenBtn').click();
        await page.mouse.move(100, 100);
        await page.locator('#playbackSpeed').focus();
        await page.keyboard.press('Tab');
        await page.waitForTimeout(2800);
        assert.equal(await page.locator('.controls').evaluate(el => getComputedStyle(el).visibility), 'visible');
        await page.evaluate(() => { document.activeElement.blur(); });
        await page.mouse.move(200, 200);
        await page.waitForFunction(() => document.getElementById('videoShell').classList.contains('controls-hidden'));
        // Simulate first touchscreen tap and verify that it reveals without pausing.
        await page.locator('video').dispatchEvent('pointerdown', { pointerType: 'touch', pointerId: 10 });
        await page.locator('video').dispatchEvent('pointerup', { pointerType: 'touch', pointerId: 10 });
        await page.locator('video').dispatchEvent('click');
        assert.equal(await page.locator('video').evaluate(v => v.paused), false);
        assert.equal(await page.locator('#videoShell').evaluate(el => el.classList.contains('controls-hidden')), false);
        await page.evaluate(async () => {
            const video = document.getElementById('video');
            video.src = 'portrait.webm';
            await video.play();
        });
        await page.waitForFunction(() => document.getElementById('video').videoWidth === 180);
        assert.ok(await fits(), 'Portrait video should use the same fullscreen viewport with contain.');
        await page.evaluate(() => document.exitFullscreen());
        await page.waitForFunction(() => !document.getElementById('videoShell').classList.contains('is-fullscreen'));
        assert.equal(await page.locator('.controls').evaluate(el => getComputedStyle(el).visibility), 'visible');
        assert.ok(await page.locator('video').evaluate(v => v.getBoundingClientRect().height <= innerHeight * .74 + 1));
        assert.deepEqual(errors, []);
        console.log('PASS: real low-res/portrait video sizing, idle hide, pointer reveal, pause, help, keyboard focus, touch reveal, fullscreen exit.');
    } finally {
        if (browser) await browser.close();
        fs.rmSync(temp, { recursive: true, force: true });
    }
})().catch(error => { console.error(error); process.exit(1); });
