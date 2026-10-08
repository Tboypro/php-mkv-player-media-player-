(() => {
    const layout = document.getElementById('watchLayout');
    const panel = document.getElementById('bookmarks');
    const toggle = document.getElementById('bookmarksToggle');
    function show(open) {
        panel.hidden = !open;
        layout.classList.toggle('bookmarks-hidden', !open);
        toggle.setAttribute('aria-expanded', String(open));
    }
    toggle.addEventListener('click', () => show(panel.hidden));
    document.getElementById('bookmarksClose').addEventListener('click', () => { show(false); toggle.focus(); });
    const video = document.getElementById('video');
    const seek = document.getElementById('progressTrack');
    seek.addEventListener('keydown', event => {
        if (!Number.isFinite(video.duration)) return;
        const amount = {'ArrowRight':5, 'ArrowUp':5, 'ArrowLeft':-5, 'ArrowDown':-5}[event.key];
        if (amount === undefined && !['Home','End'].includes(event.key)) return;
        event.preventDefault(); event.stopPropagation();
        video.currentTime = event.key === 'Home' ? 0 : event.key === 'End' ? video.duration : Math.min(video.duration, Math.max(0, video.currentTime + amount));
    });
    video.addEventListener('timeupdate', () => {
        if (!Number.isFinite(video.duration) || video.duration<=0) return;
        seek.setAttribute('aria-valuenow', String(Math.round(video.currentTime/video.duration*100)));
        seek.setAttribute('aria-valuetext', `${Math.floor(video.currentTime)} seconds of ${Math.floor(video.duration)}`);
    });
    // Start with the drawer closed on small displays so the video comes first.
    if (matchMedia('(max-width: 800px)').matches) show(false);
})();
