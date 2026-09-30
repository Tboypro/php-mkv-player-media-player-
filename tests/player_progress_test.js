// Exercise the actual player handlers without a browser, database, or dependencies.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../assets/js/player.js'), 'utf8');

function player(lastPosition = 40, useBeacon = true) {
    const elements = new Map();
    const requests = [];
    const intervals = [];
    function element(id) {
        if (!elements.has(id)) {
            const classes = new Set();
            elements.set(id, {
                style: {}, handlers: {}, value: '',
                classList: {
                    add(value) { classes.add(value); },
                    remove(value) { classes.delete(value); },
                    contains(value) { return classes.has(value); },
                },
                addEventListener(event, handler) { this.handlers[event] = handler; },
                emit(event) { this.handlers[event]?.(); },
            });
        }
        return elements.get(id);
    }
    const video = element('video');
    Object.assign(video, {
        duration: NaN, currentTime: 0, paused: true, ended: false,
        play() { this.paused = false; this.emit('play'); return Promise.resolve(); },
        pause() { this.paused = true; this.emit('pause'); },
    });
    const windowHandlers = {};
    const record = data => {
        requests.push(Object.fromEntries(data.fields));
        return true;
    };
    vm.runInNewContext(source, {
        document: { getElementById: element, addEventListener() {}, activeElement: { tagName: 'BODY' } },
        window: { __videoId: 7, __lastPosition: lastPosition, addEventListener(event, fn) { windowHandlers[event] = fn; } },
        localStorage: { getItem() { return null; }, setItem() {} },
        setInterval(fn) { intervals.push(fn); },
        navigator: useBeacon ? { sendBeacon(url, data) { return record(data); } } : {},
        fetch(url, options) { record(options.body); return Promise.resolve({ ok: true }); },
        FormData: class { constructor() { this.fields = []; } append(key, value) { this.fields.push([key, value]); } },
    });
    return {
        video, requests, element,
        load(duration = 120) { video.duration = duration; video.emit('loadedmetadata'); },
        leave() { windowHandlers.beforeunload(); },
        tick() { intervals.forEach(fn => fn()); },
        click(id) { element(id).emit('click'); },
    };
}

// Opening and leaving, pausing, or autosaving before a choice must preserve history.
{
    const app = player();
    app.leave();
    app.load();
    assert.equal(app.element('resumeToast').classList.contains('show'), true);
    app.leave();
    app.video.pause();
    app.video.play();
    app.video.currentTime = 10;
    app.tick();
    assert.equal(app.requests.length, 0);
}
// Resume saves the existing position immediately, then normal progress is saved.
{
    const app = player();
    app.load();
    app.click('resumeYes');
    assert.equal(app.video.currentTime, 40);
    assert.equal(app.requests.at(-1).position, 40);
    app.video.currentTime = 47.9;
    app.tick();
    assert.equal(app.requests.at(-1).position, 47);
    app.video.pause();
    app.leave();
    assert.equal(app.requests.at(-1).position, 47);
}
// Start over explicitly resets even if playback/seek happened while the prompt was open.
{
    const app = player();
    app.load();
    app.video.currentTime = 12;
    app.click('resumeNo');
    assert.equal(app.video.currentTime, 0);
    assert.equal(app.requests.at(-1).position, 0);
    app.leave();
    assert.equal(app.requests.at(-1).position, 0);
}
// Videos without a resume prompt still save progress normally.
for (const savedPosition of [0, 3, 118]) {
    const app = player(savedPosition);
    app.load();
    app.video.currentTime = 15;
    app.video.pause();
    assert.equal(app.requests.at(-1).position, 15);
}
// Invalid metadata never produces a progress request.
for (const duration of [NaN, Infinity, 0]) {
    const app = player(0);
    app.load(duration);
    app.leave();
    assert.equal(app.requests.length, 0);
}
console.log('PASS: pending resume choice, resume, explicit restart, normal saving, invalid metadata.');

// Completion, a following pause, and unload all send zero; replay can save again.
for (const useBeacon of [true, false]) {
    const app = player(0, useBeacon);
    app.load();
    app.video.currentTime = 120;
    // Some completion events can occur before ended has been observed.
    app.video.pause();
    assert.equal(app.requests.at(-1).position, 0);
    app.video.ended = true;
    app.video.emit('ended');
    app.video.pause();
    app.leave();
    assert.equal(app.requests.length, 4);
    assert.ok(app.requests.every(request => request.position === 0));
    app.video.ended = false;
    app.video.currentTime = 8;
    app.video.play();
    app.tick();
    assert.equal(app.requests.at(-1).position, 8);
}
// Pausing before the end preserves the actual position, rather than prematurely resetting.
{
    const app = player(0);
    app.load();
    app.video.currentTime = 119.5;
    app.video.pause();
    assert.equal(app.requests.at(-1).position, 119);
}
// An unanswered resume prompt also blocks completion events from overwriting history.
{
    const app = player();
    app.load();
    app.video.currentTime = 120;
    app.video.ended = true;
    app.video.emit('ended');
    app.leave();
    assert.equal(app.requests.length, 0);
}
console.log('PASS: completion, pause/unload, fetch fallback, replay, near-end pause.');
