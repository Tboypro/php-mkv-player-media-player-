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
    video.addEventListener('loadedmetadata', () => {
        durationTimeLabel.textContent = formatTime(video.duration);
        durTimeSmall.textContent = formatTime(video.duration);

        if (lastPosition > 5 && lastPosition < video.duration - 5) {
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
        video.play();
    });
    resumeNo.addEventListener('click', () => {
        resumeToast.classList.remove('show');
        resumeHandled = true;
        video.play();
    });

    // --- Play / pause ---
    function togglePlay() {
        if (video.paused) video.play();
        else video.pause();
    }
    playPauseBtn.addEventListener('click', togglePlay);
    video.addEventListener('click', togglePlay);

    video.addEventListener('play', () => {
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

    // --- Keyboard shortcuts ---
    document.addEventListener('keydown', e => {
        if (['INPUT', 'TEXTAREA'].includes(document.activeElement.tagName)) return;
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
        if (!video.duration) return;
        const position = Math.floor(video.currentTime);
        const data = new FormData();
        data.append('id', videoId);
        data.append('position', position);
        // sendBeacon works reliably during unload; fall back to fetch otherwise
        if (navigator.sendBeacon) {
            navigator.sendBeacon('save_progress.php', data);
        } else {
            fetch('save_progress.php', { method: 'POST', body: data, keepalive: true });
        }
    }
    setInterval(() => { if (!video.paused) saveProgress(); }, 5000);
    window.addEventListener('beforeunload', saveProgress);
    video.addEventListener('ended', () => {
        const data = new FormData();
        data.append('id', videoId);
        data.append('position', 0);
        navigator.sendBeacon('save_progress.php', data);
    });
})();
