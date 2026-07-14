(function () {
    const uploadPanel = document.getElementById('uploadPanel');
    const browseBtn = document.getElementById('browseBtn');
    const fileInput = document.getElementById('fileInput');
    const titleRow = document.getElementById('titleRow');
    const titleInput = document.getElementById('titleInput');
    const startUploadBtn = document.getElementById('startUploadBtn');
    const cancelUploadBtn = document.getElementById('cancelUploadBtn');
    const progressWrap = document.getElementById('progressWrap');
    const uploadFill = document.getElementById('uploadFill');
    const uploadStatusText = document.getElementById('uploadStatusText');

    let pendingFile = null;

    function resetUploadUI() {
        pendingFile = null;
        fileInput.value = '';
        titleInput.value = '';
        titleRow.style.display = 'none';
        progressWrap.classList.remove('active');
        uploadFill.style.width = '0%';
    }

    browseBtn.addEventListener('click', () => fileInput.click());

    fileInput.addEventListener('change', () => {
        if (fileInput.files.length) {
            pendingFile = fileInput.files[0];
            titleInput.value = pendingFile.name.replace(/\.[^/.]+$/, '');
            titleRow.style.display = 'flex';
        }
    });

    cancelUploadBtn.addEventListener('click', resetUploadUI);

    // Drag and drop
    ['dragenter', 'dragover'].forEach(evt =>
        uploadPanel.addEventListener(evt, e => {
            e.preventDefault();
            uploadPanel.classList.add('dragover');
        })
    );
    ['dragleave', 'drop'].forEach(evt =>
        uploadPanel.addEventListener(evt, e => {
            e.preventDefault();
            uploadPanel.classList.remove('dragover');
        })
    );
    uploadPanel.addEventListener('drop', e => {
        if (e.dataTransfer.files.length) {
            pendingFile = e.dataTransfer.files[0];
            titleInput.value = pendingFile.name.replace(/\.[^/.]+$/, '');
            titleRow.style.display = 'flex';
        }
    });

    startUploadBtn.addEventListener('click', () => {
        if (!pendingFile) return;

        const formData = new FormData();
        formData.append('video', pendingFile);
        formData.append('title', titleInput.value.trim());

        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'upload.php');

        titleRow.style.display = 'none';
        progressWrap.classList.add('active');

        xhr.upload.addEventListener('progress', e => {
            if (e.lengthComputable) {
                const pct = Math.round((e.loaded / e.total) * 100);
                uploadFill.style.width = pct + '%';
                uploadStatusText.textContent = `Uploading… ${pct}%`;
            }
        });

        xhr.onload = () => {
            try {
                const res = JSON.parse(xhr.responseText);
                if (res.success) {
                    uploadStatusText.textContent = res.status === 'processing'
                        ? 'Upload complete. Converting for playback…'
                        : 'Upload complete.';
                    uploadFill.style.width = '100%';
                    setTimeout(() => window.location.reload(), 900);
                } else {
                    uploadStatusText.textContent = 'Error: ' + (res.error || 'upload failed');
                }
            } catch (err) {
                uploadStatusText.textContent = 'Unexpected server response.';
            }
        };

        xhr.onerror = () => {
            uploadStatusText.textContent = 'Upload failed — check your local server is running.';
        };

        xhr.send(formData);
    });

    // Local / online conversion toggle
    const convertModeSwitch = document.getElementById('convertModeSwitch');
    const convertModeHint = document.getElementById('convertModeHint');
    if (convertModeSwitch) {
        const defaultHint = convertModeHint ? convertModeHint.textContent : '';
        convertModeSwitch.querySelectorAll('.switch-option').forEach(btn => {
            btn.addEventListener('click', () => {
                const mode = btn.dataset.value;
                if (btn.classList.contains('active')) return;

                const fd = new FormData();
                fd.append('conversion_mode', mode);

                fetch('settings.php', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(res => {
                        if (res.success) {
                            convertModeSwitch.querySelectorAll('.switch-option').forEach(b => b.classList.remove('active'));
                            btn.classList.add('active');
                            if (convertModeHint) convertModeHint.textContent = defaultHint;
                        } else if (convertModeHint) {
                            convertModeHint.textContent = res.error || 'Could not change conversion mode.';
                        }
                    })
                    .catch(() => {
                        if (convertModeHint) convertModeHint.textContent = 'Could not reach the server to change conversion mode.';
                    });
            });
        });
    }

    // Poll conversion status for any videos still processing
    let processingIds = window.__processingIds || [];
    if (processingIds.length) {
        const poll = () => {
            fetch('convert_status.php?ids=' + processingIds.join(','))
                .then(r => r.json())
                .then(data => {
                    let stillProcessing = false;
                    data.videos.forEach(v => {
                        if (v.status === 'processing') {
                            stillProcessing = true;
                            const card = document.querySelector(`.card[data-id="${v.id}"]`);
                            if (card) {
                                let noteEl = card.querySelector(`[data-note-for="${v.id}"]`);
                                if (v.note) {
                                    if (!noteEl) {
                                        noteEl = document.createElement('div');
                                        noteEl.className = 'convert-note';
                                        noteEl.dataset.noteFor = v.id;
                                        card.querySelector('.card-meta')?.after(noteEl);
                                    }
                                    noteEl.textContent = v.note;
                                } else if (noteEl) {
                                    noteEl.remove();
                                }
                            }
                        } else {
                            const card = document.querySelector(`.card[data-id="${v.id}"]`);
                            if (card && card.dataset.status !== v.status) {
                                window.location.reload();
                            }
                        }
                    });
                    if (stillProcessing) setTimeout(poll, 4000);
                })
                .catch(() => setTimeout(poll, 6000));
        };
        setTimeout(poll, 4000);
    }

    // Delete
    document.querySelectorAll('.card-delete-btn').forEach(btn => {
        btn.addEventListener('click', e => {
            e.preventDefault();
            e.stopPropagation();
            const id = btn.dataset.id;
            if (!confirm('Delete this video? This cannot be undone.')) return;
            const fd = new FormData();
            fd.append('id', id);
            fetch('delete.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        btn.closest('.card').remove();
                    } else {
                        alert('Could not delete: ' + (res.error || 'unknown error'));
                    }
                });
        });
    });
})();