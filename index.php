<?php
require_once __DIR__ . '/config.php';

$result = db()->query('SELECT * FROM videos ORDER BY created_at DESC');
$videos = $result->fetch_all(MYSQLI_ASSOC);
$processingIds = array_map(fn($v) => $v['id'], array_filter($videos, fn($v) => $v['status'] === 'processing'));
$conversionMode = get_setting('conversion_mode', 'local');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Q MP4 Player</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<div class="topbar">
    <div class="brand">
        <div class="brand-mark">
            <svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
        </div>
        <div>
            <div class="brand-name">Q<span>MP4</span>Player</div>
            <div class="brand-tag">Your library. Your device. No connection required.</div>
        </div>
    </div>
</div>

<div class="container">

    <div class="upload-panel" id="uploadPanel">
        <div class="upload-panel-inner">
            <div class="upload-icon">
                <svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 16V4M12 4l-5 5M12 4l5 5"/>
                    <path d="M4 16v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/>
                </svg>
            </div>
            <div class="upload-text">
                <strong>Drop a video here, or browse</strong>
                <span>MKV, MP4, MOV, AVI, WEBM &mdash; MKV/AVI/MOV get auto-converted to a browser-friendly file on upload.</span>
            </div>
            <button class="btn" id="browseBtn">Choose file</button>
            <input type="file" id="fileInput" accept=".mkv,.mp4,.webm,.mov,.avi,.m4v,.flv,.wmv">
        </div>

        <div class="convert-mode-row">
            <span class="convert-mode-label">Convert using:</span>
            <div class="switch" id="convertModeSwitch">
                <button type="button" class="switch-option <?= $conversionMode === 'local' ? 'active' : '' ?>" data-value="local">Local (ffmpeg)</button>
                <button type="button" class="switch-option <?= $conversionMode === 'cloud' ? 'active' : '' ?>" data-value="cloud">Online (CloudConvert)</button>
            </div>
            <span class="convert-mode-hint" id="convertModeHint">Applies to new uploads. Falls back to local automatically if the cloud job fails.</span>
        </div>

        <div class="upload-form-row" id="titleRow" style="display:none;">
            <input type="text" class="title-input" id="titleInput" placeholder="Title (optional — defaults to filename)">
            <button class="btn" id="startUploadBtn">Upload</button>
            <button class="btn btn-ghost" id="cancelUploadBtn">Cancel</button>
        </div>

        <div class="upload-progress-wrap" id="progressWrap">
            <div class="progress-track-bg">
                <div class="upload-progress-fill" id="uploadFill"></div>
            </div>
            <div class="upload-status-text" id="uploadStatusText">Uploading&hellip;</div>
        </div>
    </div>

    <div class="section-heading">Your library</div>

    <?php if (empty($videos)): ?>
        <div class="empty-state">
            <svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
            <div>Nothing here yet. Upload your first video above.</div>
        </div>
    <?php else: ?>
        <div class="grid" id="videoGrid">
            <?php foreach ($videos as $v):
                $resumePct = $v['duration_seconds'] > 0 ? round(($v['last_position'] / $v['duration_seconds']) * 100) : 0;
            ?>
            <div class="card" data-id="<?= (int)$v['id'] ?>" data-status="<?= h($v['status']) ?>">
                <button class="card-delete-btn" data-id="<?= (int)$v['id'] ?>" title="Delete">
                    <svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
                <a href="<?= $v['status'] === 'ready' ? 'watch.php?id=' . (int)$v['id'] : '#' ?>" style="text-decoration:none;color:inherit;">
                    <div class="card-thumb-wrap">
                        <?php if ($v['thumbnail']): ?>
                            <img src="uploads/thumbnails/<?= h($v['thumbnail']) ?>" alt="">
                        <?php else: ?>
                            <div class="card-thumb-fallback">
                                <svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                            </div>
                        <?php endif; ?>

                        <?php if ($v['status'] === 'ready'): ?>
                            <div class="card-play-overlay">
                                <div class="card-play-btn">
                                    <svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                                </div>
                            </div>
                            <div class="card-duration"><?= h(format_duration((int)$v['duration_seconds'])) ?></div>
                            <?php if ($resumePct > 1 && $resumePct < 97): ?>
                            <div class="card-resume-bar"><div class="card-resume-fill" style="width:<?= $resumePct ?>%"></div></div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </a>
                <div class="card-body">
                    <div class="card-title" title="<?= h($v['title']) ?>"><?= h($v['title']) ?></div>
                    <div class="card-meta">
                        <span><?= h(format_bytes((int)$v['filesize_bytes'])) ?></span>
                        <?php if ($v['status'] === 'processing'): ?>
                            <span class="badge badge-processing"><span class="spinner"></span>Converting</span>
                        <?php elseif ($v['status'] === 'failed'): ?>
                            <span class="badge badge-failed" title="<?= h($v['error_message'] ?? '') ?>">Failed</span>
                        <?php elseif ($v['actual_mode']): ?>
                            <span class="mode-tag">via <?= $v['actual_mode'] === 'cloud' ? 'Online' : 'Local' ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($v['status'] === 'processing' && !empty($v['convert_note'])): ?>
                        <div class="convert-note" data-note-for="<?= (int)$v['id'] ?>"><?= h($v['convert_note']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<script>
    window.__processingIds = <?= json_encode(array_values($processingIds)) ?>;
</script>
<script src="assets/js/app.js"></script>
</body>
</html>