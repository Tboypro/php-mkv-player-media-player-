<?php
require_once __DIR__ . '/includes/library.php';
header('Cache-Control: no-store');
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
$total = (int)db()->query('SELECT COUNT(*) AS n FROM videos')->fetch_assoc()['n'];
$page = min($page, max(1, (int)ceil($total / 24)));
$offset = ($page - 1) * 24;
$videos = db()->query('SELECT * FROM videos ORDER BY created_at DESC, id DESC LIMIT 24 OFFSET ' . $offset)->fetch_all(MYSQLI_ASSOC);
$processingIds = array_column(array_filter($videos, fn($v) => $v['status'] === 'processing'), 'id');
$conversionMode = get_setting('conversion_mode', 'local');
$title = 'Your library';
function library_url(array $changes): string { return 'index.php?' . http_build_query(array_merge(['page' => 1], $changes)); }
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?> · Q Player</title><link rel="stylesheet" href="assets/css/style.css"><link rel="stylesheet" href="assets/css/library.css"></head>
<body class="refresh library-page">
<header class="q-header"><?= brand() ?><nav aria-label="Library navigation"><a aria-current="page" href="index.php">Library</a></nav><button data-open-upload><?= ui_icon('plus') ?><span>Add video</span></button></header>
<main class="library-main">
<div class="library-toolbar"><h1><?= h($title) ?></h1><span class="muted"><?= $total ?> <?= $total === 1 ? 'video' : 'videos' ?></span>
<div class="view-switch"><button id="gridView" aria-label="Grid view" aria-pressed="true"><?= ui_icon('grid') ?></button><button id="listView" aria-label="List view" aria-pressed="false"><?= ui_icon('list') ?></button></div></div>
<?php if (!$videos): ?><div class="q-empty"><?= ui_icon('play') ?><h2>No videos here yet</h2><p>Add a video to start your library.</p><button data-open-upload>Add video</button></div><?php endif ?>
<div class="video-grid" id="videoGrid">
<?php foreach ($videos as $v): ?>
<article class="card media-card" data-id="<?= (int)$v['id'] ?>" data-status="<?= h($v['status']) ?>">
<?php $tag = $v['status'] === 'ready' ? 'a' : 'div'; ?>
<<?= $tag ?> class="media-thumb" <?= $tag === 'a' ? 'href="watch.php?id='.(int)$v['id'].'" aria-label="Play '.h($v['title']).'"' : '' ?>>
<?php thumb($v) ?>
<?php if ($v['status'] === 'ready'): ?><span class="hover-play"><?= ui_icon('play') ?></span><span class="duration-badge"><?= format_duration((int)$v['duration_seconds']) ?></span>
<?php if ((int)$v['last_position'] > 0): ?><i class="card-progress" style="width:<?= progress_percent($v) ?>%"></i><?php endif ?>
<?php else: ?><span class="status-badge"><?= $v['status'] === 'processing' ? 'Converting…' : 'Conversion failed' ?></span><?php endif ?>
</<?= $tag ?>>
<div class="media-info"><div><h3><?= h($v['title']) ?></h3><p class="card-meta">
<?= h(format_bytes((int)$v['filesize_bytes'])) ?> · Added <?= h(date('M j', strtotime($v['created_at']))) ?>
<?php if ($v['actual_mode']): ?> · via <?= $v['actual_mode'] === 'cloud' ? 'Online' : 'Local' ?><?php endif ?>
</p></div><details class="card-menu"><summary aria-label="Actions for <?= h($v['title']) ?>"><?= ui_icon('more') ?></summary><div class="menu-options">
<button class="card-delete-btn danger" data-id="<?= (int)$v['id'] ?>">Delete video</button>
</div></details></div>
<?php if (in_array($v['status'], ['processing', 'ready'], true) && !empty($v['convert_note'])): ?><p class="convert-note" data-note-for="<?= (int)$v['id'] ?>"><?= h($v['convert_note']) ?></p><?php elseif ($v['status'] === 'failed'): ?><p class="error-note"><?= h($v['error_message'] ?: 'Please try uploading again.') ?></p><?php endif ?>
</article>
<?php endforeach ?></div>
<?php if ($total > 24): ?><nav class="pagination" aria-label="Pages"><?php if ($page > 1): ?><a href="<?= h(library_url(['page'=>$page-1])) ?>">Previous</a><?php endif ?><span>Page <?= $page ?> of <?= (int)ceil($total/24) ?></span><?php if ($page*24 < $total): ?><a href="<?= h(library_url(['page'=>$page+1])) ?>">Next</a><?php endif ?></nav><?php endif ?>
</main>
<dialog id="uploadDialog" class="q-dialog upload-dialog" aria-labelledby="uploadTitle"><div class="dialog-heading"><h2 id="uploadTitle">Add a video</h2><button data-close-dialog aria-label="Close upload"><?= ui_icon('close') ?></button></div>
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
                <span>MKV, MP4, MOV, AVI, WEBM &mdash; uploads are checked and converted for browser playback when needed.</span>
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
            <span class="convert-mode-hint" id="convertModeHint">Used when conversion is needed. Online sends the video to CloudConvert; if it fails, conversion continues locally.</span>
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


</dialog>
<script>window.__processingIds=<?= json_encode(array_values($processingIds)) ?>;</script>
<script src="assets/js/app.js"></script><script src="assets/js/library.js"></script>
</body></html>
