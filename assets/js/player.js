(function () {
    const video = document.getElementById('video');
    const videoId = window.__videoId;
    const lastPosition = window.__lastPosition || 0;

    const playPauseBtn = document.getElementById('playPause');
    const playIcon = document.getElementById('playIcon');
    const pauseIcon = document.getElementById('pauseIcon');
    const skipBack = document.getElementById('skipBack');
    const skipFwd = document.getElementById('skipFwd');
    const progressTrack = document.getElementById('progressTrack');
    const progressFill = document.getElementById('progressFill');
    const progressBuffer = document.getElementById('progressBuffer');
    const progressScrubber = document.getElementById('progressScrubber');
    const currentTimeLabel = document.getElementById('currentTime');
    const durationTimeLabel = document.getElementById('durationTime');
    const curTimeSmall = document.getElementById('curTimeSmall');
    const durTimeSmall = document.getElementById('durTimeSmall');
    const muteBtn = document.getElementById('muteBtn');
    const volIcon = document.getElementById('volIcon');
    const muteIcon = document.getElementById('muteIcon');
    const volumeSlider = document.getElementById('volumeSlider');
    const fullscreenBtn = document.getElementById('fullscreenBtn');
    const videoShell = document.getElementById('videoShell');
    const resumeToast = document.getElementById('resumeToast');
    const resumeTimeSpan = document.getElementById('resumeTime');
    const resumeYes = document.getElementById('resumeYes');
    const resumeNo = document.getElementById('resumeNo');
    const shortcutsBtn = document.getElementById('shortcutsBtn');
    const shortcutsPanel = document.getElementById('shortcutsPanel');
    const shortcutsClose = document.getElementById('shortcutsClose');
    const playbackSpeed = document.getElementById('playbackSpeed');
    const speedStorageKey = 'qPlayer.playbackSpeed';
    const supportedSpeeds = [0.5, 0.75, 1, 1.25, 1.5, 2];

    // Storage may be blocked; speed controls should still work for this video.
    let savedSpeed = 1;
    try {
        const storedSpeed = Number(localStorage.getItem(speedStorageKey));
        if (supportedSpeeds.includes(storedSpeed)) savedSpeed = storedSpeed;
    } catch (_) {}
    video.defaultPlaybackRate = savedSpeed;
    video.playbackRate = savedSpeed;
    playbackSpeed.value = String(savedSpeed);

    playbackSpeed.addEventListener('change', () => {
        const speed = Number(playbackSpeed.value);
        if (!supportedSpeeds.includes(speed)) return;
        video.defaultPlaybackRate = speed;
        video.playbackRate = speed;
        try {
            localStorage.setItem(speedStorageKey, String(speed));
        } catch (_) {}
    });

    function formatTime(seconds) {
        seconds = Math.max(0, Math.floor(seconds || 0));
        const h = Math.floor(seconds / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        const s = seconds % 60;
        if (h > 0) return `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
        return `${m}:${String(s).padStart(2, '0')}`;
    }

    // --- Resume prompt ---
    let resumeHandled = false;
    let playbackStarted = false;
    video.addEventListener('loadedmetadata', () => {
        durationTimeLabel.textContent = formatTime(video.duration);
        durTimeSmall.textContent = formatTime(video.duration);

        if (lastPosition > 0 && lastPosition < video.duration) {
            resumeTimeSpan.textContent = formatTime(lastPosition);
            resumeToast.classList.add('show');
        } else {
            resumeHandled = true;
        }
    });

    resumeYes.addEventListener('click', () => {
        video.currentTime = lastPosition;
        resumeToast.classList.remove('show');
        resumeHandled = true;
        playbackStarted = true;
        saveProgress();
        video.play();
    });
    resumeNo.addEventListener('click', () => {
        video.currentTime = 0;
        resumeToast.classList.remove('show');
        resumeHandled = true;
        playbackStarted = true;
        saveProgress();
        video.play();
    });

    // Selecting a saved moment is an explicit alternative to Resume / Start over.
    video.addEventListener('qplayer:bookmark-seek', e => {
        const position = e.detail;
        if (!Number.isFinite(position) || position < 0 || !Number.isFinite(video.duration)
            || position > video.duration) return;
        video.currentTime = position;
        resumeToast.classList.remove('show');
        resumeHandled = true;
        playbackStarted = true;
        saveProgress();
        updateProgressUI();
    });

    // --- Play / pause ---
    function togglePlay() {
        if (video.paused) video.play();
        else video.pause();
    }
    playPauseBtn.addEventListener('click', togglePlay);
    video.addEventListener('click', togglePlay);

    video.addEventListener('play', () => {
        playbackStarted = true;
        playIcon.style.display = 'none';
        pauseIcon.style.display = '';
    });
    video.addEventListener('pause', () => {
        playIcon.style.display = '';
        pauseIcon.style.display = 'none';
        saveProgress();
    });

    // --- Skip ±10s ---
    skipBack.addEventListener('click', () => {
        video.currentTime = Math.max(0, video.currentTime - 10);
    });
    skipFwd.addEventListener('click', () => {
        video.currentTime = Math.min(video.duration || Infinity, video.currentTime + 10);
    });

    // --- Progress bar ---
    function updateProgressUI() {
        if (!video.duration) return;
        const pct = (video.currentTime / video.duration) * 100;
        progressFill.style.width = pct + '%';
        progressScrubber.style.left = pct + '%';
        currentTimeLabel.textContent = formatTime(video.currentTime);
        curTimeSmall.textContent = formatTime(video.currentTime);

        if (video.buffered.length) {
            const bufEnd = video.buffered.end(video.buffered.length - 1);
            progressBuffer.style.width = (bufEnd / video.duration) * 100 + '%';
        }
    }
    video.addEventListener('timeupdate', updateProgressUI);
    video.addEventListener('progress', updateProgressUI);

    let scrubbing = false;
    function seekFromEvent(e) {
        const rect = progressTrack.getBoundingClientRect();
        const clientX = e.touches ? e.touches[0].clientX : e.clientX;
        let pct = (clientX - rect.left) / rect.width;
        pct = Math.min(1, Math.max(0, pct));
        if (video.duration) video.currentTime = pct * video.duration;
        progressFill.style.width = (pct * 100) + '%';
        progressScrubber.style.left = (pct * 100) + '%';
    }
    progressTrack.addEventListener('mousedown', e => { scrubbing = true; seekFromEvent(e); });
    window.addEventListener('mousemove', e => { if (scrubbing) seekFromEvent(e); });
    window.addEventListener('mouseup', () => { scrubbing = false; });
    progressTrack.addEventListener('touchstart', e => { scrubbing = true; seekFromEvent(e); });
    window.addEventListener('touchmove', e => { if (scrubbing) seekFromEvent(e); });
    window.addEventListener('touchend', () => { scrubbing = false; });

    // --- Volume ---
    volumeSlider.addEventListener('input', () => {
        video.volume = volumeSlider.value;
        video.muted = video.volume == 0;
        updateMuteIcon();
    });
    muteBtn.addEventListener('click', () => {
        video.muted = !video.muted;
        updateMuteIcon();
    });
    function updateMuteIcon() {
        volIcon.style.display = video.muted ? 'none' : '';
        muteIcon.style.display = video.muted ? '' : 'none';
    }

    // --- Fullscreen ---
    fullscreenBtn.addEventListener('click', () => {
        if (document.fullscreenElement) {
            document.exitFullscreen();
        } else {
            videoShell.requestFullscreen().catch(() => {});
        }
    });

    // --- Keyboard shortcuts panel ---
    function isShortcutsOpen() {
        return shortcutsPanel && shortcutsPanel.classList.contains('show');
    }

    function openShortcuts() {
        if (!shortcutsPanel) return;
        shortcutsPanel.classList.add('show');
        shortcutsPanel.setAttribute('aria-hidden', 'false');
        if (shortcutsBtn) shortcutsBtn.setAttribute('aria-expanded', 'true');
    }

    function closeShortcuts() {
        if (!shortcutsPanel) return;
        shortcutsPanel.classList.remove('show');
        shortcutsPanel.setAttribute('aria-hidden', 'true');
        if (shortcutsBtn) shortcutsBtn.setAttribute('aria-expanded', 'false');
    }

    function toggleShortcuts() {
        if (isShortcutsOpen()) {
            closeShortcuts();
        } else {
            openShortcuts();
        }
    }

    if (shortcutsBtn) {
        shortcutsBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            toggleShortcuts();
        });
    }

    if (shortcutsClose) {
        shortcutsClose.addEventListener('click', (e) => {
            e.stopPropagation();
            closeShortcuts();
        });
    }

    if (shortcutsPanel) {
        shortcutsPanel.addEventListener('click', (e) => {
            e.stopPropagation();
        });
    }

    document.addEventListener('click', (e) => {
        if (isShortcutsOpen() && !shortcutsPanel.contains(e.target) && !shortcutsBtn?.contains(e.target)) {
            closeShortcuts();
        }
    });

    // --- Keyboard shortcuts ---
    document.addEventListener('keydown', e => {
        if (['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName)) return;

        if (e.key === 'Escape' && isShortcutsOpen()) {
            e.preventDefault();
            closeShortcuts();
            return;
        }

        // Allow Space to activate focused buttons (e.g. shortcuts toggle or close button)
        if (e.key === ' ' && document.activeElement?.tagName === 'BUTTON') {
            return;
        }

        switch (e.key) {
            case ' ':
            case 'k':
                e.preventDefault();
                togglePlay();
                break;
            case 'ArrowLeft':
                video.currentTime = Math.max(0, video.currentTime - 10);
                break;
            case 'ArrowRight':
                video.currentTime = Math.min(video.duration || Infinity, video.currentTime + 10);
                break;
            case 'f':
                fullscreenBtn.click();
                break;
            case 'm':
                muteBtn.click();
                break;
        }
    });

    // --- Autosave progress every 5s + on pause/unload ---
    function saveProgress() {
        if (!resumeHandled || !Number.isFinite(video.duration) || video.duration <= 0) return;
        // Every save path must agree at completion, including pause and unload.
        const position = video.ended || video.currentTime >= video.duration
            ? 0 : Math.floor(video.currentTime);
        const data = new FormData();
        data.append('id', videoId);
        data.append('position', position);
        data.append('started', playbackStarted ? '1' : '0');
        data.append('completed', video.ended || video.currentTime >= video.duration ? '1' : '0');
        // sendBeacon works reliably during unload; fall back to fetch otherwise
        if (navigator.sendBeacon) {
            navigator.sendBeacon('save_progress.php', data);
        } else {
            fetch('save_progress.php', { method: 'POST', body: data, keepalive: true });
        }
    }
    setInterval(() => { if (!video.paused) saveProgress(); }, 5000);
    window.addEventListener('beforeunload', saveProgress);
    video.addEventListener('ended', saveProgress);
})();
