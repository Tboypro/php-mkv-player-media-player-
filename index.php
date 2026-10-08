<?php
require_once __DIR__ . '/includes/library.php';
library_ready(); $token = library_token();
header('Cache-Control: no-store');
$view = is_string($_GET['view'] ?? null) && in_array($_GET['view'], ['favorites','collections'], true) ? $_GET['view'] : 'library';
$collections = db()->query('SELECT c.*, COUNT(cv.video_id) AS video_count FROM collections c LEFT JOIN collection_videos cv ON cv.collection_id=c.id GROUP BY c.id ORDER BY c.name, c.id')->fetch_all(MYSQLI_ASSOC);
$collectionId = 0; $currentCollection = null;
if (isset($_GET['collection'])) {
    $collectionId = filter_var($_GET['collection'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($collectionId === false) { http_response_code(400); exit('Invalid collection.'); }
    foreach ($collections as $collection) if ((int)$collection['id'] === $collectionId) $currentCollection = $collection;
    if (!$currentCollection) { http_response_code(404); exit('Collection not found.'); }
    $view = 'collections';
}
$isCollectionOverview = $view === 'collections' && !$collectionId;
$condition = $view === 'favorites' ? ' WHERE is_favorite = 1' : '';
$params = []; $types = '';
if ($collectionId) { $condition = ' WHERE EXISTS (SELECT 1 FROM collection_videos cv WHERE cv.video_id=videos.id AND cv.collection_id=?)'; $params = [$collectionId]; $types = 'i'; }
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
$total = (int)library_query('SELECT COUNT(*) AS n FROM videos' . $condition, $types, $params)->get_result()->fetch_assoc()['n'];
$page = min($page, max(1, (int)ceil($total / 24)));
$offset = ($page - 1) * 24;
$videos = library_query('SELECT * FROM videos' . $condition . ' ORDER BY created_at DESC, id DESC LIMIT 24 OFFSET ' . $offset, $types, $params)->get_result()->fetch_all(MYSQLI_ASSOC);
if ($isCollectionOverview) $videos = [];
// Legacy positions have unknown viewing times and sort after timestamped activity.
$continue = db()->query("SELECT * FROM videos WHERE status='ready' AND is_completed=0 AND last_position>0 AND (duration_seconds=0 OR duration_seconds>last_position) ORDER BY last_watched_at DESC, id DESC LIMIT 4")->fetch_all(MYSQLI_ASSOC);
$hero = array_shift($continue);
$showHero = $view === 'library' && $page === 1;
$processingIds = array_column(array_filter($videos, fn($v) => $v['status'] === 'processing'), 'id');
$conversionMode = get_setting('conversion_mode', 'local');
$title = $currentCollection ? $currentCollection['name'] : ($view === 'favorites' ? 'Favorites' : ($isCollectionOverview ? 'Collections' : 'Your library'));
function library_url(array $changes): string { return 'index.php?' . http_build_query(array_merge(array_filter(['view' => $GLOBALS['view'], 'collection' => $GLOBALS['collectionId'], 'page' => 1]), $changes)); }
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?> · Q Player</title><link rel="stylesheet" href="assets/css/style.css"><link rel="stylesheet" href="assets/css/library.css"></head>
<body class="refresh library-page">
<header class="q-header"><?= brand() ?><nav aria-label="Library navigation"><?php foreach (['library'=>'Library','collections'=>'Collections','favorites'=>'Favorites'] as $key=>$label): ?><a <?= $view === $key ? 'aria-current="page"' : '' ?> href="index.php?view=<?= $key ?>"><?= $label ?></a><?php endforeach ?></nav><button data-open-upload><?= ui_icon('plus') ?><span>Add video</span></button></header>
<main class="library-main">
<?php if ($showHero && $hero): ?>
<section class="continue-section" aria-label="Continue watching">
<a class="hero-video" href="watch.php?id=<?= (int)$hero['id'] ?>"><?php thumb($hero) ?><span class="hero-play"><?= ui_icon('play') ?></span><div class="hero-copy"><h2><?= h($hero['title']) ?></h2><p>Resume from <?= format_duration((int)$hero['last_position']) ?><?php if ((int)$hero['duration_seconds'] > 0): ?> · <?= (int)ceil(((int)$hero['duration_seconds']-(int)$hero['last_position'])/60) ?> min remaining<?php endif ?></p></div><div class="hero-progress"><i style="width:<?= progress_percent($hero) ?>%"></i></div></a>
<aside class="continue-list"><h2>Continue watching</h2><?php if (!$continue): ?><p class="muted">Your other unfinished videos will appear here.</p><?php endif ?><?php foreach ($continue as $v): ?><a class="continue-item" href="watch.php?id=<?= (int)$v['id'] ?>"><div class="mini-thumb"><?php thumb($v) ?><i style="width:<?= progress_percent($v) ?>%"></i></div><div><h3><?= h($v['title']) ?></h3><p><?= format_duration((int)$v['last_position']) ?><?php if ((int)$v['duration_seconds'] > 0): ?> · <?= (int)ceil(((int)$v['duration_seconds']-(int)$v['last_position'])/60) ?> min left<?php endif ?></p></div></a><?php endforeach ?></aside>
</section>
<?php endif ?>

<?php if ($view === 'collections' && !$collectionId): ?>
<div class="library-toolbar"><h1>Collections</h1><button class="primary" data-action="create_collection"><?= ui_icon('plus') ?>New collection</button></div>
<?php if (!$collections): ?><div class="q-empty"><?= ui_icon('folder') ?><h2>A place for everything</h2><p>Group your videos into collections, such as Films or Physics.</p><button data-action="create_collection">Create your first collection</button></div><?php endif ?>
<div class="collection-grid"><?php foreach ($collections as $c): ?><article class="collection-card"><a href="index.php?view=collections&amp;collection=<?= (int)$c['id'] ?>"><?= ui_icon('folder') ?><h2><?= h($c['name']) ?></h2><p><?= (int)$c['video_count'] ?> videos</p></a><div class="collection-actions"><button data-action="rename_collection" data-collection-id="<?= (int)$c['id'] ?>" data-name="<?= h($c['name']) ?>">Rename</button><button data-action="delete_collection" data-collection-id="<?= (int)$c['id'] ?>">Delete collection</button></div></article><?php endforeach ?></div>
<?php endif ?>
<?php if (!$isCollectionOverview): ?>

<div class="library-toolbar"><h1><?= h($title) ?></h1><span class="muted"><?= $total ?> <?= $total === 1 ? 'video' : 'videos' ?></span>
<div class="view-switch"><button id="gridView" aria-label="Grid view" aria-pressed="true"><?= ui_icon('grid') ?></button><button id="listView" aria-label="List view" aria-pressed="false"><?= ui_icon('list') ?></button></div></div>
<?php if (!$videos): ?><div class="q-empty"><?= ui_icon('play') ?><h2>No videos here yet</h2><p><?= $view === 'favorites' ? 'Favorite a video from its menu to find it here.' : ($currentCollection ? 'Add videos to this collection using their menu in the Library tab.' : 'Add a video to start your library.') ?></p><button data-open-upload>Add video</button></div><?php endif ?>
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
<div class="media-info"><div><h3><?= h($v['title']) ?></h3><p class="card-meta"><?php if ($v['is_favorite']): ?><span class="favorite-mark" aria-label="Favorite">♥</span> <?php endif ?>
<?= h(format_bytes((int)$v['filesize_bytes'])) ?> · Added <?= h(date('M j', strtotime($v['created_at']))) ?>
<?php if ($v['actual_mode']): ?> · via <?= $v['actual_mode'] === 'cloud' ? 'Online' : 'Local' ?><?php endif ?>
</p></div><details class="card-menu"><summary aria-label="Actions for <?= h($v['title']) ?>"><?= ui_icon('more') ?></summary><div class="menu-options">
<button data-action="favorite" data-video-id="<?= (int)$v['id'] ?>" data-value="<?= $v['is_favorite'] ? 0 : 1 ?>"><?= $v['is_favorite'] ? 'Remove favorite' : 'Favorite' ?></button>
<button data-action="add_to_collection" data-video-id="<?= (int)$v['id'] ?>">Add to collection</button>
<?php if ($collectionId): ?><button data-action="remove_from_collection" data-video-id="<?= (int)$v['id'] ?>" data-collection-id="<?= $collectionId ?>">Remove from collection</button><?php endif ?>
<button class="card-delete-btn danger" data-id="<?= (int)$v['id'] ?>">Delete video</button>
</div></details></div>
<?php if (in_array($v['status'], ['processing', 'ready'], true) && !empty($v['convert_note'])): ?><p class="convert-note" data-note-for="<?= (int)$v['id'] ?>"><?= h($v['convert_note']) ?></p><?php elseif ($v['status'] === 'failed'): ?><p class="error-note"><?= h($v['error_message'] ?: 'Please try uploading again.') ?></p><?php endif ?>
</article>
<?php endforeach ?></div>
<?php if ($total > 24): ?><nav class="pagination" aria-label="Pages"><?php if ($page > 1): ?><a href="<?= h(library_url(['page'=>$page-1])) ?>">Previous</a><?php endif ?><span>Page <?= $page ?> of <?= (int)ceil($total/24) ?></span><?php if ($page*24 < $total): ?><a href="<?= h(library_url(['page'=>$page+1])) ?>">Next</a><?php endif ?></nav><?php endif ?>
<?php endif ?>
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
<?php library_dialogs($collections, $token) ?>
<script>window.__processingIds=<?= json_encode(array_values($processingIds)) ?>;</script>
<script src="assets/js/app.js"></script><script src="assets/js/library.js"></script>
</body></html>
