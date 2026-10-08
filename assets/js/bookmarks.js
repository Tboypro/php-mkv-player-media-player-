(() => {
    const root = document.getElementById('bookmarks');
    if (!root) return;
    const video = document.getElementById('video');
    const form = document.getElementById('bookmarkForm');
    const name = document.getElementById('bookmarkName');
    const add = document.getElementById('bookmarkAdd');
    const list = document.getElementById('bookmarkList');
    const status = document.getElementById('bookmarkStatus');
    const retry = document.getElementById('bookmarkRetry');
    let busy = false;
    let loaded = false;
    function time(seconds) {
        const value = Math.floor(seconds);
        const h = Math.floor(value / 3600);
        const m = Math.floor(value / 60) % 60;
        const s = String(value % 60).padStart(2, '0');
        return h ? `${h}:${String(m).padStart(2, '0')}:${s}` : `${m}:${s}`;
    }
    function controls() {
        root.querySelectorAll('button, input').forEach(el => { el.disabled = busy; });
        add.disabled = busy || !loaded || !Number.isFinite(video.duration) || video.duration <= 0;
    }
    function announce(message, error = false) {
        status.textContent = message;
        status.classList.toggle('bookmark-error', error);
    }
    async function request(action, values = {}) {
        const url = new URL('bookmarks.php', window.location.href);
        const options = { credentials: 'same-origin' };
        if (action === 'list') {
            url.searchParams.set('video_id', root.dataset.videoId);
            options.cache = 'no-store';
        } else {
            options.method = 'POST';
            options.headers = { 'X-CSRF-Token': root.dataset.token };
            options.body = new URLSearchParams({ video_id: root.dataset.videoId, action, ...values });
        }
        const response = await fetch(url, options);
        let data;
        try { data = await response.json(); }
        catch (_) { throw new Error('The server returned an invalid response. Check your connection and try again.'); }
        if (!response.ok) throw new Error(data.error || 'Bookmark request failed.');
        return data;
    }
    function button(text, action) {
        const el = document.createElement('button');
        el.type = 'button';
        el.textContent = text;
        el.addEventListener('click', action);
        return el;
    }
    function render(rows) {
        list.replaceChildren();
        const count = document.getElementById('bookmarkCount');
        if (count) count.textContent = `(${rows.length})`;
        for (const row of rows) {
            const item = document.createElement('li');
            item.className = 'bookmark-item';
            const label = row.name || time(row.position_seconds);
            const jump = button(row.name ? `${time(row.position_seconds)} · ${row.name}` : time(row.position_seconds), () => {
                const position = Number(row.position_seconds);
                if (!Number.isFinite(video.duration) || position > video.duration) {
                    announce('This moment is not available yet. Wait for the video to load.', true);
                    return;
                }
                video.dispatchEvent(new CustomEvent('qplayer:bookmark-seek', { detail: position }));
                announce(`Jumped to ${time(position)}.`);
            });
            jump.className = 'bookmark-jump';
            jump.setAttribute('aria-label', `Jump to ${label} at ${time(row.position_seconds)}`);
            const actions = document.createElement('details');
            const summary = document.createElement('summary');
            summary.textContent = '⋮';
            summary.setAttribute('aria-label', `Actions for ${label}`);
            actions.append(summary);
            actions.className = 'bookmark-actions';
            const rename = button('Rename', () => {
                if (item.querySelector('form')) return;
                const edit = document.createElement('form');
                edit.className = 'bookmark-edit';
                const input = document.createElement('input');
                input.maxLength = 120;
                input.value = row.name;
                input.placeholder = time(row.position_seconds);
                input.setAttribute('aria-label', `New name for ${label}`);
                const save = document.createElement('button');
                save.type = 'submit';
                save.textContent = 'Save';
                const cancel = button('Cancel', () => { edit.remove(); rename.focus(); });
                edit.append(input, save, cancel);
                edit.addEventListener('keydown', e => {
                    if (e.key === 'Escape') { e.preventDefault(); edit.remove(); rename.focus(); }
                });
                edit.addEventListener('submit', async e => {
                    e.preventDefault();
                    if (await mutate('rename', { id: row.id, name: input.value }, 'Bookmark renamed.')) add.focus();
                });
                item.append(edit);
                input.focus();
            });
            rename.setAttribute('aria-label', `Rename ${label}`);
            const remove = button('Delete', async () => {
                if (!window.confirm(`Delete bookmark “${label}”?`)) return;
                if (await mutate('delete', { id: row.id }, 'Bookmark deleted.')) add.focus();
            });
            remove.setAttribute('aria-label', `Delete ${label}`);
            actions.append(rename, remove);
            item.append(jump, actions);
            list.append(item);
        }
    }
    async function refresh() {
        const data = await request('list');
        render(data.bookmarks);
        loaded = true;
        retry.hidden = true;
        return data.bookmarks.length;
    }
    async function load() {
        if (busy) return;
        busy = true;
        controls();
        try {
            const count = await refresh();
            announce(count ? `${count} saved moment${count === 1 ? '' : 's'}.` : 'No bookmarks yet. Save a moment to find it easily later.');
        } catch (error) {
            loaded = false;
            retry.hidden = false;
            announce(error.message, true);
        } finally { busy = false; controls(); }
    }
    async function mutate(action, values, message) {
        if (busy) return false;
        busy = true;
        controls();
        try {
            await request(action, values);
            if (action === 'create') name.value = '';
            try {
                await refresh();
                announce(message);
            } catch (_) {
                loaded = false;
                list.replaceChildren();
                retry.hidden = false;
                announce(`${message} Could not reload the list. Use Retry loading.`, true);
            }
            return true;
        } catch (error) {
            announce(error.message, true);
            return false;
        } finally { busy = false; controls(); }
    }
    form.addEventListener('submit', e => {
        e.preventDefault();
        if (add.disabled) return;
        // Capture before the request, so network latency cannot move the bookmark.
        const position = Math.floor(video.currentTime);
        mutate('create', { position, name: name.value }, `Saved moment at ${time(position)}.`);
    });
    retry.addEventListener('click', load);
    video.addEventListener('loadedmetadata', controls);
    video.addEventListener('durationchange', controls);
    video.addEventListener('emptied', controls);
    load();
})();
