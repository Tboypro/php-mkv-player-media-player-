<?php
require_once __DIR__ . '/config.php';

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

$size = filesize($path);
$start = 0;
$end = $size - 1;

header('Content-Type: ' . $mime);
header('Accept-Ranges: bytes');

if (isset($_SERVER['HTTP_RANGE'])) {
    if (preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
        if ($m[1] !== '') $start = (int)$m[1];
        if ($m[2] !== '') $end = (int)$m[2];
        if ($end >= $size) $end = $size - 1;

        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }
}

$length = $end - $start + 1;
header('Content-Length: ' . $length);

$fp = fopen($path, 'rb');
fseek($fp, $start);

$chunkSize = 1024 * 1024; // 1MB chunks
$bytesLeft = $length;

while ($bytesLeft > 0 && !feof($fp)) {
    $read = min($chunkSize, $bytesLeft);
    echo fread($fp, $read);
    $bytesLeft -= $read;
    flush();
}

fclose($fp);
