# Fullscreen playback

Fullscreen video fills the available viewport while preserving its aspect ratio.
Small videos are enlarged; portrait videos retain side bars rather than stretching.
Black borders encoded into a video cannot be removed by this CSS fix.

During fullscreen playback, controls and the pointer hide after 2.5 seconds of
inactivity. Move the pointer or use the keyboard to reveal them. The first touch
on the video with hidden controls reveals them without pausing playback.
Controls stay visible while paused, seeking, interacting with the controls,
using keyboard focus in the controls, or viewing the resume/shortcuts panel.
Outside fullscreen the controls remain visible.

## Verification

Hard-refresh the watch page after updating. Play a video, enter fullscreen,
move the pointer away from the controls and wait three seconds. Check that the
controls disappear and return when you move the pointer. Check pause, shortcuts,
keyboard navigation, and leaving fullscreen. Try a small converted video and a
portrait video: both should use the available space without distortion.

The automated browser test uses generated WebM videos and the real Fullscreen
API. It requires Node.js, Playwright with Chromium, and ffmpeg:

```sh
node tests/fullscreen_browser_test.js
```

Set PLAYWRIGHT_MODULE to an absolute Playwright module path if it is installed
outside the project's module search path. Touch reveal is tested using synthetic
pointer events; test actual touchscreen behavior on your device too.
