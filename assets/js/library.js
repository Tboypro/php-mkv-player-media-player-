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
})();
