(() => {
    document.querySelectorAll('[data-close-dialog]').forEach(button => button.addEventListener('click', () => button.closest('dialog').close()));
    document.querySelectorAll('[data-open-upload]').forEach(button => button.addEventListener('click', () => document.getElementById('uploadDialog').showModal()));
    function layout(value) {
        const list = value === 'list';
        document.getElementById('videoGrid')?.classList.toggle('list-layout', list);
        document.getElementById('gridView')?.setAttribute('aria-pressed', String(!list));
        document.getElementById('listView')?.setAttribute('aria-pressed', String(list));
        try { localStorage.setItem('qPlayer.libraryLayout', list ? 'list' : 'grid'); } catch (_) {}
    }
    try { layout(localStorage.getItem('qPlayer.libraryLayout')); } catch (_) {}
    document.getElementById('gridView')?.addEventListener('click', () => layout('grid'));
    document.getElementById('listView')?.addEventListener('click', () => layout('list'));
    document.addEventListener('click', event => {
        document.querySelectorAll('.card-menu[open]').forEach(menu => { if (!menu.contains(event.target)) menu.open = false; });
    });
    let busy = false;
    async function libraryRequest(data) {
        const response = await fetch('library_actions.php', {method:'POST', headers:{'X-CSRF-Token':window.__libraryToken}, body:new URLSearchParams(data)});
        let result;
        try { result = await response.json(); } catch (_) { throw new Error('Invalid server response. Please retry.'); }
        if (!response.ok || !result.success) throw new Error(result.error || 'Could not save the change.');
        return result;
    }
    document.addEventListener('click', async event => {
        const button = event.target.closest('[data-action="favorite"]');
        if (!button || busy) return;
        busy=true; button.disabled=true;
        try { await libraryRequest({action:'favorite',video_id:button.dataset.videoId,value:button.dataset.value}); location.reload(); }
        catch (error) { document.getElementById('libraryStatus').textContent=error.message; busy=false; button.disabled=false; }
    });
})();
