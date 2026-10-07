(() => {
    document.querySelectorAll('[data-close-dialog]').forEach(button => button.addEventListener('click', () => { if (!busy) button.closest('dialog').close(); }));
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
    const dialog=document.getElementById('actionDialog');
    const form=document.getElementById('actionForm');
    const name=document.getElementById('actionName');
    const choice=document.getElementById('collectionChoice');
    const error=document.getElementById('actionError');
    const submit=document.getElementById('actionSubmit');
    let pending=null;
    const titles={create_collection:'New collection',rename_collection:'Rename collection',delete_collection:'Delete collection?',add_to_collection:'Add to collection',remove_from_collection:'Remove from collection?'};
    dialog.addEventListener('cancel',event=>{if(busy)event.preventDefault();});
    document.addEventListener('click',event=>{
        const button=event.target.closest('[data-action]');
        if (!button || busy || !titles[button.dataset.action]) return;
        const action=button.dataset.action;
        pending={action};
        if(button.dataset.videoId)pending.video_id=button.dataset.videoId;
        if(button.dataset.collectionId)pending.collection_id=button.dataset.collectionId;
        const editing=['create_collection','rename_collection'].includes(action);
        document.getElementById('actionTitle').textContent=titles[action];
        name.hidden=!editing;name.required=editing;name.value=button.dataset.name||'';name.maxLength=120;
        document.getElementById('actionNameLabel').hidden=!editing;
        choice.hidden=action!=='add_to_collection';
        document.getElementById('collectionLabel').hidden=choice.hidden;
        submit.disabled=!choice.hidden&&!choice.options.length;
        submit.textContent=action==='delete_collection'?'Delete':action==='remove_from_collection'?'Remove':'Save';
        document.getElementById('actionMessage').textContent=action==='delete_collection'?'Only the collection is deleted. Its videos stay in your library.':action==='remove_from_collection'?'The video stays in your library.':!choice.hidden&&!choice.options.length?'Create a collection in the Collections tab first.':'';
        error.textContent='';dialog.showModal();if(editing){name.focus();name.select();}
    });
    form.addEventListener('submit',async event=>{
        event.preventDefault();if(busy||!pending)return;
        busy=true;submit.disabled=true;error.textContent='';
        const data={...pending};if(!name.hidden)data.name=name.value.trim();if(!choice.hidden)data.collection_id=choice.value;
        try {await libraryRequest(data);location.reload();}
        catch(err){error.textContent=err.message;busy=false;submit.disabled=false;}
    });
})();
