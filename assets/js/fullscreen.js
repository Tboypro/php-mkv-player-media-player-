(() => {
    const shell = document.getElementById('videoShell');
    const video = document.getElementById('video');
    const controls = shell?.querySelector('.controls');
    if (!shell || !video || !controls) return;
    const delay = 2500;
    let timer;
    let overControls = false;
    let dragging = false;
    let keyboardFocus = false;
    let revealTouch = false;
    function fullscreen() {
        return (document.fullscreenElement || document.webkitFullscreenElement) === shell;
    }
    function keepVisible() {
        return !fullscreen() || video.paused || video.ended || video.seeking || dragging
            || overControls || (keyboardFocus && controls.contains(document.activeElement))
            || shell.querySelector('.resume-toast.show, .shortcuts-panel.show');
    }
    function schedule() {
        clearTimeout(timer);
        if (!keepVisible()) timer = setTimeout(() => {
            if (!keepVisible()) shell.classList.add('controls-hidden');
        }, delay);
    }
    function reveal() {
        shell.classList.remove('controls-hidden');
        schedule();
    }
    function sync() {
        shell.classList.toggle('is-fullscreen', fullscreen());
        // Fullscreen transitions can move the pointer relative to the controls.
        overControls = false;
        dragging = false;
        revealTouch = false;
        reveal();
    }
    document.addEventListener('fullscreenchange', sync);
    document.addEventListener('webkitfullscreenchange', sync);
    shell.addEventListener('pointermove', event => {
        if (event.pointerType !== 'touch') {
            overControls = controls.contains(event.target);
            reveal();
        }
    });
    shell.addEventListener('pointerleave', () => { overControls = false; schedule(); });
    controls.addEventListener('pointerenter', event => {
        if (event.pointerType !== 'touch') { overControls = true; reveal(); }
    });
    controls.addEventListener('pointerleave', () => { overControls = false; schedule(); });
    shell.addEventListener('pointerdown', event => {
        keyboardFocus = false;
        revealTouch = event.pointerType === 'touch' && fullscreen()
            && shell.classList.contains('controls-hidden') && event.target === video;
        dragging = controls.contains(event.target);
        reveal();
    });
    window.addEventListener('pointerup', () => { dragging = false; schedule(); });
    window.addEventListener('pointercancel', () => { dragging = false; revealTouch = false; schedule(); });
    // The first tap on a hidden UI reveals it instead of also pausing the video.
    video.addEventListener('click', event => {
        if (revealTouch) {
            revealTouch = false;
            event.preventDefault();
            event.stopImmediatePropagation();
            reveal();
        }
    }, true);
    document.addEventListener('keydown', () => {
        keyboardFocus = true;
        if (fullscreen()) reveal();
    }, true);
    shell.addEventListener('focusin', reveal);
    shell.addEventListener('focusout', () => { setTimeout(schedule, 0); });
    for (const event of ['play', 'pause', 'ended', 'seeking', 'seeked', 'loadedmetadata', 'error']) {
        video.addEventListener(event, reveal);
    }
    // Resume/help overlays must remain usable while the video is playing.
    const observer = new MutationObserver(reveal);
    shell.querySelectorAll('.resume-toast, .shortcuts-panel').forEach(panel => {
        observer.observe(panel, { attributes: true, attributeFilter: ['class'] });
    });
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) clearTimeout(timer);
        else reveal();
    });
    sync();
})();
