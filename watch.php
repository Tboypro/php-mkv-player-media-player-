<?php
require_once __DIR__ . '/config.php';
session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax']);
if (empty($_SESSION['bookmarks_csrf'])) $_SESSION['bookmarks_csrf'] = bin2hex(random_bytes(32));
$bookmarkToken = $_SESSION['bookmarks_csrf'];
session_write_close();
header('Cache-Control: no-store');

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM videos WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$video = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$video) {
    http_response_code(404);
    die('Video not found.');
}
if ($video['status'] !== 'ready') {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($video['title']) ?> &mdash; Q MP4 Player</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="player-page">

<div class="player-topbar">
    <a href="index.php" class="back-link">
        <svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
        Library
    </a>
    <div class="player-title"><?= h($video['title']) ?></div>
</div>

<div class="stage">
    <div class="video-shell" id="videoShell">
        <video id="video" src="stream.php?id=<?= (int)$video['id'] ?>" preload="metadata"></video>

        <div class="resume-toast" id="resumeToast">
            <span>Resume from <span id="resumeTime"></span>?</span>
            <button id="resumeYes">Resume</button>
            <button id="resumeNo" style="background:transparent;color:var(--text-dim);border:1px solid var(--border);">Start over</button>
        </div>

        <div class="shortcuts-panel" id="shortcutsPanel" role="dialog" aria-modal="true" aria-labelledby="shortcutsTitle" aria-hidden="true">
            <div class="shortcuts-header">
                <div class="shortcuts-title" id="shortcutsTitle">Keyboard shortcuts</div>
                <button type="button" class="shortcuts-close" id="shortcutsClose" aria-label="Close keyboard shortcuts">&times;</button>
            </div>
            <div class="shortcuts-list">
                <div class="shortcut-item">
                    <span class="shortcut-desc">Play / pause</span>
                    <span class="shortcut-keys"><kbd>Space</kbd> or <kbd>K</kbd></span>
                </div>
                <div class="shortcut-item">
                    <span class="shortcut-desc">Back 10 seconds</span>
                    <span class="shortcut-keys"><kbd>&larr;</kbd></span>
                </div>
                <div class="shortcut-item">
                    <span class="shortcut-desc">Forward 10 seconds</span>
                    <span class="shortcut-keys"><kbd>&rarr;</kbd></span>
                </div>
                <div class="shortcut-item">
                    <span class="shortcut-desc">Mute / unmute</span>
                    <span class="shortcut-keys"><kbd>M</kbd></span>
                </div>
                <div class="shortcut-item">
                    <span class="shortcut-desc">Toggle fullscreen</span>
                    <span class="shortcut-keys"><kbd>F</kbd></span>
                </div>
            </div>
        </div>

        <div class="controls">
            <div class="progress-row">
                <span class="time-label" id="currentTime">0:00</span>
                <div class="progress-track" id="progressTrack">
                    <div class="progress-track-bg">
                        <div class="progress-buffer" id="progressBuffer"></div>
                        <div class="progress-fill" id="progressFill"></div>
                    </div>
                    <div class="progress-scrubber" id="progressScrubber"></div>
                </div>
                <span class="time-label" id="durationTime">0:00</span>
            </div>

            <div class="controls-row">
                <div class="controls-row-left">
                    <button class="ctrl-btn skip-btn" id="skipBack" title="Back 10s">
                        <svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none">
                            <path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/>
                        </svg>
                        <span class="skip-label">10</span>
                    </button>
                    <button class="ctrl-btn play-btn" id="playPause" title="Play/Pause">
                        <svg id="playIcon" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                        <svg id="pauseIcon" viewBox="0 0 24 24" style="display:none;"><path d="M6 5h4v14H6zM14 5h4v14h-4z"/></svg>
                    </button>
                    <button class="ctrl-btn skip-btn" id="skipFwd" title="Forward 10s">
                        <svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none">
                            <path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 4v5h-5"/>
                        </svg>
                        <span class="skip-label">10</span>
                    </button>
                    <span class="time-display"><span id="curTimeSmall">0:00</span> / <span id="durTimeSmall">0:00</span></span>
                </div>
                <div class="controls-row-right">
                    <select id="playbackSpeed" class="playback-speed" aria-label="Playback speed" title="Playback speed">
                        <option value="0.5">0.5×</option>
                        <option value="0.75">0.75×</option>
                        <option value="1" selected>1×</option>
                        <option value="1.25">1.25×</option>
                        <option value="1.5">1.5×</option>
                        <option value="2">2×</option>
                    </select>
                    <div class="volume-row">
                        <button class="ctrl-btn" id="muteBtn" title="Mute">
                            <svg id="volIcon" viewBox="0 0 24 24"><path d="M3 10v4h4l5 5V5L7 10H3z"/></svg>
                            <svg id="muteIcon" viewBox="0 0 24 24" style="display:none;"><path d="M3 10v4h4l5 5V5L7 10H3z"/><path d="M19 9l-4 4m0-4l4 4" stroke="var(--bg)" stroke-width="2"/></svg>
                        </button>
                        <input type="range" class="volume-slider" id="volumeSlider" min="0" max="1" step="0.05" value="1">
                    </div>
                    <button class="ctrl-btn" id="shortcutsBtn" title="Keyboard shortcuts" aria-label="Keyboard shortcuts" aria-expanded="false" aria-controls="shortcutsPanel">
                        <svg viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none">
                            <rect x="2" y="4" width="20" height="16" rx="2.5"/>
                            <path d="M6 8h.01M10 8h.01M14 8h.01M18 8h.01M6 12h.01M10 12h.01M14 12h.01M18 12h.01M8 16h8"/>
                        </svg>
                    </button>
                    <button class="ctrl-btn" id="fullscreenBtn" title="Fullscreen">
                        <svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none">
                            <path d="M8 3H5a2 2 0 0 0-2 2v3M16 3h3a2 2 0 0 1 2 2v3M21 16v3a2 2 0 0 1-2 2h-3M8 21H5a2 2 0 0 1-2-2v-3"/>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </div>
    <section class="bookmarks" id="bookmarks" aria-labelledby="bookmarksTitle"
             data-video-id="<?= (int)$video['id'] ?>" data-token="<?= h($bookmarkToken) ?>">
        <h2 id="bookmarksTitle">Saved moments</h2>
        <form id="bookmarkForm" class="bookmark-form">
            <label for="bookmarkName">Name this moment <span>(optional)</span></label>
            <div class="bookmark-create-row">
                <input id="bookmarkName" name="name" maxlength="120" placeholder="e.g. Important explanation" autocomplete="off">
                <button type="submit" id="bookmarkAdd" disabled>Bookmark current moment</button>
            </div>
        </form>
        <p id="bookmarkStatus" role="status" aria-live="polite">Loading saved moments…</p>
        <button type="button" id="bookmarkRetry" hidden>Retry loading</button>
        <ul id="bookmarkList" class="bookmark-list"></ul>
    </section>
</div>

<script>
    window.__videoId = <?= (int)$video['id'] ?>;
    window.__lastPosition = <?= (int)$video['last_position'] ?>;
    window.__duration = <?= (int)$video['duration_seconds'] ?>;
</script>
<script src="assets/js/player.js"></script>
<script src="assets/js/bookmarks.js"></script>
</body>
</html>
