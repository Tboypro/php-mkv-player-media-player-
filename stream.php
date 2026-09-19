<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/streaming.php';

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    header('Content-Length: 0');
    exit;
}

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT stored_filename, status FROM videos WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$video = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$video || $video['status'] !== 'ready') {
    http_response_code(404);
    exit('Video not found or not ready yet.');
}

$path = VIDEOS_DIR . '/' . $video['stored_filename'];
if (!is_file($path)) {
    http_response_code(404);
    exit('File missing on disk.');
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = $ext === 'webm' ? 'video/webm' : 'video/mp4';

stream_video_file($path, $mime);
